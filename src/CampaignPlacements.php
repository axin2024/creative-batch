<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignPlacements
{
    public function __construct(private Db $db, private Catalog $catalog, private Campaigns $campaigns,
        private CampaignLayout $layout, private CampaignRights $rights, private CampaignConnector $connector) {}

    public function submit(Scope $scope, string $campaign, mixed $key, array $input): array
    {
        $scope->requireRole('operator');
        if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z/D', $key)) {
            throw CampaignData::releaseField('Idempotency-Key', 'Provide a stable request key.');
        }
        $territory = CampaignData::territory($input['territory'] ?? null);
        $locale = CampaignData::locale($input['locale'] ?? null);
        $channel = Values::id($input['channel'] ?? null);
        $asset = Values::token($input['asset_rev'] ?? null);
        $rights = Values::token($input['rights_rev'] ?? null, 64);
        $replaces = isset($input['replaces']) ? Values::token($input['replaces']) : null;
        $digest = CampaignData::digest([$territory, $locale, $channel, $asset, $rights, $replaces]);
        return $this->db->transaction(function () use ($scope, $campaign, $key, $territory, $locale, $channel, $asset, $rights, $replaces, $digest): array {
            $this->catalog->lockStudio($scope);
            $this->campaigns->workspace($scope, $campaign);
            $prior = $this->db->one('SELECT id,input_digest FROM campaign_placements WHERE tenant=? AND campaign=? AND request_key=?',
                [$scope->tenant, $campaign, $key]);
            if ($prior !== null) {
                if (!hash_equals($prior['input_digest'], $digest)) {
                    throw Problem::conflict('The same key carries a different placement request.');
                }
                return $this->record($scope, $prior['id']) + ['replayed' => true];
            }
            $render = $this->layout->record($scope, $asset);
            if ([$campaign, $territory, $locale, $channel] !== [$render['campaign'], $render['territory'], $render['locale'], $render['channel']]) {
                throw CampaignData::releaseField('asset_rev', 'Choose a render for this campaign, territory, locale and channel.');
            }
            $this->rights->requireCurrentRender($scope, $render);
            $approved = $this->rights->latestApproval($scope, $asset);
            if ($approved === null || $approved['decision'] !== 'approved' || $approved['rights_rev'] !== $rights) {
                throw CampaignData::releaseField('rights_rev', 'Choose the currently approved regional rights revision.');
            }
            $schedule = $approved['snapshot'];
            $from = $schedule['starts_at'];
            $until = $schedule['ends_at'];
            if ($until <= gmdate('Y-m-d H:i:s')) {
                throw CampaignData::releaseField('ends_at', 'The approved schedule has ended.');
            }
            $checked = $this->rights->evaluate($scope, $render, $from, $until, $schedule['rights']['grants']);
            if ($checked['rights_rev'] !== $rights) {
                throw CampaignData::releaseField('rights_rev', 'The approved rights cannot be used to publish.', $checked);
            }
            $slot = CampaignData::digest([$territory, $locale, $channel]);
            $inUse = $this->db->one('SELECT id FROM campaign_placements WHERE tenant=? AND campaign=? AND active_slot=?', [$scope->tenant, $campaign, $slot]);
            if ($inUse !== null) {
                throw Problem::conflict('Withdraw the current placement before publishing a replacement.');
            }
            $latest = $this->db->one('SELECT id,asset_rev,delivery_state FROM campaign_placements WHERE tenant=? AND campaign=?
                AND territory=? AND locale=? AND channel=? ORDER BY created_at DESC,id DESC LIMIT 1',
                [$scope->tenant, $campaign, $territory, $locale, $channel]);
            if (($latest['id'] ?? null) !== $replaces || $latest !== null && (!in_array($latest['delivery_state'], ['withdrawn', 'cancelled'], true) ||
                $latest['asset_rev'] === $asset)) {
                throw CampaignData::releaseField('replaces', 'Identify the most recent withdrawn placement and select a newly approved render.');
            }
            $master = $this->campaigns->master($scope, $campaign, $render['evidence']['master_rev']);
            $id = Values::newId();
            $manifest = ['version' => 2, 'placement_id' => $id, 'campaign' => $campaign, 'master_rev' => $master['revision'],
                'master_sha256' => $master['digest'], 'term_rev' => $render['evidence']['term_rev'], 'segment_rev' => $render['localization_revision'],
                'territory' => $territory, 'locale' => $locale, 'channel' => $channel, 'asset_rev' => $asset,
                'channel_revision' => $render['evidence']['channel_revision'], 'renderer' => $render['evidence']['renderer'],
                'image_sha256' => $render['image_digest'], 'approval_revision' => $approved['revision'], 'rights_rev' => $rights,
                'grants' => $checked['grants'], 'connector' => $this->connector->binding($channel),
                'starts_at' => $from, 'ends_at' => $until, 'replaces' => $replaces];
            $manifestDigest = CampaignData::digest($manifest);
            $this->db->exec("INSERT INTO campaign_placements (tenant,id,campaign,master_revision,territory,locale,channel,asset_rev,
                approval_revision,rights_rev,request_key,input_digest,manifest_digest,manifest,delivery_state,active_slot,replaces,starts_at,ends_at,actor)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?)", [$scope->tenant, $id, $campaign, $master['revision'], $territory,
                $locale, $channel, $asset, $approved['revision'], $rights, $key, $digest, $manifestDigest, Values::json($manifest),
                $slot, $replaces, $from, $until, $scope->actor]);
            foreach ($checked['grants'] as $grant) {
                $this->db->exec('INSERT INTO campaign_placement_sources (tenant,placement,asset_type,source_digest,grant_id,grant_revision)
                    VALUES(?,?,?,?,?,?)', [$scope->tenant, $id, $grant['type'], $grant['digest'], $grant['id'], $grant['revision']]);
            }
            $this->db->exec("INSERT INTO campaign_dispatch(tenant,placement,operation,available_at,owner) VALUES(?,?,'publish',?,?)",
                [$scope->tenant, $id, $from, $scope->actor]);
            $this->db->audit($scope, 'campaign-placement-enqueued', $id, ['manifest_digest' => $manifestDigest, 'channel' => $channel, 'replaces' => $replaces]);
            return $this->record($scope, $id);
        });
    }

    public function listing(Scope $scope, string $id): array
    {
        $this->campaigns->workspace($scope, $id);
        return $this->db->all('SELECT id AS placement_id,territory,locale,channel,master_revision,asset_rev,manifest_digest,delivery_state,
            remote_revision,replaces,starts_at,ends_at FROM campaign_placements WHERE tenant=? AND campaign=? ORDER BY created_at DESC,id DESC LIMIT 500',
            [$scope->tenant, $id]);
    }

    public function record(Scope $scope, string $id): array
    {
        $scope->requireRole('operator', 'designer', 'reviewer');
        $id = Values::token($id);
        $row = $this->db->one('SELECT * FROM campaign_placements WHERE tenant=? AND id=?', [$scope->tenant, $id]) ?? throw Problem::missing();
        $record = CampaignData::decodeRow($row, 'manifest');
        unset($record['request_key'], $record['input_digest'], $record['active_slot']);
        $record['placement_id'] = $record['id'];
        $record['operations'] = array_map(static function (array $value): array {
            $value['remote_receipt'] = $value['remote_receipt'] === null ? null : Values::decode($value['remote_receipt']);
            return $value;
        }, $this->db->all('SELECT operation,phase,attempts,owner,last_failure,remote_receipt,updated_at FROM campaign_dispatch
            WHERE tenant=? AND placement=? ORDER BY operation', [$scope->tenant, $id]));
        return $record;
    }

    public function retry(Scope $scope, string $id, string $operation): array
    {
        $scope->requireRole('operator');
        $operation = Values::choose($operation, ['publish', 'withdraw']);
        return $this->db->transaction(function () use ($scope, $id, $operation): array {
            $this->catalog->lockStudio($scope);
            $placement = $this->record($scope, $id);
            if ($operation === 'publish' && $placement['delivery_state'] !== 'failed' && $placement['delivery_state'] !== 'uncertain' ||
                $operation === 'withdraw' && $placement['delivery_state'] !== 'withdrawing') {
                throw Problem::conflict('This operation has no retryable result.');
            }
            $changed = $this->db->exec("UPDATE campaign_dispatch SET phase='uncertain',available_at=UTC_TIMESTAMP(6),owner=?
                WHERE tenant=? AND placement=? AND operation=? AND phase IN ('failed','uncertain')", [$scope->actor, $scope->tenant, $id, $operation]);
            if ($changed !== 1) {
                throw Problem::conflict('This operation has no retryable receipt.');
            }
            $this->db->audit($scope, 'campaign-operation-retry', $id, ['operation' => $operation]);
            return $this->record($scope, $id);
        });
    }
}
