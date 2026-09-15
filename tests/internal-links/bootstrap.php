<?php
/**
 * Minimale WordPress-vervangers zodat de pure klassen van de Interne Links
 * add-on los te draaien zijn: `php tests/internal-links/test-x.php`.
 *
 * Alleen de functies die deze klassen echt aanroepen. Zodra een test iets
 * nodig heeft wat hier niet staat, valt hij meteen om — dat is de bedoeling:
 * dan is de code WordPress in geslopen waar dat niet hoort.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', true);
}

if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

$GLOBALS['rr_test_failures'] = 0;

function ok($cond, $msg) {
    if (!$cond) {
        $GLOBALS['rr_test_failures']++;
        fwrite(STDERR, "FAIL: $msg\n");
        return;
    }
    echo "ok: $msg\n";
}

/** Controleert dat een gate afwijst, en met wélke gate. */
function gate_rejects($verdict, $expected_gate, $msg) {
    $actual = $verdict['ok'] ? 'geaccepteerd' : $verdict['gate'];
    ok(!$verdict['ok'] && $verdict['gate'] === $expected_gate, $msg . " (verwacht $expected_gate, kreeg $actual)");
}

function done() {
    if ($GLOBALS['rr_test_failures'] > 0) {
        fwrite(STDERR, $GLOBALS['rr_test_failures'] . " FAILURES\n");
        exit(1);
    }
    echo "ALL PASS\n";
}

function il_require($relative) {
    require_once __DIR__ . '/../../addons/internal-links/' . $relative;
}
