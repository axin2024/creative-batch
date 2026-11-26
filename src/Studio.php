<?php

declare(strict_types=1);

namespace CreativeBatch;

final readonly class Studio
{
    public Access $access;
    public Catalog $catalog;
    public Production $production;
    public Delivery $delivery;

    public function __construct(public Db $db)
    {
        $this->access = new Access($db);
        $this->catalog = new Catalog($db);
        $this->production = new Production($db, $this->catalog, new ArtDirection(), new ImageBackend());
        $this->delivery = new Delivery($db, $this->catalog, $this->production);
    }

    public function audit(Scope $scope, string $id): array
    {
        $scope->requireRole('reviewer', 'operator');
        $id = Values::text($id, 80);
        return $this->db->all('SELECT id,actor,action,detail,created_at FROM audit_log
            WHERE tenant=? AND object_id=? ORDER BY id DESC LIMIT 200', [$scope->tenant, $id]);
    }

    public function operations(Scope $scope): array
    {
        $scope->requireRole('operator');
        $since = $this->db->one('SELECT UTC_TIMESTAMP(6) - INTERVAL 30 DAY AS since')['since'];
        $backends = $this->db->all("SELECT b.provider, COUNT(*) AS tasks, SUM(t.phase='completed') AS completed,
                SUM(t.phase='failed') AS failed, SUM(t.source_task IS NOT NULL) AS reworked, COUNT(c.id) AS candidates,
                SUM(COALESCE(c.verdict='approved',0)) AS approved, COUNT(s.candidate) AS selected,
                COALESCE(SUM(m.cost_micros),0) AS estimated_micros
            FROM batches b JOIN generation_tasks t ON t.tenant=b.tenant AND t.batch=b.id
            LEFT JOIN candidates c ON c.tenant=t.tenant AND c.id=t.id
            LEFT JOIN generation_meter m ON m.tenant=t.tenant AND m.task=t.id
            LEFT JOIN selections s ON s.tenant=t.tenant AND s.batch=t.batch AND s.unit_key=t.unit_key AND s.candidate=t.id
                AND s.revision=(SELECT MAX(latest.revision) FROM selections latest
                    WHERE latest.tenant=t.tenant AND latest.batch=t.batch AND latest.unit_key=t.unit_key)
            WHERE b.tenant=? AND b.created_at>=? GROUP BY b.provider ORDER BY b.provider", [$scope->tenant, $since]);
        $recent = $this->db->all("SELECT b.id AS batch,b.campaign,b.provider,t.id AS task,t.unit_key,t.updated_at,
                a.detail->>'$.reason' AS reason
            FROM batches b JOIN generation_tasks t ON t.tenant=b.tenant AND t.batch=b.id AND t.source_task IS NOT NULL
            JOIN audit_log a ON a.tenant=t.tenant AND a.object_id=t.id AND a.action='rework-queued'
            WHERE b.tenant=? AND b.created_at>=? ORDER BY t.updated_at DESC,t.id DESC LIMIT 100", [$scope->tenant, $since]);
        foreach ($backends as &$backend) {
            foreach (['tasks', 'completed', 'failed', 'reworked', 'candidates', 'approved', 'selected', 'estimated_micros'] as $column) {
                $backend[$column] = (int) $backend[$column];
            }
        }
        return ['since' => $since, 'backends' => $backends, 'recent_rework' => $recent];
    }
}
