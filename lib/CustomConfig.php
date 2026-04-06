<?php

namespace Prospektweb\PropModificator;

class CustomConfig
{
    /**
     * Нормализует JSON-конфиг из Text/HTML свойства товара.
     */
    public static function parseFromPropertyValue($rawValue): array
    {
        $json = self::extractJsonString($rawValue);
        if ($json === '') {
            return [];
        }

        $decoded = self::decodeJson($json);
        if (!is_array($decoded)) {
            return [];
        }

        if (!isset($decoded['fields']) || !is_array($decoded['fields'])) {
            return [];
        }

        $result = [
            'version' => (int)($decoded['version'] ?? 1),
            'fields'  => [],
        ];

        foreach ($decoded['fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $mode = (string)($field['mode'] ?? 'single');
            if ($mode !== 'single' && $mode !== 'group') {
                continue;
            }

            $inputs = isset($field['inputs']) && is_array($field['inputs'])
                ? array_values(array_filter($field['inputs'], 'is_array'))
                : [];

            if ($mode === 'single' && count($inputs) !== 1) {
                continue;
            }

            if ($mode === 'group' && (count($inputs) < 1 || count($inputs) > 4)) {
                continue;
            }

            $result['fields'][] = [
                'id'          => (string)($field['id'] ?? ''),
                'name'        => (string)($field['name'] ?? ''),
                'mode'        => $mode,
                'binding'     => is_array($field['binding'] ?? null) ? $field['binding'] : [],
                'replaceKeys' => isset($field['replaceKeys']) && is_array($field['replaceKeys']) ? $field['replaceKeys'] : [],
                'inputs'      => array_map([self::class, 'normalizeInput'], $inputs),
            ];
        }

        return $result;
    }

    private static function extractJsonString($rawValue): string
    {
        $json = '';

        if (is_array($rawValue)) {
            $candidates = ['~VALUE', '~TEXT', 'TEXT', 'VALUE'];
            foreach ($candidates as $key) {
                if (!array_key_exists($key, $rawValue)) {
                    continue;
                }

                if (is_array($rawValue[$key])) {
                    if (isset($rawValue[$key]['TEXT']) && is_string($rawValue[$key]['TEXT'])) {
                        $json = $rawValue[$key]['TEXT'];
                        break;
                    }
                    if (isset($rawValue[$key]['~TEXT']) && is_string($rawValue[$key]['~TEXT'])) {
                        $json = $rawValue[$key]['~TEXT'];
                        break;
                    }
                    continue;
                }

                if (is_string($rawValue[$key])) {
                    $json = $rawValue[$key];
                    break;
                }
            }
        } elseif (is_string($rawValue)) {
            $json = $rawValue;
        }

        $json = trim((string)$json);
        if ($json === '') {
            return '';
        }

        $json = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

        return trim((string)$json);
    }

    private static function decodeJson(string $json): ?array
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $normalized = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $json));
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $stripped = stripslashes($normalized);
        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    private static function normalizeInput(array $input): array
    {
        return [
            'label'             => (string)($input['label'] ?? ''),
            'min'               => self::toNullableInt($input['min'] ?? null),
            'step'              => self::toNullableInt($input['step'] ?? null),
            'max'               => self::toNullableInt($input['max'] ?? null),
            'measure'           => trim((string)($input['measure'] ?? '')),
            'showMeasure'       => !empty($input['showMeasure']),
            'hidePresetButtons' => !empty($input['hidePresetButtons']),
        ];
    }

    private static function toNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

}
