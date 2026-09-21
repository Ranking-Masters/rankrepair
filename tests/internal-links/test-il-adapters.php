<?php
/**
 * De adapters bepalen of "werkt het ook in Elementor?" een ja is.
 *
 * Getest wordt de pure kant: de boom in, segmenten uit, wijziging terug in de
 * boom. De WordPress-kant (opslaan, cache legen) hangt er omheen en is dun.
 */

require __DIR__ . '/bootstrap.php';
il_require('adapters/class-il-adapter-base.php');
il_require('adapters/class-il-adapter-gutenberg.php');
il_require('adapters/class-il-adapter-classic.php');
il_require('adapters/class-il-adapter-elementor.php');

/* ======================================================== Gutenberg-blokken */

// Zoals parse_blocks() het teruggeeft, inclusief een geneste kolom.
$blocks = [
    ['blockName' => 'core/heading',   'attrs' => [], 'innerBlocks' => [],
     'innerHTML' => '<h2>Onze aanpak</h2>', 'innerContent' => ['<h2>Onze aanpak</h2>']],
    ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [],
     'innerHTML' => "\n<p>Wij doen aan linkbuilding sinds 2011.</p>\n",
     'innerContent' => ["\n<p>Wij doen aan linkbuilding sinds 2011.</p>\n"]],
    ['blockName' => 'core/columns',   'attrs' => [], 'innerHTML' => '', 'innerContent' => [null],
     'innerBlocks' => [
        ['blockName' => 'core/column', 'attrs' => [], 'innerHTML' => '', 'innerContent' => [null],
         'innerBlocks' => [
            ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [],
             'innerHTML' => '<p>Backlinks zijn geen doel op zich.</p>',
             'innerContent' => ['<p>Backlinks zijn geen doel op zich.</p>']],
         ]],
     ]],
];

$segments = IL_Adapter_Gutenberg::collect($blocks);

ok(count($segments) === 3, 'gutenberg: alle drie de tekstblokken gevonden');
ok($segments[0]['kind'] === 'heading', 'gutenberg: een kop wordt als kop herkend');
ok($segments[1]['ref'] === 'b:1', 'gutenberg: adres van een blok op het eerste niveau');
ok($segments[2]['ref'] === 'b:2.0.0', 'gutenberg: adres van een blok in een geneste kolom');

$copy = $blocks;
$new  = '<p>Backlinks zijn geen <a href="/doel/" data-rr-il="u1">doel op zich</a>.</p>';
ok(IL_Adapter_Gutenberg::set_at_path($copy, ['2', '0', '0'], $new), 'gutenberg: schrijft op een genest pad');
ok($copy[2]['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] === $new, 'gutenberg: innerHTML is bijgewerkt');
ok($copy[2]['innerBlocks'][0]['innerBlocks'][0]['innerContent'][0] === $new,
   'gutenberg: innerContent óók — daar bouwt serialize_blocks de output uit op');
ok($copy[1]['innerHTML'] === $blocks[1]['innerHTML'], 'gutenberg: de andere blokken blijven ongemoeid');
ok(!IL_Adapter_Gutenberg::set_at_path($copy, ['9'], $new), 'gutenberg: een pad dat niet bestaat levert false');

/* ======================================================== klassieke editor */

// De omkeerbaarheid is het punt: split + implode moet byte-identiek zijn.
$content = "Eerste alinea met tekst.\n\nTweede alinea.\n\n\nDerde alinea na een extra regel.";
$parts   = IL_Adapter_Classic::split($content);

ok(implode('', $parts) === $content, 'classic: split en samenvoegen levert exact het origineel');
ok(count($parts) === 5, 'classic: drie alinea\'s en twee scheidingen');
ok($parts[0] === 'Eerste alinea met tekst.', 'classic: eerste alinea staat op index 0');
ok($parts[2] === 'Tweede alinea.', 'classic: tweede alinea staat op index 2');

$parts[2] = 'Tweede alinea met <a href="/x/">een link</a>.';
ok(strpos(implode('', $parts), "\n\n\n") !== false,
   'classic: de extra lege regel verderop blijft behouden na een wijziging');

// Windows-regeleindes moeten net zo goed werken.
$crlf = "Alinea een.\r\n\r\nAlinea twee.";
ok(implode('', IL_Adapter_Classic::split($crlf)) === $crlf, 'classic: werkt ook met CRLF');
ok(count(IL_Adapter_Classic::split($crlf)) === 3, 'classic: CRLF wordt als scheiding herkend');

/* ================================================================ Elementor */

$tree = [
    ['id' => 'aaa111', 'elType' => 'section', 'elements' => [
        ['id' => 'bbb222', 'elType' => 'column', 'elements' => [
            ['id' => 'ccc333', 'elType' => 'widget', 'widgetType' => 'heading',
             'settings' => ['title' => 'Wat wij doen']],
            ['id' => 'ddd444', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Wij bouwen aan linkbuilding voor het MKB.</p>']],
            ['id' => 'eee555', 'elType' => 'widget', 'widgetType' => 'image',
             'settings' => ['image' => ['url' => '/foto.jpg']]],
        ]],
    ]],
];

$segments = IL_Adapter_Elementor::collect($tree);

ok(count($segments) === 2, 'elementor: alleen tekstwidgets worden segmenten');
ok($segments[0]['ref'] === 'e:ccc333.title', 'elementor: adres van een heading-widget');
ok($segments[1]['ref'] === 'e:ddd444.editor', 'elementor: adres van een text-editor-widget');
ok($segments[1]['kind'] === 'paragraph', 'elementor: een text-editor is lopende tekst');

$copy = $tree;
$new  = '<p>Wij bouwen aan <a href="/doel/" data-rr-il="u2">linkbuilding</a> voor het MKB.</p>';
ok(IL_Adapter_Elementor::set_by_id($copy, 'ddd444', 'editor', $new), 'elementor: schrijft diep in de boom');
ok($copy[0]['elements'][0]['elements'][1]['settings']['editor'] === $new, 'elementor: het veld is bijgewerkt');
ok($copy[0]['elements'][0]['elements'][0]['settings']['title'] === 'Wat wij doen',
   'elementor: de andere widgets blijven ongemoeid');
ok(!IL_Adapter_Elementor::set_by_id($copy, 'bestaatniet', 'editor', $new),
   'elementor: een onbekend element-id levert false');

// Het id is het adres, juist omdat posities verschuiven bij bewerken.
$shifted = $tree;
array_unshift($shifted[0]['elements'][0]['elements'], [
    'id' => 'zzz999', 'elType' => 'widget', 'widgetType' => 'heading',
    'settings' => ['title' => 'Nieuwe kop bovenaan'],
]);
ok(IL_Adapter_Elementor::set_by_id($shifted, 'ddd444', 'editor', $new),
   'elementor: het adres blijft kloppen nadat er een widget boven is ingevoegd');

done();
