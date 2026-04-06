<?php

namespace Prospektweb\PropModificator;

/**
 * Parses and normalizes AJAX input payload.
 *
 * Input: POST/GET arrays.
 * Output: normalized request DTO array or error array.
 */
class RequestParser
{
    /** @return array{ok:bool,data?:array,error?:string} */
    public function parse(array $post, array $get): array
    {
        $productId = (int)($post['productId'] ?? $get['productId'] ?? 0);
        $volume = isset($post['volume']) && $post['volume'] !== '' ? (int)$post['volume'] : null;
        $width = isset($post['width']) && $post['width'] !== '' ? (int)$post['width'] : null;
        $height = isset($post['height']) && $post['height'] !== '' ? (int)$post['height'] : null;
        $basketQty = isset($post['basket_qty']) && (int)$post['basket_qty'] > 0 ? (int)$post['basket_qty'] : 1;

        $visibleGroups = [];
        if (isset($post['visible_groups']) && is_array($post['visible_groups'])) {
            foreach ($post['visible_groups'] as $gidRaw) {
                $gid = (int)$gidRaw;
                if ($gid > 0) {
                    $visibleGroups[$gid] = $gid;
                }
            }
            $visibleGroups = array_values($visibleGroups);
        }

        $activeGroupId = isset($post['active_group_id']) && (int)$post['active_group_id'] > 0 ? (int)$post['active_group_id'] : null;
        $otherProps = isset($post['other_props']) && is_array($post['other_props']) ? array_map('intval', $post['other_props']) : null;

        if (!$productId) {
            return ['ok' => false, 'error' => 'productId required'];
        }
        if (!ValidationRules::hasCustomInput($width, $height, $volume)) {
            return ['ok' => false, 'error' => 'At least one of volume or width+height required'];
        }

        return [
            'ok' => true,
            'data' => [
                'productId' => $productId,
                'volume' => $volume,
                'width' => $width,
                'height' => $height,
                'basketQty' => $basketQty,
                'visibleGroups' => $visibleGroups,
                'activeGroupId' => $activeGroupId,
                'otherProps' => $otherProps,
                'debug' => isset($post['debug']) && $post['debug'] === 'Y',
            ],
        ];
    }
}
