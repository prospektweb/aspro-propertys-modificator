<?php
/**
 * Автозагрузка классов модуля prospekt.propmodificator
 *
 * Этот файл подключается Битриксом при загрузке модуля.
 * Регистрирует классы Prospekt\PropModificator.
 */

use Bitrix\Main\Loader;

Loader::registerAutoloadClasses('prospekt.propmodificator', [
    'Prospekt\\PropModificator\\Config'            => 'lib/Config.php',
    'Prospekt\\PropModificator\\PropertyValidator' => 'lib/PropertyValidator.php',
    'Prospekt\\PropModificator\\PriceInterpolator' => 'lib/PriceInterpolator.php',
    'Prospekt\\PropModificator\\BasketHandler'     => 'lib/BasketHandler.php',
]);
