<?php

/*
 * Sinhala validation messages for the simple-mode (kiosk) screens. Only the rules those
 * screens can trigger are translated; anything missing falls back to English.
 * A native speaker at the factory should review this wording. See docs/KIOSK.md.
 */
return [
    'required' => ':attribute අවශ්‍යයි.',
    'required_with' => ':attribute අවශ්‍යයි.',
    'numeric' => ':attribute අංකයක් විය යුතුය.',
    'integer' => ':attribute පූර්ණ සංඛ්‍යාවක් විය යුතුය.',
    'array' => ':attribute වැරදියි.',
    'exists' => 'තෝරාගත් :attribute වලංගු නැත.',
    'in' => 'තෝරාගත් :attribute වලංගු නැත.',
    'date' => ':attribute වලංගු දිනයක් නොවේ.',
    'prohibited' => ':attribute ඇතුළත් කළ යුතු නැත.',
    'gt' => [
        'numeric' => ':attribute :value ට වඩා වැඩි විය යුතුය.',
    ],
    'min' => [
        'numeric' => ':attribute අවම වශයෙන් :min විය යුතුය.',
        'array' => ':attribute අවම වශයෙන් :min ක් තිබිය යුතුය.',
        'string' => ':attribute අවම අකුරු :min ක් විය යුතුය.',
    ],
    'max' => [
        'numeric' => ':attribute :max ට වඩා වැඩි විය නොහැක.',
        'string' => ':attribute අකුරු :max ට වඩා වැඩි විය නොහැක.',
    ],

    'attributes' => [
        'supplier_id' => 'සැපයුම්කරු',
        'customer_id' => 'පාරිභෝගිකයා',
        'warehouse_id' => 'ගබඩාව',
        'lot_id' => 'කාණ්ඩය',
        'quantity' => 'ප්‍රමාණය',
        'lines' => 'භාණ්ඩ',
        'lines.*.product_id' => 'භාණ්ඩය',
        'lines.*.quantity' => 'ප්‍රමාණය',
        'lines.*.secondary_quantity' => 'බර',
        'lines.*.unit_price' => 'මිල',
        'lines.*.lot_id' => 'කාණ්ඩය',
        'lines.*.pricing_basis' => 'මිල කරන ආකාරය',
        'outputs' => 'නිෂ්පාදන',
        'outputs.*.quantity' => 'බර',
        'outputs.*.secondary_quantity' => 'බර',
        'transport_cost' => 'ප්‍රවාහන වියදම',
        'loading_cost' => 'පැටවීමේ වියදම',
        'vehicle_number' => 'වාහන අංකය',
        'waste_quantity' => 'අපද්‍රව්‍ය',
    ],
];
