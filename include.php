<?php
/**
 * Автозагрузка классов модуля prospektweb.propmodificator
 */

use Bitrix\Main\Loader;

Loader::registerAutoloadClasses('prospektweb.propmodificator', [
    'Prospektweb\\PropModificator\\Config'       => 'lib/Config.php',
    'Prospektweb\\PropModificator\\PageHandler'  => 'lib/PageHandler.php',
    'Prospektweb\\PropModificator\\CustomConfig' => 'lib/CustomConfig.php',
    'Prospektweb\\PropModificator\\AdminHandler' => 'lib/AdminHandler.php',
]);
