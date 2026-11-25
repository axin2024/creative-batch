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
