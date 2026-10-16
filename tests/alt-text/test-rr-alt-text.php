<?php
/**
 * Pure unit tests voor RR_Alt_Text.
 * Run: /opt/homebrew/bin/php tests/alt-text/test-rr-alt-text.php
 */
define('ABSPATH', true);
require __DIR__ . '/../../addons/alt-text/class-rr-alt-text.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

// --- build_prompt ---
ok(strpos(RR_Alt_Text::build_prompt(), 'Nederlandse') !== false, 'build_prompt: Nederlandstalige instructie');
ok(strpos(RR_Alt_Text::build_prompt(), 'Logo van') !== false, 'build_prompt: logo-instructie aanwezig');
ok(strpos(RR_Alt_Text::build_prompt(), 'Context') === false, 'build_prompt: geen context-blok zonder hint');
ok(strpos(RR_Alt_Text::build_prompt('Furnea'), 'Furnea') !== false, 'build_prompt: hint verwerkt in prompt');

// --- filename_hint ---
ok(RR_Alt_Text::filename_hint('scan-logo-hoesjemaken.nl_.png') === 'scan logo hoesjemaken.nl', 'hint: logo-bestandsnaam met merk');
ok(RR_Alt_Text::filename_hint('bmw-5-serie-touring-550e-xdrive.jpg') === 'bmw 5 serie touring 550e xdrive', 'hint: product/model uit filename');
ok(RR_Alt_Text::filename_hint('Furnea.jpg') === 'Furnea', 'hint: enkele merknaam');
ok(RR_Alt_Text::filename_hint('hoesjemaken-mobile-mockup.jpg') === 'hoesjemaken mobile mockup', 'hint: beschrijvende filename');
ok(RR_Alt_Text::filename_hint('DSC03268.webp') === '', 'hint: DSC-cameranaam onderdrukt');
ok(RR_Alt_Text::filename_hint('IMG_1234.jpg') === '', 'hint: IMG-cameranaam onderdrukt');
ok(RR_Alt_Text::filename_hint('Group-2-scaled.webp') === '', 'hint: Group-2-scaled onderdrukt');
ok(RR_Alt_Text::filename_hint('images.jpeg') === '', 'hint: generieke "images" onderdrukt');
ok(RR_Alt_Text::filename_hint('foto-1024x768.jpg') === '', 'hint: afmetingen + generiek onderdrukt');

// --- sanitize_alt: basis ---
ok(RR_Alt_Text::sanitize_alt('  Een rode fiets tegen een muur  ') === 'Een rode fiets tegen een muur', 'trimt spaties');
ok(RR_Alt_Text::sanitize_alt("Rode fiets\ntegen een muur") === 'Rode fiets tegen een muur', 'nieuwe regels -> spatie (één regel)');
ok(RR_Alt_Text::sanitize_alt('Rode   fiets') === 'Rode fiets', 'dubbele spaties samengevoegd');

// --- sanitize_alt: aanhalingstekens ---
ok(RR_Alt_Text::sanitize_alt('"Rode fiets tegen een muur"') === 'Rode fiets tegen een muur', 'rechte quotes gestript');
ok(RR_Alt_Text::sanitize_alt('“Rode fiets”') === 'Rode fiets', 'curly quotes gestript');
ok(RR_Alt_Text::sanitize_alt("'Rode fiets'") === 'Rode fiets', 'enkele quotes gestript');

// --- sanitize_alt: prefixes ---
ok(RR_Alt_Text::sanitize_alt('Afbeelding van een rode fiets') === 'Een rode fiets', 'prefix "Afbeelding van" verwijderd + hoofdletter');
ok(RR_Alt_Text::sanitize_alt('foto van rode fiets') === 'Rode fiets', 'prefix "foto van" verwijderd');
ok(RR_Alt_Text::sanitize_alt('Een afbeelding van de kade') === 'De kade', 'prefix "Een afbeelding van" verwijderd');
ok(RR_Alt_Text::sanitize_alt('Image of a red bike') === 'A red bike', 'prefix "Image of" verwijderd');
ok(RR_Alt_Text::sanitize_alt('Rode fiets van de buurman') === 'Rode fiets van de buurman', 'alleen LEIDENDE prefix strippen, niet "van" midden in zin');

// --- sanitize_alt: afsluitende punt ---
ok(RR_Alt_Text::sanitize_alt('Rode fiets tegen een muur.') === 'Rode fiets tegen een muur', 'afsluitende punt weg');

// --- sanitize_alt: hoofdletter ---
ok(RR_Alt_Text::sanitize_alt('rode fiets') === 'Rode fiets', 'eerste letter hoofdletter');

// --- sanitize_alt: leeg ---
ok(RR_Alt_Text::sanitize_alt('   ') === '', 'alleen spaties -> leeg');
ok(RR_Alt_Text::sanitize_alt('') === '', 'leeg -> leeg');

// --- sanitize_alt: lengte ---
$long = str_repeat('fiets ', 80); // ~480 tekens
$capped = RR_Alt_Text::sanitize_alt($long);
ok(mb_strlen($capped) <= RR_Alt_Text::HARD_MAX, 'lange tekst begrensd op HARD_MAX');
ok(substr($capped, -1) !== ' ', 'begrensde tekst eindigt niet op spatie');

// --- parse_openrouter ---
$or = ['choices' => [['message' => ['content' => '  Rode fiets  ']]]];
ok(RR_Alt_Text::parse_openrouter($or) === 'Rode fiets', 'parse_openrouter: content eruit + getrimd');
ok(RR_Alt_Text::parse_openrouter(['choices' => []]) === '', 'parse_openrouter: lege choices -> leeg');
ok(RR_Alt_Text::parse_openrouter('geen array') === '', 'parse_openrouter: geen array -> leeg');

// --- parse_google ---
$g = ['candidates' => [['content' => ['parts' => [['text' => 'Rode fiets']]]]]];
ok(RR_Alt_Text::parse_google($g) === 'Rode fiets', 'parse_google: text eruit');
ok(RR_Alt_Text::parse_google(['candidates' => []]) === '', 'parse_google: lege candidates -> leeg');

// --- error_message ---
ok(RR_Alt_Text::error_message(['error' => ['message' => 'Invalid key']], 401) === 'Invalid key', 'error_message: genest message');
ok(RR_Alt_Text::error_message(['error' => 'kapot'], 500) === 'kapot', 'error_message: string error');
ok(RR_Alt_Text::error_message([], 503) === 'HTTP 503', 'error_message: fallback HTTP-code');

// --- data_uri ---
ok(RR_Alt_Text::data_uri('image/jpeg', 'QUJD') === 'data:image/jpeg;base64,QUJD', 'data_uri opbouw');

// --- pick_size ---
ok(RR_Alt_Text::pick_size(['thumbnail', 'medium', 'full']) === 'medium', 'pick_size: medium voorkeur');
ok(RR_Alt_Text::pick_size(['thumbnail', 'large', 'full']) === 'large', 'pick_size: large als medium ontbreekt');
ok(RR_Alt_Text::pick_size(['thumbnail']) === 'full', 'pick_size: full als niets past');

if ($fail === 0) { echo "ALL PASS\n"; } else { fwrite(STDERR, "$fail FAILED\n"); exit(1); }
