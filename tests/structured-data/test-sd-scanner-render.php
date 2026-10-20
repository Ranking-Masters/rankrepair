<?php
/**
 * Rendert het eigen-JSON-LD-blok van de inspector echt (met WordPress-vervangers), zodat een
 * onbekende variabele of fatale fout in de weergave opvalt. De eerdere v1.9.6 liep hier op stuk
 * ($types bestond niet in render_custom_jsonld_box).
 * Run: php tests/structured-data/test-sd-scanner-render.php
 */
define('ABSPATH', true);

$GLOBALS['rr_opts'] = [];
function get_option($name, $default = false) { return $GLOBALS['rr_opts'][$name] ?? $default; }
function apply_filters($tag, $value) { return $value; }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function __($t, $d = null) { return $t; }
function _e($t, $d = null) { echo $t; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_nonce_field($a = -1) { echo '<input type="hidden" name="_wpnonce" value="x">'; }

require __DIR__ . '/../../addons/structured-data/class-sd-template.php';
require __DIR__ . '/../../addons/structured-data/class-sd-scanner.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

// Elke PHP-waarschuwing of -melding in de weergave telt als fout.
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$scanner = (new ReflectionClass('RR_SD_Scanner'))->newInstanceWithoutConstructor();
$method  = new ReflectionMethod('RR_SD_Scanner', 'render_custom_jsonld_box');
$method->setAccessible(true);

function render_box($scanner, $method, string $selected, string $value, array $types = null): string {
    ob_start();
    try {
        $args = [$selected, $value, 'Productcat. (L1)'];
        if ($types !== null) $args[] = $types;
        $method->invokeArgs($scanner, $args);
    } finally {
        $html = ob_get_clean();
    }
    return $html;
}

$types = ['home' => ['label' => 'Home'], 'product_cat_l1' => ['label' => 'L1'], 'product_cat_l2' => ['label' => 'L2'], 'page' => ['label' => 'Pagina']];
$clean = '{"@context":"https://schema.org","@graph":[{"@type":"CollectionPage","image":"{{term_image}}"}]}';

// 1. Schone template: rendert zonder fouten en zonder waarschuwing.
$GLOBALS['rr_opts'] = ['rr_sd_custom_jsonld_product_cat_l1' => $clean];
try { $html = render_box($scanner, $method, 'product_cat_l1', $clean, $types); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'rr-sd-custom-form') !== false, 'blok rendert zonder fout met een schone template' . ($err ? " ($err)" : ''));
ok(strpos($html, 'rr-sd-audit') === false, 'schone template: geen waarschuwing');

// 2. Vaste afbeelding in een opgeslagen template: waarschuwing met pad en URL.
$fixed = '{"@graph":[{"@type":"Organization","image":"https://x.nl/vast.jpg"}]}';
$GLOBALS['rr_opts'] = ['rr_sd_custom_jsonld_product_cat_l2' => $fixed];
try { $html = render_box($scanner, $method, 'product_cat_l1', $clean, $types); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '', 'blok rendert zonder fout als een ander niveau een vaste afbeelding heeft' . ($err ? " ($err)" : ''));
ok(strpos($html, 'rr-sd-audit') !== false && strpos($html, 'product_cat_l2') !== false && strpos($html, 'https://x.nl/vast.jpg') !== false, 'waarschuwing noemt type, pad en URL');
ok(strpos($html, '@graph[0].image') !== false, 'waarschuwing noemt het pad in de JSON');

// 3. Zonder doorgegeven typen (oude aanroep) mag het niet crashen.
try { $html = render_box($scanner, $method, 'product_cat_l1', $clean); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '', 'blok rendert ook zonder doorgegeven typen' . ($err ? " ($err)" : ''));

// 4. "alle pagina's" met een vaste afbeelding wordt ook gemeld, zonder typen-lijst.
$GLOBALS['rr_opts'] = ['rr_sd_custom_jsonld_all' => '{"image":"/wp-content/uploads/a.jpg"}'];
try { $html = render_box($scanner, $method, 'product_cat_l1', $clean); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'rr-sd-audit') !== false, '"alle pagina\'s" met vaste afbeelding wordt gemeld' . ($err ? " ($err)" : ''));

// 5. Lege opties en ongeldige JSON in de opgeslagen templates.
$GLOBALS['rr_opts'] = ['rr_sd_custom_jsonld_page' => '{ongeldig', 'rr_sd_custom_jsonld_product_cat_l1' => ''];
try { $html = render_box($scanner, $method, 'product_cat_l1', '', $types); $err = ''; } catch (Throwable $e) { $html = ''; $err = $e->getMessage(); }
ok($err === '' && strpos($html, 'rr-sd-audit') === false, 'lege en ongeldige opgeslagen templates geven geen fout of waarschuwing' . ($err ? " ($err)" : ''));

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
