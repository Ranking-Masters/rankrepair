<?php
/**
 * Productlijst (ItemList) in het CollectionPage-blok van Yoast: de opbouw (puur) en de
 * Yoast-hook op een categoriepagina (met WordPress-vervangers).
 * Run: php tests/structured-data/test-sd-itemlist.php
 */
define('ABSPATH', true);

$GLOBALS['t'] = ['opts' => [], 'term' => null, 'is_term_page' => true, 'children' => [], 'thumb' => [], 'posts' => [], 'rows' => [], 'meta' => []];
function get_option($n, $d = false) { return $GLOBALS['t']['opts'][$n] ?? $d; }
function update_option($n, $v, $a = null) { $GLOBALS['t']['opts'][$n] = $v; return true; }
function apply_filters($tag, $value) { return $value; }
function add_action() {} function add_filter() {}
function is_category() { return $GLOBALS['t']['is_term_page']; } function is_tag() { return false; } function is_tax() { return false; }
function get_queried_object() { return $GLOBALS['t']['term']; }
function get_transient($k) { return false; } function set_transient($k, $v, $e = 0) { return true; }
function get_terms($args = []) { return $GLOBALS['t']['children'][$args['parent'] ?? 0] ?? []; }
function is_wp_error($x) { return false; }
function get_term_link($t) { return "https://x.nl/cat/{$t->slug}/"; }
function get_term_meta($id, $key, $single = false) { return $GLOBALS['t']['thumb'][$id] ?? ''; }
function wp_get_attachment_image_url($id, $size = 'thumbnail') { return $id ? "https://x.nl/uploads/att-$id-$size.jpg" : false; }
function wp_get_attachment_url($id) { return $id ? "https://x.nl/uploads/att-$id.jpg" : false; }
function attachment_url_to_postid($url) { return 0; }
function get_post_meta($id, $key, $single = false) { return ''; }
function get_the_title($id) { return "Titel $id"; }
function wp_get_attachment_image_src($id, $size = 'full') { return false; }
function get_posts($a = []) { return $GLOBALS['t']['posts']; }
function get_permalink($id) { return "https://x.nl/product-$id/"; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function get_woocommerce_currency() { return 'EUR'; }
function get_theme_mod($k, $d = false) { return $d; }
class FakeWpdb {
    public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
    public function get_results($sql) { return strpos($sql, $this->postmeta) !== false ? $GLOBALS['t']['meta'] : $GLOBALS['t']['rows']; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../../addons/structured-data/class-sd-term-image.php';
require __DIR__ . '/../../addons/structured-data/class-sd-builder.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
function reset_flag() { $p = new ReflectionProperty('RR_SD_Term_Image', 'itemlist_done'); if (PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, false); }
function node(array $g, string $type) { foreach ($g as $p) { if (($p['@type'] ?? '') === $type) return $p; } return null; }

// ── Puur: ItemList opbouwen ──
$row = ['id' => 42, 'name' => 'Bedkast Boxer', 'excerpt' => '', 'sku' => 'BM-1', 'price' => '1095', 'stock' => 'instock', 'thumb' => 7, 'url' => 'https://x.nl/bedkast-boxer/'];
$prod = RR_SD_Builder::product_item_from_row($row);
ok($prod['@type'] === 'Product' && $prod['name'] === 'Bedkast Boxer' && $prod['offers']['price'] === '1095.00', 'productnode met naam en prijs');
ok(!isset($prod['isVariantOf']), 'productnode verwijst niet naar een ProductGroup');
ok($prod['offers']['availability'] === 'https://schema.org/InStock' && $prod['image'] === 'https://x.nl/uploads/att-7-medium.jpg', 'voorraad en afbeelding blijven in de lijst');

$list = RR_SD_Builder::item_list_from_entries([['product' => $prod], ['url' => 'https://x.nl/cat/sub/', 'name' => 'Sub', 'image' => 'https://x.nl/a.jpg'], ['url' => 'https://x.nl/cat/sub2/']]);
ok($list['@type'] === 'ItemList' && $list['numberOfItems'] === 3, 'ItemList met het juiste aantal items');
ok($list['itemListElement'][0]['position'] === 1 && $list['itemListElement'][0]['item']['name'] === 'Bedkast Boxer', 'product als ListItem met item');
ok($list['itemListElement'][1]['url'] === 'https://x.nl/cat/sub/' && $list['itemListElement'][1]['name'] === 'Sub' && $list['itemListElement'][1]['image'] === 'https://x.nl/a.jpg', 'subcategorie als ListItem met url, naam en afbeelding');
ok(!isset($list['itemListElement'][2]['name']) && !isset($list['itemListElement'][2]['image']), 'ontbrekende naam of afbeelding wordt weggelaten, niet leeg gezet');
ok(RR_SD_Builder::item_list_from_entries([]) === null, 'lege invoer geeft geen lijst');
ok(RR_SD_Builder::item_list_from_entries([['name' => 'zonder url'], 'rommel', []]) === null, 'items zonder url of product worden overgeslagen');
$list2 = RR_SD_Builder::item_list_from_entries([['name' => 'x'], ['url' => 'https://x.nl/b/']]);
ok($list2['numberOfItems'] === 1 && $list2['itemListElement'][0]['position'] === 1, 'posities lopen door zonder gaten');

// ── Puur: aan het Yoast-blok toevoegen ──
$yoast = [
    ['@type' => 'CollectionPage', '@id' => 'https://x.nl/cat/', 'name' => 'SEO-titel'],
    ['@type' => 'BreadcrumbList', '@id' => 'https://x.nl/cat/#breadcrumb'],
];
$r = RR_SD_Term_Image::add_item_list($yoast, $list);
ok($r['added'] === true && $r['graph'][0]['mainEntity'] === $list, 'lijst als mainEntity aan het CollectionPage-blok van Yoast');
ok($r['graph'][0]['name'] === 'SEO-titel' && $r['graph'][0]['@id'] === 'https://x.nl/cat/' && $r['graph'][1] === $yoast[1], 'naam, @id en andere blokken blijven ongewijzigd');
ok(count($r['graph']) === count($yoast), 'er wordt geen tweede blok bijgemaakt');
$bezet = [['@type' => 'CollectionPage', 'mainEntity' => ['@type' => 'ItemList', 'x' => 1]]];
$r = RR_SD_Term_Image::add_item_list($bezet, $list);
ok($r['added'] === false && $r['graph'] === $bezet, 'een bestaande mainEntity wordt niet overschreven');
$geen = [['@type' => 'WebPage', '@id' => 'x']];
$r = RR_SD_Term_Image::add_item_list($geen, $list);
ok($r['added'] === false && $r['graph'] === $geen, 'zonder CollectionPage blijft de graaf ongewijzigd');
$r = RR_SD_Term_Image::add_item_list([['@type' => ['WebPage', 'CollectionPage'], '@id' => 'x']], $list);
ok($r['added'] === true, 'CollectionPage als lijst van types wordt herkend');
$r = RR_SD_Term_Image::add_item_list(['rommel', null, 5], $list);
ok($r['added'] === false && $r['graph'] === ['rommel', null, 5], 'onverwachte inhoud geeft geen fout');

// ── Yoast-hook op een categoriepagina ──
function state(array $opts, string $taxonomy = 'product_cat') {
    reset_flag();
    $GLOBALS['t'] = ['opts' => $opts, 'term' => (object) ['term_id' => 7, 'taxonomy' => $taxonomy, 'slug' => 'bedkasten', 'name' => 'Bedkasten'], 'is_term_page' => true,
                     'children' => [], 'thumb' => [], 'posts' => [], 'rows' => [], 'meta' => []];
}
function yoast_graph() { return [['@type' => 'CollectionPage', '@id' => 'https://x.nl/bedkasten/', 'name' => 'SEO-titel'], ['@type' => 'ImageObject', '@id' => 'https://x.nl/bedkasten/#primaryimage', 'url' => 'https://x.nl/o.jpg', 'contentUrl' => 'https://x.nl/o.jpg']]; }
function run_hook() { try { return [RR_SD_Term_Image::yoast_schema_graph(yoast_graph(), null), '']; } catch (Throwable $e) { return [null, $e->getMessage()]; } }

state([]);
[$out, $err] = run_hook();
ok($err === '' && $out === yoast_graph() && !RR_SD_Term_Image::itemlist_done(), 'instelling uit: niets aangepast en de eigen ProductGroup blijft gelden' . ($err ? " ($err)" : ''));

// Categorie zonder subcategorie: producten in de lijst.
state(['rr_sd_yoast_itemlist' => '1']);
$GLOBALS['t']['posts'] = [11, 12];
$GLOBALS['t']['rows']  = [(object) ['ID' => 11, 'post_title' => 'Bedkast A', 'post_excerpt' => ''], (object) ['ID' => 12, 'post_title' => 'Bedkast B', 'post_excerpt' => '']];
$GLOBALS['t']['meta']  = [(object) ['post_id' => 11, 'meta_key' => '_price', 'meta_value' => '999'], (object) ['post_id' => 11, 'meta_key' => '_thumbnail_id', 'meta_value' => '5'],
                          (object) ['post_id' => 12, 'meta_key' => '_price', 'meta_value' => '1200'], (object) ['post_id' => 12, 'meta_key' => '_stock_status', 'meta_value' => 'outofstock']];
[$out, $err] = run_hook();
$cp = $out ? node($out, 'CollectionPage') : null;
ok($err === '' && $cp && count($cp['mainEntity']['itemListElement']) === 2, 'categorie zonder subcategorie: de producten staan als lijst in het Yoast-blok' . ($err ? " ($err)" : ''));
ok($cp['mainEntity']['itemListElement'][0]['item']['name'] === 'Bedkast A' && $cp['mainEntity']['itemListElement'][0]['item']['offers']['price'] === '999.00', 'product met prijs in de lijst');
ok($cp['mainEntity']['itemListElement'][1]['item']['offers']['availability'] === 'https://schema.org/OutOfStock', 'voorraadstatus klopt per product');
ok(RR_SD_Term_Image::itemlist_done() === true, 'de hook onthoudt dat de lijst is toegevoegd (de eigen ProductGroup wordt dan overgeslagen)');
ok($cp['@id'] === 'https://x.nl/bedkasten/' && $cp['name'] === 'SEO-titel' && count($out) === 2, 'het Yoast-blok behoudt zijn @id en naam, en er komt geen blok bij');

// Categorie met subcategorieën: de subcategorieën in de lijst.
state(['rr_sd_yoast_itemlist' => '1']);
$GLOBALS['t']['children'][7] = [(object) ['term_id' => 70, 'name' => 'Bedkast 1 persoons', 'slug' => 'bedkast-1'], (object) ['term_id' => 71, 'name' => 'Bedkast 2 persoons', 'slug' => 'bedkast-2']];
$GLOBALS['t']['thumb'] = [70 => 9];
[$out, $err] = run_hook(); $cp = $out ? node($out, 'CollectionPage') : null;
ok($err === '' && count($cp['mainEntity']['itemListElement']) === 2 && $cp['mainEntity']['itemListElement'][0]['name'] === 'Bedkast 1 persoons' && $cp['mainEntity']['itemListElement'][0]['url'] === 'https://x.nl/cat/bedkast-1/', 'categorie met subcategorieën: de subcategorieën staan in de lijst' . ($err ? " ($err)" : ''));
ok($cp['mainEntity']['itemListElement'][0]['image'] === 'https://x.nl/uploads/att-9-medium.jpg' && !isset($cp['mainEntity']['itemListElement'][1]['image']), 'subcategorie met foto krijgt een afbeelding, zonder foto geen lege waarde');

// Randgevallen
state(['rr_sd_yoast_itemlist' => '1'], 'category');
[$out, $err] = run_hook();
ok($err === '' && $out === yoast_graph() && !RR_SD_Term_Image::itemlist_done(), 'blogcategorie (geen WooCommerce): niets aangepast' . ($err ? " ($err)" : ''));

state(['rr_sd_yoast_itemlist' => '1']); $GLOBALS['t']['is_term_page'] = false;
[$out, $err] = run_hook();
ok($err === '' && $out === yoast_graph() && !RR_SD_Term_Image::itemlist_done(), 'geen categoriepagina: niets aangepast' . ($err ? " ($err)" : ''));

state(['rr_sd_yoast_itemlist' => '1']);
[$out, $err] = run_hook();
ok($err === '' && $out === yoast_graph() && !RR_SD_Term_Image::itemlist_done(), 'categorie zonder producten of subcategorieën: niets aangepast, eigen ProductGroup blijft' . ($err ? " ($err)" : ''));

state(['rr_sd_yoast_itemlist' => '1']);
$GLOBALS['t']['posts'] = [11]; $GLOBALS['t']['rows'] = [(object) ['ID' => 11, 'post_title' => 'A', 'post_excerpt' => '']];
try { $o = RR_SD_Term_Image::yoast_schema_graph([['@type' => 'WebPage', '@id' => 'x']], null); $err = ''; } catch (Throwable $e) { $o = null; $err = $e->getMessage(); }
ok($err === '' && $o === [['@type' => 'WebPage', '@id' => 'x']] && !RR_SD_Term_Image::itemlist_done(), 'Yoast geeft geen CollectionPage: niets aangepast, eigen ProductGroup blijft' . ($err ? " ($err)" : ''));

// Samen met de afbeelding-instelling
state(['rr_sd_yoast_itemlist' => '1', 'rr_sd_yoast_schema_image' => '1', 'wpseo_taxonomy_meta' => ['product_cat' => [7 => ['wpseo_opengraph-image' => 'https://x.nl/uploads/social.jpg']]]]);
$GLOBALS['t']['children'][7] = [(object) ['term_id' => 70, 'name' => 'Sub', 'slug' => 'sub']];
[$out, $err] = run_hook(); $img = $out ? node($out, 'ImageObject') : null; $cp = $out ? node($out, 'CollectionPage') : null;
ok($err === '' && $img['url'] === 'https://x.nl/uploads/social.jpg' && !empty($cp['mainEntity']), 'beide instellingen aan: afbeelding gelijkgetrokken én lijst toegevoegd' . ($err ? " ($err)" : ''));

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
