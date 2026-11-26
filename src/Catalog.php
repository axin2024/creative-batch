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
            if ($type === 'variants') {
                $this->requireVariantIdentity($scope, $id, $previous, $fields);
            }
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
        return $this->storeGrant($scope, $id, $input, false)['right'];
    }

    public function importGrants(Scope $scope, array $items): array
    {
        $scope->requireRole();
        $results = [];
        foreach (Values::listOf($items, 1, 100) as $position => $input) {
            try {
                if (!is_array($input) || array_is_list($input)) {
                    throw Problem::invalid('Each grant must be a JSON object.');
                }
                $id = Values::id($input['id'] ?? null);
                $result = $this->storeGrant($scope, $id, $input, true);
                $results[] = ['row' => $position + 1, 'id' => $id, 'status' => $result['created'] ? 'created' : 'existing',
                    'revision' => $result['right']['revision'], 'revoked_at' => $result['right']['revoked_at']];
            } catch (Problem $error) {
                $results[] = ['row' => $position + 1, 'status' => 'rejected', 'error' => $error->kind, 'message' => $error->getMessage()];
            }
        }
        return $results;
    }

    private function storeGrant(Scope $scope, string $id, array $input, bool $allowReplay): array
    {
        $scope->requireRole();
        $id = Values::id($id);
        $digest = Values::token($input['image_digest'] ?? null, 64);
        $grantor = Values::text($input['grantor'] ?? null, 160);
        $commercial = Values::boolean($input['commercial'] ?? null);
        $channels = array_values(array_unique(array_map(Values::id(...), Values::listOf($input['channels'] ?? null, 1, 20))));
        $from = Values::time($input['valid_from'] ?? null);
        $until = Values::time($input['valid_until'] ?? null);
        if ($until <= $from) {
            throw Problem::invalid('The rights end must follow its start.');
        }
        return $this->db->transaction(function () use ($scope, $id, $digest, $grantor, $commercial, $channels, $from, $until, $allowReplay): array {
            $this->lockStudio($scope);
            if ($this->db->one('SELECT id FROM asset_rights WHERE tenant=? AND id=?', [$scope->tenant, $id])) {
                if ($allowReplay) {
                    $right = $this->rights($scope->tenant, $id);
                    $sameChannels = count($channels) === count($right['scope']) && array_diff($channels, $right['scope']) === [];
                    if ($right['image_digest'] === $digest && $right['grantor'] === $grantor && $sameChannels &&
                        (bool) $right['commercial'] === $commercial && $right['valid_from'] === $from . '.000000' &&
                        $right['valid_until'] === $until . '.000000') {
                        return ['created' => false, 'right' => $right];
                    }
                }
                throw Problem::conflict('Create a new rights identity for a different grant.');
            }
            $this->image($scope->tenant, $digest);
            $this->db->exec('INSERT INTO asset_rights(tenant,id,image_digest,revision,grantor,scope,commercial,valid_from,valid_until)
                VALUES(?,?,?,1,?,?,?,?,?)', [$scope->tenant, $id, $digest, $grantor, Values::json($channels),
                    (int) $commercial, $from, $until]);
            $this->db->audit($scope, 'rights-granted', $id, ['image' => $digest]);
            return ['created' => true, 'right' => $this->rights($scope->tenant, $id)];
        });
    }

    public function grants(Scope $scope, ?string $after): array
    {
        $scope->requireRole();
        $where = '';
        $args = [$scope->tenant];
        if ($after !== null) {
            $boundary = $this->rights($scope->tenant, Values::id($after));
            $where = ' AND (valid_until>? OR (valid_until=? AND id>?))';
            array_push($args, $boundary['valid_until'], $boundary['valid_until'], $boundary['id']);
        }
        $rows = $this->db->all("SELECT id,image_digest,revision,grantor,scope,commercial,valid_from,valid_until,revoked_at
            FROM asset_rights WHERE tenant=?$where ORDER BY valid_until,id LIMIT 26", $args);
        $next = null;
        if (count($rows) === 26) {
            array_pop($rows);
            $next = $rows[array_key_last($rows)]['id'];
        }
        foreach ($rows as &$row) {
            $row['scope'] = Values::decode($row['scope']);
        }
        return ['items' => $rows, 'next_cursor' => $next];
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

    private function requireVariantIdentity(Scope $scope, string $id, int $previous, array $fields): void
    {
        $identity = array_intersect_key($fields, ['brand' => true, 'sku' => true, 'color' => true]);
        if ($previous > 0) {
            $row = $this->revision($scope, 'variants', $id, $previous);
            if ($identity !== array_intersect_key($row, $identity)) {
                throw Problem::conflict('A variant revision must retain its brand, SKU and color identity.');
            }
        }
        if ($this->db->one('SELECT id FROM variants WHERE tenant=? AND brand=? AND sku=? AND color=? AND id<>? LIMIT 1',
            [$scope->tenant, $identity['brand'], $identity['sku'], $identity['color'], $id]) !== null) {
            throw Problem::conflict('This product color already has a variant identity.');
        }
    }

    private function template(Scope $scope, array $input): array
    {
        $brand = Values::id($input['brand'] ?? null);
        $brandRevision = Values::integer($input['brand_revision'] ?? null, 1, 1_000_000);
        $channel = Values::id($input['channel'] ?? null);
        $channelRevision = Values::integer($input['channel_revision'] ?? null, 1, 1_000_000);
        $this->revision($scope, 'brands', $brand, $brandRevision);
        $channelSpec = $this->revision($scope, 'channels', $channel, $channelRevision);
        $style = ['background' => Values::hex($input['background'] ?? null), 'accent' => Values::hex($input['accent'] ?? null),
            'headline' => Values::text($input['headline'] ?? null, 56)];
        if (preg_match('/\A[\x20-\x7e]+\z/D', $style['headline']) !== 1) {
            throw Problem::invalid('The built-in bitmap typeface supports printable ASCII headlines.');
        }
        if (array_key_exists('composition', $input)) {
            $style['composition'] = $this->composition($channelSpec, $style['headline'], $input['composition']);
        }
        if (array_key_exists('generation', $input)) {
            $generation = $input['generation'];
            if (!is_array($generation) || array_is_list($generation)) {
                throw Problem::invalid('The template backend binding must be an object.');
            }
            $backend = Values::choose($generation['backend'] ?? null, ['replay', 'live', 'image']);
            $model = $backend === 'replay' ? 'local-fixture' : Values::text($generation['model'] ?? null, 128);
            $style['generation'] = ['backend' => $backend, 'model' => $model,
                'seed_mode' => Values::choose($generation['seed_mode'] ?? 'derived', ['derived'])];
        }
        return ['brand' => $brand, 'brand_revision' => $brandRevision, 'channel' => $channel,
            'channel_revision' => $channelRevision, 'style' => Values::json($style)];
    }

    private function composition(array $channel, string $headline, mixed $input): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw Problem::invalid('The template composition requires two named safe zones.');
        }
        $spec = ['mask' => Values::choose($input['mask'] ?? null, ['source-alpha']),
            'font' => Values::choose($input['font'] ?? null, ['gd-bitmap-2', 'gd-bitmap-5'])];
        $boxes = [];
        $margin = intdiv(min($channel['width'], $channel['height']) * $channel['margin'], 100);
        foreach (['product_box', 'headline_box'] as $key) {
            $box = $input[$key] ?? null;
            if (!is_array($box) || !array_is_list($box) || count($box) !== 4) {
                throw Problem::invalid('Each safe zone must specify left, top, width and height as percentages.');
            }
            [$left, $top, $width, $height] = array_map(static fn ($value) => Values::integer($value, 0, 100), $box);
            [$x, $y, $w, $h] = Raster::templateBox($channel, [$left, $top, $width, $height]);
            if ($w < 1 || $h < 1 || $x < $margin || $y < $margin || $x + $w > $channel['width'] - $margin ||
                $y + $h > $channel['height'] - $margin) {
                throw Problem::invalid('The template zones must remain inside the channel safe margin.');
            }
            $spec[$key] = [$left, $top, $width, $height];
            $boxes[$key] = [$x, $y, $w, $h];
        }
        [$px, $py, $pw, $ph] = $boxes['product_box'];
        [$tx, $ty, $tw, $th] = $boxes['headline_box'];
        $font = Raster::templateFont($spec['font']);
        if ($px < $tx + $tw && $tx < $px + $pw && $py < $ty + $th && $ty < $py + $ph) {
            throw Problem::invalid('The headline and protected product zones cannot overlap.');
        }
        if (imagefontwidth($font) * strlen($headline) > $tw || imagefontheight($font) > $th) {
            throw Problem::invalid('The full headline must fit inside its safe zone at the selected font size.');
        }
        return $spec;
    }
}
