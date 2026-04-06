<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix;

class ProductConfigRepository
{
    public function getProductPropertyPayload(int $productId, string $customConfigCode): mixed
    {
        if ($customConfigCode === '') {
            return null;
        }

        $rsProduct = \CIBlockElement::GetByID($productId);
        $arProduct = $rsProduct->GetNextElement();
        if (!$arProduct) {
            return null;
        }

        $props = $arProduct->GetProperties([], []);

        return $props[$customConfigCode] ?? null;
    }
}
