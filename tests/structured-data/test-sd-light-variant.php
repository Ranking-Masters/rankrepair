<?php
/**
 * Pure unit tests voor RR_SD_Builder::light_row_to_variant(): de lichte productrij moet
 * dezelfde Product-node opleveren als de oude wc_product_to_variant()-uitvoer.
 * (light_products() zelf praat met de database en wordt op een echte site gecontroleerd.)
 * Run: php tests/structured-data/test-sd-light-variant.php
 */
define('ABSPATH', true);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($id, $size = 'thumbnail') { return $id ? "https://x.nl/img-$id-$size.jpg" : false; }
}
if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency() { return 'EUR'; }
}

require __DIR__ . '/../../addons/structured-data/class-sd-builder.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

$term = (object) ['name' => 'Logeerbedden', 'slug' => 'logeerbedden'];
$row  = ['id' => 42, 'name' => 'Bank Milaan', 'excerpt' => '<p>Zachte <b>bank</b></p>', 'sku' => 'BM-1',
         'price' => '499.9', 'stock' => 'instock', 'thumb' => 7, 'url' => 'https://x.nl/bank-milaan/'];
$v = RR_SD_Builder::light_row_to_variant($row, 'https://x.nl/logeerbedden/#productgroup', $term);

ok($v['@type'] === 'Product' && $v['name'] === 'Bank Milaan', 'type en naam');
ok($v['description'] === 'Zachte bank', 'beschrijving: tags eruit');
ok($v['sku'] === 'BM-1' && $v['url'] === 'https://x.nl/bank-milaan/', 'sku en url');
ok($v['image'] === 'https://x.nl/img-7-medium.jpg', 'afbeelding in formaat medium');
ok($v['isVariantOf'] === ['@type' => 'ProductGroup', '@id' => 'https://x.nl/logeerbedden/#productgroup', 'name' => 'Logeerbedden', 'productGroupID' => 'logeerbedden'], 'isVariantOf verwijst naar de ProductGroup');
ok($v['offers']['price'] === '499.90' && $v['offers']['priceCurrency'] === 'EUR', 'prijs met twee decimalen en valuta');
ok($v['offers']['availability'] === 'https://schema.org/InStock', 'op voorraad');
ok($v['offers']['priceValidUntil'] === date('Y-12-31', strtotime('+2 years')), 'priceValidUntil als eerder');

$out = RR_SD_Builder::light_row_to_variant(['stock' => 'outofstock'] + $row, 'g', $term);
ok($out['offers']['availability'] === 'https://schema.org/OutOfStock', 'niet op voorraad');

$back = RR_SD_Builder::light_row_to_variant(['stock' => 'onbackorder'] + $row, 'g', $term);
ok($back['offers']['availability'] === 'https://schema.org/InStock', 'nabestelling telt als beschikbaar (zoals is_in_stock())');

$nosku = RR_SD_Builder::light_row_to_variant(['sku' => ''] + $row, 'g', $term);
ok($nosku['sku'] === 'product-42', 'lege sku → product-ID');

$noprice = RR_SD_Builder::light_row_to_variant(['price' => ''] + $row, 'g', $term);
ok(!isset($noprice['offers']), 'geen prijs → geen offers');

$zero = RR_SD_Builder::light_row_to_variant(['price' => '0'] + $row, 'g', $term);
ok(isset($zero['offers']) && $zero['offers']['price'] === '0.00', 'prijs 0 blijft een offer (zoals de oude code)');

$noimg = RR_SD_Builder::light_row_to_variant(['thumb' => 0] + $row, 'g', $term);
ok(!isset($noimg['image']), 'zonder afbeelding geen image-key');

$noexcerpt = RR_SD_Builder::light_row_to_variant(['excerpt' => ''] + $row, 'g', $term);
ok($noexcerpt['description'] === 'Bank Milaan', 'lege beschrijving → naam');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
