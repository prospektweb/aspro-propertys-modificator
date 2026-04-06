<?php

namespace Prospektweb\PropModificator\Domain\Offer;

class EnumValueResolver
{
    public function resolveXmlId(?string $xmlIdFromRow, ?int $enumId, array $enumMap): ?string
    {
        $xmlId = trim((string)$xmlIdFromRow);
        if ($xmlId !== '') {
            return $xmlId;
        }

        if ($enumId !== null && $enumId > 0 && isset($enumMap[$enumId])) {
            return (string)$enumMap[$enumId];
        }

        return null;
    }

    public function loadEnumXmlMap(?int $propId): array
    {
        $result = [];
        if (!$propId) {
            return $result;
        }

        $rsEnum = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propId]);
        while ($arEnum = $rsEnum->Fetch()) {
            $enumId = (int)($arEnum['ID'] ?? 0);
            $xmlId = trim((string)($arEnum['XML_ID'] ?? ''));
            if ($enumId > 0 && $xmlId !== '') {
                $result[$enumId] = $xmlId;
            }
        }

        return $result;
    }
}
