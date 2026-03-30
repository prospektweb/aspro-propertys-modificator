<?php
// Existing code... // Assuming this section contains other necessary logic.

// Add mapping for volume and format enums
$volumeEnumMap = []; // Populate this map using CIBlockPropertyEnum::GetList
while ($item = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => 'your_property_id_here'])->Fetch()) {
    $volumeEnumMap[$item['ID']] = $item['XML_ID'];
}

$formatEnumMap = [];
while ($item = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => 'your_property_id_here'])->Fetch()) {
    $formatEnumMap[$item['ID']] = $item['XML_ID'];
}

// Inject maps into window.pmodConfig
echo "<script>window.pmodConfig = { volumeEnumMap: ".json_encode($volumeEnumMap).", formatEnumMap: ".json_encode($formatEnumMap)." };</script>";
?>