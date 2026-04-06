<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix\FieldMode;

use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\Domain\FieldMode\FieldModeHandlerInterface;

class FormatFieldModeHandler implements FieldModeHandlerInterface
{
    public function getMode(): string
    {
        return 'format';
    }

    public function getPropertyCode(): string
    {
        return Config::getFormatPropCode();
    }

    public function isValidXmlId(string $xmlId): bool
    {
        return $xmlId === 'X' || (bool)preg_match('/^\d+x\d+$/i', $xmlId);
    }

    public function parseXmlId(string $xmlId): ?array
    {
        if ($xmlId === 'X' || !$this->isValidXmlId($xmlId)) {
            return null;
        }

        [$w, $h] = explode('x', strtolower($xmlId));

        return ['width' => (int)$w, 'height' => (int)$h];
    }

    public function hasCustomInput(?int $width, ?int $height, ?int $volume): bool
    {
        return $width !== null && $height !== null;
    }

    public function extractLinearPoints(array $offerPoints): array
    {
        $points = array_filter($offerPoints, fn($o) => ($o['width'] ?? null) !== null && ($o['height'] ?? null) !== null && isset($o['price']));

        $linear = array_map(function (array $point): array {
            return [
                'key' => (int)$point['width'] * (int)$point['height'],
                'price' => (float)$point['price'],
            ];
        }, array_values($points));

        usort($linear, fn($a, $b) => $a['key'] <=> $b['key']);

        return $linear;
    }

    public function resolveLinearValue(?int $width, ?int $height, ?int $volume): ?int
    {
        if ($width === null || $height === null) {
            return null;
        }

        return $width * $height;
    }
}
