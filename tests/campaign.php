<?php

declare(strict_types=1);

use CreativeBatch\CampaignData;
use CreativeBatch\Db;
use CreativeBatch\Demo;
use CreativeBatch\Raster;
use CreativeBatch\Studio;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

$studio = new Studio(Db::connect());
$studio->db->migrate();
$studio->db->migrate();
$admin = identity($studio, 'kite-regional', 'lead', 'admin');
$designer = identity($studio, 'kite-regional', 'design', 'designer');
$reviewer = identity($studio, 'kite-regional', 'legal', 'reviewer');
$operator = identity($studio, 'kite-regional', 'distribution', 'operator');
$outside = identity($studio, 'unrelated', 'design', 'admin');
Demo::seed($studio, $admin);
$studio->catalog->revise($admin, 'channels', 'email', ['expected_revision' => 0, 'width' => 960, 'height' => 480, 'margin' => 7]);
$product = $studio->catalog->upload($designer, Raster::product('KITE', '#b94736'))['digest'];
$logo = $studio->catalog->upload($designer, Raster::product('MARK', '#20384c'))['digest'];
$backdrop = $studio->catalog->upload($designer, Raster::product('ORIGIN', '#b3a692'))['digest'];
$fonts = [];
foreach (['sans', 'arabic'] as $name) {
    $bytes = file_get_contents(rtrim((string) getenv('CREATIVE_TEST_FONTS'), '/\\') . '/noto-' . $name . '.ttf');
    verify($bytes !== false, 'Load the pinned font for ' . $name);
    $fonts[$name] = $studio->campaigns->registerFont($designer, $bytes, 'Noto ' . $name, 'OFL-1.1', 'Pinned Google Fonts version')['digest'];
}
$campaign = $studio->campaigns->create($designer, ['name' => 'Kite Coffee · regional launch'])['id'];
blocked(fn() => $studio->campaigns->workspace($outside, $campaign), 404);
$master = $studio->campaigns->freeze($designer, $campaign, ['expected_revision' => 0, 'source_locale' => 'en',
    'selling_points' => ['Slow roast'], 'product' => ['source' => $product, 'rect' => [5700, 1200, 4000, 7500]],
    'logo' => ['source' => $logo, 'rect' => [350, 300, 1700, 1700]], 'allow_background' => true,
    'background' => ['color' => '#eae1ce'], 'ink' => '#20384c', 'text_area' => [450, 2500, 4800, 6700], 'segments' => [
        ['id' => 'title', 'kind' => 'headline', 'text' => 'Kite Coffee'],
        ['id' => 'legal', 'kind' => 'disclaimer', 'text' => 'Available while stocks last.'],
    ]]);
verify($master['revision'] === 1 && strlen($master['digest']) === 64, 'Freeze exact master revision.');
$languages = [
    'en' => ['Kite Coffee', 'Available while stocks last.'],
    'ar' => ['قهوة كايت', 'متوفر حتى نفاد الكمية.'],
    'fr' => ['Café Kite', 'Dans la limite des stocks.'],
    'de' => ['Kite Kaffee', 'Solange der Vorrat reicht.'],
    'es' => ['Café Kite', 'Hasta agotar existencias.'],
];
foreach ($languages as $locale => [$headline, $disclaimer]) {
    $studio->campaigns->setTerms($reviewer, $campaign, ['locale' => $locale, 'expected_revision' => 0,
        'required' => [['source' => 'Kite Coffee', 'target' => $headline]], 'banned' => ['miracle', 'guaranteed'],
        'disclaimers' => [['segment' => 'legal', 'text' => $disclaimer]]]);
}
$dates = static fn(int $offset): string => gmdate('Y-m-d\TH:i:s\Z', time() + $offset);
$channels = ['square', 'portrait', 'banner', 'email'];
$territories = ['US', 'AE', 'CA', 'AU', 'AT', 'BE', 'BR', 'CH', 'CL', 'DE', 'EG', 'ES', 'FR', 'GB', 'IE', 'JO', 'MX', 'NZ', 'QA', 'SA'];
$grants = [];
foreach (['product' => ['image', $product], 'logo' => ['image', $logo], 'backdrop' => ['image', $backdrop],
    'sans' => ['font', $fonts['sans']], 'arabic' => ['font', $fonts['arabic']]] as $id => [$type, $digest]) {
    $record = $studio->campaignRights->grant($admin, $id, ['expected_revision' => 0, 'asset_type' => $type, 'source_digest' => $digest,
        'valid_from' => $dates(-3600), 'valid_until' => $dates(7200), 'territories' => $territories, 'channels' => $channels,
        'evidence' => 'Regional rehearsal grant', 'licensor' => 'Kite demo', 'commercial' => true]);
    $grants[$id] = ['id' => $id, 'revision' => $record['revision']];
}
$expired = $studio->campaignRights->grant($admin, 'logo-expired', ['expected_revision' => 0, 'asset_type' => 'image', 'source_digest' => $logo,
    'valid_from' => $dates(-7200), 'valid_until' => $dates(-120), 'territories' => ['CA'], 'channels' => $channels,
    'evidence' => 'Expired license exercise', 'licensor' => 'Kite demo', 'commercial' => true]);
$restricted = $studio->campaignRights->grant($admin, 'logo-us', ['expected_revision' => 0, 'asset_type' => 'image', 'source_digest' => $logo,
    'valid_from' => $dates(-3600), 'valid_until' => $dates(7200), 'territories' => ['US'], 'channels' => $channels,
    'evidence' => 'United States license exercise', 'licensor' => 'Kite demo', 'commercial' => true]);
$schedule = ['starts_at' => $dates(-10), 'ends_at' => $dates(3600)];
$approve = static fn(array $picked): array => $schedule + ['grants' => $picked, 'reason' => 'Compared against the frozen master',
    'decision' => 'approved', 'expected_revision' => 0];
$evidence = getenv('CREATIVE_TEST_EVIDENCE') ?: dirname(__DIR__) . '/.local/campaign-evidence';
if (!is_dir($evidence)) {
    mkdir($evidence, 0700, true);
}
$modelRuns = [];
$result = [];
$elapsed = hrtime(true);
$capacity = in_array('--capacity', $argv, true);
$connectorState = !$capacity ? getenv('CREATIVE_TEST_CHANNEL_STATE') : false;
$matrix = [];
foreach ($capacity ? $territories : ['US', 'AE', 'CA'] as $territory) {
    foreach ($capacity ? array_keys($languages) : ['fr', 'ar'] as $locale) {
        $matrix[] = [$territory, $locale];
    }
}
foreach ($matrix as [$territory, $locale]) {
    [$headline, $disclaimer] = $languages[$locale];
    $font = $locale === 'ar' ? 'arabic' : 'sans';
    $draft = ['expected_revision' => 0, 'master_rev' => 1, 'term_rev' => 1, 'territory' => $territory, 'locale' => $locale,
        'font' => $fonts[$font], 'copy' => ['title' => $headline, 'legal' => $disclaimer], 'reason' => 'Regional localization'];
    $useBackdrop = !$capacity && $locale === 'fr';
    if ($useBackdrop) {
        $draft['background'] = ['source' => $backdrop, 'color' => '#eae1ce'];
    }
    if (!$capacity) {
        blocked(fn() => $studio->campaigns->localize($designer, $campaign, $draft + ['product' => ['source' => $logo]], false), 422, 'release-blocked');
        blocked(fn() => $studio->campaigns->localize($designer, $campaign, $draft + ['logo' => ['source' => $product]], false), 422, 'release-blocked');
    }
    $modelIndex = null;
    if (!$capacity && getenv('CREATIVE_TRANSLATION_URL') && $territory === 'AE') {
        $suggestion = $studio->campaigns->localize($designer, $campaign, $draft, true);
        $modelIndex = count($modelRuns);
        $modelRuns[] = ['territory' => $territory, 'locale' => $locale, 'suggestion' => $suggestion];
        $draft['expected_revision'] = $suggestion['revision'];
    }
    $render = ['territory' => $territory, 'locale' => $locale];
    if (!$capacity && (($territory === 'US' && $locale === 'fr') || ($territory === 'AE' && $locale === 'ar'))) {
        $broken = $draft;
        if ($territory === 'US') {
            $broken['copy']['title'] .= ' miracle guaranteed' . str_repeat(' extra text', 90);
            $broken['copy']['legal'] = 'Limited edition.';
        } else {
            $broken['font'] = $fonts['sans'];
        }
        $bad = $studio->campaigns->localize($designer, $campaign, $broken, false);
        $badRender = $studio->campaignLayout->render($designer, $campaign, $render + ['segment_rev' => $bad['revision'], 'channel' => 'square']);
        blocked(fn() => $studio->campaignRights->approve($reviewer, $badRender['id'], $approve([])), 422, 'release-blocked');
        $layoutFlags = array_column($badRender['evidence']['layout_flags'], 'code');
        if ($territory === 'US') {
            $languageFlags = array_count_values(array_column($badRender['evidence']['language_flags'], 'code'));
            verify(($languageFlags['banned-term'] ?? 0) === 2 && isset($languageFlags['disclaimer-mismatch']) &&
                in_array('safe-area-overflow', $layoutFlags, true), 'Block banned terms, missing approved disclaimer and overflow.');
        } else {
            verify(in_array('missing-glyph', $layoutFlags, true), 'Block unrenderable Arabic characters.');
        }
        $result['blocked'][$territory] = $badRender['evidence'];
        $draft['expected_revision'] = $bad['revision'];
    }
    $final = $studio->campaigns->localize($designer, $campaign, $draft, false);
    if ($modelIndex !== null) {
        $original = $modelRuns[$modelIndex]['suggestion']['snapshot']['copy'];
        $modelRuns[$modelIndex]['final'] = $final;
        $modelRuns[$modelIndex]['edited_segments'] = count(array_diff_assoc($final['snapshot']['copy'], $original));
    }
    foreach ($capacity ? $channels : ['square', 'portrait'] as $channel) {
        $asset = $studio->campaignLayout->render($designer, $campaign, $render + ['segment_rev' => $final['revision'], 'channel' => $channel]);
        $picked = [$grants['product'], $grants['logo'], $grants[$font]];
        if ($useBackdrop) {
            $picked[] = $grants['backdrop'];
        }
        if (!$capacity && $territory === 'CA' && $locale === 'fr' && $channel === 'square') {
            $bad = $picked;
            $bad[1] = ['id' => 'logo-expired', 'revision' => $expired['revision']];
            $preview = $studio->campaignRights->preview($reviewer, $asset['id'], $approve($bad));
            verify(array_column($preview['blocked_regions'], 'code') === ['ends-after-grant'], 'Explain the expired Canadian license.');
            verify(array_column($preview['blocked_regions'][0]['replacement_grants'], 'id') === ['logo'], 'Locate a grant that covers the same asset, region and schedule.');
            $result['blocked']['CA'] = $preview;
            $bad[1] = ['id' => 'logo-us', 'revision' => $restricted['revision']];
            $preview = $studio->campaignRights->preview($reviewer, $asset['id'], $approve($bad));
            verify(array_column($preview['blocked_regions'], 'code') === ['territory-excluded'], 'Reject a US grant for a Canadian placement.');
            blocked(fn() => $studio->campaignRights->approve($reviewer, $asset['id'], $approve($bad)), 422, 'release-blocked');
            $result['blocked']['CA_wrong_territory'] = $preview;
        }
        $approval = $studio->campaignRights->approve($reviewer, $asset['id'], $approve($picked));
        $request = $render + ['channel' => $channel, 'asset_rev' => $asset['id'], 'rights_rev' => $approval['rights_rev']];
        $key = strtolower($territory) . '-' . $locale . '-' . $channel;
        $receipt = $studio->campaignPlacements->submit($operator, $campaign, $key, $request);
        verify($receipt['delivery_state'] === 'pending' && CampaignData::digest($receipt['manifest']) === $receipt['manifest_digest'], 'Verify manifest integrity.');
        $again = $studio->campaignPlacements->submit($operator, $campaign, $key, $request);
        verify($again['id'] === $receipt['id'] && $again['replayed'], 'Replay same placement.');
        blocked(fn() => $studio->campaignPlacements->submit($operator, $campaign, $key, array_replace($request, ['rights_rev' => str_repeat('0', 64)])), 409);
        if (!$capacity && $channel === 'square') {
            file_put_contents($evidence . '/' . $territory . '-' . $locale . '.png', $studio->campaignLayout->preview($operator, $asset['id'])['bytes']);
        }
        $result['placements'][$key] = ['id' => $receipt['id'], 'asset' => $asset['id'], 'rights_rev' => $approval['rights_rev'],
            'manifest_digest' => $receipt['manifest_digest'], 'image_sha256' => $asset['image_digest'],
            'language_flags' => $asset['evidence']['language_flags'], 'layout_flags' => $asset['evidence']['layout_flags']];
    }
}
$result['prepare_ms'] = (hrtime(true) - $elapsed) / 1e6;
while ($studio->campaignDispatcher->step($operator) !== null) {
}
if ($connectorState) {
    $remoteID = $result['placements']['us-fr-square']['id'];
    $uncertain = $studio->campaignPlacements->record($operator, $remoteID);
    verify($uncertain['delivery_state'] === 'uncertain', 'Lost response must leave the original operation uncertain.');
    $studio->campaignPlacements->retry($operator, $remoteID, 'publish');
    while ($studio->campaignDispatcher->step($operator) !== null) {
    }
    $result['connector']['unacknowledged'] = $uncertain;
}
foreach ($result['placements'] as $placement) {
    $delivery = $studio->campaignPlacements->record($operator, $placement['id']);
    verify($delivery['delivery_state'] === 'online' && $delivery['operations'][0]['remote_receipt']['state'] === 'online', 'Read durable publication receipt.');
}
$started = hrtime(true);
$recall = $studio->campaignRecalls->revoke($admin, ['asset_type' => 'image', 'source_digest' => $capacity ? $product : $backdrop,
    'reason' => 'Rehearse regional recall']);
$result['recall_plan_ms'] = (hrtime(true) - $started) / 1e6;
verify(count($recall['placements']) === ($capacity ? 400 : 6) && $recall['state'] === 'withdrawing', 'List all affected channel owners.');
verify($result['recall_plan_ms'] <= 15000, 'Build the affected-placement list within 15 seconds.');
while ($studio->campaignDispatcher->step($operator) !== null) {
}
$recall = $studio->campaignRecalls->record($operator, $recall['recall_id']);
if ($connectorState) {
    verify($recall['state'] === 'partially-withdrawn' && count($recall['open_receipts']) === 1 &&
        $recall['open_receipts'][0]['placement_id'] === $remoteID && $recall['open_receipts'][0]['owner'] !== '' &&
        $recall['open_receipts'][0]['last_failure'] === 'channel-declined', 'Keep the still-online channel, owner and failure visible.');
    $result['connector']['partially_withdrawn'] = $recall;
    file_put_contents($connectorState . '/allow-withdraw', 'release the rehearsal hold');
    $studio->campaignPlacements->retry($operator, $remoteID, 'withdraw');
    while ($studio->campaignDispatcher->step($operator) !== null) {
    }
    $recall = $studio->campaignRecalls->record($operator, $recall['recall_id']);
    $events = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($connectorState . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    $requests = array_values(array_filter($events, static fn(array $event): bool => $event['placement'] === $remoteID));
    $publish = array_values(array_filter($requests, static fn(array $event): bool => $event['operation'] === 'publish'));
    verify(array_column($publish, 'method') === ['GET', 'PUT', 'GET'] && count(array_unique(array_column($publish, 'key'))) === 1,
        'Lost acknowledgement must be recovered by its original operation identity without a second PUT.');
    $result['connector']['requests'] = $requests;
}
verify($recall['state'] === 'withdrawn' && $recall['open_receipts'] === [], 'Read every withdrawal receipt.');
if (!$capacity) {
    foreach ($result['placements'] as $key => $placement) {
        $expected = str_contains($key, '-fr-') ? 'withdrawn' : 'online';
        verify($studio->campaignPlacements->record($operator, $placement['id'])['delivery_state'] === $expected,
            'Leave placements using other source layers online.');
    }
}
$result['recall_elapsed_ms'] = (hrtime(true) - $started) / 1e6;
$result['recall'] = $recall;
$result['real_model'] = $modelRuns;
if ($connectorState) {
    $regional = ['territory' => 'DE', 'locale' => 'fr'];
    $locale = $studio->campaigns->localize($designer, $campaign, $regional + ['expected_revision' => 0, 'master_rev' => 1, 'term_rev' => 1,
        'font' => $fonts['sans'], 'copy' => ['title' => $languages['fr'][0], 'legal' => $languages['fr'][1]], 'reason' => 'Four-channel rehearsal'], false);
    $deliveries = [];
    foreach ($channels as $channel) {
        $asset = $studio->campaignLayout->render($designer, $campaign, $regional + ['segment_rev' => $locale['revision'], 'channel' => $channel]);
        $approval = $studio->campaignRights->approve($reviewer, $asset['id'], $approve([$grants['product'], $grants['logo'], $grants['sans']]));
        $delivery = $studio->campaignPlacements->submit($operator, $campaign, 'de-fr-' . $channel, $regional +
            ['channel' => $channel, 'asset_rev' => $asset['id'], 'rights_rev' => $approval['rights_rev']]);
        $deliveries[$channel] = $delivery['id'];
    }
    while ($studio->campaignDispatcher->step($operator) !== null) {
    }
    foreach ($deliveries as $channel => $id) {
        $state = $studio->campaignPlacements->record($operator, $id)['delivery_state'];
        verify($state === ($channel === 'square' ? 'uncertain' : 'online'), 'Only the unacknowledged square placement needs recovery.');
        $result['connector']['four_channels']['before'][$channel] = $state;
    }
    $studio->campaignPlacements->retry($operator, $deliveries['square'], 'publish');
    while ($studio->campaignDispatcher->step($operator) !== null) {
    }
    $events = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($connectorState . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    foreach ($deliveries as $channel => $id) {
        $state = $studio->campaignPlacements->record($operator, $id)['delivery_state'];
        $requests = array_values(array_filter($events, static fn(array $event): bool => $event['placement'] === $id));
        $methods = array_column($requests, 'method');
        verify($state === 'online' && $methods === ($channel === 'square' ? ['GET', 'PUT', 'GET'] : ['GET', 'PUT']),
            'Reconcile only the missing receipt using the original operation.');
        $result['connector']['four_channels']['after'][$channel] = ['placement' => $id, 'state' => $state, 'requests' => $methods];
    }
}
if (!$capacity && getenv('CREATIVE_TEST_WEB_ORIGIN')) {
    $credentials = $studio->access->provision('kite-regional', 'browser-reviewer', 'reviewer', 1);
    $external = $studio->access->provision('unrelated', 'browser-visitor', 'reviewer', 1);
    $environment = getenv() + ['CREATIVE_BROWSER_TOKEN' => $credentials['token'], 'CREATIVE_BROWSER_FOREIGN' => $external['token'],
        'CREATIVE_BROWSER_RECALL' => $recall['recall_id']];
    $browser = proc_open(['node', __DIR__ . '/campaign-browser.mjs'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes, dirname(__DIR__), $environment);
    verify(is_resource($browser), 'Start the isolated campaign workbench browser.');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    verify(proc_close($browser) === 0, 'The regional workbench did not pass the browser rehearsal.');
    $result['browser'] = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}
file_put_contents($evidence . '/results.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo count($result['placements']), ' placements and withdrawals verified, ', count($modelRuns), " backend translations\n";
