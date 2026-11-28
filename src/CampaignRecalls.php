<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignRecalls
{
    public function __construct(private Db $db, private Catalog $catalog, private CampaignRights $rights, private CampaignPlacements $placements) {}

    public function revoke(Scope $scope, array $input): array
    {
        $scope->requireRole();
        $type = Values::choose($input['asset_type'] ?? null, ['image', 'font']);
        $digest = Values::token($input['source_digest'] ?? null, 64);
        $reason = Values::text($input['reason'] ?? null, 600);
        $grantID = isset($input['grant_id']) ? Values::id($input['grant_id']) : null;
        $grantRev = $grantID === null ? null : Values::integer($input['grant_revision'] ?? null, 1, 1_000_000);
        return $this->db->transaction(function () use ($scope, $type, $digest, $reason, $grantID, $grantRev): array {
            $this->catalog->lockStudio($scope);
            $scopeDigest = CampaignData::digest([$type, $digest, $grantID, $grantRev]);
            $prior = $this->db->one('SELECT id FROM campaign_recalls WHERE tenant=? AND scope_digest=?', [$scope->tenant, $scopeDigest]);
            if ($prior !== null) {
                return $this->record($scope, $prior['id']);
            }
            if ($grantID !== null) {
                $grant = $this->rights->record($scope, $grantID, $grantRev);
                if ($grant['asset_type'] !== $type || $grant['source_digest'] !== $digest) {
                    throw CampaignData::releaseField('source_digest', 'The grant applies to a different asset.');
                }
                $this->db->exec('UPDATE campaign_grants SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant=? AND id=? AND revision=? AND revoked_at IS NULL',
                    [$scope->tenant, $grantID, $grantRev]);
            } else {
                $table = $type === 'image' ? 'images' : 'campaign_fonts';
                $this->db->one("SELECT digest FROM $table WHERE tenant=? AND digest=?", [$scope->tenant, $digest]) ?? throw Problem::missing();
                $this->db->exec('UPDATE campaign_grants SET revoked_at=UTC_TIMESTAMP(6)
                    WHERE tenant=? AND asset_type=? AND source_digest=? AND revoked_at IS NULL', [$scope->tenant, $type, $digest]);
            }
            $id = Values::newId();
            $this->db->exec('INSERT INTO campaign_recalls(tenant,id,asset_type,source_digest,reason,owner,scope_digest,grant_id,grant_revision)
                VALUES(?,?,?,?,?,?,?,?,?)', [$scope->tenant, $id, $type, $digest, $reason, $scope->actor, $scopeDigest, $grantID, $grantRev]);
            $params = [$scope->tenant, $type, $digest];
            $grantFilter = $grantID === null ? '' : ' AND s.grant_id=? AND s.grant_revision=?';
            if ($grantID !== null) {
                $params[] = $grantID;
                $params[] = $grantRev;
            }
            $affected = $this->db->all("SELECT p.id,p.delivery_state FROM campaign_placement_sources s JOIN campaign_placements p
                ON (p.tenant,p.id)=(s.tenant,s.placement) WHERE s.tenant=? AND s.asset_type=? AND s.source_digest=?
                AND p.delivery_state NOT IN ('cancelled','withdrawn') $grantFilter ORDER BY p.id", $params);
            foreach ($affected as $item) {
                $this->db->exec('INSERT INTO campaign_recall_items(tenant,recall,placement) VALUES(?,?,?)', [$scope->tenant, $id, $item['id']]);
                $this->withdrawLocked($scope, $item, 'rights-revoked');
            }
            $this->db->audit($scope, 'campaign-source-recalled', $id, ['source_digest' => $digest, 'affected' => count($affected), 'grant' => $grantID]);
            return $this->record($scope, $id);
        });
    }

    public function record(Scope $scope, string $id): array
    {
        $scope->requireRole('operator', 'reviewer', 'designer');
        $id = Values::token($id);
        $record = $this->db->one('SELECT id,asset_type,source_digest,reason,owner,grant_id,grant_revision,created_at FROM campaign_recalls
            WHERE tenant=? AND id=?', [$scope->tenant, $id]) ?? throw Problem::missing();
        $record['recall_id'] = $id;
        $record['placements'] = $this->db->all("SELECT p.id AS placement_id,p.campaign,p.territory,p.locale,p.channel,p.delivery_state,
                COALESCE(w.owner,r.owner) AS owner,w.phase AS withdrawal_phase,w.attempts,w.last_failure,w.remote_receipt
            FROM campaign_recall_items i JOIN campaign_placements p ON (p.tenant,p.id)=(i.tenant,i.placement)
            JOIN campaign_recalls r ON (r.tenant,r.id)=(i.tenant,i.recall)
            LEFT JOIN campaign_dispatch w ON (w.tenant,w.placement)=(p.tenant,p.id) AND w.operation='withdraw'
            WHERE i.tenant=? AND i.recall=? ORDER BY p.territory,p.locale,p.channel,p.id", [$scope->tenant, $id]);
        foreach ($record['placements'] as &$item) {
            if ($item['remote_receipt'] !== null) {
                $item['remote_receipt'] = Values::decode($item['remote_receipt']);
            }
        }
        unset($item);
        $record['open_receipts'] = array_values(array_filter($record['placements'], static fn(array $item): bool =>
            !in_array($item['delivery_state'], ['cancelled', 'withdrawn'], true)));
        $record['state'] = $record['open_receipts'] === [] ? 'withdrawn' :
            (count($record['open_receipts']) === count($record['placements']) ? 'withdrawing' : 'partially-withdrawn');
        return $record;
    }

    public function withdraw(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('operator');
        $reason = Values::text($input['reason'] ?? null, 600);
        return $this->db->transaction(function () use ($scope, $id, $reason): array {
            $this->catalog->lockStudio($scope);
            $this->withdrawLocked($scope, $this->placements->record($scope, $id), $reason);
            return $this->placements->record($scope, $id);
        });
    }

    public function expireLocked(Scope $scope): void
    {
        $rows = $this->db->all("SELECT id,delivery_state FROM campaign_placements WHERE tenant=? AND ends_at<=UTC_TIMESTAMP(6)
            AND delivery_state IN ('pending','publishing','uncertain','failed','online') ORDER BY ends_at,id LIMIT 100", [$scope->tenant]);
        foreach ($rows as $row) {
            $this->withdrawLocked($scope, $row, 'schedule-ended');
        }
    }

    public function withdrawLocked(Scope $scope, array $placement, string $reason): void
    {
        if (in_array($placement['delivery_state'], ['withdrawn', 'cancelled', 'withdrawing'], true)) {
            return;
        }
        $id = $placement['id'];
        $publish = $this->db->one("SELECT phase,attempts FROM campaign_dispatch WHERE tenant=? AND placement=? AND operation='publish'", [$scope->tenant, $id]);
        if ($publish['phase'] === 'pending' && $publish['attempts'] === 0) {
            $this->db->exec("UPDATE campaign_dispatch SET phase='cancelled',last_failure=? WHERE tenant=? AND placement=? AND operation='publish'",
                [preg_match('/^.{0,240}/us', $reason, $match) ? $match[0] : 'withdrawal-requested', $scope->tenant, $id]);
            $this->db->exec("UPDATE campaign_placements SET delivery_state='cancelled',active_slot=NULL WHERE tenant=? AND id=?", [$scope->tenant, $id]);
        } else {
            $this->db->exec("UPDATE campaign_placements SET delivery_state='withdrawing' WHERE tenant=? AND id=?", [$scope->tenant, $id]);
            $this->db->exec("INSERT INTO campaign_dispatch(tenant,placement,operation,owner) VALUES(?,?,'withdraw',?)
                ON DUPLICATE KEY UPDATE placement=campaign_dispatch.placement", [$scope->tenant, $id, $scope->actor]);
        }
        $this->db->audit($scope, 'campaign-placement-withdrawal-requested', $id, ['reason' => $reason]);
    }
}
