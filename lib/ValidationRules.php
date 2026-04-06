<?php

namespace Prospektweb\PropModificator;

class ValidationRules
{
    public static function hasCustomInput(?int $width, ?int $height, ?int $volume): bool
    {
        return ($width !== null && $height !== null) || $volume !== null;
    }

    public static function validateFormat(?int $width, ?int $height, array $formatSettings): bool
    {
        if ($width === null && $height === null) {
            return true;
        }

        if ($width === null || $height === null) {
            return false;
        }

        if (isset($formatSettings['MIN_WIDTH']) && $width < (int)$formatSettings['MIN_WIDTH']) {
            return false;
        }
        if (isset($formatSettings['MAX_WIDTH']) && $width > (int)$formatSettings['MAX_WIDTH']) {
            return false;
        }
        if (isset($formatSettings['MIN_HEIGHT']) && $height < (int)$formatSettings['MIN_HEIGHT']) {
            return false;
        }
        if (isset($formatSettings['MAX_HEIGHT']) && $height > (int)$formatSettings['MAX_HEIGHT']) {
            return false;
        }

        return true;
    }

    public static function validateVolume(?int $volume, array $volumeSettings): bool
    {
        if ($volume === null) {
            return true;
        }

        if (isset($volumeSettings['MIN']) && $volume < (int)$volumeSettings['MIN']) {
            return false;
        }
        if (isset($volumeSettings['MAX']) && $volume > (int)$volumeSettings['MAX']) {
            return false;
        }

        return true;
    }

    public static function validateInput(?int $width, ?int $height, ?int $volume, array $formatSettings, array $volumeSettings): bool
    {
        return self::validateFormat($width, $height, $formatSettings)
            && self::validateVolume($volume, $volumeSettings);
    }
}
