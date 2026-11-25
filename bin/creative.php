<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Problem;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';

$command = $argv[1] ?? 'help';
if (!in_array($command, ['migrate', 'provision', 'seed', 'worker'], true)) {
    echo "creative.php migrate\ncreative.php provision TENANT ACTOR ROLE HOURS\ncreative.php seed\ncreative.php worker [--once]\n";
    exit($command === 'help' ? 0 : 2);
}
try {
    $studio = new Studio(Db::connect());
    if ($command === 'migrate') {
        echo Values::json(['applied' => $studio->db->migrate()]), PHP_EOL;
    }
    if ($command === 'provision') {
        if (count($argv) !== 6 || !ctype_digit($argv[5])) {
            throw Problem::invalid('Provide tenant, actor, role and hours.');
        }
        echo Values::json($studio->access->provision($argv[2], $argv[3], $argv[4], (int) $argv[5])), PHP_EOL;
    }
    if ($command === 'seed' || $command === 'worker') {
        $scope = $studio->access->authenticate('Bearer ' . (getenv('CREATIVE_TOKEN') ?: ''));
        if ($command === 'seed') {
            echo Values::json(Demo::seed($studio, $scope)), PHP_EOL;
        } else {
            do {
                $scope = $studio->access->authenticate('Bearer ' . (getenv('CREATIVE_TOKEN') ?: ''));
                $task = $studio->production->step($scope);
                if ($task !== null) {
                    echo Values::json(['task' => $task['id'], 'phase' => $task['phase']]), PHP_EOL;
                } elseif (($argv[2] ?? '') !== '--once') {
                    usleep(550000);
                }
            } while (($argv[2] ?? '') !== '--once');
        }
    }
} catch (Problem $problem) {
    fwrite(STDERR, $problem->kind . ' ' . $problem->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Creative Batch command failed (' . get_class($error) . '). Inspect local runtime configuration.' . PHP_EOL);
    exit(1);
}
