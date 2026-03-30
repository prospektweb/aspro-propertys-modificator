<?php
/**
 * Обработчик событий страницы для подключения модуля без правки шаблона Аспро.
 *
 * Метод onEpilogHtml() регистрируется на событие main::OnEpilogHtml
 * установщиком модуля и вызывает template_include.php после того,
 * как все компоненты страницы завершили выполнение.
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class PageHandler
{
    /**
     * Обработчик события main::OnEpilogHtml.
     *
     * Подключает template_include.php только на детальных страницах товаров,
     * где определён ELEMENT_ID или arResult['ID'].
     * На остальных страницах завершается немедленно без дополнительных операций.
     */
    public static function onEpilogHtml(): void
    {
        // Быстрая проверка: только детальные страницы товаров
        $productId = (int)(
            $GLOBALS['ELEMENT_ID']
            ?? $GLOBALS['arResult']['ID']
            ?? $GLOBALS['arParams']['ELEMENT_ID']
            ?? 0
        );

        if (!$productId && empty($GLOBALS['arResult']['OFFERS'])) {
            return;
        }

        if (!Loader::includeModule('prospektweb.propmodificator')) {
            return;
        }

        $modulePath = Application::getDocumentRoot()
            . getLocalPath('modules/prospektweb.propmodificator/template_include.php');

        if (file_exists($modulePath)) {
            include_once $modulePath;
        }
    }
}
