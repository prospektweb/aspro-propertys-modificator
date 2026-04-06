<?php

namespace Prospektweb\PropModificator\Domain\DTO;

final class CalcPriceRequest
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $volume,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $basketQty,
        /** @var int[] */
        public readonly array $visibleGroups,
        public readonly ?int $activeGroupId,
        /** @var array<int,int>|null */
        public readonly ?array $otherProps,
        public readonly bool $debug,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int)($data['productId'] ?? 0),
            isset($data['volume']) ? (int)$data['volume'] : null,
            isset($data['width']) ? (int)$data['width'] : null,
            isset($data['height']) ? (int)$data['height'] : null,
            max(1, (int)($data['basketQty'] ?? 1)),
            array_values(array_map('intval', (array)($data['visibleGroups'] ?? []))),
            isset($data['activeGroupId']) ? (int)$data['activeGroupId'] : null,
            isset($data['otherProps']) && is_array($data['otherProps']) ? array_map('intval', $data['otherProps']) : null,
            (bool)($data['debug'] ?? false),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'productId' => $this->productId,
            'volume' => $this->volume,
            'width' => $this->width,
            'height' => $this->height,
            'basketQty' => $this->basketQty,
            'visibleGroups' => $this->visibleGroups,
            'activeGroupId' => $this->activeGroupId,
            'otherProps' => $this->otherProps,
            'debug' => $this->debug,
        ];
    }
}
