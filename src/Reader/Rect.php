<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

/**
 * A viewport-relative box in CSS pixels, as it was at read time.
 */
final readonly class Rect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $w,
        public int $h,
    ) {}

    /**
     * @param  array{x: int, y: int, w: int, h: int}  $rect
     */
    public static function fromArray(array $rect): self
    {
        return new self($rect['x'], $rect['y'], $rect['w'], $rect['h']);
    }
}
