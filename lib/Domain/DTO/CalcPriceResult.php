<?php

namespace Prospektweb\PropModificator\Domain\DTO;

final class CalcPriceResult
{
    /**
     * @param array<int,float> $rawPrices
     * @param array<int,array<int,array{from:?int,to:?int,price:float}>> $rangePrices
     * @param array<int,array<string,mixed>> $catalogGroups
     * @param array<int,array<string,mixed>> $roundingRules
     * @param int[] $accessibleGroupIds
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error,
        public readonly array $rawPrices,
        public readonly array $rangePrices,
        public readonly array $catalogGroups,
        public readonly array $roundingRules,
        public readonly array $accessibleGroupIds,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            (bool)($payload['ok'] ?? false),
            isset($payload['error']) ? (string)$payload['error'] : null,
            (array)($payload['rawPrices'] ?? []),
            (array)($payload['rangePrices'] ?? []),
            (array)($payload['catalogGroups'] ?? []),
            (array)($payload['roundingRules'] ?? []),
            array_values(array_map('intval', (array)($payload['accessibleGroupIds'] ?? []))),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'error' => $this->error,
            'rawPrices' => $this->rawPrices,
            'rangePrices' => $this->rangePrices,
            'catalogGroups' => $this->catalogGroups,
            'roundingRules' => $this->roundingRules,
            'accessibleGroupIds' => $this->accessibleGroupIds,
        ];
    }
}
