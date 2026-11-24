<?php
/**
 * Welke opgeslagen templates komen in aanmerking voor een pagina-type (L1-terugval).
 * Run: php tests/structured-data/test-sd-template-chain.php
 */
define('ABSPATH', true);
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0) { return json_encode($d, $f); } }
require __DIR__ . '/../../addons/structured-data/class-sd-template.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}
function chain(string $t, bool $fb) { return RR_SD_Template::template_option_chain($t, $fb); }

// Zonder terugval: gedrag zoals voor 1.9.10.
ok(chain('product_cat_l1', false) === ['rr_sd_custom_jsonld_product_cat_l1', 'rr_sd_custom_jsonld_product_cat'], 'L1 zonder terugval: eigen template, dan de oude generieke');
ok(chain('product_cat_l2', false) === ['rr_sd_custom_jsonld_product_cat_l2', 'rr_sd_custom_jsonld_product_cat'], 'L2 zonder terugval: eigen template, dan de oude generieke');
ok(chain('product_cat_l3', false) === ['rr_sd_custom_jsonld_product_cat_l3', 'rr_sd_custom_jsonld_product_cat'], 'L3 zonder terugval: eigen template, dan de oude generieke');
ok(chain('home', false) === ['rr_sd_custom_jsonld_home'], 'home: alleen de eigen template');
ok(chain('category', false) === ['rr_sd_custom_jsonld_category'], 'blogcategorie: alleen de eigen template');
ok(chain('product', true) === ['rr_sd_custom_jsonld_product'], 'product: terugval is niet van toepassing');

// Met terugval: L1 komt erbij voor L2 en L3, achteraan.
ok(chain('product_cat_l2', true) === ['rr_sd_custom_jsonld_product_cat_l2', 'rr_sd_custom_jsonld_product_cat', 'rr_sd_custom_jsonld_product_cat_l1'], 'L2 met terugval: eigen, generieke, daarna L1');
ok(chain('product_cat_l3', true) === ['rr_sd_custom_jsonld_product_cat_l3', 'rr_sd_custom_jsonld_product_cat', 'rr_sd_custom_jsonld_product_cat_l1'], 'L3 met terugval: eigen, generieke, daarna L1');
ok(chain('product_cat_l1', true) === ['rr_sd_custom_jsonld_product_cat_l1', 'rr_sd_custom_jsonld_product_cat'], 'L1 met terugval: geen verwijzing naar zichzelf');
ok(chain('home', true) === ['rr_sd_custom_jsonld_home'], 'home met terugval: ongewijzigd');
ok(chain('category', true) === ['rr_sd_custom_jsonld_category'], 'blogcategorie met terugval: ongewijzigd');

// Een ingevulde eigen template gaat altijd voor (de eerste optie is altijd de eigen).
foreach (['product_cat_l1', 'product_cat_l2', 'product_cat_l3', 'home', 'page'] as $t) {
    ok(chain($t, true)[0] === 'rr_sd_custom_jsonld_' . $t, "$t: de eigen template staat altijd vooraan");
}
ok(chain('product_cat_deep', true) === ['rr_sd_custom_jsonld_product_cat_deep', 'rr_sd_custom_jsonld_product_cat'], 'onbekend diep niveau: geen L1-terugval');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
