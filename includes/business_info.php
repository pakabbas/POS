<?php
// Business details shown on screens and printed slips, edited from Settings.
// Stored one per line in restaurantInfo.txt: name, tagline, phone, address.
function business_info(): array {
    $info = ['name' => 'Saeed Egg Dealer', 'tagline' => '', 'phone' => '', 'address' => ''];
    $file = __DIR__ . '/../restaurantInfo.txt';
    if (is_readable($file)) {
        $lines = array_map('trim', file($file));
        foreach (['name', 'tagline', 'phone', 'address'] as $i => $k) {
            if (isset($lines[$i])) $info[$k] = $lines[$i];
        }
        if ($info['name'] === '') $info['name'] = 'Saeed Egg Dealer';
    }
    return $info;
}
