<?php

namespace Prospektweb\PropModificator;

use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;

class RequestParser
{
    /** @return array{ok:bool,data?:array,error?:string} */
    public function parse(array $post, array $get): array
    {
        return $this->parseFromInput(new RequestInput($post, $get));
    }

    /** @return array{ok:bool,data?:array,error?:string} */
    public function parseFromInput(RequestInput $input): array
    {
        $productId = (int)$input->input('productId', 0);
        $volume = $input->post('volume');
        $width = $input->post('width');
        $height = $input->post('height');
        $basketQty = max(1, (int)$input->post('basket_qty', 1));

        $visibleGroups = [];
        $visibleRaw = $input->post('visible_groups', []);
        if (is_array($visibleRaw)) {
            foreach ($visibleRaw as $gidRaw) {
                $gid = (int)$gidRaw;
                if ($gid > 0) {
                    $visibleGroups[$gid] = $gid;
                }
            }
            $visibleGroups = array_values($visibleGroups);
        }

        $activeGroupIdRaw = $input->post('active_group_id');
        $activeGroupId = (int)$activeGroupIdRaw > 0 ? (int)$activeGroupIdRaw : null;
        $otherPropsRaw = $input->post('other_props');
        $otherProps = is_array($otherPropsRaw) ? array_map('intval', $otherPropsRaw) : null;

        $data = [
            'productId' => $productId,
            'volume' => $volume !== null && $volume !== '' ? (int)$volume : null,
            'width' => $width !== null && $width !== '' ? (int)$width : null,
            'height' => $height !== null && $height !== '' ? (int)$height : null,
            'basketQty' => $basketQty,
            'visibleGroups' => $visibleGroups,
            'activeGroupId' => $activeGroupId,
            'otherProps' => $otherProps,
            'debug' => $input->post('debug') === 'Y',
        ];

        if (!$productId) {
            return ['ok' => false, 'error' => 'productId required'];
        }
        if (!ValidationRules::hasCustomInput($data['width'], $data['height'], $data['volume'])) {
            return ['ok' => false, 'error' => 'At least one of volume or width+height required'];
        }

        return ['ok' => true, 'data' => $data];
    }

    /** @return array{ok:bool,dto?:CalcPriceRequest,error?:string} */
    public function parseDtoFromInput(RequestInput $input): array
    {
        $parsed = $this->parseFromInput($input);
        if (!$parsed['ok']) {
            return $parsed;
        }

        return ['ok' => true, 'dto' => CalcPriceRequest::fromArray($parsed['data'])];
    }
}
