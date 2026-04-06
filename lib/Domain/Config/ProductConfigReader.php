<?php

namespace Prospektweb\PropModificator\Domain\Config;

use Prospektweb\PropModificator\Config;
use Prospektweb\PropModificator\CustomConfig;
use Prospektweb\PropModificator\Infrastructure\Bitrix\ProductConfigRepository;

class ProductConfigReader
{
    public function __construct(private ?ProductConfigRepository $repository = null)
    {
        $this->repository = $this->repository ?? new ProductConfigRepository();
    }

    /** @return array{formatSettings: array<string,mixed>, volumeSettings: array<string,mixed>, customConfig: array<string,mixed>} */
    public function readByProductId(int $productId): array
    {
        $formatPropCode = Config::getFormatPropCode();
        $volumePropCode = Config::getVolumePropCode();
        $customConfigCode = Config::getCustomConfigPropCode();

        $payload = $this->repository->getProductPropertyPayload($productId, $customConfigCode);

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
