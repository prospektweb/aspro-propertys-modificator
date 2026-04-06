<?php

namespace Prospektweb\PropModificator\Domain\DTO;

final class BasketCalcData
{
    /** @param array<int,int>|null $otherProps */
    public function __construct(
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $volume,
        public readonly ?float $customPrice,
        public readonly string $isCustom,
        public readonly ?int $productId,
        public readonly ?array $otherProps,
        public readonly ?float $serverPrice = null,
    ) {
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            isset($raw['width']) ? (int)$raw['width'] : null,
            isset($raw['height']) ? (int)$raw['height'] : null,
            isset($raw['volume']) ? (int)$raw['volume'] : null,
            isset($raw['custom_price']) ? (float)$raw['custom_price'] : null,
            ($raw['is_custom'] ?? '') === 'Y' ? 'Y' : 'N',
            isset($raw['product_id']) ? (int)$raw['product_id'] : null,
            isset($raw['other_props']) && is_array($raw['other_props']) ? array_map('intval', $raw['other_props']) : null,
            isset($raw['server_price']) ? (float)$raw['server_price'] : null,
        );
    }

    public function withServerPrice(float $price): self
    {
        return new self(
            $this->width,
            $this->height,
            $this->volume,
            $this->customPrice,
            $this->isCustom,
            $this->productId,
            $this->otherProps,
            $price,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'volume' => $this->volume,
            'custom_price' => $this->customPrice,
            'is_custom' => $this->isCustom,
            'product_id' => $this->productId,
            'other_props' => $this->otherProps,
            'server_price' => $this->serverPrice,
        ];
    }
}
