<?php
// Mock Data
$desc1 = "Select Package: Deluxe Package ; Automated Chlorinators & Sanitizers: Hayward 9lb Chlorine Dispenser CL220 ; Chemicals: Starter Package ; Ladders & Steps: Resin Above Ground Deck Ladder (SKU: RAGDL ; Price: 128.79) ; Some Other Prop: Value";
$desc2 = "Select Package: Deluxe Package ; Addon: Logic Test: Legacy Value (SKU: LEGACY ; Price: 99.99)";

$tests = [
    'Generalized Format' => $desc1,
    'Legacy Format' => $desc2
];

foreach ($tests as $name => $desc) {
    echo "Testing $name: $desc\n";
    $addonsFound = [];

    // Generalized Regex
    if (preg_match_all('/(?:^|;)\s*([^:;]+):\s*([^:;]+?)\s*\(SKU:\s*(.*?)\s*[;|]\s*Price:\s*(.*?)\)/i', $desc, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = trim($m[1]);
            if (strpos($key, 'Addon SKU') === 0 || strpos($key, 'Addon Price') === 0)
                continue;

            $variantTitle = trim($m[2]);

            if (!isset($addonsFound[$key]))
                $addonsFound[$key] = [];
            $addonsFound[$key]['sku'] = trim($m[3]);
            $addonsFound[$key]['price'] = trim($m[4]);
            $addonsFound[$key]['variant'] = $variantTitle;
        }
    }

    // Legacy Regex
    if (preg_match_all('/Addon:\s*(.*?):\s*(.*?)\s*\(SKU:\s*(.*?)\s*[;|]\s*Price:\s*(.*?)\)/i', $desc, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = trim($m[1]);
            $variantTitle = trim($m[2]);
            if (!isset($addonsFound[$key]))
                $addonsFound[$key] = [];
            $addonsFound[$key]['sku'] = trim($m[3]);
            $addonsFound[$key]['price'] = trim($m[4]);
            $addonsFound[$key]['variant'] = $variantTitle;
        }
    }

    print_r($addonsFound);
    echo "---------------------------------\n";
}
