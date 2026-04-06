<?php

namespace Prospektweb\PropModificator;

/**
 * Resolves a parent product ID from global template context and request params.
 *
 * Input: product/offer context from globals and query string.
 * Output: positive productId or null when not resolvable.
 */
class ProductResolver
{
    public function __construct(private readonly int $offersIblockId, private readonly int $productsIblockId)
    {
    }

    public function resolve(): ?int
    {
        $productId = (int)(
            $GLOBALS['ELEMENT_ID']
            ?? $GLOBALS['arResult']['ID']
            ?? $GLOBALS['arParams']['ELEMENT_ID']
            ?? 0
        );

        if (!$productId && !empty($GLOBALS['arResult']['OFFERS'])) {
            $firstOffer = reset($GLOBALS['arResult']['OFFERS']);
            $productId  = (int)($firstOffer['PROPERTY_CML2_LINK_VALUE'] ?? 0);
        }

        if (!$productId) {
            foreach (['id', 'element_id', 'product_id'] as $param) {
                $val = (int)($_GET[$param] ?? 0);
                if ($val > 0) {
                    $productId = $val;
                    break;
                }
            }
        }

        if (!$productId) {
            $oidParam = (int)($_GET['oid'] ?? $_GET['offer_id'] ?? 0);
            if ($oidParam > 0 && $this->offersIblockId > 0) {
                $rsOff = \CIBlockElement::GetList([], ['IBLOCK_ID' => $this->offersIblockId, 'ID' => $oidParam, 'ACTIVE' => 'Y'], false, false, ['ID', 'PROPERTY_CML2_LINK']);
                if ($arOff = $rsOff->Fetch()) {
                    $productId = (int)($arOff['PROPERTY_CML2_LINK_VALUE'] ?? 0);
                }
                PageHandler::debugLog('oid=' . $oidParam . ' resolved to productId=' . $productId);
            }
        }

        if (!$productId) {
            global $APPLICATION;
            foreach (['element_id', 'item_id', 'catalog_element_id', 'ELEMENT_ID', 'catalog_item_id'] as $propKey) {
                $propVal = (int)$APPLICATION->GetPageProperty($propKey);
                if ($propVal > 0) {
                    if ($this->offersIblockId > 0) {
                        $rsEl = \CIBlockElement::GetList([], ['IBLOCK_ID' => $this->offersIblockId, 'ID' => $propVal, 'ACTIVE' => 'Y'], false, false, ['ID', 'PROPERTY_CML2_LINK']);
                        if ($arEl = $rsEl->Fetch()) {
                            $productId = (int)($arEl['PROPERTY_CML2_LINK_VALUE'] ?? 0);
                        }
                    }
                    if (!$productId) {
                        $productId = $propVal;
                    }
                    PageHandler::debugLog('GetPageProperty(' . $propKey . ') resolved to productId=' . $productId);
                    break;
                }
            }
        }

        if (!$productId && $this->productsIblockId > 0) {
            $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
            if ($requestPath) {
                $lastSegment = basename(rtrim($requestPath, '/'));
                if ($lastSegment && !is_numeric($lastSegment)) {
                    $rsEl = \CIBlockElement::GetList([], ['IBLOCK_ID' => $this->productsIblockId, 'CODE' => $lastSegment, 'ACTIVE' => 'Y'], false, ['nTopCount' => 1], ['ID']);
                    if ($arEl = $rsEl->Fetch()) {
                        $productId = (int)$arEl['ID'];
                        PageHandler::debugLog('URL CODE="' . $lastSegment . '" resolved to productId=' . $productId);
                    }
                }
            }
        }

        if (!$productId) {
            $html = ob_get_contents();
            if ($html && preg_match('/data-item-id=["\'](\d+)["\']/', $html, $obMatch)) {
                $candidateId = (int)$obMatch[1];
                if ($candidateId > 0) {
                    if ($this->offersIblockId > 0) {
                        $rsEl = \CIBlockElement::GetList([], ['IBLOCK_ID' => $this->offersIblockId, 'ID' => $candidateId, 'ACTIVE' => 'Y'], false, false, ['ID', 'PROPERTY_CML2_LINK']);
                        if ($arEl = $rsEl->Fetch()) {
                            $productId = (int)($arEl['PROPERTY_CML2_LINK_VALUE'] ?? 0);
                        }
                    }
                    if (!$productId) {
                        $productId = $candidateId;
                    }
                }
            }
        }

        return $productId > 0 ? $productId : null;
    }
}
