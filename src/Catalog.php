<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Catalog
{
    public function __construct(private Db $db)
    {
    }

    public function upload(Scope $scope, string $bytes): array
    {
        $scope->requireRole('designer');
        $image = Raster::normalize($bytes);
        $this->saveImage($scope->tenant, $image);
        return array_diff_key($image, ['bytes' => true]);
    }

    public function saveImage(string $tenant, array $image): void
    {
        $this->db->exec('INSERT INTO images(tenant,digest,width,height,bytes) VALUES(?,?,?,?,?)
            ON DUPLICATE KEY UPDATE digest=images.digest',
            [$tenant, $image['digest'], $image['width'], $image['height'], $image['bytes']]);
    }

    public function image(string $tenant, string $digest): array
    {
        return $this->db->one('SELECT digest,width,height,bytes FROM images WHERE tenant=? AND digest=?',
            [$tenant, Values::token($digest, 64)]) ?? throw Problem::missing();
    }

    public function revise(Scope $scope, string $type, string $id, array $input): array
    {
        $scope->requireRole();
        $type = Values::choose($type, ['brands', 'variants', 'channels', 'templates']);
        $id = Values::id($id);
        $previous = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        return $this->db->transaction(function () use ($scope, $type, $id, $input, $previous): array {
            $this->lockStudio($scope);
            $row = $this->db->one("SELECT MAX(revision) AS current_revision FROM $type WHERE tenant=? AND id=?", [$scope->tenant, $id]);
            if ((int) $row['current_revision'] !== $previous) {
                throw Problem::conflict('The catalog item revision has changed.');
            }
            $fields = match ($type) {
                'brands' => $this->brand($scope, $input),
                'variants' => $this->variant($scope, $input),
                'channels' => [
                    'width' => Values::integer($input['width'] ?? null, 256, 2048),
                    'height' => Values::integer($input['height'] ?? null, 256, 2048),
                    'margin' => Values::integer($input['margin'] ?? null, 4, 22),
                ],
                'templates' => $this->template($scope, $input),
            };
            $values = ['tenant' => $scope->tenant, 'id' => $id, 'revision' => $previous + 1] + $fields;
            $marks = implode(',', array_fill(0, count($values), '?'));
            $columns = implode(',', array_keys($values));
            try {
                $this->db->exec("INSERT INTO $type($columns) VALUES($marks)", array_values($values));
            } catch (\Throwable $error) {
                if (Db::isDuplicate($error)) {
                    throw Problem::conflict('This catalog revision conflicts with an existing item.');
                }
                throw $error;
            }
            $this->db->audit($scope, 'catalog-revised', $id, ['type' => $type, 'revision' => $previous + 1]);
            return $this->revision($scope, $type, $id, $previous + 1);
        });
    }

    public function grant(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole();
        $id = Values::id($id);
        $digest = Values::token($input['image_digest'] ?? null, 64);
        $grantor = Values::text($input['grantor'] ?? null, 160);
        $commercial = Values::boolean($input['commercial'] ?? null);
        $channels = array_map(Values::id(...), Values::listOf($input['channels'] ?? null, 1, 20));
        $from = Values::time($input['valid_from'] ?? null);
        $until = Values::time($input['valid_until'] ?? null);
        if ($until <= $from) {
            throw Problem::invalid('The rights end must follow its start.');
        }
        return $this->db->transaction(function () use ($scope, $id, $digest, $grantor, $commercial, $channels, $from, $until): array {
            $this->lockStudio($scope);
            $this->image($scope->tenant, $digest);
            if ($this->db->one('SELECT id FROM asset_rights WHERE tenant=? AND id=?', [$scope->tenant, $id])) {
                throw Problem::conflict('Create a new rights identity for a different grant.');
            }
            $this->db->exec('INSERT INTO asset_rights(tenant,id,image_digest,revision,grantor,scope,commercial,valid_from,valid_until)
                VALUES(?,?,?,1,?,?,?,?,?)', [$scope->tenant, $id, $digest, $grantor, Values::json(array_values(array_unique($channels))),
                    (int) $commercial, $from, $until]);
            $this->db->audit($scope, 'rights-granted', $id, ['image' => $digest]);
            return $this->rights($scope->tenant, $id);
        });
    }

    public function revoke(Scope $scope, string $id, string $reason): array
    {
        $scope->requireRole();
        $id = Values::id($id);
        $reason = Values::text($reason, 600);
        return $this->db->transaction(function () use ($scope, $id, $reason): array {
            $this->lockStudio($scope);
            $right = $this->rights($scope->tenant, $id);
            if ($right['revoked_at'] === null) {
                $this->db->exec('UPDATE asset_rights SET revoked_at=UTC_TIMESTAMP(6),revision=revision+1 WHERE tenant=? AND id=?',
                    [$scope->tenant, $id]);
                $this->db->audit($scope, 'rights-revoked', $id, ['reason' => $reason]);
            }
            $affected = $this->db->all('SELECT b.bundle,r.actor,r.issued_at FROM bundle_rights b
                LEFT JOIN bundle_receipts r ON r.tenant=b.tenant AND r.bundle=b.bundle
                WHERE b.tenant=? AND b.right_id=? ORDER BY b.bundle,r.id', [$scope->tenant, $id]);
            return ['rights' => $this->rights($scope->tenant, $id), 'affected' => $affected];
        });
    }

    public function rights(string $tenant, string $id): array
    {
        $row = $this->db->one('SELECT id,image_digest,revision,grantor,scope,commercial,valid_from,valid_until,revoked_at,
                UTC_TIMESTAMP(6) AS checked_at FROM asset_rights WHERE tenant=? AND id=?', [$tenant, $id]) ?? throw Problem::missing();
        $row['scope'] = Values::decode($row['scope']);
        return $row;
    }

    public function usable(array $grant, string $channel, string $from, string $until): bool
    {
        return (bool) $grant['commercial'] && $grant['revoked_at'] === null && $grant['checked_at'] >= $grant['valid_from'] &&
            $grant['checked_at'] < $grant['valid_until'] &&
            $grant['valid_from'] <= $from && $grant['valid_until'] >= $until && in_array($channel, $grant['scope'], true);
    }

    public function revision(Scope $scope, string $type, string $id, int $revision): array
    {
        $type = Values::choose($type, ['brands', 'variants', 'channels', 'templates']);
        $row = $this->db->one("SELECT * FROM $type WHERE tenant=? AND id=? AND revision=?", [$scope->tenant, $id, $revision]);
        if ($row === null) {
            throw Problem::missing();
        }
        unset($row['tenant']);
        foreach (['palette', 'style'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = Values::decode($row[$field]);
            }
        }
        return $row;
    }

    public function all(Scope $scope): array
    {
        $scope->requireRole('designer', 'reviewer', 'operator');
        $result = [];
        foreach (['brands', 'variants', 'channels', 'templates'] as $type) {
            $keys = $this->db->all("SELECT id, MAX(revision) AS revision FROM $type WHERE tenant=? GROUP BY id ORDER BY id LIMIT 200", [$scope->tenant]);
            $result[$type] = array_map(fn (array $row): array => $this->revision($scope, $type, $row['id'], $row['revision']), $keys);
        }
        return $result;
    }

    public function lockStudio(Scope $scope): void
    {
        $this->db->one('SELECT tenant FROM studios WHERE tenant=? FOR UPDATE', [$scope->tenant]) ?? throw Problem::missing();
    }

    private function brand(Scope $scope, array $input): array
    {
        return ['name' => Values::text($input['name'] ?? null, 160), 'packaging' => Values::text($input['packaging'] ?? null, 120),
            'palette' => Values::json(array_values(array_unique(array_map(Values::hex(...), Values::listOf($input['palette'] ?? null, 1, 8))))),
            'approved_by' => $scope->actor];
    }

    private function variant(Scope $scope, array $input): array
    {
        $brand = Values::id($input['brand'] ?? null);
        $brandRevision = Values::integer($input['brand_revision'] ?? null, 1, 1_000_000);
        $approved = $this->revision($scope, 'brands', $brand, $brandRevision);
        $digest = Values::token($input['image_digest'] ?? null, 64);
        $right = $this->rights($scope->tenant, Values::id($input['right_id'] ?? null));
        $color = Values::hex($input['rgb'] ?? null);
        if ($right['image_digest'] !== $digest || !in_array($color, $approved['palette'], true)) {
            throw Problem::invalid('The source grants and color must match the approved product.');
        }
        return ['brand' => $brand, 'brand_revision' => $brandRevision, 'sku' => Values::id($input['sku'] ?? null),
            'color' => Values::id($input['color'] ?? null), 'rgb' => $color, 'image_digest' => $digest, 'right_id' => $right['id']];
    }

    private function template(Scope $scope, array $input): array
    {
        $brand = Values::id($input['brand'] ?? null);
        $brandRevision = Values::integer($input['brand_revision'] ?? null, 1, 1_000_000);
        $channel = Values::id($input['channel'] ?? null);
        $channelRevision = Values::integer($input['channel_revision'] ?? null, 1, 1_000_000);
        $this->revision($scope, 'brands', $brand, $brandRevision);
        $this->revision($scope, 'channels', $channel, $channelRevision);
        $style = ['background' => Values::hex($input['background'] ?? null), 'accent' => Values::hex($input['accent'] ?? null),
            'headline' => Values::text($input['headline'] ?? null, 56)];
        if (preg_match('/\A[\x20-\x7e]+\z/D', $style['headline']) !== 1) {
            throw Problem::invalid('The built-in bitmap typeface supports printable ASCII headlines.');
        }
        return ['brand' => $brand, 'brand_revision' => $brandRevision, 'channel' => $channel,
            'channel_revision' => $channelRevision, 'style' => Values::json($style)];
    }
}
