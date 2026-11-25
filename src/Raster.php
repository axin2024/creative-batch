<?php

declare(strict_types=1);

namespace CreativeBatch;

use GdImage;

final class Raster
{
    public const LAYOUTS = ['center', 'left', 'right'];
    public const FINISHES = ['studio', 'halo', 'diagonal'];

    public static function normalize(string $bytes): array
    {
        $image = self::decode($bytes);
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);
        $clean = self::encode($image);
        return ['digest' => hash('sha256', $clean), 'width' => imagesx($image), 'height' => imagesy($image), 'bytes' => $clean];
    }

    public static function product(string $label, string $tone): string
    {
        $base = self::canvas(320, 420);
        imagealphablending($base, false);
        $transparency = imagecolorallocatealpha($base, 0, 0, 0, 127);
        imagefill($base, 0, 0, $transparency);
        imagesavealpha($base, true);
        $body = self::rgb($tone);
        $dark = self::mix($body, [12, 14, 24], 0.62);
        $pale = self::mix($body, [255, 249, 235], 0.7);
        imagefilledpolygon($base, [51, 82, 236, 82, 272, 53, 87, 53], self::color($base, $pale));
        imagefilledpolygon($base, [236, 82, 272, 53, 272, 345, 236, 373], self::color($base, $dark));
        for ($x = 51; $x < 237; $x++) {
            imageline($base, $x, 82, $x, 373, self::color($base, self::mix($body, [255, 248, 235], (237 - $x) / 1150)));
        }
        imagefilledrectangle($base, 51, 276, 236, 320, self::color($base, [242, 234, 211]));
        imagefilledellipse($base, 143, 158, 78, 78, self::color($base, $pale));
        imagefilledpolygon($base, [143, 125, 175, 158, 143, 191, 111, 158], self::color($base, $dark));
        self::centered($base, 5, 143, 236, $label, self::color($base, [248, 241, 221]));
        self::centered($base, 3, 143, 289, 'ROASTED BLEND', self::color($base, [31, 29, 28]));
        return self::encode($base);
    }

    public static function render(array $unit, string $source, array $direction, bool $badPackaging = false): string
    {
        $sourceImage = self::decode($source);
        [$x, $y, $w, $h] = self::bounds($unit, $sourceImage, $direction['layout']);
        $out = self::canvas($unit['width'], $unit['height']);
        $background = self::rgb($unit['style']['background']);
        $accent = self::rgb($unit['style']['accent']);
        for ($row = 0; $row < $unit['height']; $row++) {
            $position = $row / max(1, $unit['height'] - 1);
            $mix = $direction['finish'] === 'studio' ? 0.12 * $position : 0.28 * $position;
            imageline($out, 0, $row, $unit['width'] - 1, $row, self::color($out, self::mix($background, $accent, $mix)));
        }
        $halo = self::color($out, self::mix($background, $accent, 0.24));
        if ($direction['finish'] === 'halo') {
            imagefilledellipse($out, $x + intdiv($w, 2), $y + intdiv($h, 2), (int) ($w * 1.28), (int) ($h * 1.1), $halo);
        }
        if ($direction['finish'] === 'diagonal') {
            imagefilledpolygon($out, [0, $unit['height'], $unit['width'], intdiv($unit['height'], 2),
                $unit['width'], $unit['height']], $halo);
        }
        $shadow = self::color($out, self::mix($background, [0, 0, 0], 0.25));
        imagefilledellipse($out, $x + intdiv($w, 2), $y + (int) ($h * 0.89), (int) ($w * 0.78), max(8, intdiv($h, 11)), $shadow);
        $scaled = self::scale($sourceImage, $w, $h);
        imagecopy($out, $scaled, $x, $y, 0, 0, $w, $h);
        if ($badPackaging) {
            imagefilledrectangle($out, $x + intdiv($w, 4), $y + intdiv($h, 2), $x + intdiv($w * 3, 4),
                $y + intdiv($h * 3, 4), $halo);
        }
        $contrast = array_sum($background) > 400 ? [34, 31, 33] : [245, 238, 218];
        $margin = (int) (min($unit['width'], $unit['height']) * $unit['margin'] / 100);
        $headline = $unit['style']['headline'];
        $font = imagefontwidth(5) * strlen($headline) <= $unit['width'] - 2 * $margin ? 5 : 2;
        $headline = substr($headline, 0, (int) floor(($unit['width'] - 2 * $margin) / imagefontwidth($font)));
        $ink = self::color($out, $contrast);
        imagestring($out, $font, $margin, $margin, $headline, $ink);
        imagestring($out, 2, $margin, $unit['height'] - $margin - 14, strtoupper($unit['color'] . ' / ' . $unit['channel']), $ink);
        return self::encode($out);
    }

    public static function quality(array $unit, string $source, string $result, string $layout): array
    {
        $output = self::decode($result);
        $dimensions = imagesx($output) === $unit['width'] && imagesy($output) === $unit['height'];
        if (!$dimensions) {
            return ['dimensions' => false, 'source_pixels' => false, 'sampled' => 0, 'changed' => 0];
        }
        $input = self::decode($source);
        [$x, $y, $w, $h] = self::bounds($unit, $input, $layout);
        $scaled = self::scale($input, $w, $h);
        $step = max(1, intdiv(min($w, $h), 160));
        $checked = $changed = 0;
        for ($row = 0; $row < $h; $row += $step) {
            for ($col = 0; $col < $w; $col += $step) {
                $color = imagecolorat($scaled, $col, $row);
                if (($color >> 24) !== 0) {
                    continue;
                }
                $checked++;
                $changed += (int) ($color !== imagecolorat($output, $x + $col, $y + $row));
            }
        }
        return ['dimensions' => true, 'source_pixels' => $checked >= 32 && $changed === 0, 'sampled' => $checked, 'changed' => $changed];
    }

    private static function bounds(array $unit, GdImage $input, string $layout): array
    {
        $wide = $unit['width'] > $unit['height'] * 1.2;
        $factor = min($unit['width'] * ($wide ? 0.50 : 0.76) / imagesx($input), $unit['height'] * 0.66 / imagesy($input));
        $w = max(1, (int) (imagesx($input) * $factor));
        $h = max(1, (int) (imagesy($input) * $factor));
        $offset = $wide ? 0.18 : 0.10;
        $center = match ($layout) { 'left' => 0.5 - $offset, 'right' => 0.5 + $offset, default => 0.5 };
        return [(int) ($unit['width'] * $center - $w / 2), (int) ($unit['height'] * 0.54 - $h / 2), $w, $h];
    }

    private static function scale(GdImage $image, int $width, int $height): GdImage
    {
        $scaled = self::canvas($width, $height);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        return $scaled;
    }

    private static function decode(string $bytes): GdImage
    {
        if (strlen($bytes) > 6 * 1024 * 1024 || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw Problem::invalid('Upload a PNG of at most 6 MiB.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 64 || $info[1] < 64 || $info[0] > 2048 || $info[1] > 2048) {
            throw Problem::invalid('Each PNG dimension must be between 64 and 2048 pixels.');
        }
        return @imagecreatefromstring($bytes) ?: throw Problem::invalid('The PNG could not be decoded.');
    }

    private static function encode(GdImage $image): string
    {
        ob_start();
        try {
            if (!imagepng($image, null, 5)) {
                throw new \RuntimeException('PNG encoding failed.');
            }
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private static function canvas(int $w, int $h): GdImage
    {
        return imagecreatetruecolor($w, $h) ?: throw new \RuntimeException('Cannot allocate image canvas.');
    }

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function mix(array $a, array $b, float $fromB): array
    {
        return array_map(static fn (int $x, int $y): int => (int) round($x * (1 - $fromB) + $y * $fromB), $a, $b);
    }

    private static function color(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, ...$rgb);
    }

    private static function centered(GdImage $image, int $font, int $x, int $y, string $text, int $color): void
    {
        imagestring($image, $font, $x - intdiv(imagefontwidth($font) * strlen($text), 2), $y, $text, $color);
    }
}
