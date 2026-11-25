<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

$studio = new Studio(Db::connect());
$studio->db->migrate();
$admin = identity($studio, 'kite-live', 'admin', 'admin');
Demo::seed($studio, $admin);
$input = Demo::request('live-layout', 'live');
$input['ceiling_micros'] = (int) (getenv('CREATIVE_LIVE_COST_MICROS') ?: 0) * 6;
$batch = $studio->production->plan($admin, $input);
$studio->production->submit($admin, $batch['id'], $batch['input_hash']);
while ($studio->production->step($admin) !== null) {}
$result = $studio->production->details($admin, $batch['id']);
verify(count($result['candidates']) === 6, 'Expected six live model render receipts: ' . Values::json($result['tasks']));
$evidence = [];
foreach ($result['candidates'] as $candidate) {
    $receipt = $candidate['evidence'];
    verify($candidate['eligible'] && $candidate['verdict'] === 'pending' && $receipt['provider']['prompt_tokens'] > 0,
        'Live renders require verifiable local pixels and must enter human review.');
    $evidence[$candidate['unit_key']] = ['art_direction' => $receipt['provider'], 'quality' => $candidate['quality'],
        'duration_ms' => $receipt['elapsed_ms'], 'sha256' => $candidate['image_digest']];
}
echo Values::json(['model_mode' => 'live', 'accepted_for_human_review' => count($evidence), 'assets' => $evidence,
    'meter' => $result['meter']]), PHP_EOL;
