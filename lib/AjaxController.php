<?php

namespace Prospektweb\PropModificator;

use Bitrix\Main\Loader;

/**
 * AJAX controller entrypoint for recalculating prices.
 */
class AjaxController
{
    public static function calcPrice(): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return ['success' => false, 'error' => 'Required modules not loaded'];
        }

        $parsed = (new RequestParser())->parse($_POST, $_GET);
        if (!$parsed['ok']) {
            return (new ResponseFactory())->error($parsed['error']);
        }

        $request = $parsed['data'];
        $pricing = (new PricingService())->calculate($request);
        if (!$pricing['ok']) {
            return (new ResponseFactory())->error((string)$pricing['error']);
        }

        $mainPrice = (new MainPriceResolver())->resolve(
            $pricing['rawPrices'],
            $pricing['rangePrices'],
            $pricing['catalogGroups'],
            $pricing['accessibleGroupIds'],
            (int)$request['basketQty'],
            $request['visibleGroups'],
            $request['activeGroupId']
        );

        return (new ResponseFactory())->success($pricing, $mainPrice, (bool)$request['debug'], $request);
    }
}
