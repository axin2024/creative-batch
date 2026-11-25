<?php

declare(strict_types=1);

use CreativeBatch\Db;
use CreativeBatch\Problem;
use CreativeBatch\Studio;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'self\'; img-src \'self\' blob:; frame-ancestors \'none\'; base-uri \'none\'; form-action \'self\'');
header('Cache-Control: no-store');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$staticFiles = ['/' => ['workbench.html', 'text/html'], '/studio.css' => ['studio.css', 'text/css'],
    '/studio.js' => ['studio.js', 'text/javascript']];
if ($method === 'GET' && isset($staticFiles[$path])) {
    [$name, $type] = $staticFiles[$path];
    header("Content-Type: $type; charset=utf-8");
    readfile(__DIR__ . '/' . $name);
    return;
}

function body(int $limit): string
{
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        throw new Problem(413, 'input-too-large', 'The request exceeds the input limit.');
    }
    return $raw;
}

function payload(): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new Problem(415, 'unsupported-input', 'An application/json body is required.');
    }
    $raw = body(65536);
    $value = Values::decode($raw);
    if (!is_array($value) || !str_starts_with(ltrim($raw), '{')) {
        throw Problem::invalid('Expected a JSON object.');
    }
    return $value;
}

try {
    if (!is_string($path) || !str_starts_with($path, '/api/')) {
        throw Problem::missing();
    }
    $studio = new Studio(Db::connect());
    if ($method === 'GET' && $path === '/api/health') {
        $studio->db->one('SELECT 1 AS ok');
        $result = ['status' => 'ready'];
    } else {
        $who = $studio->access->authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
        $parts = explode('/', substr($path, 5));
        [$domain, $id, $action] = array_pad($parts, 3, '');
        if (count($parts) > 3) {
            throw Problem::missing();
        }
        $result = match ([$method, $domain, $id === '', $action]) {
            ['GET', 'session', true, ''] => ['tenant' => $who->tenant, 'actor' => $who->actor, 'role' => $who->role],
            ['GET', 'catalog', true, ''] => $studio->catalog->all($who),
            ['POST', 'images', true, ''] => $studio->catalog->upload($who, body(6 * 1024 * 1024)),
            ['PUT', 'brands', false, ''], ['PUT', 'channels', false, ''], ['PUT', 'templates', false, ''],
            ['PUT', 'variants', false, ''] => $studio->catalog->revise($who, $domain, $id, payload()),
            ['POST', 'rights', false, ''] => $studio->catalog->grant($who, $id, payload()),
            ['POST', 'rights', false, 'revoke'] => $studio->catalog->revoke($who, $id, Values::text(payload()['reason'] ?? null, 600)),
            ['POST', 'actors', false, 'revoke'] => ['updated' => $studio->access->revoke($who, $id)],
            ['GET', 'batches', true, ''] => $studio->production->batches($who),
            ['POST', 'batches', true, ''] => $studio->production->plan($who, payload()),
            ['GET', 'batches', false, ''] => $studio->production->details($who, $id),
            ['POST', 'batches', false, 'submit'] => $studio->production->submit($who, $id, Values::token(payload()['input_hash'] ?? null, 64)),
            ['GET', 'batches', false, 'preview'] => $studio->delivery->preview($who, $id, false),
            ['POST', 'batches', false, 'publish'] => $studio->delivery->preview($who, $id, true),
            ['GET', 'batches', false, 'bundles'] => $studio->delivery->bundles($who, $id),
            ['POST', 'tasks', false, 'resolve'] => $studio->production->resolve($who, $id, payload()['close_reason'] ?? null),
            ['POST', 'tasks', false, 'rework'] => rework($studio, $who, $id, payload()),
            ['POST', 'candidates', false, 'review'] => $studio->delivery->review($who, $id, payload()),
            ['POST', 'candidates', false, 'select'] => $studio->delivery->select($who, $id, payload()),
            ['GET', 'candidates', false, 'image'], ['GET', 'candidates', false, 'source'] => ['png' => $studio->delivery->compare($who, $id, $action === 'source')],
            ['GET', 'bundles', false, 'download'] => ['download' => $studio->delivery->zip($who, $id)],
            ['GET', 'audit', false, ''] => $studio->audit($who, $id),
            default => throw Problem::missing(),
        };
    }
    if (isset($result['png'])) {
        header('Content-Type: image/png');
        header('ETag: "' . $result['png']['digest'] . '"');
        echo $result['png']['bytes'];
    } elseif (isset($result['download'])) {
        $asset = $result['download'];
        try {
            header('Content-Type: application/zip');
            header('Content-Length: ' . filesize($asset['file']));
            header('Content-Disposition: attachment; filename="' . $asset['filename'] . '"');
            header('X-Creative-Receipt: ' . $asset['receipt']);
            header('X-Creative-Manifest: ' . $asset['digest']);
            readfile($asset['file']);
        } finally {
            unlink($asset['file']);
        }
    } else {
        send($result);
    }
} catch (Problem $problem) {
    http_response_code($problem->status);
    send(['error' => $problem->kind, 'message' => $problem->getMessage()]);
} catch (JsonException | TypeError) {
    http_response_code(422);
    send(['error' => 'invalid-input', 'message' => 'Review request field types and JSON syntax.']);
} catch (Throwable $error) {
    error_log('Creative Batch request failed: ' . get_class($error));
    http_response_code(500);
    send(['error' => 'internal-error', 'message' => 'The request could not be completed.']);
}

function send(array $value): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo Values::json($value);
}

function rework(Studio $studio, CreativeBatch\Scope $scope, string $id, array $input): array
{
    return $studio->production->rework($scope, $id, Values::text($input['reason'] ?? null, 600),
        Values::integer($input['expected_ordinal'] ?? null, 0, 127));
}
