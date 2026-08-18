<?php
// Lightweight regression test for the scraper's Toman price normalization.
// This mirrors the expected behavior of the plugin: Persian/Arabic digits and
// thousand separators are normalized, and Toman values are NOT multiplied.

function clean_digits($s) {
    return strtr($s, array(
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        '٬'=>',', '٫'=>'.'
    ));
}

function money($value) {
    $v = clean_digits((string) $value);
    $v = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
    $v = str_replace(array('تومان','تومن','ریال','ريال','﷼','IRR','IRT'), '', $v);
    $v = trim($v);
    $v = preg_replace('/[^0-9,.]/u', '', $v);
    if ($v === '') return '';

    $lastComma = strrpos($v, ',');
    $lastDot   = strrpos($v, '.');
    $sep = false;
    if ($lastComma !== false || $lastDot !== false) {
        $sep = ($lastComma === false) ? '.' : (($lastDot === false) ? ',' : (($lastComma > $lastDot) ? ',' : '.'));
    }

    if ($sep !== false) {
        $pos = strrpos($v, $sep);
        $parts = explode($sep, $v);
        $fraction = end($parts);
        $before = substr($v, 0, $pos);
        $before = preg_replace('/[^0-9]/', '', $before);
        $fraction = preg_replace('/[^0-9]/', '', $fraction);
        // A 3-digit suffix is treated as a thousands group, not decimals.
        if (strlen($fraction) === 3) {
            $v = $before . $fraction;
        } else {
            $v = $before . '.' . $fraction;
        }
    } else {
        $v = preg_replace('/[^0-9]/', '', $v);
    }

    if ($v === '' || !is_numeric($v)) return '';
    return (string) round((float) $v);
}

$cases = array(
    '180.000 تومان' => '180000',
    '180,000 تومان' => '180000',
    '۱۸۰.۰۰۰ تومان' => '180000',
    '۱۸۰٬۰۰۰ تومان' => '180000',
    '1.250.000 تومان' => '1250000',
    '1,250,000 تومان' => '1250000',
    '500.000' => '500000',
    '180000 تومان' => '180000',
);

foreach ($cases as $input => $expected) {
    $actual = money($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$input} => {$actual}; expected {$expected}\n");
        exit(1);
    }
    echo "PASS: {$input} => {$actual}\n";
}

echo "All price parser regression tests passed.\n";
