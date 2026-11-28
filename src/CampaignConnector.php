<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignConnector
{
    public function binding(string $channel): array
    {
        $target = $this->target($channel);
        return ['kind' => $target === null ? 'filesystem' : 'http', 'channel' => $channel,
            'destination' => $target === null ? 'local-channel-spool' : hash('sha256', $target['url'])];
    }

    private function target(string $channel): ?array
    {
        try {
            $routes = Values::decode(getenv('CREATIVE_CAMPAIGN_CHANNELS') ?: '{}');
        } catch (\JsonException) {
            throw new Problem(503, 'channel-config-invalid', 'Invalid channel connector configuration.');
        }
        if (!is_array($routes)) {
            throw new Problem(503, 'channel-config-invalid', 'Invalid channel connector configuration.');
        }
        if (!array_key_exists($channel, $routes)) {
            return null;
        }
        $entry = $routes[$channel];
        $url = is_string($entry['url'] ?? null) ? rtrim($entry['url'], '/') : '';
        $token = $entry['token'] ?? '';
        $parsed = parse_url($url);
        if (($parsed['host'] ?? '') === '' || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query']) || isset($parsed['fragment']) ||
            (($parsed['scheme'] ?? '') !== 'https' && !(($parsed['scheme'] ?? '') === 'http' && in_array($parsed['host'], ['127.0.0.1', '[::1]'], true))) ||
            !is_string($token) || preg_match('/[\x00-\x1f\x7f]/D', $token)) {
            throw new Problem(503, 'channel-config-invalid', 'Configure HTTPS or a loopback channel endpoint.');
        }
        return ['url' => $url, 'token' => $token];
    }

    public function result(string $tenant, array $placement, string $operation, bool $write, ?string $png = null): ?array
    {
        $target = $this->target($placement['channel']);
        $binding = $placement['manifest']['connector'];
        if (CampaignData::digest($binding) !== CampaignData::digest($this->binding($placement['channel']))) {
            throw new Problem(409, 'channel-destination-changed', 'Restore the connector approved for this placement.');
        }
        $key = hash('sha256', implode(':', [$tenant, $placement['id'], $operation, $placement['manifest_digest']]));
        $payload = ['operation_id' => $key, 'placement_id' => $placement['id'], 'operation' => $operation,
            'channel' => $placement['channel'], 'manifest_digest' => $placement['manifest_digest']];
        if ($target === null) {
            return $this->local($tenant, $placement, $payload, $write, $png);
        }
        $result = $this->remote($target, $payload, $write ? $payload + [
            'manifest' => $placement['manifest'], 'image_base64' => $png === null ? null : base64_encode($png),
            'remote_revision' => $placement['remote_revision'],
        ] : null);
        if ($result === null) {
            return null;
        }
        if (!is_array($result) || array_diff_assoc($payload, array_intersect_key($result, $payload)) ||
            !is_string($result['remote_revision'] ?? null) || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,159}\z/D', $result['remote_revision']) ||
            ($result['state'] ?? null) !== ($operation === 'publish' ? 'online' : 'withdrawn')) {
            throw new Problem(503, 'channel-receipt-invalid', 'Query the original channel operation for its receipt.');
        }
        return $result;
    }

    private function local(string $tenant, array $placement, array $payload, bool $write, ?string $png): ?array
    {
        $root = getenv('CREATIVE_CHANNEL_ROOT') ?: dirname(__DIR__) . '/.local/channel-spool';
        $directory = rtrim($root, '/\\') . '/' . $tenant . '/' . $placement['channel'] . '/' . $placement['id'];
        if (!is_dir($directory)) {
            if (!$write) {
                return null;
            }
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new Problem(503, 'channel-spool-unavailable', 'The channel spool cannot be created.');
            }
        }
        $handle = fopen($directory . '/operation.lock', 'c+b');
        if ($handle === false) {
            throw new Problem(503, 'channel-spool-unavailable', 'The channel spool lock is unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new Problem(503, 'channel-spool-unavailable', 'The channel spool lock could not be acquired.');
            }
            $operation = $payload['operation'];
            $receipt = $directory . '/' . $operation . '.json';
            if (is_file($receipt)) {
                $value = Values::decode((string) file_get_contents($receipt));
                if (!is_array($value) || array_diff_assoc($payload, array_intersect_key($value, $payload)) || !is_string($value['remote_revision'] ?? null)) {
                    throw new Problem(503, 'channel-spool-invalid', 'The stored channel receipt differs from the original operation.');
                }
                return $value;
            }
            if (!$write) {
                return null;
            }
            $artifact = $directory . '/asset.png';
            if ($operation === 'publish') {
                if (is_file($directory . '/withdraw.json')) {
                    throw new Problem(422, 'channel-withdrawn', 'This placement already has a withdrawal receipt.');
                }
                if ($png === null || !hash_equals($placement['manifest']['image_sha256'], hash('sha256', $png))) {
                    throw new Problem(422, 'channel-asset-invalid', 'The staged bytes do not match the placement.');
                }
                self::atomic($artifact, $png);
            } elseif (is_file($artifact) && !unlink($artifact)) {
                throw new Problem(503, 'channel-spool-unavailable', 'The published image could not be withdrawn.');
            }
            $result = $payload + ['state' => $operation === 'publish' ? 'online' : 'withdrawn',
                'remote_revision' => 'local-' . substr($payload['operation_id'], 0, 24), 'ack_at' => gmdate(DATE_ATOM)];
            self::atomic($receipt, Values::json($result));
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private static function atomic(string $path, string $bytes): void
    {
        $temp = $path . '.' . Values::newId();
        try {
            if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temp, $path)) {
                throw new Problem(503, 'channel-spool-unavailable', 'The channel state could not be persisted.');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function remote(array $target, array $operation, ?array $payload): mixed
    {
        $path = '/v1/placements/' . $operation['placement_id'] . '/operations/' . $operation['operation'];
        $curl = curl_init($target['url'] . $path);
        $headers = ['Accept: application/json', 'Idempotency-Key: ' . $operation['operation_id']];
        if ($target['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $target['token'];
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => Values::json($payload)]);
        }
        $body = '';
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 2500, CURLOPT_TIMEOUT_MS => 10000,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$body): int {
                if (strlen($body) + strlen($part) > 64 * 1024) {
                    return 0;
                }
                $body .= $part;
                return strlen($part);
            }]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response !== false && $status === 404 && $payload === null) {
            return null;
        }
        if ($response !== false && $payload !== null && in_array($status, [400, 403, 422], true)) {
            throw new Problem(422, 'channel-declined', 'The connector rejected the operation. Review its configuration before retry.');
        }
        if ($response === false || !in_array($status, [200, 201], true)) {
            throw new Problem(503, 'channel-outcome-unknown', 'The operation receipt is pending. Query this identifier before any retry.');
        }
        try {
            return Values::decode($body);
        } catch (\JsonException) {
            throw new Problem(503, 'channel-receipt-invalid', 'The connector receipt could not be read.');
        }
    }
}
