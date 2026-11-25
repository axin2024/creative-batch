<?php

declare(strict_types=1);

namespace CreativeBatch;

final readonly class Scope
{
    public function __construct(public string $tenant, public string $actor, public string $role)
    {
    }

    public function requireRole(string ...$roles): void
    {
        if ($this->role !== 'admin' && !in_array($this->role, $roles, true)) {
            throw Problem::denied();
        }
    }
}
