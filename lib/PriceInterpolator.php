<?php

namespace Prospektweb\PropModificator;

use Prospektweb\PropModificator\Domain\FieldMode\FieldModeHandlerInterface;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\FormatFieldModeHandler;
use Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode\VolumeFieldModeHandler;

/**
 * Pure interpolation math for offer points.
 *
 * Input: offer points [{width,height,volume,price}, ...] and requested custom params.
 * Output: interpolated price or null.
 */
class PriceInterpolator
{
    private FieldModeHandlerInterface $formatHandler;
    private FieldModeHandlerInterface $volumeHandler;

    public function __construct(?FieldModeHandlerInterface $formatHandler = null, ?FieldModeHandlerInterface $volumeHandler = null)
    {
        $this->formatHandler = $formatHandler ?? new FormatFieldModeHandler();
        $this->volumeHandler = $volumeHandler ?? new VolumeFieldModeHandler();
    }

    public function interpolatePoints(array $offerPoints, ?int $width, ?int $height, ?int $volume): ?float
    {
        $customFormat = $this->formatHandler->hasCustomInput($width, $height, $volume);
        $customVolume = $this->volumeHandler->hasCustomInput($width, $height, $volume);

        if (!$customFormat && !$customVolume) {
            return null;
        }
        if ($customFormat && $customVolume) {
            return $this->bilinearInterpolatePoints($offerPoints, $width, $height, $volume);
        }

        $handler = $customFormat ? $this->formatHandler : $this->volumeHandler;
        $value = $handler->resolveLinearValue($width, $height, $volume);
        if ($value === null) {
            return null;
        }

        return $this->linearInterp($handler->extractLinearPoints($offerPoints), $value);
    }

    private function bilinearInterpolatePoints(array $offerPoints, ?int $width, ?int $height, ?int $volume): ?float
    {
        $area = $this->formatHandler->resolveLinearValue($width, $height, $volume);
        if ($area === null || $volume === null) {
            return null;
        }

        $points = array_filter($offerPoints, fn($o) => $o['width'] !== null && $o['height'] !== null && $o['volume'] !== null && isset($o['price']));
        if (empty($points)) {
            return $this->linearInterp($this->formatHandler->extractLinearPoints($offerPoints), $area);
        }

        foreach ($points as &$o) {
            $o['area'] = $o['width'] * $o['height'];
        }
        unset($o);

        $areas = array_unique(array_column($points, 'area'));
        $volumes = array_unique(array_column($points, 'volume'));
        sort($areas);
        sort($volumes);

        [$areaLow, $areaHigh] = $this->findNeighbors($areas, $area);
        [$volumeLow, $volumeHigh] = $this->findNeighbors($volumes, $volume);

        if ($areaLow === $areaHigh) {
            return $this->lerp(
                (float)$this->findClosestPoint($points, $areaLow, $volumeLow),
                (float)$this->findClosestPoint($points, $areaLow, $volumeHigh),
                $volumeLow,
                $volumeHigh,
                $volume
            );
        }

        if ($volumeLow === $volumeHigh) {
            return $this->lerp(
                (float)$this->findClosestPoint($points, $areaLow, $volumeLow),
                (float)$this->findClosestPoint($points, $areaHigh, $volumeLow),
                $areaLow,
                $areaHigh,
                $area
            );
        }

        $q11 = $this->findClosestPoint($points, $areaLow, $volumeLow);
        $q12 = $this->findClosestPoint($points, $areaLow, $volumeHigh);
        $q21 = $this->findClosestPoint($points, $areaHigh, $volumeLow);
        $q22 = $this->findClosestPoint($points, $areaHigh, $volumeHigh);

        if ($q11 === null || $q12 === null || $q21 === null || $q22 === null) {
            return $this->linearInterp($this->formatHandler->extractLinearPoints($offerPoints), $area);
        }

        $r1 = $this->lerp($q11, $q21, $areaLow, $areaHigh, $area);
        $r2 = $this->lerp($q12, $q22, $areaLow, $areaHigh, $area);

        return $this->lerp($r1, $r2, $volumeLow, $volumeHigh, $volume);
    }

    private function findNeighbors(array $sorted, int|float $value): array
    {
        $low = $sorted[0];
        $high = $sorted[count($sorted) - 1];
        foreach ($sorted as $v) {
            if ($v <= $value) {
                $low = $v;
            }
            if ($v >= $value) {
                $high = $v;
                break;
            }
        }
        return [$low, $high];
    }

    private function findClosestPoint(array $points, int|float $area, int $volume): ?float
    {
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($points as $p) {
            $dist = abs($p['area'] - $area) + abs($p['volume'] - $volume);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = (float)$p['price'];
            }
        }
        return $best;
    }

    private function linearInterp(array $sorted, int|float $value): ?float
    {
        if (empty($sorted)) {
            return null;
        }

        if ($value <= $sorted[0]['key']) {
            return (float)$sorted[0]['price'];
        }
        if ($value >= $sorted[count($sorted) - 1]['key']) {
            return (float)$sorted[count($sorted) - 1]['price'];
        }

        for ($i = 0; $i < count($sorted) - 1; $i++) {
            $lo = $sorted[$i];
            $hi = $sorted[$i + 1];
            if ($value >= $lo['key'] && $value <= $hi['key']) {
                $t = ($value - $lo['key']) / ($hi['key'] - $lo['key']);
                return (float)$lo['price'] + $t * ((float)$hi['price'] - (float)$lo['price']);
            }
        }

        return (float)$sorted[count($sorted) - 1]['price'];
    }

    private function lerp(float $p1, float $p2, int|float $lo, int|float $hi, int|float $val): float
    {
        if ($lo === $hi) {
            return $p1;
        }
        $t = ($val - $lo) / ($hi - $lo);
        return $p1 + $t * ($p2 - $p1);
    }
}
