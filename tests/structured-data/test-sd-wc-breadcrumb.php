<?php
/**
 * Schakelaar "WooCommerce BreadcrumbList uitzetten" + hint in de inspector.
 * Run: php tests/structured-data/test-sd-wc-breadcrumb.php
 */
define('ABSPATH', true);
$GLOBALS['t'] = ['opts' => [], 'filters' => []];
function get_option($n, $d = false) { return $GLOBALS['t']['opts'][$n] ?? $d; }
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['t']['filters'][$tag][] = [$cb, $prio, $args]; }
function apply_filters($tag, $value) { return $value; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_html_e($t, $d = null) { echo esc_html($t); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return (string) $t; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }

require __DIR__ . '/../../addons/structured-data/class-sd-dedupe.php';
require __DIR__ . '/../../addons/structured-data/class-sd-template.php';
require __DIR__ . '/../../addons/structured-data/class-sd-scanner.php';

$fail = 0;
function ok($cond, $msg) { global $fail; if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; } echo "ok  $msg\n"; }
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

// Zoals WC_Structured_Data::set_data(): zonder geldig @type komt er niets in de uitvoer.
function wc_set_data($data) {
    if (!isset($data['@type']) || !preg_match('|^[a-zA-Z]{1,20}$|', $data['@type'])) return false;
    return true;
}

$markup = ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1]]];

// 1. Hook is geregistreerd
RR_SD_Dedupe::register_hooks();
ok(isset($GLOBALS['t']['filters']['woocommerce_structured_data_breadcrumblist']), 'hangt aan woocommerce_structured_data_breadcrumblist');
ok(isset($GLOBALS['t']['filters']['wpseo_schema_graph']), 'bestaande hook voor de dedupe blijft geregistreerd');

// 2. Zonder WooCommerce doet de schakelaar niets, ook als de optie aan staat
$GLOBALS['t']['opts']['rr_sd_disable_wc_breadcrumb'] = '1';
ok(!class_exists('WooCommerce'), 'testopstelling: WooCommerce niet geladen');
ok(RR_SD_Dedupe::wc_breadcrumb_disabled() === false, 'zonder WooCommerce: niet actief');
ok(RR_SD_Dedupe::filter_wc_breadcrumb($markup) === $markup, 'zonder WooCommerce: markup ongewijzigd');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([['data' => $markup, 'source' => ''], ['data' => ['@type' => 'BreadcrumbList', '@id' => 'x#breadcrumb'], 'source' => 'Yoast SEO']]) === false, 'zonder WooCommerce: geen hint');

// 3. Met WooCommerce, optie standaard uit: niets verandert
eval('class WooCommerce {}'); // pas nu declareren: een gewone class-declaratie wordt bovenaan het bestand al geladen
$GLOBALS['t']['opts'] = [];
ok(RR_SD_Dedupe::wc_breadcrumb_disabled() === false, 'standaard uit');
ok(RR_SD_Dedupe::filter_wc_breadcrumb($markup) === $markup, 'standaard uit: markup ongewijzigd');
ok(wc_set_data(RR_SD_Dedupe::filter_wc_breadcrumb($markup)) === true, 'standaard uit: WooCommerce geeft zijn BreadcrumbList nog uit');

// 4. Met WooCommerce, optie aan: lege array, WooCommerce slaat het over
$GLOBALS['t']['opts']['rr_sd_disable_wc_breadcrumb'] = '1';
ok(RR_SD_Dedupe::wc_breadcrumb_disabled() === true, 'aan: actief');
ok(RR_SD_Dedupe::filter_wc_breadcrumb($markup) === [], 'aan: lege array');
ok(wc_set_data(RR_SD_Dedupe::filter_wc_breadcrumb($markup)) === false, 'aan: set_data() weigert een leeg array, dus geen WooCommerce-blok');
$GLOBALS['t']['opts']['rr_sd_disable_wc_breadcrumb'] = '0';
ok(RR_SD_Dedupe::filter_wc_breadcrumb($markup) === $markup, 'weer uit: markup ongewijzigd');

// 5. Hint in de inspector
$yoast = ['data' => ['@type' => 'BreadcrumbList', '@id' => 'https://x.test/c/#breadcrumb'], 'source' => 'Yoast SEO'];
$wcbl  = ['data' => $markup, 'source' => ''];
$wcbl2 = ['data' => $markup, 'source' => 'WooCommerce'];
$rr    = ['data' => ['@type' => 'BreadcrumbList'], 'source' => 'RankRepair'];
$org   = ['data' => ['@type' => 'Organization', '@id' => 'https://x.test/#organization'], 'source' => 'Yoast SEO'];

$GLOBALS['t']['opts'] = [];
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$org, $yoast, $wcbl]) === true, 'hint: Yoast + blok zonder @id');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$org, $yoast, $wcbl2]) === true, 'hint: bron WooCommerce herkend');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$org, $yoast]) === false, 'geen hint met één BreadcrumbList');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$org, $yoast, $rr]) === false, 'geen hint als de tweede van RankRepair is');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$wcbl]) === false, 'geen hint met alleen WooCommerce (er is dan niets dubbel)');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([]) === false, 'geen hint zonder schemas');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([['data' => 'rommel'], ['geen' => 'data'], $yoast, $wcbl]) === true, 'rommel in de lijst veroorzaakt geen fout');
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([['data' => ['@type' => ['BreadcrumbList']], 'source' => ''], $yoast]) === true, '@type als lijst herkend');
$GLOBALS['t']['opts']['rr_sd_disable_wc_breadcrumb'] = '1';
ok(RR_SD_Scanner::has_wc_breadcrumb_duplicate([$org, $yoast, $wcbl]) === false, 'geen hint als de schakelaar aan staat');

// 6. De hint rendert zonder fouten en verwijst naar de instelling
$scanner = (new ReflectionClass('RR_SD_Scanner'))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod('RR_SD_Scanner', 'render_wc_breadcrumb_hint');
if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
function render_hint($scanner, $method, array $schemas): string {
    ob_start();
    try { $method->invoke($scanner, $schemas); } finally { $html = ob_get_clean(); }
    return $html;
}
$GLOBALS['t']['opts'] = [];
try { $html = render_hint($scanner, $method, [$org, $yoast, $wcbl]); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'WooCommerce BreadcrumbList uitzetten') !== false && strpos($html, 'rankrepair-settings') !== false, 'hint rendert zonder fout en noemt de instelling' . ($err ? " ($err)" : ''));
try { $html = render_hint($scanner, $method, [$org, $yoast]); $err = ''; } catch (Throwable $e) { $html = 'x'; $err = $e->getMessage(); }
ok($err === '' && $html === '', 'geen hint: er wordt niets uitgevoerd' . ($err ? " ($err)" : ''));

echo $fail ? "\n$fail test(s) mislukt\n" : "\nAlles ok\n";
exit($fail ? 1 : 0);
