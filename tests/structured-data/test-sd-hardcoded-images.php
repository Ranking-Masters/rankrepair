<?php
/**
 * Detectie van vaste afbeeldingen in templates (waarschuwing in de inspector).
 * Run: php tests/structured-data/test-sd-hardcoded-images.php
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
function paths(string $json): array { return array_column(RR_SD_Template::find_hardcoded_images($json), 'path'); }

ok(paths('{"@graph":[{"@type":"Organization","image":"https://x.nl/vast.jpg"}]}') === ['@graph[0].image'], 'vaste image-URL bij Organization gevonden, met pad');
ok(paths('{"@graph":[{"@type":"CollectionPage","image":"{{term_image}}"}]}') === [], 'placeholder is geen vaste afbeelding');
ok(paths('{"@graph":[{"@type":"Organization","logo":"https://x.nl/logo.svg"}]}') === [], 'een vast logo is prima');
ok(paths('{"image":{"@type":"ImageObject","url":"https://x.nl/a.jpg"}}') === ['image.url'], 'vaste URL in een ImageObject');
ok(paths('{"image":{"@type":"ImageObject","url":"{{term_image}}"}}') === [], 'ImageObject met placeholder is goed');
ok(paths('{"image":["https://x.nl/a.jpg","{{product_images}}","/uploads/b.png"]}') === ['image[0]', 'image[2]'], 'lijst: alleen de vaste URL\'s, ook paden met /');
ok(paths('{"thumbnailUrl":"//cdn.x.nl/t.jpg"}') === ['thumbnailUrl'], 'schema-relatieve URL en thumbnailUrl');
ok(paths('{"image":{"@id":"https://x.nl/#primaryimage"}}') === [], 'verwijzing via @id is geen vaste afbeelding');
ok(paths('{"image":""}') === [], 'lege waarde telt niet');
ok(paths('{"image": "https://x.nl/a.jpg",}') === [], 'ongeldige JSON geeft geen resultaat');
ok(paths('{"name":"Foto https://x.nl/a.jpg"}') === [], 'URL in een tekstveld telt niet');

ok(RR_SD_Template::type_shares_template('product_cat_l1') && RR_SD_Template::type_shares_template('product_cat_l3'), 'categorietypen delen een template');
ok(RR_SD_Template::type_shares_template('category') && RR_SD_Template::type_shares_template('product') && RR_SD_Template::type_shares_template('page') && RR_SD_Template::type_shares_template('all'), 'category, product, page en all delen een template');
ok(!RR_SD_Template::type_shares_template('home') && !RR_SD_Template::type_shares_template('contact') && !RR_SD_Template::type_shares_template('blog_index'), 'home, contact en blogindex zijn enkele pagina\'s');

$report = RR_SD_Template::audit_templates([
    'home'           => '{"image":"https://x.nl/home.jpg"}',
    'product_cat_l1' => '{"@graph":[{"@type":"Organization","image":"https://x.nl/vast.jpg"}]}',
    'product_cat_l2' => '{"@graph":[{"@type":"ProductGroup","image":"{{term_image}}"}]}',
    'page'           => '',
    'all'            => '{"image":"/wp-content/uploads/a.jpg"}',
]);
ok(array_keys($report) === ['product_cat_l1', 'all'], 'audit: alleen typen met een vaste afbeelding die een template delen (home en schone templates niet)');
ok($report['product_cat_l1'][0]['value'] === 'https://x.nl/vast.jpg', 'audit: de gevonden URL staat in het overzicht');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
