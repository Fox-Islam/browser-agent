<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use GdImage;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Rect;

/**
 * A screenshot with each offered element in view outlined and tagged with its index, so the model
 * can match what it sees to the element table. Drawn on the captured image, never on the page:
 * marking the page would change what the decision is checked against and what the next reading
 * sees.
 */
final class MarkedScreenshot
{
    /** The API accepts 256 to 1568 pixels a side, inclusive, and rejects an image outside them. */
    public const int MIN_SIDE = 256;

    public const int MAX_SIDE = 1568;

    /** The API's pixel-count limits, inclusive. */
    public const int MIN_PIXELS = 78_400;

    public const int MAX_PIXELS = 2_458_624;

    private const string JPEG = "\xFF\xD8\xFF";

    /**
     * JPEG bytes, or null when the capture cannot be read or fitted to the API's limits. One
     * rejected image fails the whole request, so an image that does not fit is not sent.
     *
     * @param  array<string, array<string, Action>>  $targets  operation => target index => action
     */
    public static function of(string $jpeg, int $viewportWidth, array $targets): ?string
    {
        // Checked before GD reads it: GD warns on data it does not recognise.
        $image = $viewportWidth > 0 && str_starts_with($jpeg, self::JPEG) ? imagecreatefromstring($jpeg) : false;
        if ($image === false) {
            return null;
        }
        $image = self::fitted($image);
        if ($image === null) {
            return null;
        }
        self::mark($image, imagesx($image) / $viewportWidth, self::places($targets));
        ob_start();
        imageinterlace($image, false);
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * One rectangle per element index, from whichever of its actions has one. A select's options
     * are indexed under their element as `3:2`, and share its place.
     *
     * @param  array<string, array<string, Action>>  $targets
     * @return array<string, Rect>
     */
    public static function places(array $targets): array
    {
        $places = [];
        foreach ($targets as $candidates) {
            foreach ($candidates as $key => $action) {
                $index = explode(':', (string) $key)[0];
                if (! isset($places[$index]) && $action->rect !== null && $action->offscreen === null) {
                    $places[$index] = $action->rect;
                }
            }
        }

        return $places;
    }

    private static function fitted(GdImage $image): ?GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest > self::MAX_SIDE) {
            $scaled = imagescale($image, (int) round($width * self::MAX_SIDE / $longest), (int) round($height * self::MAX_SIDE / $longest));
            $image = $scaled === false ? $image : $scaled;
        }
        $pixels = imagesx($image) * imagesy($image);
        $fits = min(imagesx($image), imagesy($image)) >= self::MIN_SIDE && max(imagesx($image), imagesy($image)) <= self::MAX_SIDE
            && $pixels >= self::MIN_PIXELS && $pixels <= self::MAX_PIXELS;

        return $fits ? $image : null;
    }

    /**
     * @param  array<string, Rect>  $places
     */
    private static function mark(GdImage $image, float $scale, array $places): void
    {
        $ink = (int) imagecolorallocate($image, 255, 0, 170);
        $paper = (int) imagecolorallocate($image, 255, 255, 255);
        imagesetthickness($image, 2);
        $font = 3;
        foreach ($places as $index => $rect) {
            $left = (int) round($rect->x * $scale);
            $top = (int) round($rect->y * $scale);
            $right = (int) round(($rect->x + $rect->w) * $scale);
            $bottom = (int) round(($rect->y + $rect->h) * $scale);
            if ($right <= 0 || $bottom <= 0 || $left >= imagesx($image) || $top >= imagesy($image)) {
                continue;
            }
            imagerectangle($image, $left, $top, $right, $bottom, $ink);
            $tagLeft = max(0, $left);
            $tagTop = max(0, $top);
            $label = (string) $index;
            imagefilledrectangle($image, $tagLeft, $tagTop, $tagLeft + imagefontwidth($font) * mb_strlen($label) + 3, $tagTop + imagefontheight($font) + 1, $ink);
            imagestring($image, $font, $tagLeft + 2, $tagTop + 1, $label, $paper);
        }
    }
}
