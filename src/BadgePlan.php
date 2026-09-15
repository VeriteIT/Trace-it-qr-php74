<?php

/*
 * GENERATED — PHP 7.4 build. Do not edit.
 * Source: src/BadgePlan.php  ·  Regenerate: php tools/build-php74.php
 */

declare (strict_types=1);
namespace VeriteIt\TraceItQr;

/** The resolved geometry of one badge. Output of Layout::plan(). */
final class BadgePlan
{
    public bool $fits;
    public int $qrWidth;
    public int $qrHeight;
    public int $plateWidth;
    public int $plateHeight;
    public int $platePadding;
    public int $x;
    public int $y;
    public int $radius;
    public bool $plate;
    public ?string $reason;
    public function __construct(bool $fits, int $qrWidth = 0, int $qrHeight = 0, int $plateWidth = 0, int $plateHeight = 0, int $platePadding = 0, int $x = 0, int $y = 0, int $radius = 0, bool $plate = false, ?string $reason = null)
    {
        $this->fits = $fits;
        $this->qrWidth = $qrWidth;
        $this->qrHeight = $qrHeight;
        $this->plateWidth = $plateWidth;
        $this->plateHeight = $plateHeight;
        $this->platePadding = $platePadding;
        $this->x = $x;
        $this->y = $y;
        $this->radius = $radius;
        $this->plate = $plate;
        $this->reason = $reason;
    }
    public static function doesNotFit(string $reason): self
    {
        return new self(false, 0, 0, 0, 0, 0, 0, 0, 0, false, $reason);
    }
    /**
     * True when the badge box lies entirely within the image.
     *
     * Exposed so callers can assert it in their own tests. The bug this guards
     * against produced negative coordinates, which GD draws without complaint.
     */
    public function isInside(int $imageWidth, int $imageHeight): bool
    {
        return $this->fits && $this->x >= 0 && $this->y >= 0 && $this->x + $this->plateWidth <= $imageWidth && $this->y + $this->plateHeight <= $imageHeight;
    }
}
