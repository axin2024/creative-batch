<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Scope;
use CreativeBatch\Studio;
use CreativeBatch\Values;

[, $mode, $source, $evidence] = $argv;
require $source . '/bootstrap.php';
require __DIR__ . '/scenario.php';

function releaseCampaign(Studio $studio, Scope $operator, Scope $reviewer, array $input): array
{
    $batch = $studio->production->plan($operator, $input);
    $studio->production->submit($operator, $batch['id'], $batch['input_hash']);
    while ($studio->production->step($operator) !== null) {
        continue;
    }
    $selected = [];
    foreach ($studio->production->details($reviewer, $batch['id'])['candidates'] as $candidate) {
        $unit = $candidate['unit_key'];
        if (isset($selected[$unit])) {
            continue;
        }
        $studio->delivery->review($reviewer, $candidate['id'], approval(0));
        $selection = $studio->delivery->select($operator, $candidate['id'], ['expected_revision' => 0,
            'reason' => 'Approved for the fixture campaign.']);
        $selected[$unit] = $selection['candidate'];
    }
    verify(count($selected) === 6, 'All six fixture combinations must be selected.');
    return $studio->delivery->preview($operator, $batch['id'], true);
}

function archiveProof(Studio $studio, Scope $channel, array $bundle): array
{
    $file = $studio->delivery->zip($channel, $bundle['id'])['file'];
    try {
        $archive = new ZipArchive();
        verify($archive->open($file) === true, 'Could not open the delivered archive.');
        try {
            $manifest = $archive->getFromName('manifest.json');
            verify(hash('sha256', $manifest) === $bundle['digest'], 'The historical manifest digest did not match.');
            $digests = [];
            foreach (Values::decode($manifest)['entries'] as $entry) {
                $bytes = $archive->getFromName($entry['filename']);
                verify(is_string($bytes) && hash('sha256', $bytes) === $entry['output_sha256'], 'Historical PNG digest did not match.');
                $digests[$entry['filename']] = $entry['output_sha256'];
            }
            return ['manifest_sha256' => $bundle['digest'], 'zip_sha256' => hash_file('sha256', $file), 'images' => $digests];
        } finally {
            $archive->close();
        }
    } finally {
        unlink($file);
    }
}

$studio = new Studio(Db::connect());
$migrations = $studio->db->migrate();
$tenant = 'upgrade-fixture';
if ($mode === 'seed') {
    $admin = identity($studio, $tenant, 'admin', 'admin');
    $operator = identity($studio, $tenant, 'producer', 'operator');
    $reviewer = identity($studio, $tenant, 'reviewer', 'reviewer');
    $grant = $studio->access->provision($tenant, 'recipient', 'channel', 2);
    $channel = $studio->access->authenticate('Bearer ' . $grant['token']);
    $bundle = releaseCampaign($studio, $operator, $reviewer, Demo::seed($studio, $admin));
    verify($bundle['manifest']['version'] === 1, 'Expected the published v1.0.0 manifest format.');
    $record = ['batch' => $bundle['batch'], 'bundle' => $bundle['id'], 'channel_token' => $grant['token'],
        'archive' => archiveProof($studio, $channel, $bundle), 'migrations' => count($migrations)];
    verify(file_put_contents($evidence, Values::json($record)) !== false, 'Could not save the isolated historical fixture.');
    return;
}

verify($mode === 'verify', 'Expected an upgrade verification phase.');
$history = Values::decode(file_get_contents($evidence));
verify($history['migrations'] === 3 && $migrations === [4], 'Expected to upgrade the three released migrations with the operations indexes.');
$studio->db->exec('DELETE FROM schema_migrations WHERE version=4');
verify($studio->db->migrate() === [4] && $studio->db->migrate() === [], 'A restart after index creation must repair the migration journal.');
$channel = $studio->access->authenticate('Bearer ' . $history['channel_token']);
$operator = identity($studio, $tenant, 'new-producer', 'operator');
$reviewer = identity($studio, $tenant, 'new-reviewer', 'reviewer');
$foreign = identity($studio, 'unrelated-upgrade-fixture', 'recipient', 'channel');
$bundle = $studio->delivery->preview($operator, $history['batch'], true);
verify($bundle['id'] === $history['bundle'] && $bundle['revision'] === 1 && $bundle['manifest']['version'] === 1,
    'Republishing an unchanged historical campaign must return the original bundle.');
verify(archiveProof($studio, $channel, $bundle) === $history['archive'], 'The restored archive changed.');
blocked(fn () => $studio->delivery->zip($foreign, $bundle['id']), 404);
$details = $studio->production->details($reviewer, $history['batch']);
verify(count($details['candidates']) === 12 && count($details['selections']) === 6, 'Restored candidate or selection history was lost.');
verify(in_array('bundle-published', array_column($studio->audit($operator, $bundle['id']), 'action'), true), 'Restored audit trail was lost.');
$new = releaseCampaign($studio, $operator, $reviewer, Demo::request('post-restore'));
verify($new['manifest']['version'] === 2 && $new['manifest']['entries']['coral:square']['candidate_costs']['replay_outputs'] === 2,
    'A new campaign on the restored database must support the current manifest format.');
verify(count(archiveProof($studio, $channel, $new)['images']) === 6, 'The restored database must deliver new packages.');
echo Values::json(['previous_commit' => $argv[4], 'backup_sha256' => $argv[5], 'previous_migrations' => $history['migrations'],
    'upgrade_migrations' => $migrations, 'previous_manifest_version' => $bundle['manifest']['version'],
    'previous_manifest_sha256' => $history['archive']['manifest_sha256'], 'previous_zip_sha256' => $history['archive']['zip_sha256'],
    'unchanged_restored_images' => count($history['archive']['images']), 'new_manifest_version' => $new['manifest']['version'],
    'restored_audit_verified' => true, 'foreign_download_blocked' => true, 'migration_retry_verified' => true]), PHP_EOL;
