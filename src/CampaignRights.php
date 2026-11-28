<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignRights
{
    public function __construct(private Db $db, private Catalog $catalog, private Campaigns $campaigns, private CampaignLayout $layout) {}

    public function grant(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole();
        $id = Values::id($id);
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $type = Values::choose($input['asset_type'] ?? null, ['image', 'font']);
        $digest = Values::token($input['source_digest'] ?? null, 64);
        if ($type === 'image') {
            $this->catalog->image($scope->tenant, $digest);
        } else {
            $this->campaigns->font($scope, $digest, false);
        }
        $from = Values::time($input['valid_from'] ?? null);
        $until = Values::time($input['valid_until'] ?? null);
        if ($from >= $until) {
            throw CampaignData::releaseField('valid_until', 'The grant must expire after its start.');
        }
        $territories = array_map(CampaignData::territory(...), Values::listOf($input['territories'] ?? null, 1, 64));
        $channels = array_map(Values::id(...), Values::listOf($input['channels'] ?? null, 1, 32));
        sort($territories, SORT_STRING);
        sort($channels, SORT_STRING);
        $snapshot = ['evidence' => Values::text($input['evidence'] ?? null, 600), 'licensor' => Values::text($input['licensor'] ?? null, 160),
            'territories' => array_values(array_unique($territories)), 'channels' => array_values(array_unique($channels)),
            'commercial' => Values::boolean($input['commercial'] ?? null)];
        return $this->db->transaction(function () use ($scope, $id, $expected, $type, $digest, $from, $until, $snapshot): array {
            $this->catalog->lockStudio($scope);
            if ($this->sourceRevoked($scope, $type, $digest)) {
                throw CampaignData::releaseField('source_digest', 'Replace this withdrawn source before restoring a placement.');
            }
            $previous = $this->db->one('SELECT revision,revoked_at,valid_until,asset_type,source_digest FROM campaign_grants
                WHERE tenant=? AND id=? ORDER BY revision DESC LIMIT 1', [$scope->tenant, $id]);
            if (($previous['revision'] ?? 0) !== $expected) {
                throw Problem::conflict('The rights grant was revised.');
            }
            if ($previous !== null && ($previous['asset_type'] !== $type || $previous['source_digest'] !== $digest)) {
                throw CampaignData::releaseField('source_digest', 'Create a separate grant for a different source.');
            }
            if ($previous !== null && $previous['revoked_at'] === null && $previous['valid_until'] > gmdate('Y-m-d H:i:s')) {
                throw Problem::conflict('Revoke or let the previous grant expire before issuing another revision.');
            }
            $revision = $expected + 1;
            $this->db->exec('INSERT INTO campaign_grants(tenant,id,revision,asset_type,source_digest,snapshot,valid_from,valid_until,actor)
                VALUES(?,?,?,?,?,?,?,?,?)', [$scope->tenant, $id, $revision, $type, $digest, Values::json($snapshot), $from, $until, $scope->actor]);
            $this->db->audit($scope, 'campaign-granted', $id, ['grant_rev' => $revision, 'source_digest' => $digest]);
            return $this->record($scope, $id, $revision);
        });
    }

    public function record(Scope $scope, string $id, int $revision): array
    {
        $scope->requireRole('reviewer', 'operator', 'designer');
        $row = $this->db->one('SELECT * FROM campaign_grants WHERE tenant=? AND id=? AND revision=?',
            [$scope->tenant, Values::id($id), $revision]) ?? throw Problem::missing();
        return CampaignData::decodeRow($row, 'snapshot');
    }

    public function listing(Scope $scope): array
    {
        $scope->requireRole('reviewer', 'operator', 'designer');
        return array_map(static fn(array $row): array => CampaignData::decodeRow($row, 'snapshot'),
            $this->db->all('SELECT * FROM campaign_grants WHERE tenant=? ORDER BY created_at DESC,id,revision DESC LIMIT 150', [$scope->tenant]));
    }

    public function sourceRevoked(Scope $scope, string $type, string $digest): bool
    {
        return $this->db->one('SELECT id FROM campaign_recalls WHERE tenant=? AND asset_type=? AND source_digest=? AND grant_id IS NULL LIMIT 1',
            [$scope->tenant, $type, $digest]) !== null;
    }

    public function evaluate(Scope $scope, array $render, string $from, string $until, array $selection): array
    {
        $available = [];
        foreach (Values::listOf($selection, 0, 8) as $entry) {
            $id = Values::id($entry['id'] ?? null);
            $revision = Values::integer($entry['revision'] ?? null, 1, 1_000_000);
            $grant = $this->record($scope, $id, $revision);
            $grant['valid_from'] = substr($grant['valid_from'], 0, 19);
            $grant['valid_until'] = substr($grant['valid_until'], 0, 19);
            $key = $grant['asset_type'] . ':' . $grant['source_digest'];
            if (isset($available[$key])) {
                throw CampaignData::releaseField('grants', 'Select a single rights revision per source.');
            }
            $available[$key] = $grant;
        }
        $sources = [];
        foreach ($render['evidence']['sources'] as $source) {
            $sources[$source['type'] . ':' . $source['digest']] = $source;
        }
        if (array_diff_key($available, $sources)) {
            throw CampaignData::releaseField('grants', 'Select only grants for sources used in this render.');
        }
        ksort($sources, SORT_STRING);
        $blocked = [];
        $frozen = [];
        $latest = null;
        foreach ($sources as $key => $source) {
            $grant = $available[$key] ?? null;
            $policy = $grant['snapshot'] ?? [];
            $reason = match (true) {
                $this->sourceRevoked($scope, $source['type'], $source['digest']) => 'source-withdrawn',
                $grant === null => 'no-grant',
                $grant['revoked_at'] !== null => 'grant-revoked',
                !$policy['commercial'] => 'preview-only',
                !in_array($render['territory'], $policy['territories'], true) => 'territory-excluded',
                !in_array($render['channel'], $policy['channels'], true) => 'channel-excluded',
                $grant['valid_from'] > $from => 'starts-before-grant',
                $grant['valid_until'] < $until => 'ends-after-grant',
                default => null,
            };
            if ($grant !== null && ($reason === null || $reason === 'ends-after-grant')) {
                $latest = $latest === null ? $grant['valid_until'] : min($latest, $grant['valid_until']);
            }
            if ($reason !== null) {
                $blocked[] = $source + ['code' => $reason, 'territory' => $render['territory'], 'channel' => $render['channel'],
                    'conflict_date' => $reason === 'starts-before-grant' ? $grant['valid_from'] : ($grant['valid_until'] ?? null),
                    'replacement_grants' => $reason === 'source-withdrawn' ? [] : $this->eligibleGrants($scope, $source, $render, $from, $until)];
                continue;
            }
            $frozen[] = $source + ['id' => $grant['id'], 'revision' => $grant['revision'], 'valid_from' => $grant['valid_from'],
                'valid_until' => $grant['valid_until'], 'evidence' => $policy['evidence'], 'licensor' => $policy['licensor']];
        }
        return ['rights_rev' => $blocked === [] ? CampaignData::digest([$render['id'], $from, $until, $frozen]) : null,
            'latest_eligible_end' => $latest, 'grants' => $frozen, 'blocked_regions' => $blocked];
    }

    public function preview(Scope $scope, string $id, array $input): array
    {
        $render = $this->layout->record($scope, $id);
        [$from, $until] = self::schedule($input);
        return $this->evaluate($scope, $render, $from, $until, $input['grants'] ?? []);
    }

    private function eligibleGrants(Scope $scope, array $source, array $render, string $from, string $until): array
    {
        return $this->db->all("SELECT id,revision,valid_from,valid_until FROM campaign_grants
            WHERE tenant=? AND asset_type=? AND source_digest=? AND revoked_at IS NULL AND valid_from<=? AND valid_until>=?
            AND JSON_EXTRACT(snapshot,'$.commercial')=CAST('true' AS JSON)
            AND JSON_CONTAINS(snapshot->'$.territories',JSON_QUOTE(?))
            AND JSON_CONTAINS(snapshot->'$.channels',JSON_QUOTE(?))
            ORDER BY valid_until DESC,id,revision DESC LIMIT 12",
            [$scope->tenant, $source['type'], $source['digest'], $from, $until, $render['territory'], $render['channel']]);
    }

    public static function schedule(array $input): array
    {
        $from = Values::time($input['starts_at'] ?? null);
        $until = Values::time($input['ends_at'] ?? null);
        if ($from >= $until || $until <= gmdate('Y-m-d H:i:s')) {
            throw CampaignData::releaseField('ends_at', 'Use an end after the start and the current time.');
        }
        return [$from, $until];
    }

    public function approve(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('reviewer');
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $decision = Values::choose($input['decision'] ?? null, ['approved', 'rejected']);
        $reason = Values::text($input['reason'] ?? null, 600);
        return $this->db->transaction(function () use ($scope, $id, $input, $expected, $decision, $reason): array {
            $this->catalog->lockStudio($scope);
            $render = $this->layout->record($scope, $id);
            $prior = $this->latestApproval($scope, $id);
            if (($prior['revision'] ?? 0) !== $expected) {
                throw Problem::conflict('The regional review changed.');
            }
            $snapshot = ['reason' => $reason];
            $rights = null;
            if ($decision === 'approved') {
                $this->requireCurrentRender($scope, $render);
                $language = $render['evidence']['language_flags'];
                $layout = $render['evidence']['layout_flags'];
                if ($language || $layout) {
                    throw CampaignData::releaseField('render', 'Resolve language and layout blockers before approval.',
                        ['language_flags' => $language, 'layout_flags' => $layout]);
                }
                [$from, $until] = self::schedule($input);
                $rights = $this->evaluate($scope, $render, $from, $until, $input['grants'] ?? []);
                if ($rights['blocked_regions']) {
                    throw CampaignData::releaseField('grants', 'The regional rights do not cover the scheduled placement.', $rights);
                }
                $snapshot += ['starts_at' => $from, 'ends_at' => $until, 'rights' => $rights];
            }
            $revision = $expected + 1;
            $this->db->exec('INSERT INTO campaign_approvals(tenant,render,revision,decision,rights_rev,snapshot,actor) VALUES(?,?,?,?,?,?,?)',
                [$scope->tenant, $id, $revision, $decision, $rights['rights_rev'] ?? null, Values::json($snapshot), $scope->actor]);
            $this->db->audit($scope, 'campaign-regional-reviewed', $id, ['review_rev' => $revision, 'decision' => $decision, 'rights_rev' => $rights['rights_rev'] ?? null]);
            return $this->latestApproval($scope, $id);
        });
    }

    public function requireCurrentRender(Scope $scope, array $render): void
    {
        $campaign = $this->campaigns->workspace($scope, $render['campaign']);
        $draft = $this->campaigns->localeVersion($scope, $render['campaign'], $render['territory'], $render['locale']);
        $terms = $this->campaigns->terms($scope, $render['campaign'], $render['locale']);
        if ($campaign['master_revision'] !== $render['evidence']['master_rev'] || $draft['revision'] !== $render['localization_revision'] ||
            $terms['revision'] !== $render['evidence']['term_rev']) {
            throw CampaignData::releaseField('asset_rev', 'Render the current master, terms and regional draft.');
        }
    }

    public function latestApproval(Scope $scope, string $render): ?array
    {
        $row = $this->db->one('SELECT * FROM campaign_approvals WHERE tenant=? AND render=? ORDER BY revision DESC LIMIT 1',
            [$scope->tenant, Values::token($render)]);
        return $row === null ? null : CampaignData::decodeRow($row, 'snapshot');
    }
}
