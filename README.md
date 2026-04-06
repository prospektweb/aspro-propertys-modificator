# aspro-propertys-modificator

Модуль `prospektweb.propmodificator` для 1С-Битрикс (Аспро: Премьер): добавляет на карточке товара UI для кастомных параметров SKU (формат/тираж и др.) и пересчитывает цену по данным торговых предложений.

## Quick start

1. Скопируйте модуль в `/local/modules/prospektweb.propmodificator/`.
2. Установите через **Marketplace → Установленные решения**.
3. Проверьте настройки: `/bitrix/admin/settings.php?mid=prospektweb.propmodificator`.
4. Откройте карточку товара в каталоге и убедитесь, что загружены ассеты `/bitrix/js/prospektweb.propmodificator/*` и объект `window.pmodConfig`.

## Матрица читателя

- **Администратор магазина** → `docs/installation.md`, `docs/configuration.md`, `docs/troubleshooting.md`.
- **Разработчик модуля (PHP/архитектура)** → `docs/architecture.md`, `docs/api/calc-price.md`, `docs/changelog.md`.
- **Интегратор фронтенда/шаблона** → `docs/frontend-integration.md`, `docs/api/calc-price.md`, `docs/troubleshooting.md`.

## Документация

- Архитектура: `docs/architecture.md`
- Установка/обновление/удаление: `docs/installation.md`
- Конфигурация и опции: `docs/configuration.md`
- Frontend-интеграция: `docs/frontend-integration.md`
- API расчёта цены: `docs/api/calc-price.md`
- Диагностика: `docs/troubleshooting.md`
- История изменений: `docs/changelog.md`
