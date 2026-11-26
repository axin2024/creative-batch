<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Raster;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $path = $_SERVER['REQUEST_URI'];
    if ($path === '/health') {
        echo '{}';
        return;
    }
    verify(preg_match('#^/v1/jobs/(creative-[0-9a-f]{32})$#D', $path, $matches) === 1, 'Expected the original job identity.');
    $id = $matches[1];
    $file = getenv('CREATIVE_TEST_JOBS');
    $stored = file_get_contents($file);
    $jobs = $stored === '' ? [] : Values::decode($stored);
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = Values::decode(file_get_contents('php://input'));
        verify(array_keys($input) === ['model', 'seed', 'width', 'height', 'background', 'accent'], 'Unexpected source data left the studio.');
        verify($input['model'] === 'local-test-image' && $input['width'] === 720 && $input['height'] === 720, 'Image settings differ from the frozen plan.');
        $jobs[$id] ??= ['order' => count($jobs), 'posts' => 0, 'reads' => 0];
        $jobs[$id]['posts']++;
        file_put_contents($file, Values::json($jobs), LOCK_EX);
        // The first job commits before an upstream error is returned.
        http_response_code($jobs[$id]['order'] === 0 ? 503 : 202);
        echo Values::json(['job_id' => $id, 'model' => 'local-test-image', 'state' => 'queued']);
        return;
    }
    if (!isset($jobs[$id])) {
        http_response_code(404);
        echo '{}';
        return;
    }
    $jobs[$id]['reads']++;
    file_put_contents($file, Values::json($jobs), LOCK_EX);
    if ($jobs[$id]['reads'] === 1) {
        echo Values::json(['job_id' => $id, 'model' => 'local-test-image', 'state' => 'running']);
        return;
    }
    $png = Raster::product('TEST', '#536996');
    echo Values::json(['job_id' => $id, 'model' => 'local-test-image', 'state' => 'completed', 'png_base64' => base64_encode($png),
        'png_sha256' => hash('sha256', $png), 'commercial' => $jobs[$id]['order'] === 0, 'cost_micros' => $jobs[$id]['order'] === 0 ? 0 : 37]);
    return;
}

$socket = stream_socket_server('tcp://127.0.0.1:0');
verify(is_resource($socket), 'Could not reserve a disposable fixture port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$jobs = tempnam(sys_get_temp_dir(), 'creative-jobs-');
$log = tempnam(sys_get_temp_dir(), 'creative-http-');
$url = 'http://' . $address;
putenv('CREATIVE_TEST_JOBS=' . $jobs);
putenv('CREATIVE_IMAGE_URL=' . $url);
putenv('CREATIVE_IMAGE_MODEL=local-test-image');
putenv('CREATIVE_IMAGE_TOKEN');
putenv('CREATIVE_IMAGE_COST_MICROS=50');
$command = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $command = array_merge($command, ['-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=gd']);
}
$server = proc_open(array_merge($command, ['-S', $address, __FILE__]), [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a']], $pipes, __DIR__);
try {
    verify(is_resource($server), 'Could not start the local provider fixture.');
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (@file_get_contents($url . '/health') === '{}') {
            $ready = true;
            break;
        }
        usleep(50000);
    }
    verify($ready, 'The image fixture did not respond.');
    $studio = new Studio(Db::connect());
    $studio->db->migrate();
    $admin = identity($studio, 'image-fixture', 'admin', 'admin');
    Demo::seed($studio, $admin);
    $input = Demo::request('frozen-provider', 'image');
    $input['variants'] = [$input['variants'][0]];
    $input['templates'] = [$input['templates'][0]];
    $input['candidates_per_unit'] = 2;
    $input['ceiling_micros'] = 100;
    $batch = $studio->production->plan($admin, $input);
    $studio->production->submit($admin, $batch['id'], $batch['input_hash']);
    $first = $studio->production->step($admin);
    $second = $studio->production->step($admin);
    verify($first['phase'] === 'unknown' && $first['error_code'] === 'provider-response-lost' &&
        $second['phase'] === 'unknown' && $second['error_code'] === 'provider-pending', 'Pending and uncertain receipt states were mixed.');
    verify($studio->production->step($admin) === null, 'The original image submissions must not be repeated.');
    blocked(fn () => $studio->production->resolve($admin, $first['id']), 409, 'provider-pending');
    blocked(fn () => $studio->production->resolve($admin, $second['id'], 'The provider outcome is not known.'), 409, 'provider-pending');
    foreach ([$first, $second] as $task) {
        verify($studio->production->resolve($admin, $task['id'])['phase'] === 'completed', 'The original image result did not settle.');
        verify($studio->production->resolve($admin, $task['id'])['attempts'] === 1, 'A repeated lookup triggered new work.');
    }
    $accepted = $studio->delivery->candidate($admin, $first['id']);
    $rejected = $studio->delivery->candidate($admin, $second['id']);
    verify($accepted['eligible'] && $accepted['quality']['source_pixels'] && !$rejected['eligible'], 'Commercial output rights or product preservation failed.');
    verify($accepted['evidence']['backend'] === 'image' && $accepted['evidence']['provider']['reported_cost_micros'] === 0,
        'A provider-reported zero must remain distinct from missing cost.');
    $report = $studio->production->details($admin, $batch['id']);
    verify($report['unit_usage']['coral:square']['reported_micros'] === 37 && $report['unit_usage']['coral:square']['unpriced_live_outputs'] === 0,
        'Provider expenses must be attributed to the correct unit.');
    blocked(fn () => $studio->delivery->review($admin, $second['id'], approval(0)), 409);
    $studio->delivery->review($admin, $first['id'], approval(0));
    $studio->delivery->select($admin, $first['id'], ['expected_revision' => 0, 'reason' => 'The commercially cleared candidate passed review.']);
    $published = $studio->delivery->preview($admin, $batch['id'], true);
    $entry = $published['manifest']['entries']['coral:square'];
    $costs = $entry['candidate_costs'];
    verify($published['manifest']['version'] === 2 && $entry['candidate'] === $first['id'] && $entry['candidates_charged'] === 2 &&
        $costs['live_estimated_micros'] === 100 && $costs['live_outputs'] === 2 && $costs['replay_outputs'] === 0 &&
        $costs['reported_micros'] === 37 && $costs['unpriced_live_outputs'] === 0,
        'The delivered selection must explain both planned and reported candidate costs.');
    $requests = Values::decode(file_get_contents($jobs));
    verify(count($requests) === 2 && array_column($requests, 'posts') === [1, 1] && array_column($requests, 'reads') === [2, 2],
        'Remote history must show one submission and two lookups per original job.');
    echo Values::json(['mode' => 'synthetic HTTP image provider', 'original_jobs' => count($requests), 'provider_requests' => array_values($requests),
        'cost_reported_micros' => 37, 'rights_blocked' => 1, 'product_pixels_preserved' => $accepted['quality']['sampled']]), PHP_EOL;
} finally {
    if (is_resource($server)) {
        fclose($pipes[0]);
        proc_terminate($server);
        proc_close($server);
    }
    unlink($jobs);
    unlink($log);
}
