<?php

namespace Prospektweb\PropModificator\Infrastructure\Bitrix;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\RoundingTable;

class CatalogRepository
{
    public function loadCatalogGroups(): array
    {
        $groups = [];
        try {
            $rs = GroupTable::getList(['select' => ['ID', 'NAME', 'BASE'], 'order' => ['ID' => 'ASC']]);
            while ($r = $rs->fetch()) {
                $id = (int)$r['ID'];
                $groups[$id] = ['id' => $id, 'name' => (string)$r['NAME'], 'base' => ($r['BASE'] ?? 'N') === 'Y'];
            }
        } catch (\Throwable $e) {
        }

        return $groups;
    }

    public function loadRoundingRules(): array
    {
        $rules = [];
        try {
            $rs = RoundingTable::getList(['select' => ['CATALOG_GROUP_ID', 'PRICE', 'ROUND_TYPE', 'ROUND_PRECISION'], 'order' => ['CATALOG_GROUP_ID' => 'ASC', 'PRICE' => 'ASC']]);
            while ($r = $rs->fetch()) {
                $gid = (int)$r['CATALOG_GROUP_ID'];
                $rules[$gid][] = ['price' => (float)$r['PRICE'], 'type' => (int)$r['ROUND_TYPE'], 'precision' => (float)$r['ROUND_PRECISION']];
            }
        } catch (\Throwable $e) {
        }

        return $rules;
    }

    public function getAccessiblePriceGroups(): array
    {
        global $USER;

        $userGroups = [];
        if (is_object($USER) && method_exists($USER, 'GetUserGroupArray')) {
            $userGroups = (array)$USER->GetUserGroupArray();
        }

        if (empty($userGroups)) {
            $userGroups = [2];
        }

        $ids = [];
        if (class_exists('CCatalogGroup')) {
            try {
                $perms = \CCatalogGroup::GetGroupsPerms($userGroups);
                if (is_array($perms)) {
                    foreach ($perms as $gid => $perm) {
                        if (($perm['buy'] ?? 'N') === 'Y') {
                            $ids[] = (int)$gid;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return array_values(array_unique($ids));
    }
}
