<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Problem;
use CreativeBatch\Raster;
use CreativeBatch\Scope;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

if (($argv[1] ?? null) === 'select') {
    $studio = new Studio(Db::connect());
    echo "ready\n";
    $attempt = Values::decode(trim(fgets(STDIN)));
    $scope = $studio->access->authenticate('Bearer ' . $attempt['token']);
    try {
        $selection = $studio->delivery->select($scope, $attempt['candidate'], ['expected_revision' => 0,
            'reason' => 'Selected from a concurrent studio review.']);
        echo Values::json(['status' => 200, 'candidate' => $selection['candidate']]), PHP_EOL;
    } catch (Problem $problem) {
        echo Values::json(['status' => $problem->status, 'error' => $problem->kind]), PHP_EOL;
    }
    exit;
}

function raceSelections(Studio $studio, Scope $operator, array $candidates): string
{
    verify(count($candidates) === 2, 'The race needs two approved candidates for a single unit.');
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        $command = array_merge($command, ['-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=pdo_mysql']);
    }
    $command[] = __FILE__;
    $command[] = 'select';
    $actors = ['producer-a', 'producer-b'];
    $processes = [];
    try {
        foreach ($candidates as $index => $candidate) {
            $grant = $studio->access->provision($operator->tenant, $actors[$index], 'operator', 1);
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            verify(is_resource($process), 'Could not start a concurrent selection client.');
            $processes[] = [$process, $pipes, ['token' => $grant['token'], 'candidate' => $candidate]];
        }
        foreach ($processes as [, $pipes]) {
            verify(trim((string) fgets($pipes[1])) === 'ready', 'A selection client could not connect to MySQL.');
        }
        foreach ($processes as [, $pipes, $input]) {
            $encoded = Values::json($input) . "\n";
            verify(fwrite($pipes[0], $encoded) === strlen($encoded), 'Could not submit a concurrent selection.');
        }
        $results = [];
        foreach ($processes as [, $pipes]) {
            $results[] = Values::decode(trim((string) fgets($pipes[1])));
        }
        usort($results, static fn (array $a, array $b): int => $a['status'] <=> $b['status']);
        verify(array_column($results, 'status') === [200, 409], 'Concurrent selection must yield one winner and one conflict.');
        return $results[0]['candidate'];
    } finally {
        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}

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
verify($studio->production->plan($operator, array_replace($input, ['variants' => array_reverse($input['variants'])]))['id'] === $batch['id'],
    'Changing the ordering of the same catalog references must replay the original batch.');
blocked(fn () => $studio->production->plan($operator, array_replace($input, ['candidates_per_unit' => 1])), 409);
$oldImage = $batch['frozen']['units']['coast:square']['image_digest'];
$oldVariant = ['brand' => 'kite', 'brand_revision' => 1, 'sku' => 'roast-04', 'color' => 'coast',
    'rgb' => '#286a7a', 'image_digest' => $oldImage, 'right_id' => 'reference-coast'];
blocked(fn () => $studio->catalog->revise($admin, 'variants', 'duplicate-coast', ['expected_revision' => 0] + $oldVariant), 409);
blocked(fn () => $studio->catalog->revise($admin, 'variants', 'coast', ['expected_revision' => 1, 'sku' => 'changed-sku'] + $oldVariant), 409);
verify($studio->catalog->revise($admin, 'variants', 'coast', ['expected_revision' => 1] + $oldVariant)['revision'] === 2,
    'A stable variant identity permits editing its approved version.');
$studio->catalog->grant($admin, 'expired-coast', ['image_digest' => $oldImage, 'grantor' => 'Expired fixture', 'commercial' => true,
    'channels' => ['square'], 'valid_from' => gmdate('Y-m-d\TH:i:s\Z', time() - 172800),
    'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
$studio->catalog->revise($admin, 'variants', 'expired', ['expected_revision' => 0, 'brand' => 'kite', 'brand_revision' => 1,
    'sku' => 'expired-sku', 'color' => 'coast', 'rgb' => '#286a7a', 'image_digest' => $oldImage, 'right_id' => 'expired-coast']);
blocked(fn () => $studio->production->plan($operator, array_replace($input, ['campaign' => 'expired-grant',
    'variants' => [['id' => 'expired', 'revision' => 1]], 'templates' => [$input['templates'][0]]])), 422);
$studio->catalog->revise($admin, 'channels', 'banner', ['expected_revision' => 1, 'width' => 1024, 'height' => 576, 'margin' => 9]);
verify($studio->production->batch($operator, $batch['id'])['frozen']['units']['coast:banner']['width'] === 1200,
    'A new channel revision must preserve the original canvas in the draft.');
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
verify($details['meter'][0]['source'] === 'replay' && (int) $details['meter'][0]['cost_micros'] === 0,
    'Replay receipts must never contribute to live spending.');
blocked(fn () => $studio->production->details($other, $batch['id']), 404);
blocked(fn () => $studio->production->details($channel, $batch['id']), 403);
verify(count($studio->delivery->preview($operator, $batch['id'], false)['blocked']) === 6, 'All units initially lack an approved selection.');

$winner = [];
$raceCandidates = [];
$rejected = 0;
$manualReject = null;
foreach ($details['candidates'] as $asset) {
    $id = $asset['id'];
    blocked(fn () => $studio->delivery->compare($other, $id, false), 404);
    $unit = $batch['frozen']['units'][$asset['unit_key']];
    verify($studio->delivery->compare($reviewer, $id, true)['digest'] === $unit['image_digest'],
        'Every candidate must compare against its own color reference.');
    verify($asset['quality']['actual_size'] === $asset['quality']['expected_size'], 'Channel canvas did not match the frozen dimensions.');
    if (!$asset['eligible']) {
        $rejected++;
        blocked(fn () => $studio->delivery->review($reviewer, $id, approval(0)), 409);
        $studio->delivery->review($reviewer, $id, array_replace(approval(0), ['verdict' => 'rejected']));
        continue;
    }
    if (isset($winner[$asset['unit_key']])) {
        if ($asset['unit_key'] === 'coral:portrait') {
            $review = array_replace(approval(0), ['verdict' => 'rejected', 'reason' => 'The package lettering is unacceptable.']);
            $review['checks']['packaging'] = false;
            $studio->delivery->review($reviewer, $id, $review);
            blocked(fn () => $studio->delivery->select($operator, $id, ['expected_revision' => 1, 'reason' => 'Use this rejected candidate.']), 409);
            $manualReject = $id;
        }
        continue;
    }
    blocked(fn () => $studio->delivery->review($operator, $id, approval(0)), 403);
    blocked(fn () => $studio->delivery->review($reviewer, $id,
        array_replace(approval(0), ['checks' => array_replace(approval(0)['checks'], ['marks' => false])])), 409);
    $studio->delivery->review($reviewer, $id, approval(0));
    blocked(fn () => $studio->delivery->review($reviewer, $id, approval(0)), 409);
    if ($asset['unit_key'] === 'coral:square') {
        $raceCandidates[] = $id;
        continue;
    }
    $winner[$asset['unit_key']] = $id;
    $studio->delivery->select($operator, $id, ['expected_revision' => 0, 'reason' => 'Chosen for storefront production.']);
}
$incomplete = $studio->delivery->preview($operator, $batch['id'], false);
verify($incomplete['blocked'] === ['coral:square' => 'missing-selection'] && count($incomplete['manifest']['entries']) === 5,
    'Five approved selections should produce a preview with one explicit missing unit.');
blocked(fn () => $studio->delivery->preview($operator, $batch['id'], true), 409);
$winner['coral:square'] = raceSelections($studio, $operator, $raceCandidates);
verify($manualReject !== null, 'A machine-eligible candidate must have been refused by a human reviewer.');
verify($rejected === 2 && count($winner) === 6, 'Expected two failed proposals and six winners.');
$wrongSize = Raster::quality($batch['frozen']['units']['coral:banner'], Raster::product('KITE', '#b94736'),
    Raster::product('KITE', '#b94736'), 'center');
verify(!$wrongSize['dimensions'] && $wrongSize['expected_size'] === ['width' => 1200, 'height' => 628] &&
    $wrongSize['actual_size'] === ['width' => 320, 'height' => 420], 'Incorrect dimensions must report the right canvas in pixels.');
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
$afterRework = $studio->production->details($reviewer, $batch['id']);
verify($afterRework['meter'][0]['completed'] === 13 && $afterRework['unit_usage']['coral:banner']['reworks'] === 1 &&
    $afterRework['unit_usage']['coast:banner']['reworks'] === 0, 'Rework must add exactly one job in its original unit.');
$studio->delivery->review($reviewer, $task['id'], approval(0));
$studio->delivery->select($operator, $task['id'], ['expected_revision' => 1, 'reason' => 'Adopt the replacement banner.']);
$nextBundle = $studio->delivery->preview($operator, $batch['id'], true);
verify($nextBundle['revision'] === 2 && $nextBundle['manifest']['entries']['coral:banner']['candidate'] === $task['id'] &&
    $nextBundle['manifest']['entries']['coast:banner']['candidate'] === $winner['coast:banner'], 'Revised banner spilled into another variant.');
$entry = $nextBundle['manifest']['entries']['coral:banner'];
$costs = $entry['candidate_costs'];
verify($entry['candidates_charged'] === 3 && $entry['allocated_cost_micros'] === 0 && $costs['live_estimated_micros'] === 0 &&
    $costs['live_outputs'] === 0 && $costs['replay_outputs'] === 3 && $costs['reported_micros'] === null && $costs['unpriced_live_outputs'] === 0,
    'The candidate count should explain the selected unit without billing replay.');
$operations = $studio->operations($operator);
verify(count($operations['backends']) === 1 && $operations['backends'][0]['provider'] === 'replay' &&
    $operations['backends'][0]['tasks'] === 13 && $operations['backends'][0]['reworked'] === 1 &&
    $operations['backends'][0]['selected'] === 6 && $operations['backends'][0]['estimated_micros'] === 0 &&
    $operations['recent_rework'][0]['task'] === $task['id'], 'Production statistics must count this batch without counting replaced selections.');
blocked(fn () => $studio->operations($channel), 403);
$composition = ['mask' => 'source-alpha', 'font' => 'gd-bitmap-5', 'product_box' => [40, 16, 45, 66], 'headline_box' => [9, 10, 29, 12]];
$templateInput = ['expected_revision' => 1, 'brand' => 'kite', 'brand_revision' => 1, 'channel' => 'banner', 'channel_revision' => 2,
    'background' => '#402b2a', 'accent' => '#e87f62', 'headline' => 'KITE COFFEE', 'composition' => $composition,
    'generation' => ['backend' => 'replay', 'seed_mode' => 'derived']];
blocked(fn () => $studio->catalog->revise($admin, 'templates', 'banner', array_replace($templateInput, [
    'composition' => array_replace($composition, ['headline_box' => [43, 17, 30, 10]]),
])), 422);
blocked(fn () => $studio->catalog->revise($admin, 'templates', 'banner', array_replace($templateInput, [
    'composition' => array_replace($composition, ['headline_box' => [9, 10, 2, 12]]),
])), 422);
$studio->catalog->revise($admin, 'templates', 'banner', $templateInput);
$customInput = array_replace($input, ['campaign' => 'new-banner-zones', 'variants' => [$input['variants'][0]],
    'templates' => [['id' => 'banner', 'revision' => 2]], 'candidates_per_unit' => 1]);
blocked(fn () => $studio->production->plan($operator, array_replace($customInput, ['provider' => 'live'])), 422);
$custom = $studio->production->plan($operator, $customInput);
$customUnit = $custom['frozen']['units']['coral:banner'];
verify($customUnit['width'] === 1024 && $customUnit['height'] === 576 && $customUnit['style']['composition']['headline_box'] === [9, 10, 29, 12] &&
    !isset($batch['frozen']['units']['coral:banner']['style']['composition']), 'Template revision must freeze the named zones only in new campaigns.');
$studio->production->submit($operator, $custom['id'], $custom['input_hash']);
$customTask = $studio->production->step($operator);
$customCandidate = $studio->delivery->candidate($reviewer, $customTask['id']);
verify($customCandidate['batch'] === $custom['id'] && $customCandidate['quality']['source_pixels'] && $customCandidate['quality']['dimensions'],
    'The new composition must render while preserving the protected product and channel canvas.');
$revocation = $studio->catalog->revoke($admin, 'reference-coast', 'The fictional grant was withdrawn.');
verify(count($revocation['affected']) === 3 && in_array('channel', array_column($revocation['affected'], 'actor'), true),
    'Revoked rights must list the affected packages and previous recipients.');
verify($studio->production->plan($operator, $input)['id'] === $batch['id'], 'Planning replay must return the receipt after revocation.');
blocked(fn () => $studio->delivery->zip($channel, $bundle['id']), 409, 'rights-unavailable');
verify(count($studio->delivery->preview($operator, $batch['id'], false)['blocked']) === 3, 'Only the affected variant should be blocked.');

$sparse = sparseSource($studio, $admin);
$sparseInput = array_replace($input, ['campaign' => 'low-sample', 'variants' => [['id' => 'sparse', 'revision' => 1]],
    'templates' => [$input['templates'][0]], 'candidates_per_unit' => 1]);
$sparseBatch = $studio->production->plan($operator, $sparseInput);
$studio->production->submit($operator, $sparseBatch['id'], $sparseBatch['input_hash']);
$sparseTask = $studio->production->step($operator);
$sparseCandidate = $studio->delivery->candidate($reviewer, $sparseTask['id']);
verify($sparseCandidate['quality']['source_check'] === 'inconclusive' && $sparseCandidate['verdict'] === 'pending' &&
    $studio->delivery->compare($reviewer, $sparseTask['id'], true)['digest'] === $sparse['digest'], 'Low confidence must reach review alongside the source.');
blocked(fn () => $studio->delivery->select($operator, $sparseTask['id'], ['expected_revision' => 0, 'reason' => 'Attempt selection before review.']), 409);
$studio->delivery->review($reviewer, $sparseTask['id'], approval(0));
$studio->delivery->select($operator, $sparseTask['id'], ['expected_revision' => 0, 'reason' => 'Verified the deliberately sparse reference.']);
$counts = ['renders' => 13, 'first_pass_candidates' => 12, 'blocked_candidates' => 2, 'bundle_images' => 6,
    'reconciled' => $unknown, 'download_receipts' => 2, 'rights_blocks' => 3, 'manifest_sha256' => $bundle['digest'],
    'selection_races' => 1, 'revision_after_rework' => $nextBundle['revision'], 'manual_packaging_rejects' => 1,
    'low_confidence_samples' => $sparseCandidate['quality']['sampled'],
    'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)];
echo Values::json($counts), PHP_EOL;
