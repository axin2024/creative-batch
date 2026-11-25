<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Demo
{
    public static function seed(Studio $studio, Scope $scope): array
    {
        $scope->requireRole();
        $channels = ['square' => [720, 720, '#eae1ce', '#bd7851', 'KITE / ROAST NO. 04'],
            'portrait' => [720, 960, '#1b3439', '#43827a', 'KITE / A BRIGHTER MORNING'],
            'banner' => [1200, 628, '#402b2a', '#e87f62', 'KITE / SMALL BATCH COFFEE']];
        $colors = ['coral' => '#b94736', 'coast' => '#286a7a'];
        $studio->catalog->revise($scope, 'brands', 'kite', ['expected_revision' => 0, 'name' => 'Kite Coffee',
            'packaging' => 'KITE / ROASTED BLEND on the source packaging', 'palette' => array_values($colors)]);
        foreach ($colors as $id => $color) {
            $image = $studio->catalog->upload($scope, Raster::product('KITE', $color));
            $studio->catalog->grant($scope, 'reference-' . $id, ['image_digest' => $image['digest'], 'grantor' => 'Kite Studio fixture',
                'commercial' => true, 'channels' => array_keys($channels), 'valid_from' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
                'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 30 * 86400)]);
            $studio->catalog->revise($scope, 'variants', $id, ['expected_revision' => 0, 'brand' => 'kite', 'brand_revision' => 1,
                'sku' => 'roast-04', 'color' => $id, 'rgb' => $color, 'image_digest' => $image['digest'], 'right_id' => 'reference-' . $id]);
        }
        foreach ($channels as $id => [$width, $height, $background, $accent, $headline]) {
            $studio->catalog->revise($scope, 'channels', $id, ['expected_revision' => 0, 'width' => $width, 'height' => $height, 'margin' => 7]);
            $studio->catalog->revise($scope, 'templates', $id, ['expected_revision' => 0, 'brand' => 'kite', 'brand_revision' => 1,
                'channel' => $id, 'channel_revision' => 1, 'background' => $background, 'accent' => $accent, 'headline' => $headline]);
        }
        return self::request('spring-release');
    }

    public static function request(string $campaign, string $provider = 'replay'): array
    {
        $ref = static fn (string $id): array => ['id' => $id, 'revision' => 1];
        return ['campaign' => $campaign, 'provider' => $provider, 'candidates_per_unit' => $provider === 'replay' ? 2 : 1,
            'ceiling_micros' => 0, 'starts_at' => gmdate('Y-m-d\TH:i:s\Z'), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 10 * 86400),
            'variants' => array_map($ref, ['coral', 'coast']), 'templates' => array_map($ref, ['square', 'portrait', 'banner'])];
    }
}
