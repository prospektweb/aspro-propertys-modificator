<?php

namespace Prospektweb\PropModificator\Domain\FieldMode;

interface FieldModeHandlerInterface
{
    public function getMode(): string;

    public function getPropertyCode(): string;

    public function isValidXmlId(string $xmlId): bool;

    public function parseXmlId(string $xmlId): mixed;

    public function hasCustomInput(?int $width, ?int $height, ?int $volume): bool;

    /** @return array<int,array{key:int|float,price:float}> */
    public function extractLinearPoints(array $offerPoints): array;

    public function resolveLinearValue(?int $width, ?int $height, ?int $volume): int|float|null;
}
