<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Production
{
    public function __construct(private Db $db, private Catalog $catalog, private ArtDirection $art) {}

    public function plan(Scope $scope, array $input): array
    {
        $scope->requireRole('operator');
        $campaign = Values::id($input['campaign'] ?? null);
        $provider = Values::choose($input['provider'] ?? null, ['replay', 'live']);
        $count = Values::integer($input['candidates_per_unit'] ?? null, 1, 4);
        $from = Values::time($input['starts_at'] ?? null) . '.000000';
        $until = Values::time($input['ends_at'] ?? null) . '.000000';
        $charge = $this->price($provider);
        $budget = Values::integer($input['ceiling_micros'] ?? null, 0, 1_000_000_000);
        $variants = Values::listOf($input['variants'] ?? null, 1, 12);
        $templates = Values::listOf($input['templates'] ?? null, 1, 8);
        $total = count($variants) * count($templates) * $count;
        if ($until <= $from || $until <= gmdate('Y-m-d H:i:s') . '.000000' || $total > 96 || $budget < $total * $charge) {
            throw Problem::invalid('Review the campaign time window, candidate count and generation budget.');
        }
        return $this->db->transaction(function () use ($scope, $campaign, $provider, $count, $from, $until, $charge,
            $budget, $variants, $templates): array {
            $this->catalog->lockStudio($scope);
            $units = [];
            foreach ($variants as $variantRef) {
                $variant = $this->reference($scope, 'variants', $variantRef);
                $brand = $this->catalog->revision($scope, 'brands', $variant['brand'], $variant['brand_revision']);
                $right = $this->catalog->rights($scope->tenant, $variant['right_id']);
                foreach ($templates as $templateRef) {
                    $template = $this->reference($scope, 'templates', $templateRef);
                    $channel = $this->catalog->revision($scope, 'channels', $template['channel'], $template['channel_revision']);
                    $unitKey = $variant['id'] . ':' . $channel['id'];
                    if (isset($units[$unitKey]) || $template['brand'] !== $brand['id'] ||
                        $template['brand_revision'] !== $brand['revision'] ||
                        !$this->catalog->usable($right, $channel['id'], $from, $until)) {
                        throw Problem::invalid('A variant/channel is duplicated, or the brand revision or commercial grant does not cover it.');
                    }
                    $units[$unitKey] = ['variant' => $variant['id'], 'variant_revision' => $variant['revision'], 'sku' => $variant['sku'],
                        'color' => $variant['color'], 'rgb' => $variant['rgb'], 'brand' => $brand['id'], 'brand_revision' => $brand['revision'],
                        'packaging' => $brand['packaging'], 'image_digest' => $variant['image_digest'], 'right_id' => $right['id'],
                        'right_revision' => $right['revision'], 'template' => $template['id'], 'template_revision' => $template['revision'],
                        'channel' => $channel['id'], 'channel_revision' => $channel['revision'], 'width' => $channel['width'],
                        'height' => $channel['height'], 'margin' => $channel['margin'], 'style' => $template['style']];
                }
            }
            ksort($units);
            $frozen = ['units' => $units, 'provider' => $provider, 'cost_per_task_micros' => $charge, 'count' => $count,
                'from' => $from, 'until' => $until, 'ceiling_micros' => $budget, 'renderer' => 'catalog-png-v1'];
            $json = Values::json($frozen);
            $digest = hash('sha256', $json);
            $old = $this->db->one('SELECT id,input_hash FROM batches WHERE tenant=? AND campaign=?', [$scope->tenant, $campaign]);
            if ($old !== null) {
                if (!hash_equals($old['input_hash'], $digest)) {
                    throw Problem::conflict('Use a new campaign identity for different production inputs.');
                }
                return $this->batch($scope, $old['id']);
            }
            $id = Values::newId();
            $this->db->exec('INSERT INTO batches(tenant,id,campaign,input_hash,frozen,provider,ceiling_micros,ceiling_per_task,
                rights_from,rights_until,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [$scope->tenant, $id, $campaign, $digest, $json, $provider, $budget, $charge, $from, $until, $scope->actor]);
            $this->db->audit($scope, 'batch-planned', $id, ['input_hash' => $digest]);
            return $this->batch($scope, $id);
        });
    }

    public function submit(Scope $scope, string $id, string $inputHash): array
    {
        $scope->requireRole('operator');
        return $this->db->transaction(function () use ($scope, $id, $inputHash): array {
            $this->catalog->lockStudio($scope);
            $batch = $this->batch($scope, $id);
            if (!hash_equals($batch['input_hash'], Values::token($inputHash, 64))) {
                throw Problem::conflict('The confirmed input digest does not match the batch.');
            }
            if ($batch['phase'] !== 'draft') {
                return $batch;
            }
            foreach ($batch['frozen']['units'] as $key => $unit) {
                $this->requireRights($scope, $batch, $unit);
                for ($index = 0; $index < $batch['frozen']['count']; $index++) {
                    $this->enqueue($scope, $batch, $key, $index, null);
                }
            }
            $this->db->exec("UPDATE batches SET phase='queued' WHERE tenant=? AND id=?", [$scope->tenant, $id]);
            $this->db->audit($scope, 'batch-submitted', $id);
            return $this->batch($scope, $id);
        });
    }

    public function rework(Scope $scope, string $taskId, string $reason, int $expectedOrdinal): array
    {
        $scope->requireRole('operator');
        $reason = Values::text($reason, 600);
        return $this->db->transaction(function () use ($scope, $taskId, $reason, $expectedOrdinal): array {
            $this->catalog->lockStudio($scope);
            $task = $this->task($scope, $taskId);
            if (!in_array($task['phase'], ['completed', 'failed'], true)) {
                throw Problem::conflict('Resolve or close the existing provider request first.');
            }
            $batch = $this->batch($scope, $task['batch']);
            $this->requireRights($scope, $batch, $batch['frozen']['units'][$task['unit_key']]);
            $counters = $this->db->one('SELECT COUNT(*) AS total,MAX(CASE WHEN unit_key=? THEN ordinal END) AS last_slot
                FROM generation_tasks WHERE tenant=? AND batch=?', [$task['unit_key'], $scope->tenant, $task['batch']]);
            if ($counters['last_slot'] !== $expectedOrdinal || $counters['total'] >= 128 ||
                ($counters['total'] + 1) * $batch['ceiling_per_task'] > $batch['ceiling_micros']) {
                throw Problem::conflict('The unit advanced or reached its batch budget.');
            }
            $id = $this->enqueue($scope, $batch, $task['unit_key'], $expectedOrdinal + 1, $taskId);
            $this->db->exec("UPDATE batches SET phase='queued' WHERE tenant=? AND id=?", [$scope->tenant, $batch['id']]);
            $this->db->audit($scope, 'rework-queued', $id, ['previous' => $taskId, 'reason' => $reason]);
            return $this->task($scope, $id);
        });
    }

    public function step(Scope $scope, ?callable $fixture = null): ?array
    {
        $scope->requireRole('operator');
        $claim = $this->db->transaction(function () use ($scope): ?array {
            $this->catalog->lockStudio($scope);
            $this->db->exec("UPDATE generation_tasks SET phase='unknown',error_code='lease-expired'
                WHERE tenant=? AND phase='running' AND lease_until<=UTC_TIMESTAMP(6)", [$scope->tenant]);
            $task = $this->db->one("SELECT * FROM generation_tasks WHERE tenant=? AND phase='queued'
                ORDER BY updated_at,id LIMIT 1 FOR UPDATE SKIP LOCKED", [$scope->tenant]);
            if ($task === null) {
                return null;
            }
            $batch = $this->batch($scope, $task['batch']);
            $unit = $batch['frozen']['units'][$task['unit_key']];
            try {
                $this->requireRights($scope, $batch, $unit);
            } catch (Problem $error) {
                $this->fail($scope, $task, 'failed', $error->kind);
                return [$task, null, null];
            }
            $task['lease'] = Values::newId();
            $task['provider_ref'] = 'creative-' . $task['id'];
            $this->db->exec("UPDATE generation_tasks SET phase='running',provider_ref=?,lease=?,
                lease_until=UTC_TIMESTAMP(6)+INTERVAL 90 SECOND,attempts=attempts+1 WHERE tenant=? AND id=?",
                [$task['provider_ref'], $task['lease'], $scope->tenant, $task['id']]);
            return [$task, $batch, $unit];
        });
        if ($claim === null) {
            return null;
        }
        [$task, $batch, $unit] = $claim;
        if ($batch === null) {
            return $this->task($scope, $task['id']);
        }
        $timer = hrtime(true);
        try {
            $source = $this->catalog->image($scope->tenant, $unit['image_digest']);
            $direction = $this->art->choose($batch['provider'], $unit, $task['seed']);
            $fault = $batch['provider'] === 'replay' && $fixture !== null ? $fixture($task) : null;
            $png = Raster::render($unit, $source['bytes'], $direction, $fault === 'bad-packaging');
            $image = Raster::normalize($png);
            $quality = Raster::quality($unit, $source['bytes'], $image['bytes'], $direction['layout']);
        } catch (Problem $error) {
            return $this->db->transaction(function () use ($scope, $task, $error): array {
                $this->catalog->lockStudio($scope);
                $current = $this->task($scope, $task['id']);
                if ($current['phase'] === 'running' && $current['lease'] === $task['lease']) {
                    $this->fail($scope, $task, $error->kind === 'provider-response-lost' ? 'unknown' : 'failed', $error->kind);
                }
                return $this->task($scope, $task['id']);
            });
        }
        $receipt = ['source' => $batch['provider'], 'provider_ref' => $task['provider_ref'], 'seed' => $task['seed'],
            'provider' => $direction, 'elapsed_ms' => (int) ((hrtime(true) - $timer) / 1000000),
            'cost_micros' => $batch['ceiling_per_task'], 'quality' => $quality, 'commercial_output' => $fault !== 'no-rights'];
        $this->db->transaction(function () use ($scope, $task, $image, $receipt): void {
            $this->catalog->lockStudio($scope);
            $current = $this->task($scope, $task['id']);
            if (!in_array($current['phase'], ['running', 'unknown'], true) || $current['lease'] !== $task['lease']) {
                throw Problem::conflict('The provider receipt was fenced out by a newer decision.');
            }
            $this->catalog->saveImage($scope->tenant, $image);
            $this->db->exec('INSERT INTO render_receipts(tenant,task,image_digest,evidence) VALUES(?,?,?,?)',
                [$scope->tenant, $task['id'], $image['digest'], Values::json($receipt)]);
        });
        if ($fault === 'lost-after-receipt') {
            return $this->db->transaction(function () use ($scope, $task): array {
                $this->catalog->lockStudio($scope);
                if ($this->task($scope, $task['id'])['phase'] === 'running') {
                    $this->fail($scope, $task, 'unknown', 'provider-response-lost');
                }
                return $this->task($scope, $task['id']);
            });
        }
        return $this->resolve($scope, $task['id']);
    }

    public function resolve(Scope $scope, string $id, ?string $retireReason = null): array
    {
        $scope->requireRole('operator');
        return $this->db->transaction(function () use ($scope, $id, $retireReason): array {
            $this->catalog->lockStudio($scope);
            $task = $this->task($scope, $id);
            if ($task['phase'] === 'completed') {
                return $task;
            }
            if (!in_array($task['phase'], ['running', 'unknown'], true)) {
                throw Problem::conflict('Only submitted provider tasks can be reconciled.');
            }
            $row = $this->db->one('SELECT image_digest,evidence FROM render_receipts WHERE tenant=? AND task=?', [$scope->tenant, $id]);
            if ($row === null) {
                if ($retireReason !== null && $task['phase'] === 'unknown') {
                    $this->fail($scope, $task, 'failed', 'operator-closed-unknown');
                    $this->db->audit($scope, 'unknown-task-closed', $id, ['reason' => Values::text($retireReason, 600)]);
                    return $this->task($scope, $id);
                }
                throw Problem::conflict('No receipt exists for the original provider task.');
            }
            $receipt = Values::decode($row['evidence']);
            $quality = $receipt['quality'];
            $eligible = $quality['dimensions'] && $quality['source_pixels'] && $receipt['commercial_output'];
            $this->db->exec('INSERT INTO candidates(tenant,id,batch,unit_key,image_digest,evidence,quality,eligible)
                VALUES(?,?,?,?,?,?,?,?)', [$scope->tenant, $id, $task['batch'], $task['unit_key'], $row['image_digest'],
                    $row['evidence'], Values::json($quality), (int) $eligible]);
            $this->db->exec('INSERT INTO generation_meter(tenant,task,source,cost_micros,prompt_tokens,output_tokens,elapsed_ms)
                VALUES(?,?,?,?,?,?,?)', [$scope->tenant, $id, $receipt['source'], $receipt['cost_micros'],
                    $receipt['provider']['prompt_tokens'] ?? null, $receipt['provider']['output_tokens'] ?? null, $receipt['elapsed_ms']]);
            $this->db->exec("UPDATE generation_tasks SET phase='completed',error_code=NULL,lease=NULL,lease_until=NULL
                WHERE tenant=? AND id=?", [$scope->tenant, $id]);
            $this->maybeReview($scope, $task['batch']);
            $this->db->audit($scope, 'generation-reconciled', $id, ['eligible' => $eligible, 'source' => $receipt['source']]);
            return $this->task($scope, $id);
        });
    }

    public function batches(Scope $scope): array
    {
        $scope->requireRole('operator', 'designer', 'reviewer', 'channel');
        return $this->db->all('SELECT id,campaign,phase,provider,created_at FROM batches WHERE tenant=? ORDER BY created_at DESC LIMIT 100',
            [$scope->tenant]);
    }

    public function batch(Scope $scope, string $id): array
    {
        $row = $this->db->one('SELECT * FROM batches WHERE tenant=? AND id=?', [$scope->tenant, Values::token($id)])
            ?? throw Problem::missing();
        unset($row['tenant']);
        $row['frozen'] = Values::decode($row['frozen']);
        $row['planned_tasks'] = count($row['frozen']['units']) * $row['frozen']['count'];
        $row['estimated_cost_micros'] = $row['planned_tasks'] * $row['ceiling_per_task'];
        return $row;
    }

    public function details(Scope $scope, string $id): array
    {
        $scope->requireRole('operator', 'designer', 'reviewer');
        $batch = $this->batch($scope, $id);
        $batch['tasks'] = $this->db->all('SELECT id,unit_key,ordinal,source_task,phase,provider_ref,attempts,error_code
            FROM generation_tasks WHERE tenant=? AND batch=? ORDER BY unit_key,ordinal', [$scope->tenant, $id]);
        $batch['candidates'] = array_map(self::decodeCandidate(...), $this->db->all('SELECT id,unit_key,image_digest,quality,evidence,
                eligible,verdict,review_revision FROM candidates WHERE tenant=? AND batch=? ORDER BY unit_key,id', [$scope->tenant, $id]));
        $batch['selections'] = $this->db->all('SELECT unit_key,candidate,revision FROM selections s WHERE tenant=? AND batch=?
            AND revision=(SELECT MAX(r.revision) FROM selections r WHERE r.tenant=s.tenant AND r.batch=s.batch AND r.unit_key=s.unit_key)',
            [$scope->tenant, $id]);
        $batch['meter'] = $this->db->all('SELECT m.source,COUNT(*) AS completed,SUM(m.cost_micros) AS cost_micros,
                SUM(m.prompt_tokens) AS prompt_tokens,SUM(m.output_tokens) AS output_tokens FROM generation_meter m
            JOIN generation_tasks t ON t.tenant=m.tenant AND t.id=m.task WHERE t.tenant=? AND t.batch=? GROUP BY m.source', [$scope->tenant, $id]);
        return $batch;
    }

    public function requireRights(Scope $scope, array $batch, array $unit): void
    {
        $right = $this->catalog->rights($scope->tenant, $unit['right_id']);
        if ($right['revision'] !== $unit['right_revision'] || $unit['image_digest'] !== $right['image_digest'] ||
            !$this->catalog->usable($right, $unit['channel'], $batch['rights_from'], $batch['rights_until'])) {
            throw new Problem(409, 'rights-unavailable', 'Commercial rights no longer cover this output.');
        }
    }

    public static function decodeCandidate(array $row): array
    {
        $row['quality'] = Values::decode($row['quality']);
        $row['evidence'] = Values::decode($row['evidence']);
        $row['eligible'] = (bool) $row['eligible'];
        return $row;
    }

    private function task(Scope $scope, string $id): array
    {
        $row = $this->db->one('SELECT * FROM generation_tasks WHERE tenant=? AND id=?', [$scope->tenant, Values::token($id)])
            ?? throw Problem::missing();
        unset($row['tenant']);
        return $row;
    }

    private function reference(Scope $scope, string $type, mixed $input): array
    {
        return $this->catalog->revision($scope, $type, Values::id($input['id'] ?? null),
            Values::integer($input['revision'] ?? null, 1, 1_000_000));
    }

    private function enqueue(Scope $scope, array $batch, string $key, int $ordinal, ?string $source): string
    {
        $id = Values::newId();
        $seed = hexdec(substr(hash('sha256', $batch['input_hash'] . '/' . $key . '/' . $ordinal), 0, 8));
        $this->db->exec('INSERT INTO generation_tasks(tenant,id,batch,unit_key,ordinal,seed,source_task) VALUES(?,?,?,?,?,?,?)',
            [$scope->tenant, $id, $batch['id'], $key, $ordinal, $seed, $source]);
        return $id;
    }

    private function fail(Scope $scope, array $task, string $phase, string $kind): void
    {
        $this->db->exec('UPDATE generation_tasks SET phase=?,error_code=? WHERE tenant=? AND id=?',
            [$phase, $kind, $scope->tenant, $task['id']]);
        $this->db->audit($scope, 'generation-' . $phase, $task['id'], ['reason' => $kind]);
        $this->maybeReview($scope, $task['batch']);
    }

    private function maybeReview(Scope $scope, string $id): void
    {
        $active = $this->db->one("SELECT id FROM generation_tasks WHERE tenant=? AND batch=? AND phase IN ('queued','running','unknown') LIMIT 1",
            [$scope->tenant, $id]);
        if ($active === null) {
            $this->db->exec("UPDATE batches SET phase='review' WHERE tenant=? AND id=? AND phase='queued'", [$scope->tenant, $id]);
        }
    }

    private function price(string $provider): int
    {
        if ($provider === 'replay') {
            return 0;
        }
        $price = getenv('CREATIVE_LIVE_COST_MICROS');
        if ($price === false || preg_match('/\A[0-9]{1,8}\z/D', $price) !== 1) {
            throw Problem::invalid('Configure an operator-defined live cost per candidate before planning.');
        }
        return (int) $price;
    }
}
