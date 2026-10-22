<?php
/**
 * Lege waarden uit automatisch opgebouwde schema-nodes halen (RR_SD_Builder::drop_empty_values).
 * Run: php tests/structured-data/test-sd-empty-values.php
 */
define('ABSPATH', true);
require __DIR__ . '/../../addons/structured-data/class-sd-builder.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}
function clean(array $n): array { return RR_SD_Builder::drop_empty_values($n); }

// Het geval uit de praktijk: ProductGroup van een categorie zonder beschrijving.
$group = ['@type' => 'ProductGroup', '@id' => 'https://x.nl/a/#productgroup', 'name' => 'Logeerbedden', 'productGroupID' => 'logeerbedden',
          'url' => 'https://x.nl/a/', 'description' => '', 'brand' => ['@id' => 'https://x.nl/#organization'], 'hasVariant' => [['@type' => 'Product', 'name' => 'A']]];
$out = clean($group);
ok(!array_key_exists('description', $out), 'lege description verdwijnt uit de ProductGroup');
ok($out['name'] === 'Logeerbedden' && $out['brand'] === ['@id' => 'https://x.nl/#organization'] && count($out['hasVariant']) === 1, 'de rest van de ProductGroup blijft ongewijzigd');
ok(clean(array_merge($group, ['description' => 'Tekst']))['description'] === 'Tekst', 'gevulde description blijft staan');

// Wat wel en niet leeg is.
ok(clean(['a' => '   ']) === [], 'alleen spaties telt als leeg');
ok(clean(['a' => null]) === [], 'null telt als leeg');
ok(clean(['a' => []]) === [], 'lege lijst telt als leeg');
ok(clean(['a' => 0, 'b' => '0', 'c' => false, 'd' => 0.0]) === ['a' => 0, 'b' => '0', 'c' => false, 'd' => 0.0], '0, "0", false en 0.0 blijven staan');
ok(clean(['price' => '0.00'])['price'] === '0.00', 'prijs 0.00 blijft staan');

// Diep in de structuur.
$org = ['@type' => 'Organization', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Straat 1', 'postalCode' => '']];
ok(clean($org)['address'] === ['@type' => 'PostalAddress', 'streetAddress' => 'Straat 1'], 'lege waarde in een geneste node verdwijnt');
ok(clean(['x' => ['a' => '', 'b' => null]]) === [], 'een node die helemaal leeg raakt verdwijnt');
ok(clean(['image' => ['@type' => 'ImageObject', 'url' => '']])['image'] === ['@type' => 'ImageObject'], 'een node met alleen @type blijft (we gokken niet wat weg mag)');

// Lijsten blijven lijsten.
$out = clean(['sameAs' => ['https://a.nl', '', 'https://b.nl']]);
ok($out['sameAs'] === ['https://a.nl', 'https://b.nl'], 'lege waarde uit een lijst, lijst opnieuw genummerd');
ok(array_keys($out['sameAs']) === [0, 1], 'sleutels van de lijst lopen weer van 0');
ok(clean(['sameAs' => ['', null]]) === [], 'lijst met alleen lege waarden verdwijnt');

// Een node zonder lege waarden komt ongewijzigd terug.
$full = ['@type' => 'WebSite', '@id' => 'x', 'name' => 'Site', 'potentialAction' => ['@type' => 'SearchAction', 'target' => 'https://x.nl/?s={q}']];
ok(clean($full) === $full, 'node zonder lege waarden blijft identiek');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
