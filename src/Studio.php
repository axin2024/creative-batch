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
        $this->production = new Production($db, $this->catalog, new ArtDirection());
        $this->delivery = new Delivery($db, $this->catalog, $this->production);
    }

    public function audit(Scope $scope, string $id): array
    {
        $scope->requireRole('reviewer', 'operator');
        $id = Values::text($id, 80);
        return $this->db->all('SELECT id,actor,action,detail,created_at FROM audit_log
            WHERE tenant=? AND object_id=? ORDER BY id DESC LIMIT 200', [$scope->tenant, $id]);
    }
}
