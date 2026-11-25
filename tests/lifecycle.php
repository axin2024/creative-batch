<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

$started = hrtime(true);
$studio = new Studio(Db::connect());
$studio->db->migrate(1);
$admin = identity($studio, 'kite-fixture', 'admin', 'admin');
$studio->db->migrate();
verify($studio->db->migrate() === [], 'Migrations must be repeatable.');
$operator = identity($studio, $admin->tenant, 'producer', 'operator');
$reviewer = identity($studio, $admin->tenant, 'reviewer', 'reviewer');
$channel = identity($studio, $admin->tenant, 'channel', 'channel');
$other = identity($studio, 'another-studio', 'reviewer', 'reviewer');
$input = Demo::seed($studio, $admin);
$batch = $studio->production->plan($operator, $input);
verify($batch['planned_tasks'] === 12, 'Expected a 2 × 3 × 2 matrix.');
verify($studio->production->plan($operator, $input)['id'] === $batch['id'], 'Plan must be idempotent.');
blocked(fn () => $studio->production->plan($operator, array_replace($input, ['candidates_per_unit' => 1])), 409);
blocked(fn () => $studio->production->submit($operator, $batch['id'], str_repeat('0', 64)), 409);
$studio->production->submit($operator, $batch['id'], $batch['input_hash']);
$studio->production->submit($operator, $batch['id'], $batch['input_hash']);

$fixture = static fn ($task) => match ($task['unit_key'] . ':' . $task['ordinal']) {
    'coral:banner:0' => 'lost-after-receipt',
    'coast:square:0' => 'bad-packaging',
    'coast:portrait:0' => 'no-rights',
    default => null,
};
$unknown = 0;
while (($task = $studio->production->step($operator, $fixture)) !== null) {
    if ($task['phase'] === 'unknown') {
        $unknown++;
        $task = $studio->production->resolve($operator, $task['id']);
        $again = $studio->production->resolve($operator, $task['id']);
        verify($task['phase'] === 'completed' && $again['attempts'] === 1, 'A recovered receipt must settle a single original attempt.');
    }
}
$details = $studio->production->details($reviewer, $batch['id']);
verify($unknown === 1 && count($details['tasks']) === 12 && $details['phase'] === 'review', 'Unexpected task outcomes.');
verify($details['meter'][0]['completed'] === 12, 'The metering ledger must contain exactly twelve receipts.');
blocked(fn () => $studio->production->details($other, $batch['id']), 404);
blocked(fn () => $studio->production->details($channel, $batch['id']), 403);
verify(count($studio->delivery->preview($operator, $batch['id'], false)['blocked']) === 6, 'All units initially lack an approved selection.');

$winner = [];
$rejected = 0;
foreach ($details['candidates'] as $asset) {
    $id = $asset['id'];
    blocked(fn () => $studio->delivery->compare($other, $id, false), 404);
    if (!$asset['eligible']) {
        $rejected++;
        blocked(fn () => $studio->delivery->review($reviewer, $id, approval(0)), 409);
        $studio->delivery->review($reviewer, $id, array_replace(approval(0), ['verdict' => 'rejected']));
        continue;
    }
    if (isset($winner[$asset['unit_key']])) {
        continue;
    }
    blocked(fn () => $studio->delivery->review($operator, $id, approval(0)), 403);
    blocked(fn () => $studio->delivery->review($reviewer, $id,
        array_replace(approval(0), ['checks' => array_replace(approval(0)['checks'], ['marks' => false])])), 409);
    $studio->delivery->review($reviewer, $id, approval(0));
    blocked(fn () => $studio->delivery->review($reviewer, $id, approval(0)), 409);
    $winner[$asset['unit_key']] = $id;
    $studio->delivery->select($operator, $id, ['expected_revision' => 0, 'reason' => 'Chosen for storefront production.']);
}
verify($rejected === 2 && count($winner) === 6, 'Expected two failed proposals and six winners.');
$bundle = $studio->delivery->preview($operator, $batch['id'], true);
verify($studio->delivery->preview($operator, $batch['id'], true)['id'] === $bundle['id'], 'The frozen manifest must deduplicate publication.');
blocked(fn () => $studio->delivery->zip($other, $bundle['id']), 403);

$first = $studio->delivery->zip($channel, $bundle['id']);
$second = $studio->delivery->zip($channel, $bundle['id']);
try {
    verify($first['receipt'] !== $second['receipt'] && hash_file('sha256', $first['file']) === hash_file('sha256', $second['file']),
        'Logical receipts must be distinct and the archive bytes reproducible.');
    $archive = new ZipArchive();
    verify($archive->open($first['file']) === true, 'The ZIP could not be read.');
    verify($archive->numFiles === 7, 'Expected six PNGs and one manifest.');
    $json = $archive->getFromName('manifest.json');
    verify(hash('sha256', $json) === $bundle['digest'], 'Manifest hash mismatch.');
    foreach (Values::decode($json)['entries'] as $entry) {
        $bytes = $archive->getFromName($entry['filename']);
        $size = getimagesizefromstring($bytes);
        verify(hash('sha256', $bytes) === $entry['output_sha256'] && $size[0] === $entry['width'] && $size[1] === $entry['height'],
            'Approved image dimensions and digest must match the export manifest.');
    }
    $archive->close();
    if (($evidence = getenv('CREATIVE_TEST_BUNDLE')) !== false) {
        verify(copy($first['file'], $evidence), 'Could not copy the synthetic showcase ZIP.');
    }
} finally {
    unlink($first['file']);
    unlink($second['file']);
}

$task = $studio->production->rework($operator, $winner['coral:banner'], 'Try another studio finish.', 1);
verify($studio->production->step($operator)['id'] === $task['id'], 'Rework must queue a distinct job.');
verify($studio->production->details($reviewer, $batch['id'])['meter'][0]['completed'] === 13, 'Rework must add one charge.');
$studio->catalog->revoke($admin, 'reference-coast', 'The fictional grant was withdrawn.');
blocked(fn () => $studio->delivery->zip($channel, $bundle['id']), 409, 'rights-unavailable');
verify(count($studio->delivery->preview($operator, $batch['id'], false)['blocked']) === 3, 'Only the affected variant should be blocked.');
$counts = ['renders' => 13, 'first_pass_candidates' => 12, 'blocked_candidates' => 2, 'bundle_images' => 6,
    'reconciled' => $unknown, 'download_receipts' => 2, 'rights_blocks' => 3, 'manifest_sha256' => $bundle['digest'],
    'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)];
echo Values::json($counts), PHP_EOL;
