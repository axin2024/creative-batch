<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Access
{
    public function __construct(private Db $db)
    {
    }

    public function provision(string $tenant, string $actor, string $role, int $hours): array
    {
        $tenant = Values::id($tenant);
        $actor = Values::id($actor);
        $role = Values::choose($role, ['admin', 'designer', 'operator', 'reviewer', 'channel']);
        $hours = Values::integer($hours, 1, 744);
        return $this->db->transaction(function () use ($tenant, $actor, $role, $hours): array {
            $this->db->exec('INSERT IGNORE INTO studios(tenant) VALUES (?)', [$tenant]);
            $token = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
            $this->db->exec('INSERT INTO access_keys(digest,tenant,actor,role,expires_at) VALUES(?,?,?,?,?)',
                [hash('sha256', $token), $tenant, $actor, $role, $expires]);
            $this->db->audit(new Scope($tenant, $actor, $role), 'credential-issued', $actor, ['role' => $role]);
            return ['tenant' => $tenant, 'actor' => $actor, 'role' => $role, 'expires_at' => $expires, 'token' => $token];
        });
    }

    public function authenticate(?string $header): Scope
    {
        if ($header === null || preg_match('/\ABearer ([0-9a-f]{64})\z/D', $header, $parts) !== 1) {
            throw new Problem(401, 'credential-required', 'Provide a valid bearer credential.');
        }
        $row = $this->db->one('SELECT tenant,actor,role FROM access_keys
            WHERE digest=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP(6)', [hash('sha256', $parts[1])]);
        if ($row === null) {
            throw new Problem(401, 'credential-invalid', 'The credential has expired or is unavailable.');
        }
        return new Scope($row['tenant'], $row['actor'], $row['role']);
    }

    public function revoke(Scope $scope, string $actor): int
    {
        $scope->requireRole();
        $actor = Values::id($actor);
        return $this->db->transaction(function () use ($scope, $actor): int {
            $updated = $this->db->exec('UPDATE access_keys SET revoked_at=UTC_TIMESTAMP(6)
                WHERE tenant=? AND actor=? AND revoked_at IS NULL', [$scope->tenant, $actor]);
            $this->db->audit($scope, 'credentials-revoked', $actor, ['credential_count' => $updated]);
            return $updated;
        });
    }
}
