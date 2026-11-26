<?php

declare(strict_types=1);

use CreativeBatch\Raster;
use CreativeBatch\Values;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/scenario.php';

function bottle(string $color): string
{
    $image = imagecreatetruecolor(320, 420);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    $tone = hexdec($color);
    imagefilledrectangle($image, 121, 20, 200, 65, 0x172735);
    imagefilledrectangle($image, 140, 66, 181, 156, $tone);
    imagefilledellipse($image, 160, 177, 105, 100, $tone);
    imagefilledrectangle($image, 108, 177, 212, 358, $tone);
    imagefilledellipse($image, 160, 358, 105, 65, $tone);
    imagefilledrectangle($image, 109, 216, 211, 287, 0xf3efe3);
    imagestring($image, 5, 133, 238, 'BRACKEN', 0x172735);
    ob_start();
    imagepng($image);
    return (string) ob_get_clean();
}

function differenceHash(string $png): string
{
    $source = imagecreatefromstring($png);
    $small = imagecreatetruecolor(9, 8);
    imagefill($small, 0, 0, 0xffffff);
    imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
    imagefilter($small, IMG_FILTER_GRAYSCALE);
    $bits = '';
    for ($row = 0; $row < 8; $row++) {
        for ($column = 0; $column < 8; $column++) {
            $bits .= imagecolorat($small, $column, $row) > imagecolorat($small, $column + 1, $row) ? '1' : '0';
        }
    }
    return $bits;
}

$fixtures = [
    ['product_group' => 'kite-coffee', 'split' => 'development', 'variant' => 'coral', 'png' => Raster::product('KITE', '#b94736'), 'mutate' => false],
    ['product_group' => 'kite-coffee', 'split' => 'development', 'variant' => 'coast', 'png' => Raster::product('KITE', '#286a7a'), 'mutate' => true],
    ['product_group' => 'bracken-bottle', 'split' => 'validation', 'variant' => 'navy', 'png' => bottle('2e5366'), 'mutate' => false],
    ['product_group' => 'bracken-bottle', 'split' => 'validation', 'variant' => 'leaf', 'png' => bottle('408654'), 'mutate' => true],
];
$partitions = $evidence = [];
foreach ($fixtures as $fixture) {
    $group = $fixture['product_group'];
    $split = $fixture['split'];
    $png = $fixture['png'];
    $digest = hash('sha256', $png);
    $fingerprint = differenceHash($png);
    verify(($partitions[$group] ?? $split) === $split, 'A product group spans the evaluation partitions.');
    $partitions[$group] = $split;
    foreach ($evidence as $prior) {
        if ($prior['split'] !== $split) {
            verify($prior['source_sha256'] !== $digest && substr_count($prior['difference_hash'] ^ $fingerprint, "\x01") > 6,
                'A duplicate or near-duplicate reference crossed the evaluation partition.');
        }
    }
    $unit = ['width' => 720, 'height' => 720, 'margin' => 7, 'color' => $fixture['variant'], 'channel' => 'square',
        'style' => ['background' => '#eae1ce', 'accent' => '#bd7851', 'headline' => 'CATALOG FIXTURE']];
    $render = Raster::render($unit, $png, ['layout' => 'center', 'finish' => 'studio'], $fixture['mutate']);
    $quality = Raster::quality($unit, $png, $render, 'center');
    $expected = $fixture['mutate'] ? 'failed' : 'passed';
    verify($quality['dimensions'] && $quality['source_check'] === $expected, 'Unexpected protected pixels result.');
    $evidence[] = ['product_group' => $group, 'split' => $split, 'variant' => $fixture['variant'], 'source_sha256' => $digest,
        'difference_hash' => $fingerprint, 'expected_source_check' => $expected, 'source_check' => $quality['source_check']];
}
echo Values::json(['mode' => 'synthetic-offline', 'product_groups' => count($partitions), 'cases' => $evidence]), PHP_EOL;
