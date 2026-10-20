<?php
/**
 * Voorbeeldtemplates (inspector) en de regel voor de bedrijfsafbeelding op term-pagina's.
 * Run: php tests/structured-data/test-sd-examples.php
 */
define('ABSPATH', true);

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}

require __DIR__ . '/../../addons/structured-data/class-sd-template.php';
require __DIR__ . '/../../addons/structured-data/class-sd-builder.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

/** Zoek in een @graph de node met dit @type. */
function node(array $doc, string $type): ?array {
    foreach ($doc['@graph'] ?? [] as $n) {
        if (($n['@type'] ?? null) === $type) return $n;
    }
    return null;
}

$types = ['product_cat_l1', 'product_cat_l2', 'product_cat_l3', 'category', 'home', 'page', 'post', 'blog_index', 'contact', 'product'];

// Elk voorbeeld is geldige JSON en heeft een Organization met het bedrijfslogo (geen vaste afbeelding).
foreach ($types as $t) {
    $json = RR_SD_Template::get_example_template($t);
    $doc  = json_decode($json, true);
    ok(is_array($doc), "voorbeeld $t is geldige JSON");
    $org = node($doc ?: [], 'Organization');
    if ($org !== null) {
        ok(($org['logo'] ?? '') === '{{org_logo}}', "voorbeeld $t: Organization gebruikt {{org_logo}}");
        ok(!array_key_exists('image', $org), "voorbeeld $t: Organization heeft geen (vaste) image");
    }
}

// Categorie-voorbeelden gebruiken de dynamische categorieafbeelding.
foreach (['product_cat_l2', 'product_cat_l3'] as $t) {
    $group = node(json_decode(RR_SD_Template::get_example_template($t), true), 'ProductGroup');
    ok(($group['image'] ?? '') === '{{term_image}}', "voorbeeld $t: ProductGroup heeft image {{term_image}}");
}
$cp = node(json_decode(RR_SD_Template::get_example_template('category'), true), 'CollectionPage');
ok(($cp['image'] ?? '') === '{{term_image}}', 'voorbeeld category: CollectionPage heeft image {{term_image}}');

// Zonder logo en zonder categorieafbeelding verdwijnen die keys, de rest blijft staan.
$l2   = RR_SD_Template::get_example_template('product_cat_l2');
$out  = RR_SD_Template::drop_empty_placeholders($l2, ['org_logo' => '', 'term_image' => '', 'site_name' => 'X'], ['breadcrumb_items' => [['a']], 'children_variants' => [['b']]]);
$doc  = json_decode($out, true);
ok(is_array($doc) && !isset(node($doc, 'Organization')['logo']), 'leeg logo → logo-key weg uit de Organization');
ok(is_array($doc) && !isset(node($doc, 'ProductGroup')['image']), 'lege term_image → image-key weg uit de ProductGroup');
ok(is_array($doc) && node($doc, 'ProductGroup')['hasVariant'] === '{{children_variants}}', 'overige placeholders blijven staan');

// Vaste bedrijfsafbeelding alleen op niet-term-pagina's.
ok(RR_SD_Builder::org_image_allowed(['type' => 'home']) === true, 'home: bedrijfsafbeelding toegestaan');
ok(RR_SD_Builder::org_image_allowed(['type' => 'page', 'post_id' => 5]) === true, 'pagina: bedrijfsafbeelding toegestaan');
ok(RR_SD_Builder::org_image_allowed(['type' => 'product_cat_l2', 'term' => (object) ['term_id' => 3]]) === false, 'productcategorie: geen bedrijfsafbeelding');
ok(RR_SD_Builder::org_image_allowed(['type' => 'category', 'term' => (object) ['term_id' => 4]]) === false, 'blogcategorie: geen bedrijfsafbeelding');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
