<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode;

use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\Domain\FieldMode\FieldModeHandlerInterface;

class VolumeFieldModeHandler implements FieldModeHandlerInterface
{
    public function getMode(): string
    {
        return 'volume';
    }

    public function getPropertyCode(): string
    {
        return Config::getVolumePropCode();
    }

    public function isValidXmlId(string $xmlId): bool
    {
        return $xmlId === 'X' || (is_numeric($xmlId) && (int)$xmlId > 0);
    }

    public function parseXmlId(string $xmlId): ?int
    {
        if ($xmlId === 'X' || !$this->isValidXmlId($xmlId)) {
            return null;
        }

        return (int)$xmlId;
    }

    public function hasCustomInput(?int $width, ?int $height, ?int $volume): bool
    {
        return $volume !== null;
    }

    public function extractLinearPoints(array $offerPoints): array
    {
        $points = array_filter($offerPoints, fn($o) => ($o['volume'] ?? null) !== null && isset($o['price']));
        $linear = array_map(fn($point) => ['key' => (int)$point['volume'], 'price' => (float)$point['price']], array_values($points));
        usort($linear, fn($a, $b) => $a['key'] <=> $b['key']);

        return $linear;
    }

    public function resolveLinearValue(?int $width, ?int $height, ?int $volume): ?int
    {
        return $volume;
    }
}
