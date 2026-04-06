<?php

namespace Prospektweb\PropModificator\Domain\PropertyBinding;

interface PropertyBindingResolverInterface
{
    public function resolvePropertyId(int $iblockId, string $propertyCode): ?int;

    /** @return array<int,string> */
    public function loadEnumXmlMap(?int $propertyId): array;
}
