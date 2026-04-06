<?php

namespace Prospektweb\PropModificator\Domain\Config;

use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\CustomConfig;

class ProductConfigReader
{
    /** @return array{formatSettings: array<string,mixed>, volumeSettings: array<string,mixed>, customConfig: array<string,mixed>} */
    public function readByProductId(int $productId): array
    {
        $formatPropCode = Config::getFormatPropCode();
        $volumePropCode = Config::getVolumePropCode();
        $customConfigCode = Config::getCustomConfigPropCode();

        if ($customConfigCode === '') {
            return ['formatSettings' => [], 'volumeSettings' => [], 'customConfig' => []];
        }

        $rsProduct = \CIBlockElement::GetByID($productId);
        $arProduct = $rsProduct->GetNextElement();
        if (!$arProduct) {
            return ['formatSettings' => [], 'volumeSettings' => [], 'customConfig' => []];
        }

        $props = $arProduct->GetProperties([], []);
        $payload = $props[$customConfigCode] ?? null;

        return $this->readFromPropertyPayload($payload, $formatPropCode, $volumePropCode);
    }

    /** @return array{formatSettings: array<string,mixed>, volumeSettings: array<string,mixed>, customConfig: array<string,mixed>} */
    public function readFromPropertyPayload($payload, string $formatPropCode, string $volumePropCode): array
    {
        $rawConfigValue = is_array($payload)
            ? ($payload['~VALUE'] ?? $payload['VALUE'] ?? null)
            : $payload;

        $customConfig = CustomConfig::parseFromPropertyValue($rawConfigValue);
        if (empty($customConfig)) {
            return ['formatSettings' => [], 'volumeSettings' => [], 'customConfig' => []];
        }

        $extracted = CustomConfig::extractCalculatorSettings($customConfig, $formatPropCode, $volumePropCode);

        return [
            'formatSettings' => $extracted['formatSettings'] ?? [],
            'volumeSettings' => $extracted['volumeSettings'] ?? [],
            'customConfig' => $customConfig,
        ];
    }
}
