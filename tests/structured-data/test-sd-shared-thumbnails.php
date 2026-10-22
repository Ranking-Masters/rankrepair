<?php
/**
 * Controle op dezelfde categorieafbeelding bij meerdere categorieën:
 * de logica (puur) en de weergave in de inspector (met WordPress-vervangers).
 * Run: php tests/structured-data/test-sd-shared-thumbnails.php
 */
define('ABSPATH', true);

$GLOBALS['t'] = ['opts' => [], 'terms' => [], 'meta' => [], 'transients' => []];
function get_option($n, $d = false) { return $GLOBALS['t']['opts'][$n] ?? $d; }
function update_option($n, $v, $a = null) { $GLOBALS['t']['opts'][$n] = $v; return true; }
function apply_filters($tag, $value) { return $value; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_field($a = -1) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function taxonomy_exists($t) { return $t === 'product_cat'; }
function get_terms($args = []) { return $GLOBALS['t']['terms']; }
function is_wp_error($x) { return false; }
function get_term_meta($id, $key, $single = false) { return $GLOBALS['t']['meta'][$id] ?? ''; }
function get_edit_term_link($id, $tax) { return "https://example.test/wp-admin/term.php?tag_ID=$id&taxonomy=$tax"; }
function get_transient($k) { return $GLOBALS['t']['transients'][$k] ?? false; }
function set_transient($k, $v, $e = 0) { $GLOBALS['t']['transients'][$k] = $v; return true; }
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

require __DIR__ . '/../../addons/structured-data/class-sd-template.php';
require __DIR__ . '/../../addons/structured-data/class-sd-term-image.php';
require __DIR__ . '/../../addons/structured-data/class-sd-scanner.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

// ── Logica ──
$terms = [
    362 => ['name' => 'Bedbanken', 'thumb' => 7404], 290 => ['name' => 'Opklapbed', 'thumb' => 7404],
    364 => ['name' => 'Bedbank 140', 'thumb' => 8366], 366 => ['name' => 'Bedbank 2-persoons', 'thumb' => 8366], 365 => ['name' => 'Bedbank 160', 'thumb' => 8366],
    722 => ['name' => 'Stoffen', 'thumb' => 0], 800 => ['name' => 'Zonder foto', 'thumb' => 0], 801 => ['name' => 'Uniek', 'thumb' => 9001],
];
$g = RR_SD_Term_Image::find_shared_thumbnails($terms);
ok(count($g) === 2, 'twee groepen met een gedeelde afbeelding gevonden');
ok($g[0]['thumb'] === 8366 && count($g[0]['terms']) === 3, 'grootste groep eerst (drie categorieën met dezelfde foto)');
ok($g[1]['thumb'] === 7404 && array_values($g[1]['terms']) === ['Bedbanken', 'Opklapbed'], 'tweede groep met de namen van de categorieën');
ok(RR_SD_Term_Image::find_shared_thumbnails(['1' => ['name' => 'A', 'thumb' => 0], '2' => ['name' => 'B', 'thumb' => 0]]) === [], 'categorieën zonder afbeelding tellen niet als gedeeld');
ok(RR_SD_Term_Image::find_shared_thumbnails([1 => ['name' => 'A', 'thumb' => 5]]) === [], 'één categorie met een afbeelding is geen groep');
ok(RR_SD_Term_Image::find_shared_thumbnails([]) === [], 'lege invoer geeft geen groepen');
ok(RR_SD_Term_Image::find_shared_thumbnails([1 => [], 2 => ['thumb' => '7'], 3 => ['thumb' => 7, 'name' => 'C']]) !== null, 'onvolledige gegevens geven geen fout');

// ── WordPress-laag: rapport ──
function term($id, $name) { return (object) ['term_id' => $id, 'name' => $name]; }
$GLOBALS['t']['terms'] = [term(362, 'Bedbanken'), term(290, 'Opklapbed'), term(722, 'Stoffen')];
$GLOBALS['t']['meta']  = [362 => 7404, 290 => 7404, 722 => ''];
$r = RR_SD_Term_Image::shared_thumbnails_report('product_cat');
ok(count($r) === 1 && $r[0]['thumb'] === 7404, 'rapport vindt de gedeelde afbeelding op een site');
ok(RR_SD_Term_Image::shared_thumbnails_report('post_tag') === [], 'onbekende taxonomie (geen WooCommerce) geeft een leeg rapport');
$GLOBALS['t']['terms'] = [];
ok(count(RR_SD_Term_Image::shared_thumbnails_report('product_cat')) === 1, 'tweede aanroep komt uit de cache (zelfde versie)');

// ── Weergave in de inspector ──
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
$scanner = (new ReflectionClass('RR_SD_Scanner'))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod('RR_SD_Scanner', 'render_custom_jsonld_box');
if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
function render_box($scanner, $method): string {
    ob_start();
    try { $method->invokeArgs($scanner, ['product_cat_l1', '', 'Productcat. (L1)', ['product_cat_l1' => ['label' => 'L1']]]); }
    finally { $html = ob_get_clean(); }
    return $html;
}
$GLOBALS['t']['transients'] = []; $GLOBALS['t']['opts'] = [];
$GLOBALS['t']['terms'] = [term(362, 'Bedbanken'), term(290, 'Opklapbed')]; $GLOBALS['t']['meta'] = [362 => 7404, 290 => 7404];
try { $html = render_box($scanner, $method); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'rr-sd-shared') !== false, 'inspector toont de melding bij een gedeelde afbeelding' . ($err ? " ($err)" : ''));
ok(strpos($html, 'Bedbanken') !== false && strpos($html, 'Opklapbed') !== false && strpos($html, 'Afbeelding 7404') !== false, 'melding noemt de categorieën en de afbeelding');
ok(strpos($html, 'term.php?tag_ID=362') !== false, 'categorienamen linken naar het bewerkscherm');

$GLOBALS['t']['transients'] = []; $GLOBALS['t']['terms'] = [term(362, 'Bedbanken'), term(290, 'Opklapbed')]; $GLOBALS['t']['meta'] = [362 => 7404, 290 => 7405];
try { $html = render_box($scanner, $method); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'rr-sd-shared') === false && strpos($html, 'rr-sd-custom-form') !== false, 'geen gedeelde afbeeldingen → geen melding, scherm rendert gewoon' . ($err ? " ($err)" : ''));

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
