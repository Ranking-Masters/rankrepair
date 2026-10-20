<?php
/**
 * Pure unit tests voor {{term_image}} (RR_SD_Term_Image) en het weglaten van lege
 * placeholders (RR_SD_Template::drop_empty_placeholders).
 * Run: php tests/structured-data/test-sd-term-image.php
 */
define('ABSPATH', true);

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}

require __DIR__ . '/../../addons/structured-data/class-sd-term-image.php';
require __DIR__ . '/../../addons/structured-data/class-sd-template.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

/** Nepbron: alles wat de keuzelogica nodig heeft, als arrays. */
class Fake_Source implements RR_SD_Term_Image_Source {
    public $yoast = [], $thumb = [], $children = [], $products = [];
    public function yoast_image(int $id): string   { return $this->yoast[$id] ?? ''; }
    public function thumbnail_url(int $id): string { return $this->thumb[$id] ?? ''; }
    public function child_ids_by_size(int $id): array { return $this->children[$id] ?? []; }
    public function product_image_urls(int $id, int $limit): array { return array_slice($this->products[$id] ?? [], 0, $limit); }
    public function is_logo(string $url): bool { return strpos($url, 'logo') !== false; }
}
function resolve(Fake_Source $s, int $term, bool $yoast = false): string {
    return (new RR_SD_Term_Image($s, $yoast))->resolve($term);
}

// ---------------------------------------------------------------------------
// Keuzevolgorde
// ---------------------------------------------------------------------------
$s = new Fake_Source();
$s->thumb[1] = 'own.jpg';
$s->children[1] = [2]; $s->thumb[2] = 'child.jpg';
ok(resolve($s, 1) === 'own.jpg', 'eigen categorieafbeelding wint van subcategorie');

$s = new Fake_Source();
$s->thumb[1] = 'sabe-logo.png';
$s->children[1] = [2, 3]; $s->thumb[2] = 'big-child.jpg'; $s->thumb[3] = 'small-child.jpg';
ok(resolve($s, 1) === 'big-child.jpg', 'eigen afbeelding is logo → grootste subcategorie (eerste in de lijst)');

$s = new Fake_Source();
$s->children[1] = [2, 3]; $s->thumb[3] = 'second.jpg';
ok(resolve($s, 1) === 'second.jpg', 'grootste subcategorie zonder afbeelding → volgende subcategorie');

$s = new Fake_Source();
$s->children[1] = [2]; $s->children[2] = [3]; $s->thumb[3] = 'grandchild.jpg';
ok(resolve($s, 1) === 'grandchild.jpg', 'subcategorie zonder afbeelding → diepere subcategorie');

$s = new Fake_Source();
$s->thumb[1] = 'logo.png';
$s->products[1] = ['logo-print.png', 'product-a.jpg', 'product-b.jpg'];
ok(resolve($s, 1) === 'product-a.jpg', 'geen subcategorie-afbeelding → eerste product (logo-afbeeldingen overgeslagen)');

$s = new Fake_Source();
$s->thumb[1] = 'logo.png'; $s->children[1] = [2]; $s->thumb[2] = 'logo-2.png'; $s->products[1] = ['logo-3.png'];
ok(resolve($s, 1) === '', 'alleen logo\'s overal → lege string');

ok(resolve(new Fake_Source(), 99) === '', 'term zonder iets → lege string');

// ---------------------------------------------------------------------------
// Robuustheid
// ---------------------------------------------------------------------------
$s = new Fake_Source();
$s->children[1] = [2]; $s->children[2] = [1, 2]; // cirkelverwijzing
$s->products[1] = ['fallback.jpg'];
ok(resolve($s, 1) === 'fallback.jpg', 'cirkelverwijzing tussen termen loopt niet vast');

$s = new Fake_Source();
for ($i = 1; $i <= 20; $i++) { $s->children[$i] = [$i + 1]; }
$s->thumb[12] = 'too-deep.jpg'; $s->products[1] = ['product.jpg'];
ok(resolve($s, 1) === 'product.jpg', 'diepte-limiet: te diepe afbeelding wordt niet gebruikt, product-fallback wel');

// ---------------------------------------------------------------------------
// Yoast (optioneel, standaard uit)
// ---------------------------------------------------------------------------
$s = new Fake_Source();
$s->thumb[1] = 'wc.jpg'; $s->yoast[1] = 'yoast.jpg';
ok(resolve($s, 1, false) === 'wc.jpg', 'Yoast-afbeelding wordt standaard NIET gebruikt');
ok(resolve($s, 1, true) === 'yoast.jpg', 'met Yoast aan gaat de social image voor');
$s->yoast[1] = 'yoast-logo.png';
ok(resolve($s, 1, true) === 'wc.jpg', 'Yoast-afbeelding die een logo is wordt overgeslagen');

// ---------------------------------------------------------------------------
// Bestandsnaam normaliseren (logo-herkenning)
// ---------------------------------------------------------------------------
ok(RR_SD_Term_Image_WP_Source::normalize_basename('https://x.nl/wp-content/uploads/2024/05/Sabe-Logo-300x200.png?v=1') === 'sabe-logo', 'formaat-suffix, extensie en query weggehaald');
ok(RR_SD_Term_Image_WP_Source::normalize_basename('https://x.nl/uploads/foto-scaled.jpg') === 'foto', '-scaled weggehaald');
ok(RR_SD_Term_Image_WP_Source::normalize_basename('') === '', 'lege URL geeft lege naam');

// ---------------------------------------------------------------------------
// Lege placeholders weglaten
// ---------------------------------------------------------------------------
function drop(string $json, array $scalars = [], array $arrays = []): string {
    return RR_SD_Template::drop_empty_placeholders($json, $scalars, $arrays);
}
function same_json(string $a, string $b): bool { return json_decode($a, true) === json_decode($b, true); }

$tpl = '{"@type":"CollectionPage","name":"{{name}}","image":"{{term_image}}"}';
ok(same_json(drop($tpl, ['name' => 'Dozen', 'term_image' => '']), '{"@type":"CollectionPage","name":"{{name}}"}'),
    'lege {{term_image}} → key "image" verdwijnt, rest blijft');

ok(drop($tpl, ['name' => 'Dozen', 'term_image' => 'https://x.nl/a.jpg']) === $tpl,
    'gevulde placeholder → invoer blijft byte-voor-byte hetzelfde');

ok(same_json(drop('{"@type":"CollectionPage","image":{"@type":"ImageObject","url":"{{term_image}}"}}', ['term_image' => '']),
    '{"@type":"CollectionPage"}'), 'ImageObject zonder url (leeg omhulsel) verdwijnt ook');

ok(same_json(drop('{"image":{"@type":"ImageObject","url":"{{term_image}}","width":1200}}', ['term_image' => '']),
    '{"image":{"@type":"ImageObject","width":1200}}'), 'object met andere inhoud blijft staan, alleen de lege key gaat weg');

ok(same_json(drop('{"@graph":[{"@type":"X","name":"n","image":"{{term_image}}"}]}', ['term_image' => '']),
    '{"@graph":[{"@type":"X","name":"n"}]}'), 'werkt ook diep in een @graph');

$out = drop('{"image":["{{a}}","{{b}}"]}', ['a' => '', 'b' => 'https://x.nl/b.jpg']);
ok(json_decode($out, true) === ['image' => ['{{b}}']] , 'lege waarde uit een lijst weggehaald, lijst blijft een lijst');

ok(same_json(drop('{"name":"n","image":["{{term_image}}"]}', ['term_image' => '']), '{"name":"n"}'),
    'lijst die leeg raakt verdwijnt');

ok(same_json(drop('{"name":"n","hasVariant":"{{children_variants}}"}', [], ['children_variants' => []]), '{"name":"n"}'),
    'lege array-placeholder → key weg');

$withVariants = '{"name":"n","hasVariant":"{{children_variants}}"}';
ok(drop($withVariants, [], ['children_variants' => [['name' => 'a']]]) === $withVariants,
    'gevulde array-placeholder blijft ongemoeid');

$partial = '{"image":"{{term_image}}?v=1"}';
ok(drop($partial, ['term_image' => '']) === $partial, 'placeholder midden in tekst wordt niet weggehaald');

ok(same_json(drop('{"name":"n","description":"{{description}}"}', ['description' => '   ']), '{"name":"n"}'),
    'alleen spaties telt als leeg');

$bad = '{"image": "{{term_image}}",}';
ok(drop($bad, ['term_image' => '']) === $bad, 'ongeldige JSON blijft ongemoeid');

ok(drop('{"name":"n"}', ['term_image' => '']) === '{"name":"n"}', 'template zonder die placeholder blijft ongemoeid');

// ---------------------------------------------------------------------------
// Yoast social image (opt-in): redacteurskeuze wordt nooit overschreven
// ---------------------------------------------------------------------------
ok(RR_SD_Term_Image::decide_social_image(true, '', 'https://x.nl/cat.jpg') === 'https://x.nl/cat.jpg', 'veld leeg + instelling aan → categorieafbeelding');
ok(RR_SD_Term_Image::decide_social_image(true, 'https://x.nl/redactie.jpg', 'https://x.nl/cat.jpg') === '', 'veld in Yoast gevuld → niets overschrijven');
ok(RR_SD_Term_Image::decide_social_image(false, '', 'https://x.nl/cat.jpg') === '', 'instelling uit → niets doen');
ok(RR_SD_Term_Image::decide_social_image(true, '   ', 'https://x.nl/cat.jpg') === 'https://x.nl/cat.jpg', 'alleen spaties in het veld telt als leeg');
ok(RR_SD_Term_Image::decide_social_image(true, '', '') === '', 'geen categorieafbeelding gevonden → niets toevoegen');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
