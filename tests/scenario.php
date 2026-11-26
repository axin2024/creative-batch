<?php

declare(strict_types=1);

use CreativeBatch\Problem;
use CreativeBatch\Scope;
use CreativeBatch\Studio;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function blocked(callable $call, int $status, ?string $kind = null): void
{
    try {
        $call();
    } catch (Problem $error) {
        verify($error->status === $status && ($kind === null || $error->kind === $kind), 'Unexpected refusal: ' . $error->kind);
        return;
    }
    throw new RuntimeException('Expected request to be refused.');
}

function identity(Studio $studio, string $tenant, string $actor, string $role): Scope
{
    $grant = $studio->access->provision($tenant, $actor, $role, 2);
    return $studio->access->authenticate('Bearer ' . $grant['token']);
}

function approval(int $revision): array
{
    return ['expected_revision' => $revision, 'verdict' => 'approved', 'reason' => 'Reviewed the approved source side by side.',
        'checks' => array_fill_keys(['color', 'packaging', 'form', 'background', 'marks'], true)];
}

function sparseSource(Studio $studio, Scope $admin): array
{
    $transparent = imagecreatetruecolor(320, 420);
    imagealphablending($transparent, false);
    imagefill($transparent, 0, 0, imagecolorallocatealpha($transparent, 0, 0, 0, 127));
    imagesavealpha($transparent, true);
    imagefilledrectangle($transparent, 151, 202, 154, 205, imagecolorallocate($transparent, 190, 80, 65));
    ob_start();
    imagepng($transparent);
    $image = $studio->catalog->upload($admin, (string) ob_get_clean());
    $studio->catalog->grant($admin, 'reference-sparse', ['image_digest' => $image['digest'], 'grantor' => 'Sparse test fixture',
        'commercial' => true, 'channels' => ['square'], 'valid_from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400)]);
    $studio->catalog->revise($admin, 'variants', 'sparse', ['expected_revision' => 0, 'brand' => 'kite', 'brand_revision' => 1,
        'sku' => 'minimal-sku', 'color' => 'coral', 'rgb' => '#b94736', 'image_digest' => $image['digest'], 'right_id' => 'reference-sparse']);
    return $image;
}
