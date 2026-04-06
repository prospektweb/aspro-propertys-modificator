<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix;

use Prospektweb\PropModificator\Domain\Offer\EnumValueResolver;
use Prospektweb\PropModificator\Domain\PropertyBinding\PropertyBindingResolverInterface;

class BitrixPropertyBindingResolver implements PropertyBindingResolverInterface
{
    public function __construct(private ?EnumValueResolver $enumValueResolver = null)
    {
        $this->enumValueResolver = $this->enumValueResolver ?? new EnumValueResolver();
    }

    public function resolvePropertyId(int $iblockId, string $propertyCode): ?int
    {
        if ($propertyCode === '') {
            return null;
        }

        $rsProp = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode, 'ACTIVE' => 'Y']);
        if ($arProp = $rsProp->Fetch()) {
            return (int)$arProp['ID'];
        }

        return null;
    }

    public function loadEnumXmlMap(?int $propertyId): array
    {
        return $this->enumValueResolver->loadEnumXmlMap($propertyId);
    }
}
