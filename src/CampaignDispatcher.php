<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignDispatcher
{
    public function __construct(private Db $db, private Catalog $catalog, private CampaignLayout $layout,
        private CampaignPlacements $placements, private CampaignRecalls $recalls, private CampaignConnector $connector) {}

    public function step(Scope $scope): ?array
    {
        $scope->requireRole('operator');
        $job = $this->claim($scope);
        if ($job === null) {
            return null;
        }
        $id = $job['placement'];
        $operation = $job['operation'];
        $placement = $this->placements->record($scope, $id);
        try {
            if (!hash_equals($placement['manifest_digest'], CampaignData::digest($placement['manifest']))) {
                throw new Problem(422, 'manifest-integrity', 'The stored manifest differs from the approved manifest.');
            }
            $receipt = $this->connector->result($scope->tenant, $placement, $operation, false);
            if ($receipt === null) {
                $decision = $this->allowWrite($scope, $job);
                if ($decision === 'stale') {
                    return $this->placements->record($scope, $id);
                }
                if ($decision === 'cancelled') {
                    return $this->finish($scope, $job, 'cancelled', null, 'publication-recalled');
                }
                $png = $operation === 'publish' ? $this->layout->preview($scope, $placement['asset_rev'])['bytes'] : null;
                if ($png !== null && !hash_equals($placement['manifest']['image_sha256'], hash('sha256', $png))) {
                    throw new Problem(422, 'asset-integrity', 'The stored asset differs from the approved manifest.');
                }
                $receipt = $this->connector->result($scope->tenant, $placement, $operation, true, $png);
            }
            return $this->finish($scope, $job, 'done', $receipt, null);
        } catch (Problem $problem) {
            return $this->finish($scope, $job, $problem->status >= 500 ? 'uncertain' : 'failed', null, $problem->kind);
        }
    }

    private function claim(Scope $scope): ?array
    {
        return $this->db->transaction(function () use ($scope): ?array {
            $this->catalog->lockStudio($scope);
            $this->recalls->expireLocked($scope);
            $job = $this->db->one("SELECT d.placement,d.operation,d.attempts,p.delivery_state FROM campaign_dispatch d
                JOIN campaign_placements p ON (p.tenant,p.id)=(d.tenant,d.placement)
                JOIN campaign_dispatch publication ON (publication.tenant,publication.placement)=(d.tenant,d.placement)
                    AND publication.operation='publish'
                WHERE d.tenant=? AND ((d.phase IN ('pending','uncertain') AND d.available_at<=UTC_TIMESTAMP(6))
                    OR (d.phase='leased' AND d.lease_until<=UTC_TIMESTAMP(6)))
                    AND (d.operation='publish' OR publication.phase IN ('done','failed','cancelled'))
                ORDER BY (d.operation='withdraw') DESC,d.available_at,d.placement LIMIT 1", [$scope->tenant]);
            if ($job === null) {
                return null;
            }
            $job['lease_id'] = Values::newId();
            ++$job['attempts'];
            $this->db->exec("UPDATE campaign_dispatch SET phase='leased',lease_id=?,lease_until=UTC_TIMESTAMP(6)+INTERVAL 45 SECOND,
                attempts=attempts+1 WHERE tenant=? AND placement=? AND operation=?",
                [$job['lease_id'], $scope->tenant, $job['placement'], $job['operation']]);
            if ($job['operation'] === 'publish' && $job['delivery_state'] !== 'withdrawing') {
                $this->db->exec("UPDATE campaign_placements SET delivery_state='publishing' WHERE tenant=? AND id=?",
                    [$scope->tenant, $job['placement']]);
            }
            return $job;
        });
    }

    private function allowWrite(Scope $scope, array $job): string
    {
        return $this->db->transaction(function () use ($scope, $job): string {
            $this->catalog->lockStudio($scope);
            if (!$this->ownsLease($scope, $job)) {
                return 'stale';
            }
            if ($job['operation'] === 'withdraw') {
                return 'allowed';
            }
            $placement = $this->db->one('SELECT id,delivery_state,ends_at<=UTC_TIMESTAMP(6) AS ended FROM campaign_placements
                WHERE tenant=? AND id=?', [$scope->tenant, $job['placement']]);
            if ($placement['delivery_state'] === 'withdrawing') {
                return 'cancelled';
            }
            $rightsInvalid = $this->db->one('SELECT 1 FROM campaign_placement_sources s JOIN campaign_grants g
                ON (g.tenant,g.id,g.revision)=(s.tenant,s.grant_id,s.grant_revision)
                WHERE s.tenant=? AND s.placement=? AND (g.revoked_at IS NOT NULL OR g.valid_until<=UTC_TIMESTAMP(6)) LIMIT 1',
                [$scope->tenant, $job['placement']]);
            if ($placement['ended'] || $rightsInvalid !== null) {
                $this->recalls->withdrawLocked($scope, $placement, 'publication-rights-ended');
                return 'cancelled';
            }
            return 'allowed';
        });
    }

    private function ownsLease(Scope $scope, array $job): bool
    {
        return $this->db->one("SELECT 1 FROM campaign_dispatch WHERE tenant=? AND placement=? AND operation=?
            AND phase='leased' AND lease_id=? AND lease_until>UTC_TIMESTAMP(6)",
            [$scope->tenant, $job['placement'], $job['operation'], $job['lease_id']]) !== null;
    }

    private function finish(Scope $scope, array $job, string $phase, ?array $receipt, ?string $failure): array
    {
        return $this->db->transaction(function () use ($scope, $job, $phase, $receipt, $failure): array {
            $this->catalog->lockStudio($scope);
            $id = $job['placement'];
            $operation = $job['operation'];
            if (!$this->ownsLease($scope, $job)) {
                return $this->placements->record($scope, $id);
            }
            $delay = $phase === 'uncertain' ? min(300, 2 ** min(8, $job['attempts'])) : 0;
            $this->db->exec('UPDATE campaign_dispatch SET phase=?,lease_id=NULL,lease_until=NULL,last_failure=?,remote_receipt=?,
                available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE tenant=? AND placement=? AND operation=?',
                [$phase, $failure, $receipt === null ? null : Values::json($receipt), $delay, $scope->tenant, $id, $operation]);
            if ($operation === 'withdraw' && $phase === 'done') {
                $this->db->exec("UPDATE campaign_placements SET delivery_state='withdrawn',active_slot=NULL WHERE tenant=? AND id=?",
                    [$scope->tenant, $id]);
            }
            if ($operation === 'publish' && $phase !== 'cancelled') {
                $state = $phase === 'done' ? 'online' : $phase;
                $this->db->exec("UPDATE campaign_placements SET delivery_state=IF(delivery_state='withdrawing','withdrawing',?),
                    remote_revision=COALESCE(?,remote_revision) WHERE tenant=? AND id=?",
                    [$state, $receipt['remote_revision'] ?? null, $scope->tenant, $id]);
            }
            $this->db->audit($scope, 'campaign-dispatch-result', $id, ['operation' => $operation, 'phase' => $phase, 'failure' => $failure]);
            return $this->placements->record($scope, $id);
        });
    }
}
