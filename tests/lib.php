<?php
/**
 * Helper assertion sederhana untuk suite uji offline.
 */

$GLOBALS['t_total'] = 0;
$GLOBALS['t_ok']    = 0;
$GLOBALS['t_fails'] = array();

function t_ok($condition, $label, $detail = '')
{
    $GLOBALS['t_total']++;
    if ($condition) {
        $GLOBALS['t_ok']++;
        echo "  ok   {$label}\n";
        return true;
    }
    $GLOBALS['t_fails'][] = $label . ($detail !== '' ? ' -- ' . $detail : '');
    echo "  FAIL {$label}" . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    return false;
}

function t_same($actual, $expected, $label)
{
    $detail = 'harap ' . var_export($expected, true) . ', dapat ' . var_export($actual, true);
    return t_ok($actual === $expected, $label, $detail);
}

function t_contains($needle, $haystack, $label)
{
    return t_ok(is_string($haystack) && strpos($haystack, $needle) !== false, $label, 'tidak memuat ' . var_export($needle, true));
}

function t_summary($suite)
{
    $total = $GLOBALS['t_total'];
    $ok    = $GLOBALS['t_ok'];
    $fails = count($GLOBALS['t_fails']);

    echo "\n=== {$suite}: {$ok}/{$total} ok, {$fails} gagal ===\n";
    if ($fails > 0) {
        foreach ($GLOBALS['t_fails'] as $fail) {
            echo "  - {$fail}\n";
        }
        exit(1);
    }
}
