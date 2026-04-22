<?php
/**
 * Обработчик событий страницы для подключения модуля без правки шаблона Аспро.
 *
 * Метод onEpilog() регистрируется на событие main::OnEpilog установщиком
 * модуля и вызывает template_include.php после того, как все компоненты
 * страницы завершили выполнение.
 *
 * Дополнительная гарантия подключения — файл-обработчик, создаваемый
 * установщиком в /bitrix/php_interface/include/prospektweb_propmodificator.php.
 */

namespace Prospektweb\PropModificator;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;

class PageHandler
{
    /**
     * Обработчик события main::OnEpilog.
     *
     * Подключает template_include.php только на публичных детальных страницах
     * каталога. На остальных страницах завершается немедленно.
     */
    public static function onEpilog(): void
    {
        // Пропускаем административную часть
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return;
        }

        // Пропускаем AJAX-запросы
        if (defined('BX_AJAX') && BX_AJAX) {
            return;
        }

        // Фильтр по пути URL (configurable, по умолчанию /catalog/)
        $pathFilter = \COption::GetOptionString('prospektweb.propmodificator', 'CATALOG_PATH_FILTER', '/catalog/');
        if ($pathFilter !== '') {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, $pathFilter) === false) {
                self::debugLog('Path filter mismatch: "' . $requestUri . '" does not contain "' . $pathFilter . '"');
                return;
            }
        }

        if (!Loader::includeModule('prospektweb.propmodificator')) {
            self::debugLog('Module prospektweb.propmodificator could not be loaded');
            return;
        }

        $isDetailPage = false;
        if (Loader::includeModule('iblock') && Loader::includeModule('catalog')) {
            $resolver = new ProductResolver(Config::getOffersIblockId(), Config::getProductsIblockId());
            $isDetailPage = $resolver->resolve() > 0;
        }

        if (!$isDetailPage) {
            self::injectListingHiddenValuesScript();
        }

        $modulePath = Application::getDocumentRoot()
            . getLocalPath('modules/prospektweb.propmodificator/template_include.php');

        if (!file_exists($modulePath)) {
            self::debugLog('template_include.php not found at: ' . $modulePath);
            return;
        }

        include_once $modulePath;
    }

    private static function injectListingHiddenValuesScript(): void
    {
        $ids = Config::getHiddenOfferValueIds();
        if (!$ids) {
            return;
        }

        $asset = Asset::getInstance();
        $asset->addJs('/bitrix/js/prospektweb.propmodificator/listing/hide-technical-values.js');
        $asset->addString(
            '<script>window.pmodListingHiddenValueIds = ' . json_encode($ids) . ';</script>',
            false,
            AssetLocation::AFTER_CSS
        );
    }

    /**
     * Записывает отладочное сообщение, если включена опция DEBUG.
     */
    public static function debugLog(string $message): void
    {
        $debug = \COption::GetOptionString('prospektweb.propmodificator', 'DEBUG', 'N');
        if ($debug !== 'Y') {
            return;
        }

        $logFile = Application::getDocumentRoot() . '/bitrix/logs/prospektweb.propmodificator.log';
        $logDir  = dirname($logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        if (!is_dir($logDir)) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
