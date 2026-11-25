<?php

declare(strict_types=1);

namespace CreativeBatch;

use RuntimeException;

final class Problem extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(422, 'invalid-input', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'state-conflict', $message);
    }

    public static function missing(): self
    {
        return new self(404, 'not-found', 'The requested object is unavailable.');
    }

    public static function denied(): self
    {
        return new self(403, 'forbidden', 'The credential does not have this permission.');
    }
}
