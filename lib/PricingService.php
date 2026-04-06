<?php

namespace Prospektweb\PropModificator;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\RoundingTable;
use Bitrix\Main\Loader;
use Prospektweb\PropModificator\Domain\Config\ProductConfigReader;

/**
 * Calculates interpolated prices and applies Bitrix rounding rules.
 *
 * Input: normalized request payload.
 * Output: computed pricing context with prices/ranges/groups/access metadata.
 */
class PricingService
{
    /** @param array<string,mixed> $request */
    public function calculate(array $request): array
    {
        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return ['ok' => false, 'error' => 'Required modules not loaded'];
        }

        if (!ValidationRules::hasCustomInput($request['width'], $request['height'], $request['volume'])) {
            return ['ok' => false, 'error' => 'At least one of volume, width+height required'];
        }

        $settings = (new ProductConfigReader())->readByProductId((int)$request['productId']);
        if (!ValidationRules::validateInput(
            $request['width'],
            $request['height'],
            $request['volume'],
            $settings['formatSettings'] ?? [],
            $settings['volumeSettings'] ?? []
        )) {
            return ['ok' => false, 'error' => 'Input is out of configured limits'];
        }

        $repo = new OfferRepository();
        $meta = $repo->loadOfferMetadata((int)$request['productId'], $request['otherProps'] ?? null);
        if (empty($meta)) {
            return ['ok' => false, 'error' => 'No prices could be calculated'];
        }

        $offerIds = array_keys($meta);
        $interpolator = new PriceInterpolator();

        $pricesByGroup = $repo->loadGroupPrices($offerIds);
        $rangeRowsByGroup = $repo->loadGroupRangePrices($offerIds);

        $rawPrices = [];
        foreach ($pricesByGroup as $gid => $groupPrices) {
            $points = [];
            foreach ($meta as $oid => $m) {
                $price = $groupPrices[$oid] ?? null;
                if ($price === null || $price <= 0 || ($m['volume'] ?? null) === null) {
                    continue;
                }
                $points[] = array_merge($m, ['price' => $price]);
            }
            $p = $interpolator->interpolatePoints($points, $request['width'], $request['height'], $request['volume']);
            if ($p !== null) {
                $rawPrices[(int)$gid] = $p;
            }
        }

        $rangePrices = [];
        foreach ($rangeRowsByGroup as $gid => $rangeMap) {
            foreach ($rangeMap as $rangeRows) {
                if (empty($rangeRows)) {
                    continue;
                }
                $from = $rangeRows[0]['from'];
                $to = $rangeRows[0]['to'];
                $points = [];
                foreach ($rangeRows as $row) {
                    $oid = (int)$row['offerId'];
                    if (!isset($meta[$oid])) {
                        continue;
                    }
                    $m = $meta[$oid];
                    if (($m['volume'] ?? null) === null || $row['price'] <= 0) {
                        continue;
                    }
                    $points[] = array_merge($m, ['price' => (float)$row['price']]);
                }
                $p = $interpolator->interpolatePoints($points, $request['width'], $request['height'], $request['volume']);
                if ($p !== null) {
                    $rangePrices[(int)$gid][] = ['from' => $from, 'to' => $to, 'price' => $p];
                }
            }
        }

        $catalogGroups = $this->loadCatalogGroups();
        $roundingRules = $this->loadRoundingRules();

        foreach ($rawPrices as $gid => &$price) {
            if (!empty($roundingRules[$gid])) {
                $price = $this->applyRounding((float)$price, $roundingRules[$gid]);
            }
        }
        unset($price);

        foreach ($rangePrices as $gid => &$rows) {
            if (empty($roundingRules[$gid])) {
                continue;
            }
            foreach ($rows as &$row) {
                $row['price'] = $this->applyRounding((float)$row['price'], $roundingRules[$gid]);
            }
            unset($row);
        }
        unset($rows);

        $accessibleGroupIds = $this->getAccessiblePriceGroups();

        return [
            'ok' => !empty($rawPrices) || !empty($rangePrices),
            'error' => empty($rawPrices) && empty($rangePrices) ? 'No prices could be calculated' : null,
            'rawPrices' => $rawPrices,
            'rangePrices' => $rangePrices,
            'catalogGroups' => $catalogGroups,
            'roundingRules' => $roundingRules,
            'accessibleGroupIds' => $accessibleGroupIds,
        ];
    }

    private function loadCatalogGroups(): array { $groups=[]; try { $rs=GroupTable::getList(['select'=>['ID','NAME','BASE'],'order'=>['ID'=>'ASC']]); while($r=$rs->fetch()){ $id=(int)$r['ID']; $groups[$id]=['id'=>$id,'name'=>(string)$r['NAME'],'base'=>($r['BASE']??'N')==='Y'];}} catch(\Throwable $e) {} return $groups; }
    private function loadRoundingRules(): array { $rules=[]; try { $rs=RoundingTable::getList(['select'=>['CATALOG_GROUP_ID','PRICE','ROUND_TYPE','ROUND_PRECISION'],'order'=>['CATALOG_GROUP_ID'=>'ASC','PRICE'=>'ASC']]); while($r=$rs->fetch()){ $gid=(int)$r['CATALOG_GROUP_ID']; $rules[$gid][]=['price'=>(float)$r['PRICE'],'type'=>(int)$r['ROUND_TYPE'],'precision'=>(float)$r['ROUND_PRECISION']]; }} catch(\Throwable $e) {} return $rules; }
    private function applyRounding(float $price, array $rules): float { $applied=null; foreach($rules as $rule){ if($price>=(float)$rule['price']){$applied=$rule;}} if(!$applied){return $price;} $precision=max((float)$applied['precision'],0.000001); return match((int)$applied['type']){1=>floor($price/$precision)*$precision,2=>round($price/$precision)*$precision,3=>ceil($price/$precision)*$precision,default=>$price}; }
    private function getAccessiblePriceGroups(): array { global $USER; $userGroups=[]; if(is_object($USER)&&method_exists($USER,'GetUserGroupArray')){$userGroups=(array)$USER->GetUserGroupArray();} if(empty($userGroups)){$userGroups=[2];} $ids=[]; if(class_exists('CCatalogGroup')){ try{$perms=\CCatalogGroup::GetGroupsPerms($userGroups); if(is_array($perms)){ foreach($perms as $gid=>$perm){ if(($perm['buy']??'N')==='Y'){$ids[]=(int)$gid;}}}} catch(\Throwable $e){} } return array_values(array_unique($ids)); }
}
