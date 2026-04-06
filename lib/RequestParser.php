<?php

namespace Prospektweb\PropModificator;

use Prospektweb\PropModificator\Domain\DTO\CalcPriceRequest;
use Prospektweb\PropModificator\Infrastructure\Http\RequestInput;

class RequestParser
{
    private const ALLOWED_POST_FIELDS = [
        'productId',
        'volume',
        'width',
        'height',
        'basket_qty',
        'visible_groups',
        'active_group_id',
        'other_props',
        'debug',
        'sessid',
    ];

    private const LIMITS = [
        'productId' => ['min' => 1, 'max' => 2000000000],
        'volume' => ['min' => 1, 'max' => 1000000],
        'width' => ['min' => 1, 'max' => 100000],
        'height' => ['min' => 1, 'max' => 100000],
        'basketQty' => ['min' => 1, 'max' => 10000],
        'groupId' => ['min' => 1, 'max' => 100000],
        'otherPropId' => ['min' => 1, 'max' => 1000000],
        'otherPropValue' => ['min' => 0, 'max' => 1000000],
    ];

    private const MAX_VISIBLE_GROUPS = 100;
    private const MAX_OTHER_PROPS = 100;

    /** @return array{ok:bool,data?:array,error?:string,errorCode?:string} */
    public function parse(array $post, array $get): array
    {
        return $this->parseFromInput(new RequestInput($post, $get));
    }

    /** @return array{ok:bool,data?:array,error?:string,errorCode?:string} */
    public function parseFromInput(RequestInput $input): array
    {
        if ($unknownPost = $this->findUnknownKeys($input->postAll(), self::ALLOWED_POST_FIELDS)) {
            return ['ok' => false, 'errorCode' => 'INVALID_PAYLOAD_FIELD', 'error' => 'Unknown POST fields: ' . implode(', ', $unknownPost)];
        }

        $productId = $this->parseBoundedInt($input->input('productId', 0), 'productId');
        if ($productId === null) {
            return ['ok' => false, 'errorCode' => 'INVALID_PRODUCT_ID', 'error' => 'productId must be an integer in allowed range'];
        }

        $volume = $this->parseNullableBoundedInt($input->post('volume'), 'volume');
        if ($input->post('volume') !== null && $input->post('volume') !== '' && $volume === null) {
            return ['ok' => false, 'errorCode' => 'INVALID_VOLUME', 'error' => 'volume must be an integer in allowed range'];
        }

        $width = $this->parseNullableBoundedInt($input->post('width'), 'width');
        if ($input->post('width') !== null && $input->post('width') !== '' && $width === null) {
            return ['ok' => false, 'errorCode' => 'INVALID_WIDTH', 'error' => 'width must be an integer in allowed range'];
        }

        $height = $this->parseNullableBoundedInt($input->post('height'), 'height');
        if ($input->post('height') !== null && $input->post('height') !== '' && $height === null) {
            return ['ok' => false, 'errorCode' => 'INVALID_HEIGHT', 'error' => 'height must be an integer in allowed range'];
        }

        $basketQty = $this->parseBoundedInt($input->post('basket_qty', 1), 'basketQty');
        if ($basketQty === null) {
            return ['ok' => false, 'errorCode' => 'INVALID_BASKET_QTY', 'error' => 'basket_qty must be an integer in allowed range'];
        }

        $visibleGroups = [];
        $visibleRaw = $input->post('visible_groups', []);
        if (!is_array($visibleRaw)) {
            return ['ok' => false, 'errorCode' => 'INVALID_VISIBLE_GROUPS', 'error' => 'visible_groups must be an array'];
        }
        foreach ($visibleRaw as $gidRaw) {
            $gid = $this->parseBoundedInt($gidRaw, 'groupId');
            if ($gid === null) {
                return ['ok' => false, 'errorCode' => 'INVALID_VISIBLE_GROUPS', 'error' => 'visible_groups contains invalid group id'];
            }
            $visibleGroups[$gid] = $gid;
            if (count($visibleGroups) > self::MAX_VISIBLE_GROUPS) {
                return ['ok' => false, 'errorCode' => 'VISIBLE_GROUPS_LIMIT', 'error' => 'visible_groups exceeded max allowed size'];
            }
        }
        $visibleGroups = array_values($visibleGroups);

        $activeGroupId = null;
        $activeGroupIdRaw = $input->post('active_group_id');
        if ($activeGroupIdRaw !== null && $activeGroupIdRaw !== '') {
            $activeGroupId = $this->parseBoundedInt($activeGroupIdRaw, 'groupId');
            if ($activeGroupId === null) {
                return ['ok' => false, 'errorCode' => 'INVALID_ACTIVE_GROUP_ID', 'error' => 'active_group_id must be an integer in allowed range'];
            }
        }

        $otherProps = null;
        $otherPropsRaw = $input->post('other_props');
        if ($otherPropsRaw !== null) {
            if (!is_array($otherPropsRaw)) {
                return ['ok' => false, 'errorCode' => 'INVALID_OTHER_PROPS', 'error' => 'other_props must be an array'];
            }

            $otherProps = [];
            foreach ($otherPropsRaw as $propIdRaw => $valueRaw) {
                $propId = $this->parseBoundedInt($propIdRaw, 'otherPropId');
                $value = $this->parseBoundedInt($valueRaw, 'otherPropValue');
                if ($propId === null || $value === null) {
                    return ['ok' => false, 'errorCode' => 'INVALID_OTHER_PROPS', 'error' => 'other_props contains invalid key/value'];
                }

                $otherProps[$propId] = $value;
                if (count($otherProps) > self::MAX_OTHER_PROPS) {
                    return ['ok' => false, 'errorCode' => 'OTHER_PROPS_LIMIT', 'error' => 'other_props exceeded max allowed size'];
                }
            }
        }

        $data = [
            'productId' => $productId,
            'volume' => $volume,
            'width' => $width,
            'height' => $height,
            'basketQty' => $basketQty,
            'visibleGroups' => $visibleGroups,
            'activeGroupId' => $activeGroupId,
            'otherProps' => $otherProps,
            'debug' => $input->post('debug') === 'Y',
        ];

        if (!ValidationRules::hasCustomInput($data['width'], $data['height'], $data['volume'])) {
            return ['ok' => false, 'errorCode' => 'MISSING_DIMENSIONS', 'error' => 'At least one of volume or width+height required'];
        }

        return ['ok' => true, 'data' => $data];
    }

    /** @return array{ok:bool,dto?:CalcPriceRequest,error?:string,errorCode?:string} */
    public function parseDtoFromInput(RequestInput $input): array
    {
        $parsed = $this->parseFromInput($input);
        if (!$parsed['ok']) {
            return $parsed;
        }

        return ['ok' => true, 'dto' => CalcPriceRequest::fromArray($parsed['data'])];
    }

    /** @param array<string,mixed> $input @param string[] $allowed @return string[] */
    private function findUnknownKeys(array $input, array $allowed): array
    {
        $unknown = array_diff(array_keys($input), $allowed);
        sort($unknown);

        return array_values($unknown);
    }

    private function parseNullableBoundedInt(mixed $value, string $key): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->parseBoundedInt($value, $key);
    }

    private function parseBoundedInt(mixed $value, string $key): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
            return null;
        }

        $int = (int)$value;
        $limits = self::LIMITS[$key] ?? null;
        if ($limits === null) {
            return $int;
        }

        if ($int < $limits['min'] || $int > $limits['max']) {
            return null;
        }

        return $int;
    }
}
