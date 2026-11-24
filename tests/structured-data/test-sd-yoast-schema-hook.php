<?php
/**
 * De WordPress-laag van de Yoast-schemahook (RR_SD_Term_Image::yoast_schema_graph) met
 * WordPress-vervangers, zodat een onbekende variabele of fatale fout opvalt (zoals bij 1.9.6).
 * Run: php tests/structured-data/test-sd-yoast-schema-hook.php
 */
define('ABSPATH', true);

$GLOBALS['t'] = ['opts' => [], 'term' => null, 'is_term_page' => true, 'thumb' => [], 'transients' => []];
function get_option($n, $d = false) { return $GLOBALS['t']['opts'][$n] ?? $d; }
function update_option($n, $v, $a = null) { $GLOBALS['t']['opts'][$n] = $v; return true; }
function apply_filters($tag, $value) { return $value; }
function add_action() {} function add_filter() {}
function is_category() { return $GLOBALS['t']['is_term_page']; } function is_tag() { return false; } function is_tax() { return false; }
function get_queried_object() { return $GLOBALS['t']['term']; }
function get_transient($k) { return $GLOBALS['t']['transients'][$k] ?? false; }
function set_transient($k, $v, $e = 0) { $GLOBALS['t']['transients'][$k] = $v; return true; }
function get_term_meta($id, $key, $single = false) { return $GLOBALS['t']['thumb'][$id] ?? ''; }
function wp_get_attachment_image_url($id, $size = 'thumbnail') { return $id ? "https://x.nl/uploads/att-$id-$size.jpg" : false; }
function wp_get_attachment_url($id) { return $id ? "https://x.nl/uploads/att-$id.jpg" : false; }
function attachment_url_to_postid($url) { return preg_match('#att-(\d+)#', (string) $url, $m) ? (int) $m[1] : 0; }
function get_post_meta($id, $key, $single = false) { return $key === '_wp_attachment_image_alt' ? "Alt $id" : ''; }
function get_the_title($id) { return "Titel $id"; }
function wp_get_attachment_image_src($id, $size = 'full') { return ["https://x.nl/uploads/att-$id.jpg", 1200, 800, false]; }
function get_terms($args = []) { return []; }
function is_wp_error($x) { return false; }
function get_taxonomy($t) { return false; }
function get_posts($a = []) { return []; }
function get_theme_mod($k, $d = false) { return $d; }

require __DIR__ . '/../../addons/structured-data/class-sd-term-image.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

function graph() {
    return [
        ['@type' => 'CollectionPage', '@id' => 'https://x.nl/cat/', 'thumbnailUrl' => 'https://x.nl/uploads/eerste.jpg', 'image' => ['@id' => 'https://x.nl/cat/#primaryimage']],
        ['@type' => 'ImageObject', '@id' => 'https://x.nl/cat/#primaryimage', 'url' => 'https://x.nl/uploads/eerste.jpg', 'contentUrl' => 'https://x.nl/uploads/eerste.jpg', 'width' => 1000, 'height' => 1000, 'caption' => 'Eerste'],
    ];
}
function reset_state(array $o = []) {
    $GLOBALS['t'] = ['opts' => $o, 'term' => (object) ['term_id' => 7, 'taxonomy' => 'product_cat'], 'is_term_page' => true, 'thumb' => [], 'transients' => []];
}
function run(array $g = null) {
    try { return [RR_SD_Term_Image::yoast_schema_graph($g ?? graph(), null), '']; }
    catch (Throwable $e) { return [null, $e->getMessage()]; }
}
function primary(array $g) { foreach ($g as $p) { if (($p['@type'] ?? '') === 'ImageObject') return $p; } return null; }
function with_social_field(string $url) { return ['wpseo_taxonomy_meta' => ['product_cat' => [7 => ['wpseo_opengraph-image' => $url]]]]; }

// 1. Instelling uit: niets aanpassen.
reset_state(with_social_field('https://x.nl/uploads/att-5.jpg'));
[$out, $err] = run();
ok($err === '' && $out === graph(), 'instelling uit → graaf ongewijzigd' . ($err ? " ($err)" : ''));

// 2. Instelling aan, geen termpagina: niets aanpassen.
reset_state(['rr_sd_yoast_schema_image' => '1'] + with_social_field('https://x.nl/uploads/att-5.jpg')); $GLOBALS['t']['is_term_page'] = false;
[$out, $err] = run();
ok($err === '' && $out === graph(), 'geen categoriepagina → graaf ongewijzigd' . ($err ? " ($err)" : ''));

// 3. Aan + social image ingesteld in Yoast: schema volgt die afbeelding, met maten van de volledige versie.
reset_state(['rr_sd_yoast_schema_image' => '1'] + with_social_field('https://x.nl/uploads/att-5.jpg'));
[$out, $err] = run();
$i = $out ? primary($out) : null;
ok($err === '' && $i['url'] === 'https://x.nl/uploads/att-5.jpg' && $i['contentUrl'] === 'https://x.nl/uploads/att-5.jpg', 'aan + social image → schema-afbeelding gelijk aan de social image' . ($err ? " ($err)" : ''));
ok($i['width'] === 1200 && $i['height'] === 800 && $i['caption'] === 'Alt 5', 'maten en bijschrift (alt-tekst) van de nieuwe afbeelding');
ok($out[0]['thumbnailUrl'] === 'https://x.nl/uploads/att-5.jpg', 'thumbnailUrl van de pagina volgt');

// 4. Aan, social image staat als verkleinde versie: URL klopt, maten weggelaten (we kennen ze niet).
reset_state(['rr_sd_yoast_schema_image' => '1'] + with_social_field('https://x.nl/uploads/att-5-300x300.jpg'));
[$out, $err] = run(); $i = $out ? primary($out) : null;
ok($err === '' && $i['url'] === 'https://x.nl/uploads/att-5-300x300.jpg' && !isset($i['width']) && !isset($i['height']), 'verkleinde afbeelding: url klopt, geen verzonnen maten' . ($err ? " ($err)" : ''));

// 5. Aan, veld leeg, vullen uit: niets aanpassen.
reset_state(['rr_sd_yoast_schema_image' => '1']);
[$out, $err] = run();
ok($err === '' && $out === graph(), 'veld leeg + "social image vullen" uit → ongewijzigd' . ($err ? " ($err)" : ''));

// 6. Aan, veld leeg, vullen aan: categorieafbeelding (WooCommerce-thumbnail) wordt de schema-afbeelding.
reset_state(['rr_sd_yoast_schema_image' => '1', 'rr_sd_yoast_social_image' => '1']); $GLOBALS['t']['thumb'][7] = 9;
[$out, $err] = run(); $i = $out ? primary($out) : null;
ok($err === '' && $i['url'] === 'https://x.nl/uploads/att-9-large.jpg', 'veld leeg + vullen aan → categorieafbeelding als schema-afbeelding' . ($err ? " ($err)" : ''));

// 7. Aan + vullen aan, maar de categorie heeft niets: ongewijzigd.
reset_state(['rr_sd_yoast_schema_image' => '1', 'rr_sd_yoast_social_image' => '1']);
[$out, $err] = run();
ok($err === '' && $out === graph(), 'geen afbeelding gevonden → ongewijzigd' . ($err ? " ($err)" : ''));

// 8. Aan, categorieafbeelding is het logo: niet gebruiken.
reset_state(['rr_sd_yoast_schema_image' => '1', 'rr_sd_yoast_social_image' => '1', 'rr_sd_org_logo' => 'https://x.nl/uploads/att-9.jpg']); $GLOBALS['t']['thumb'][7] = 9;
[$out, $err] = run();
ok($err === '' && $out === graph(), 'categorieafbeelding is het bedrijfslogo → niet gebruikt, graaf ongewijzigd' . ($err ? " ($err)" : ''));

// 9. Onverwachte invoer van Yoast geeft geen fout.
reset_state(['rr_sd_yoast_schema_image' => '1'] + with_social_field('https://x.nl/uploads/att-5.jpg'));
[$out, $err] = run(['rommel', null]);
ok($err === '' && $out === ['rommel', null], 'onverwachte inhoud in de graaf geeft geen fout' . ($err ? " ($err)" : ''));
try { $o = RR_SD_Term_Image::yoast_schema_graph('geen-array', null); $err = ''; } catch (Throwable $e) { $o = null; $err = $e->getMessage(); }
ok($err === '' && $o === 'geen-array', 'geen array van Yoast → onveranderd teruggegeven' . ($err ? " ($err)" : ''));

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
