<?php

declare(strict_types=1);

namespace CreativeBatch;

final class ImageBackend
{
    public static function configuration(): array
    {
        $endpoint = rtrim(getenv('CREATIVE_IMAGE_URL') ?: '', '/');
        $target = parse_url($endpoint);
        $host = $target['host'] ?? '';
        $scheme = $target['scheme'] ?? '';
        $model = getenv('CREATIVE_IMAGE_MODEL') ?: '';
        $token = getenv('CREATIVE_IMAGE_TOKEN') ?: '';
        if ($host === '' || isset($target['user']) || isset($target['pass']) || isset($target['query']) || isset($target['fragment']) ||
            ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['127.0.0.1', '[::1]'], true))) ||
            $model === '' || strlen($model) > 128 || preg_match('/[\x00-\x1f\x7f]/D', $model) || !preg_match('//u', $model) ||
            preg_match('/[\x00-\x1f\x7f]/D', $token)) {
            throw new Problem(503, 'provider-unavailable', 'Configure the image backend endpoint and model.');
        }
        return ['endpoint' => $endpoint, 'model' => $model, 'token' => $token];
    }

    public function result(array $binding, string $reference, ?array $input = null): ?array
    {
        $config = self::configuration();
        if ($config['model'] !== $binding['model'] || hash('sha256', $config['endpoint']) !== $binding['endpoint_sha256']) {
            throw new Problem(503, 'provider-model-changed', 'Restore the image backend configured for this batch.');
        }
        $headers = ['Accept: application/json'];
        if ($config['token'] !== '') {
            $headers[] = 'Authorization: Bearer ' . $config['token'];
        }
        $curl = curl_init($config['endpoint'] . '/v1/jobs/' . rawurlencode($reference));
        $body = '';
        if ($input !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => Values::json($input)]);
        }
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 2500, CURLOPT_TIMEOUT_MS => 20000,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$body): int {
                if (strlen($body) + strlen($part) > 8 * 1024 * 1024 + 8192) {
                    return 0;
                }
                $body .= $part;
                return strlen($part);
            }]);
        $sent = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($sent === false || $status === 429 || $status >= 500) {
            throw new Problem(503, 'provider-response-lost', 'The backend outcome is unknown. Query the original job.');
        }
        if ($status === 404 && $input === null) {
            throw new Problem(409, 'provider-job-missing', 'The backend has no receipt available for the original job.');
        }
        if (!in_array($status, [200, 201, 202], true)) {
            throw new Problem(503, 'provider-rejected', 'The backend rejected the image job.');
        }
        try {
            return $this->decode($body, $reference, $binding['model']);
        } catch (\JsonException | \TypeError) {
            throw new Problem(503, 'provider-invalid-output', 'The image job receipt could not be decoded.');
        }
    }

    private function decode(string $body, string $reference, string $model): ?array
    {
        $result = Values::decode($body);
        if (!is_array($result) || ($result['job_id'] ?? null) !== $reference || ($result['model'] ?? null) !== $model) {
            throw new Problem(503, 'provider-invalid-output', 'The image receipt identity or model did not match.');
        }
        if (in_array($result['state'] ?? null, ['queued', 'running'], true)) {
            return null;
        }
        if (($result['state'] ?? null) === 'failed') {
            throw new Problem(503, 'provider-failed', 'The original image job failed at the backend.');
        }
        $encoded = $result['png_base64'] ?? null;
        $png = is_string($encoded) ? base64_decode($encoded, true) : false;
        $expected = $result['png_sha256'] ?? null;
        $commercial = $result['commercial'] ?? null;
        $cost = $result['cost_micros'] ?? null;
        if (($result['state'] ?? null) !== 'completed' || $png === false || !is_string($expected) ||
            !hash_equals($expected, hash('sha256', $png)) || !is_bool($commercial) ||
            ($cost !== null && (!is_int($cost) || $cost < 0 || $cost > 1_000_000_000))) {
            throw new Problem(503, 'provider-invalid-output', 'The image receipt contents, digest or rights are invalid.');
        }
        return ['png' => $png, 'source_sha256' => $expected, 'commercial' => $commercial, 'reported_cost_micros' => $cost];
    }
}
