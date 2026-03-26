<?php
/**
 * Автозагрузка классов модуля prospekt.propmodificator
 *
 * Этот файл подключается Битриксом при загрузке модуля.
 * Регистрирует пространство имён Prospekt\PropModificator.
 */

use Bitrix\Main\Loader;

$loader = Loader::getInstance();
$loader->registerNamespace(
    'Prospekt\\PropModificator',
    __DIR__ . '/lib'
);
