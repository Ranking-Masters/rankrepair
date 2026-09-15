<?php
/**
 * De inserter is de enige plek die andermans tekst aanraakt. Deze tests leggen
 * vast wat hij mag en wat hij nooit mag.
 */

require __DIR__ . '/bootstrap.php';
il_require('class-il-text.php');
il_require('class-il-inserter.php');

$UID = 'abc123def456';
$URL = 'https://voorbeeld.nl/doelpagina/';

/* ------------------------------------------------------------- wrap-modus */

$html   = '<p>Een goede interne linkstructuur helpt Google je site te begrijpen.</p>';
$anchor = 'interne linkstructuur';
$hits   = IL_Text::phrase_offsets($html, $anchor);

ok(count($hits) === 1, 'wrap: de frase wordt één keer gevonden');

$result = IL_Inserter::build([
    'mode' => 'wrap', 'html' => $html, 'anchor' => $anchor, 'url' => $URL, 'uid' => $UID,
    'offset' => $hits[0]['offset'], 'length' => strlen($hits[0]['match']),
]);

ok(!isset($result['error']), 'wrap: bouwt zonder fout');
ok(strpos($result['html'], '<a href="https://voorbeeld.nl/doelpagina/" data-rr-il="abc123def456">interne linkstructuur</a>') !== false,
   'wrap: de link staat er precies omheen');
ok(IL_Text::plain_text($result['html']) === IL_Text::plain_text($html),
   'wrap: de platte tekst blijft exact gelijk');

/* De klassieke woordgrens-bug: "Enter" mag niet binnen "Deventer" matchen. */

$deventer = '<p>Ons kantoor in Deventer is elke werkdag open voor bezoek.</p>';
ok(IL_Text::phrase_offsets($deventer, 'Enter') === [],
   'wrap: "Enter" matcht niet binnen "Deventer"');

/* Een frase die al in een link zit is beschermd gebied. */

$linked = '<p>Lees onze <a href="/gids/">gids over linkbuilding</a> voor de basis.</p>';
$hit    = IL_Text::phrase_offsets($linked, 'linkbuilding');
ok(count($hit) === 1, 'wrap: frase in bestaande link wordt wel gevónden');
ok(IL_Text::in_protected_range($hit[0]['offset'], strlen($hit[0]['match']), IL_Text::protected_ranges($linked)),
   'wrap: maar valt in een beschermd bereik');

/* En binnen een attribuut ook. */

$attr = '<p><img src="/linkbuilding.png" alt="linkbuilding"> Wat is linkbuilding?</p>';
$ranges = IL_Text::protected_ranges($attr);
$offsets = IL_Text::phrase_offsets($attr, 'linkbuilding');
$vrij = 0;
foreach ($offsets as $o) {
    if (!IL_Text::in_protected_range($o['offset'], strlen($o['match']), $ranges)) { $vrij++; }
}
ok($vrij === 1, 'wrap: alleen het voorkomen in de lopende tekst is bruikbaar');

/* Een verschoven positie moet worden geweigerd, niet blind toegepast. */

$bad = IL_Inserter::build([
    'mode' => 'wrap', 'html' => $html, 'anchor' => $anchor, 'url' => $URL, 'uid' => $UID,
    'offset' => 3, 'length' => strlen($anchor),
]);
ok(isset($bad['error']), 'wrap: weigert als de ankertekst niet op de opgegeven positie staat');

/* --------------------------------------------------------- herschrijfmodus */

$para = '<p>We schrijven al jaren teksten. Een goede tekst begint bij het zoekwoord dat je kiest.</p>';
$rw = IL_Inserter::build([
    'mode'            => 'rewrite',
    'html'            => $para,
    'anchor'          => 'het juiste zoekwoord kiezen',
    'url'             => $URL,
    'uid'             => $UID,
    'sentence_before' => 'Een goede tekst begint bij het zoekwoord dat je kiest.',
    'sentence_after'  => 'Een goede tekst begint bij het juiste zoekwoord kiezen.',
]);
ok(!isset($rw['error']), 'rewrite: bouwt zonder fout');
ok(strpos($rw['html'], 'We schrijven al jaren teksten.') === 3, 'rewrite: de andere zin blijft ongemoeid');
ok(strpos($rw['html'], 'data-rr-il="' . $UID . '">het juiste zoekwoord kiezen</a>') !== false,
   'rewrite: het anker is gemarkeerd in de nieuwe zin');

/* Een zin die niet (meer) in de tekst staat mag niets opleveren. */

$missing = IL_Inserter::build([
    'mode' => 'rewrite', 'html' => $para, 'anchor' => 'zoekwoord kiezen', 'url' => $URL, 'uid' => $UID,
    'sentence_before' => 'Deze zin staat er niet in.',
    'sentence_after'  => 'Deze zin staat er niet in, met zoekwoord kiezen.',
]);
ok(isset($missing['error']), 'rewrite: weigert een zin die niet in de tekst staat');

/* Opmaak middenin de zin: liever niets doen dan de <strong> weggooien. */

$bold = '<p>Inleiding hier. Een <strong>goede</strong> tekst begint bij je zoekwoord.</p>';
$lost = IL_Inserter::build([
    'mode' => 'rewrite', 'html' => $bold, 'anchor' => 'je zoekwoord bepalen', 'url' => $URL, 'uid' => $UID,
    'sentence_before' => 'Een goede tekst begint bij je zoekwoord.',
    'sentence_after'  => 'Een goede tekst begint bij je zoekwoord bepalen.',
]);
ok(isset($lost['error']), 'rewrite: weigert een zin waar opmaak doorheen loopt');

/* Entiteiten: de zin komt uit platte tekst, de bron bevat &amp;. */

$ent = '<p>Eerste zin hier. Wij doen SEO &amp; SEA voor het MKB.</p>';
$entres = IL_Inserter::build([
    'mode' => 'rewrite', 'html' => $ent, 'anchor' => 'SEA voor het MKB', 'url' => $URL, 'uid' => $UID,
    'sentence_before' => 'Wij doen SEO & SEA voor het MKB.',
    'sentence_after'  => 'Wij doen SEO en SEA voor het MKB.',
]);
ok(!isset($entres['error']), 'rewrite: vindt een zin met &amp; in de bron terug');

/* ------------------------------------------------------------ terugdraaien */

$undo = IL_Inserter::remove(['html' => $result['html'], 'uid' => $UID]);
ok(!isset($undo['error']), 'undo: verwijdert de link');
ok($undo['html'] === $html, 'undo: levert exact de oorspronkelijke HTML terug');

/* Na een herschrijving moet ook de oude zin terugkomen. */

$undo2 = IL_Inserter::remove([
    'html'            => $rw['html'],
    'uid'             => $UID,
    'sentence_before' => 'Een goede tekst begint bij het zoekwoord dat je kiest.',
    'sentence_after'  => 'Een goede tekst begint bij het juiste zoekwoord kiezen.',
]);
ok(!isset($undo2['error']), 'undo: verwerkt een herschreven zin');
ok(IL_Text::plain_text($undo2['html']) === IL_Text::plain_text($para),
   'undo: de oorspronkelijke zin staat er weer');

/* Een uid die er niet is, is geen stille no-op. */

$gone = IL_Inserter::remove(['html' => $html, 'uid' => 'ditbestaatniet']);
ok(isset($gone['error']), 'undo: meldt het als de link al weg is');

done();
