<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignLayout
{
    public function __construct(private Db $db, private Catalog $catalog, private Campaigns $campaigns) {}

    public function render(Scope $scope, string $campaign, array $input): array
    {
        $scope->requireRole('designer', 'reviewer');
        $territory = CampaignData::territory($input['territory'] ?? null);
        $locale = CampaignData::locale($input['locale'] ?? null);
        $revision = Values::integer($input['segment_rev'] ?? null, 1, 1_000_000);
        $channel = Values::id($input['channel'] ?? null);
        $draft = $this->campaigns->localeVersion($scope, $campaign, $territory, $locale, $revision);
        $master = $this->campaigns->master($scope, $campaign, $draft['master_revision'])['snapshot'];
        $localized = $draft['snapshot'];
        $format = $this->db->one('SELECT id,revision,width,height,margin FROM channels WHERE tenant=? AND id=? ORDER BY revision DESC LIMIT 1',
            [$scope->tenant, $channel]) ?? throw Problem::missing();
        $font = $this->campaigns->font($scope, $localized['font'], true);
        if (!hash_equals($font['digest'], hash('sha256', $font['bytes']))) {
            throw Problem::conflict('Font bytes differ from the registered digest.');
        }
        $background = $localized['background']['source'];
        $layers = [];
        foreach (['product', 'logo'] as $layer) {
            $source = $master[$layer]['source'];
            $layers[$layer] = ['rect' => $master[$layer]['rect'], 'png' => base64_encode($this->imageBytes($scope, $source))];
        }
        $layoutInput = ['width' => $format['width'], 'height' => $format['height'], 'locale' => $locale, 'direction' => $localized['direction'],
            'background_color' => $localized['background']['color'], 'background' => $background === null ? null : base64_encode($this->imageBytes($scope, $background)),
            'font' => base64_encode($font['bytes']), 'ink' => $master['ink'], 'text_area' => $master['text_area'],
            'segments' => array_map(static fn(array $segment): array => [
                'id' => $segment['id'], 'kind' => $segment['kind'], 'text' => $localized['copy'][$segment['id']],
            ], $master['segments'])] + $layers;
        $raw = Values::json($layoutInput);
        $result = $this->backend($raw);
        if (($result['product_sha256'] ?? null) !== $master['product']['source'] || ($result['logo_sha256'] ?? null) !== $master['logo']['source'] ||
            ($result['direction'] ?? null) !== $localized['direction'] || ($result['product_transform'] ?? null) !== 'contain' ||
            !is_array($result['renderer'] ?? null) || !is_array($result['flags'] ?? null) || !is_array($result['lines'] ?? null)) {
            throw new Problem(502, 'layout-output-mismatch', 'The layout result does not match the frozen input.');
        }
        $bytes = base64_decode($result['png_base64'] ?? '', true);
        if (!is_string($bytes) || $bytes === '') {
            throw new Problem(502, 'layout-output-mismatch', 'The layout service returned invalid image bytes.');
        }
        unset($result['png_base64']);
        $image = Raster::normalize($bytes);
        if ($image['width'] !== $format['width'] || $image['height'] !== $format['height']) {
            throw new Problem(502, 'layout-output-mismatch', 'The rendered image dimensions do not match the channel.');
        }
        $sources = [
            'product' => ['type' => 'image', 'digest' => $master['product']['source']],
            'logo' => ['type' => 'image', 'digest' => $master['logo']['source']],
            'font' => ['type' => 'font', 'digest' => $font['digest']],
        ];
        if ($background !== null) {
            $sources['background'] = ['type' => 'image', 'digest' => $background];
        }
        $evidence = ['master_rev' => $draft['master_revision'], 'term_rev' => $draft['term_revision'], 'channel_revision' => $format['revision'],
            'sources' => $sources, 'font_license' => $font['license'], 'font_evidence' => $font['evidence'],
            'language_flags' => $localized['language_flags'], 'layout_flags' => $result['flags'], 'render_digest' => $image['digest']] + $result;
        $digest = hash('sha256', Values::json([$territory, $locale, $revision, $channel, $format['revision'], hash('sha256', $raw), $result['renderer']]));
        return $this->db->transaction(function () use ($scope, $campaign, $territory, $locale, $revision, $channel, $digest, $image, $evidence): array {
            $this->catalog->lockStudio($scope);
            $prior = $this->db->one('SELECT id FROM campaign_renders WHERE tenant=? AND campaign=? AND input_digest=?',
                [$scope->tenant, $campaign, $digest]);
            if ($prior !== null) {
                return $this->record($scope, $prior['id']);
            }
            $id = Values::newId();
            $this->catalog->saveImage($scope->tenant, $image);
            $this->db->exec('INSERT INTO campaign_renders(tenant,id,campaign,territory,locale,localization_revision,channel,input_digest,image_digest,evidence,actor)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)', [$scope->tenant, $id, $campaign, $territory, $locale, $revision, $channel, $digest,
                $image['digest'], Values::json($evidence), $scope->actor]);
            $this->db->audit($scope, 'campaign-rendered', $id, ['campaign' => $campaign, 'territory' => $territory, 'locale' => $locale,
                'render_digest' => $image['digest'], 'layout_flags' => $evidence['layout_flags']]);
            return $this->record($scope, $id);
        });
    }

    public function record(Scope $scope, string $id): array
    {
        $scope->requireRole('designer', 'reviewer', 'operator');
        $render = $this->db->one('SELECT * FROM campaign_renders WHERE tenant=? AND id=?', [$scope->tenant, Values::token($id)])
            ?? throw Problem::missing();
        return CampaignData::decodeRow($render, 'evidence');
    }

    public function listing(Scope $scope, string $campaign): array
    {
        $this->campaigns->workspace($scope, $campaign);
        return $this->db->all('SELECT id,territory,locale,channel,localization_revision,image_digest,created_at
            FROM campaign_renders WHERE tenant=? AND campaign=? ORDER BY created_at DESC,id DESC LIMIT 100',
            [$scope->tenant, $campaign]);
    }

    public function preview(Scope $scope, string $id): array
    {
        $render = $this->record($scope, $id);
        return $this->catalog->image($scope->tenant, $render['image_digest']);
    }

    private function imageBytes(Scope $scope, string $digest): string
    {
        $asset = $this->catalog->image($scope->tenant, $digest);
        if (!hash_equals($digest, hash('sha256', $asset['bytes']))) {
            throw Problem::conflict('The image bytes no longer match the frozen source.');
        }
        return $asset['bytes'];
    }

    private function backend(string $input): array
    {
        $endpoint = rtrim(getenv('CREATIVE_LAYOUT_URL') ?: '', '/');
        $token = getenv('CREATIVE_LAYOUT_TOKEN') ?: '';
        if (!preg_match('~\Ahttp://127\.0\.0\.1:[1-9]\d{3,4}\z~D', $endpoint) || strlen($token) < 16 ||
            preg_match('/[\x00-\x1f\x7f]/D', $token)) {
            throw new Problem(503, 'layout-unavailable', 'Configure the loopback layout endpoint and renderer token.');
        }
        $curl = curl_init($endpoint . '/render');
        $response = '';
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $input, CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 45000,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $part) use (&$response): int {
                if (strlen($response) + strlen($part) > 22 * 1024 * 1024) {
                    return 0;
                }
                $response .= $part;
                return strlen($part);
            }]);
        $sent = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status === 422 || $status === 413) {
            throw CampaignData::releaseField('render', 'The renderer rejected the supplied image, font or dimensions.');
        }
        if ($sent === false || $status !== 200) {
            throw new Problem(503, 'layout-unavailable', 'The layout service did not return an image.');
        }
        try {
            $value = Values::decode($response);
            return is_array($value) ? $value : throw new \TypeError();
        } catch (\JsonException | \TypeError) {
            throw new Problem(502, 'layout-output-mismatch', 'The renderer receipt is invalid.');
        }
    }
}
