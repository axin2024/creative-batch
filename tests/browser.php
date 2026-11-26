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
$tenant = 'browser-fixture';
$admin = identity($studio, $tenant, 'admin', 'admin');
$input = Demo::seed($studio, $admin);
sparseSource($studio, $admin);
$grants = [];
foreach (['admin', 'operator', 'reviewer', 'channel'] as $role) {
    $grants[$role] = $studio->access->provision($tenant, $role, $role, 1)['token'];
}
$grants['foreign'] = $studio->access->provision('another-browser-studio', 'recipient', 'channel', 1)['token'];
$operator = $studio->access->authenticate('Bearer ' . $grants['operator']);
$input = array_replace($input, ['campaign' => 'browser-recovery', 'variants' => [['id' => 'sparse', 'revision' => 1]],
    'templates' => [$input['templates'][0]], 'candidates_per_unit' => 1]);
$batch = $studio->production->plan($operator, $input);
$studio->production->submit($operator, $batch['id'], $batch['input_hash']);
$task = $studio->production->step($operator, static fn () => 'lost-after-receipt');
verify($task['phase'] === 'unknown' && $task['attempts'] === 1, 'Expected a recoverable original task.');
$draft = $studio->production->plan($operator, array_replace($input, ['campaign' => 'private-draft']));
$fixture = ['grants' => $grants, 'batch' => $batch['id'], 'draft' => $draft['id'], 'task' => $task['id']];
$script = str_replace('/* STUDIO_FIXTURE */ {}', Values::json($fixture), file_get_contents(__DIR__ . '/browser.js'));
verify(file_put_contents($argv[1], $script) !== false, 'Could not create the local browser script.');
