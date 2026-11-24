<?php
/**
 * Yoast-schema: de hoofdafbeelding (#primaryimage) gelijktrekken met de og:image.
 * Run: php tests/structured-data/test-sd-yoast-schema.php
 */
define('ABSPATH', true);
require __DIR__ . '/../../addons/structured-data/class-sd-term-image.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}
function img(array $g) { foreach ($g as $p) { if (($p['@type'] ?? '') === 'ImageObject' && strpos($p['@id'] ?? '', '#primaryimage') !== false) return $p; } return null; }
function node(array $g, string $type) { foreach ($g as $p) { if (($p['@type'] ?? '') === $type) return $p; } return null; }

// Een graaf zoals Yoast die op een categoriepagina uitgeeft (vereenvoudigd van slaapbanken.nl).
$graph = [
    ['@type' => 'CollectionPage', '@id' => 'https://x.nl/cat/', 'url' => 'https://x.nl/cat/', 'name' => 'Logeerbedden',
     'primaryImageOfPage' => ['@id' => 'https://x.nl/cat/#primaryimage'], 'image' => ['@id' => 'https://x.nl/cat/#primaryimage'],
     'thumbnailUrl' => 'https://x.nl/uploads/eerste-product.jpg'],
    ['@type' => 'ImageObject', 'inLanguage' => 'nl-NL', '@id' => 'https://x.nl/cat/#primaryimage',
     'url' => 'https://x.nl/uploads/eerste-product.jpg', 'contentUrl' => 'https://x.nl/uploads/eerste-product.jpg',
     'width' => 1000, 'height' => 1000, 'caption' => 'Eerste product'],
    ['@type' => 'Organization', '@id' => 'https://x.nl/#organization', 'logo' => ['@id' => 'https://x.nl/#/schema/logo/image/']],
    ['@type' => 'ImageObject', '@id' => 'https://x.nl/#/schema/logo/image/', 'url' => 'https://x.nl/uploads/logo.png', 'width' => 400, 'height' => 400],
];

// schema_target_image
ok(RR_SD_Term_Image::schema_target_image(false, 'https://x.nl/social.jpg', 'https://x.nl/cat.jpg') === 'https://x.nl/social.jpg', 'social image uit Yoast gaat voor, ook met vullen uit');
ok(RR_SD_Term_Image::schema_target_image(true, 'https://x.nl/social.jpg', 'https://x.nl/cat.jpg') === 'https://x.nl/social.jpg', 'social image uit Yoast gaat voor categorieafbeelding');
ok(RR_SD_Term_Image::schema_target_image(true, '', 'https://x.nl/cat.jpg') === 'https://x.nl/cat.jpg', 'veld leeg + vullen aan → categorieafbeelding');
ok(RR_SD_Term_Image::schema_target_image(true, '  ', 'https://x.nl/cat.jpg') === 'https://x.nl/cat.jpg', 'alleen spaties telt als leeg');
ok(RR_SD_Term_Image::schema_target_image(false, '', 'https://x.nl/cat.jpg') === '', 'veld leeg + vullen uit → niets aanpassen (we gokken niet)');
ok(RR_SD_Term_Image::schema_target_image(true, '', '') === '', 'geen afbeelding gevonden → niets aanpassen');

// align_primary_image
$out = RR_SD_Term_Image::align_primary_image($graph, 'https://x.nl/uploads/categorie.webp', ['width' => 1200, 'height' => 800, 'caption' => 'Categorie']);
$i = img($out);
ok($i['url'] === 'https://x.nl/uploads/categorie.webp' && $i['contentUrl'] === 'https://x.nl/uploads/categorie.webp', 'url en contentUrl van de hoofdafbeelding aangepast');
ok($i['width'] === 1200 && $i['height'] === 800 && $i['caption'] === 'Categorie', 'breedte, hoogte en bijschrift van de nieuwe afbeelding');
ok($i['@id'] === 'https://x.nl/cat/#primaryimage' && $i['inLanguage'] === 'nl-NL', '@id en overige velden blijven ongewijzigd (verwijzingen blijven kloppen)');
ok(node($out, 'CollectionPage')['thumbnailUrl'] === 'https://x.nl/uploads/categorie.webp', 'thumbnailUrl van de pagina volgt');
ok(node($out, 'CollectionPage')['image'] === ['@id' => 'https://x.nl/cat/#primaryimage'], 'de pagina verwijst nog naar dezelfde ImageObject');

$out2 = RR_SD_Term_Image::align_primary_image($graph, 'https://x.nl/uploads/categorie.webp');
$i2 = img($out2);
ok(!isset($i2['width']) && !isset($i2['height']) && !isset($i2['caption']), 'zonder gegevens over de nieuwe afbeelding: oude maten en bijschrift weg, niet laten staan');

// De logo-ImageObject en andere nodes blijven onaangeroerd.
$logo = null; foreach ($out as $p) { if (($p['@id'] ?? '') === 'https://x.nl/#/schema/logo/image/') $logo = $p; }
ok($logo === $graph[3], 'de ImageObject van het logo blijft ongewijzigd');
ok(node($out, 'Organization') === $graph[2], 'Organization blijft ongewijzigd');

// Geen hoofdafbeelding in de graaf: niets bijmaken.
$zonder = [$graph[0], $graph[2], $graph[3]]; unset($zonder[0]['thumbnailUrl']);
ok(RR_SD_Term_Image::align_primary_image($zonder, 'https://x.nl/a.jpg') === $zonder, 'graaf zonder hoofdafbeelding blijft ongewijzigd');
ok(RR_SD_Term_Image::align_primary_image($graph, '') === $graph, 'lege url → niets aanpassen');
ok(RR_SD_Term_Image::align_primary_image([], 'https://x.nl/a.jpg') === [], 'lege graaf blijft leeg');
ok(RR_SD_Term_Image::align_primary_image(['rommel', null, 5], 'https://x.nl/a.jpg') === ['rommel', null, 5], 'onverwachte inhoud in de graaf geeft geen fout');

// thumbnailUrl wordt alleen aangepast als er een hoofdafbeelding is aangepast.
$alleen_thumb = [['@type' => 'WebPage', 'thumbnailUrl' => 'https://x.nl/oud.jpg']];
ok(RR_SD_Term_Image::align_primary_image($alleen_thumb, 'https://x.nl/a.jpg') === $alleen_thumb, 'thumbnailUrl zonder hoofdafbeelding blijft staan');

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
