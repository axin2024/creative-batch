<?php

declare(strict_types=1);

$root = getenv('CREATIVE_TEST_CHANNEL_STATE');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!$root || !preg_match('~\A/v1/placements/([a-f0-9]{32})/operations/(publish|withdraw)\z~D', $path, $match)) {
    http_response_code(404);
    return;
}
[, $placement, $operation] = $match;
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if (!preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
    http_response_code(403);
    return;
}
$file = "$root/$placement-$operation.json";
file_put_contents("$root/requests.jsonl", json_encode(compact('placement', 'operation', 'key', 'method')) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if (is_file($file)) {
    $prior = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    if ($prior['operation_id'] !== $key) {
        http_response_code(409);
        return;
    }
    echo json_encode($prior);
    return;
}
if ($method === 'GET') {
    http_response_code(404);
    return;
}
if ($method !== 'PUT') {
    http_response_code(405);
    return;
}
$payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
$manifest = $payload['manifest'] ?? [];
if (($payload['operation_id'] ?? '') !== $key || ($payload['placement_id'] ?? '') !== $placement ||
    ($payload['operation'] ?? '') !== $operation || ($manifest['placement_id'] ?? '') !== $placement ||
    ($operation === 'publish' && ($manifest['image_sha256'] ?? null) !== hash('sha256', (string) base64_decode($payload['image_base64'] ?? '', true)))) {
    http_response_code(422);
    return;
}
$delayed = in_array($manifest['territory'] ?? '', ['US', 'DE'], true) && ($manifest['locale'] ?? '') === 'fr'
    && ($payload['channel'] ?? '') === 'square';
if ($operation === 'withdraw' && $delayed && $manifest['territory'] === 'US' && !is_file("$root/allow-withdraw")) {
    http_response_code(422);
    return;
}
$receipt = array_intersect_key($payload, array_flip(['operation_id', 'placement_id', 'operation', 'channel', 'manifest_digest'])) +
    ['state' => $operation === 'publish' ? 'online' : 'withdrawn', 'remote_revision' => 'fixture-' . substr($key, 0, 24)];
file_put_contents($file, json_encode($receipt), LOCK_EX);
if ($operation === 'publish' && $delayed) {
    http_response_code(503);
    return;
}
http_response_code(201);
echo json_encode($receipt);
