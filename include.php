<?php
/**
 * Автозагрузка классов модуля prospektweb.propmodificator
 *
 * Этот файл подключается Битриксом при загрузке модуля.
 * Регистрирует классы Prospektweb\PropModificator.
 */

use Bitrix\Main\Loader;

Loader::registerAutoloadClasses('prospektweb.propmodificator', [
    'Prospektweb\\PropModificator\\Config'            => 'lib/Config.php',
    'Prospektweb\\PropModificator\\PropertyValidator' => 'lib/PropertyValidator.php',
    'Prospektweb\\PropModificator\\PriceInterpolator' => 'lib/PriceInterpolator.php',
    'Prospektweb\\PropModificator\\BasketHandler'     => 'lib/BasketHandler.php',
    'Prospektweb\\PropModificator\\PageHandler'       => 'lib/PageHandler.php',
]);
