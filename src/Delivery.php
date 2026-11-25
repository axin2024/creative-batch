<?php

declare(strict_types=1);

namespace CreativeBatch;

use ZipArchive;

final class Delivery
{
    public function __construct(private Db $db, private Catalog $catalog, private Production $production) {}

    public function candidate(Scope $scope, string $id): array
    {
        $scope->requireRole('reviewer', 'designer', 'operator');
        $row = $this->db->one('SELECT * FROM candidates WHERE tenant=? AND id=?', [$scope->tenant, Values::token($id)])
            ?? throw Problem::missing();
        unset($row['tenant']);
        return Production::decodeCandidate($row);
    }

    public function compare(Scope $scope, string $id, bool $source): array
    {
        $candidate = $this->candidate($scope, $id);
        $batch = $this->production->batch($scope, $candidate['batch']);
        $digest = $source ? $batch['frozen']['units'][$candidate['unit_key']]['image_digest'] : $candidate['image_digest'];
        return $this->catalog->image($scope->tenant, $digest);
    }

    public function review(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('reviewer');
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $verdict = Values::choose($input['verdict'] ?? null, ['approved', 'rejected']);
        $reason = Values::text($input['reason'] ?? null, 600);
        $checks = [];
        foreach (['color', 'packaging', 'form', 'background', 'marks'] as $key) {
            $checks[$key] = Values::boolean($input['checks'][$key] ?? null);
        }
        return $this->db->transaction(function () use ($scope, $id, $expected, $verdict, $reason, $checks): array {
            $this->catalog->lockStudio($scope);
            $candidate = $this->candidate($scope, $id);
            if ($candidate['review_revision'] !== $expected) {
                throw Problem::conflict('Somebody already updated this review.');
            }
            if ($verdict === 'approved') {
                if (!$candidate['eligible'] || in_array(false, $checks, true)) {
                    throw Problem::conflict('All automatic and manual checks must pass before approval.');
                }
                $batch = $this->production->batch($scope, $candidate['batch']);
                $this->production->requireRights($scope, $batch, $batch['frozen']['units'][$candidate['unit_key']]);
            }
            $revision = $expected + 1;
            $this->db->exec('INSERT INTO human_reviews(tenant,candidate,revision,actor,verdict,checks,reason) VALUES(?,?,?,?,?,?,?)',
                [$scope->tenant, $id, $revision, $scope->actor, $verdict, Values::json($checks), $reason]);
            $this->db->exec('UPDATE candidates SET verdict=?,review_revision=? WHERE tenant=? AND id=?',
                [$verdict, $revision, $scope->tenant, $id]);
            $this->db->audit($scope, 'candidate-reviewed', $id, ['verdict' => $verdict, 'revision' => $revision]);
            return $this->candidate($scope, $id);
        });
    }

    public function select(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('operator');
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $reason = Values::text($input['reason'] ?? null, 600);
        return $this->db->transaction(function () use ($scope, $id, $expected, $reason): array {
            $this->catalog->lockStudio($scope);
            $candidate = $this->candidate($scope, $id);
            $batch = $this->production->batch($scope, $candidate['batch']);
            $unit = $candidate['unit_key'];
            if (!$candidate['eligible'] || $candidate['verdict'] !== 'approved') {
                throw Problem::conflict('Select an eligible, approved candidate.');
            }
            $this->production->requireRights($scope, $batch, $batch['frozen']['units'][$unit]);
            $old = $this->latest($scope, $batch['id'], $unit);
            if (($old['revision'] ?? 0) !== $expected) {
                throw Problem::conflict('The active selection changed.');
            }
            $this->db->exec('INSERT INTO selections(tenant,batch,unit_key,revision,candidate,reason,actor) VALUES(?,?,?,?,?,?,?)',
                [$scope->tenant, $batch['id'], $unit, $expected + 1, $id, $reason, $scope->actor]);
            $this->db->audit($scope, 'candidate-selected', $id, ['unit' => $unit, 'revision' => $expected + 1]);
            return $this->latest($scope, $batch['id'], $unit);
        });
    }

    public function preview(Scope $scope, string $id, bool $publish): array
    {
        $scope->requireRole('operator');
        return $this->db->transaction(function () use ($scope, $id, $publish): array {
            $this->catalog->lockStudio($scope);
            $batch = $this->production->batch($scope, $id);
            $entries = [];
            $blocked = [];
            foreach ($batch['frozen']['units'] as $key => $unit) {
                $selected = $this->latest($scope, $id, $key);
                if ($selected === null) {
                    $blocked[$key] = 'missing-selection';
                    continue;
                }
                $candidate = $this->candidate($scope, $selected['candidate']);
                try {
                    $this->production->requireRights($scope, $batch, $unit);
                    if (!$candidate['eligible'] || $candidate['verdict'] !== 'approved') {
                        $blocked[$key] = 'review-unavailable';
                        continue;
                    }
                    $image = $this->catalog->image($scope->tenant, $candidate['image_digest']);
                    if ($image['width'] !== $unit['width'] || $image['height'] !== $unit['height'] ||
                        !hash_equals($image['digest'], hash('sha256', $image['bytes']))) {
                        $blocked[$key] = 'invalid-asset';
                        continue;
                    }
                } catch (Problem $error) {
                    $blocked[$key] = $error->kind;
                    continue;
                }
                $cost = $this->db->one('SELECT COUNT(*) AS outputs,COALESCE(SUM(m.cost_micros),0) AS cost
                    FROM generation_meter m JOIN generation_tasks t ON t.tenant=m.tenant AND t.id=m.task
                    WHERE t.tenant=? AND t.batch=? AND t.unit_key=?', [$scope->tenant, $id, $key]);
                $entries[$key] = $unit + ['filename' => $unit['variant'] . '/' . $unit['channel'] . '.png',
                    'output_sha256' => $image['digest'], 'candidate' => $candidate['id'], 'review_revision' => $candidate['review_revision'],
                    'selection_revision' => $selected['revision'], 'selection_reason' => $selected['reason'],
                    'candidates_charged' => $cost['outputs'], 'allocated_cost_micros' => (int) $cost['cost']];
            }
            ksort($entries);
            $manifest = ['version' => 1, 'campaign' => $batch['campaign'], 'input_sha256' => $batch['input_hash'],
                'provider' => $batch['provider'], 'campaign_start' => $batch['rights_from'], 'campaign_end' => $batch['rights_until'],
                'entries' => $entries];
            if (!$publish) {
                return ['manifest' => $manifest, 'blocked' => $blocked];
            }
            if ($blocked !== [] || !in_array($batch['phase'], ['review', 'published'], true)) {
                throw Problem::conflict('Complete production and resolve all blocked units before export.');
            }
            $json = Values::json(self::canonical($manifest));
            $digest = hash('sha256', $json);
            $existing = $this->db->one('SELECT id FROM bundles WHERE tenant=? AND batch=? AND digest=?', [$scope->tenant, $id, $digest]);
            if ($existing !== null) {
                return $this->bundle($scope, $existing['id']);
            }
            $revision = (int) $this->db->one('SELECT MAX(revision) AS last_revision FROM bundles WHERE tenant=? AND batch=?',
                [$scope->tenant, $id])['last_revision'] + 1;
            $bundle = Values::newId();
            $this->db->exec('INSERT INTO bundles(tenant,id,batch,revision,digest,manifest,actor) VALUES(?,?,?,?,?,?,?)',
                [$scope->tenant, $bundle, $id, $revision, $digest, $json, $scope->actor]);
            $rights = array_column($entries, 'right_revision', 'right_id');
            foreach ($rights as $right => $rev) {
                $this->db->exec('INSERT INTO bundle_rights(tenant,right_id,bundle,right_revision) VALUES(?,?,?,?)',
                    [$scope->tenant, $right, $bundle, $rev]);
            }
            $this->db->exec("UPDATE batches SET phase='published' WHERE tenant=? AND id=?", [$scope->tenant, $id]);
            $this->db->audit($scope, 'bundle-published', $bundle, ['digest' => $digest]);
            return $this->bundle($scope, $bundle);
        });
    }

    public function bundles(Scope $scope, string $id): array
    {
        $scope->requireRole('operator', 'channel');
        $this->production->batch($scope, $id);
        return $this->db->all('SELECT id,revision,digest,created_at FROM bundles WHERE tenant=? AND batch=? ORDER BY revision DESC',
            [$scope->tenant, $id]);
    }

    public function zip(Scope $scope, string $id): array
    {
        $scope->requireRole('channel', 'operator');
        $bundle = $this->bundle($scope, $id);
        $batch = $this->production->batch($scope, $bundle['batch']);
        $this->releaseAllowed($scope, $bundle, $batch);
        $path = tempnam(sys_get_temp_dir(), 'creative-bundle-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary archive.');
        }
        $archive = new ZipArchive();
        $open = false;
        try {
            if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot open the ZIP archive.');
            }
            $open = true;
            $total = 0;
            foreach ($bundle['manifest']['entries'] as $entry) {
                $image = $this->catalog->image($scope->tenant, $entry['output_sha256']);
                $total += strlen($image['bytes']);
                if ($total > 96 * 1024 * 1024 || !hash_equals($image['digest'], hash('sha256', $image['bytes'])) ||
                    !$archive->addFromString($entry['filename'], $image['bytes'])) {
                    throw Problem::conflict('One or more assets are invalid or exceed the ZIP size limit.');
                }
                $archive->setCompressionName($entry['filename'], ZipArchive::CM_STORE);
                $archive->setMtimeName($entry['filename'], 315532800);
            }
            $manifest = Values::json(self::canonical($bundle['manifest']));
            if (!hash_equals($bundle['digest'], hash('sha256', $manifest)) ||
                !$archive->addFromString('manifest.json', $manifest)) {
                throw Problem::conflict('The bundle does not match its frozen manifest.');
            }
            $archive->setMtimeName('manifest.json', 315532800);
            $closed = $archive->close();
            $open = false;
            if (!$closed) {
                throw new \RuntimeException('The ZIP archive could not be finalized.');
            }
            $receiptId = $this->db->transaction(function () use ($scope, $bundle, $batch): int {
                $this->catalog->lockStudio($scope);
                $this->releaseAllowed($scope, $bundle, $batch);
                $this->db->exec('INSERT INTO bundle_receipts(tenant,bundle,actor) VALUES(?,?,?)', [$scope->tenant, $bundle['id'], $scope->actor]);
                return (int) $this->db->one('SELECT LAST_INSERT_ID() AS receipt_id')['receipt_id'];
            });
            return ['file' => $path, 'receipt' => $receiptId, 'digest' => $bundle['digest'], 'filename' => $batch['campaign'] . '-r' . $bundle['revision'] . '.zip'];
        } catch (\Throwable $error) {
            if ($open) {
                $archive->close();
            }
            unlink($path);
            throw $error;
        }
    }

    private function releaseAllowed(Scope $scope, array $bundle, array $batch): void
    {
        foreach ($bundle['manifest']['entries'] as $entry) {
            $this->production->requireRights($scope, $batch, $entry);
            $review = $this->db->one('SELECT verdict,review_revision FROM candidates WHERE tenant=? AND id=?',
                [$scope->tenant, $entry['candidate']]);
            if ($review === null || $review['verdict'] !== 'approved' || $review['review_revision'] !== $entry['review_revision']) {
                throw Problem::conflict('A bundled candidate has an obsolete approval.');
            }
        }
    }

    private function bundle(Scope $scope, string $id): array
    {
        $row = $this->db->one('SELECT * FROM bundles WHERE tenant=? AND id=?', [$scope->tenant, Values::token($id)]) ?? throw Problem::missing();
        unset($row['tenant']);
        $row['manifest'] = Values::decode($row['manifest']);
        return $row;
    }

    private function latest(Scope $scope, string $batch, string $unit): ?array
    {
        return $this->db->one('SELECT revision,candidate,reason,actor FROM selections WHERE tenant=? AND batch=? AND unit_key=?
            ORDER BY revision DESC LIMIT 1', [$scope->tenant, $batch, $unit]);
    }

    private static function canonical(array $fields): array
    {
        if (!array_is_list($fields)) {
            ksort($fields, SORT_STRING);
        }
        foreach ($fields as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        return $fields;
    }
}
