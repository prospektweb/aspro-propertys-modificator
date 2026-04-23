<?php

namespace Prospektweb\PropModificator;

/**
 * Builds frontend payload for window.pmodConfig.
 *
 * Input: normalized product payload and request context.
 * Output: final JS config array consumable by frontend entrypoint.
 */
class FrontendConfigBuilder
{
    /** @param array<string,mixed> $productData */
    public function build(array $productData): array
    {
        $requestOid = (int)($_GET['oid'] ?? 0);
        $initialVolume = null;
        $pmodVolume = isset($_GET['pmod_volume']) ? (int)$_GET['pmod_volume'] : 0;
        if ($pmodVolume > 0) {
            $initialVolume = $pmodVolume;
        }

        if ($requestOid > 0 && $initialVolume === null) {
            foreach (($productData['offers'] ?? []) as $offerData) {
                if ((int)$offerData['id'] === $requestOid && ($offerData['volume'] ?? null) === null) {
                    $baseUrl = preg_replace('/[\r\n]/', '', strtok($_SERVER['REQUEST_URI'], '?'));
                    LocalRedirect($baseUrl, false, '302 Found');
                    die();
                }
            }
        }

        $productId = (int)$productData['productId'];

        return [
            'ajaxUrl' => '/ajax/prospektweb.propmodificator/calc_price.php',
            'products' => [
                $productId => [
                    'formatPropId' => $productData['formatPropId'],
                    'volumePropId' => $productData['volumePropId'],
                    'formatPropCode' => $productData['formatPropCode'],
                    'volumePropCode' => $productData['volumePropCode'],
                    'formatSettings' => $productData['formatSettings'],
                    'volumeSettings' => $productData['volumeSettings'],
                    'offers' => $productData['offers'],
                    'volumeEnumMap' => $productData['volumeEnumMap'],
                    'formatEnumMap' => $productData['formatEnumMap'],
                    'skuPropsEnumMap' => $productData['skuPropsEnumMap'],
                    'catalogGroups' => $productData['catalogGroups'],
                    'canBuyGroups' => $productData['canBuyGroups'],
                    'allPropIds' => $productData['allPropIds'],
                    'skuPropCodeToId' => $productData['skuPropCodeToId'],
                    'roundingRules' => $productData['roundingRules'],
                    'initialVolume' => $initialVolume,
                    'customConfig' => $productData['customConfig'],
                ],
            ],
        ];
    }
}
