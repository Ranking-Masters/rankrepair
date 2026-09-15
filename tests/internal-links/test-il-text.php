<?php
define('ABSPATH', true);
require __DIR__ . '/../../addons/internal-links/class-il-text.php';

function ok($cond, $msg) {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

// tokenize
$t = IL_Text::tokenize('Hét beste SEO-advies, in 2024!');
ok(in_array('beste', $t) && in_array('seo', $t) && in_array('advies', $t), 'tokenize splitst en lowercased');
ok(!in_array('in', $t), 'tokenize verwijdert woorden < 3 tekens');

// remove_stopwords
$s = IL_Text::remove_stopwords(['de','beste','een','linkbuilding']);
ok($s === array_values(['beste','linkbuilding']), 'remove_stopwords verwijdert NL-stopwoorden');

// extract_internal_hrefs
$html = '<p>Zie <a href="/over-ons">ons team</a> en <a href="https://example.com/blog/x">deze blog</a> '
      . 'en <a href="https://google.com">extern</a>.</p>';
$links = IL_Text::extract_internal_hrefs($html, 'example.com');
$hrefs = array_map(function ($l) { return $l['href']; }, $links);
ok(in_array('/over-ons', $hrefs), 'relatieve link is intern');
ok(in_array('https://example.com/blog/x', $hrefs), 'zelfde-host link is intern');
ok(!in_array('https://google.com', $hrefs), 'externe link wordt uitgesloten');
ok($links[0]['anchor'] === 'ons team', 'anchor-tekst wordt meegenomen');

// detect_editor
ok(IL_Text::detect_editor('<!-- wp:paragraph --><p>x</p>', false) === 'gutenberg', 'gutenberg gedetecteerd');
ok(IL_Text::detect_editor('<p>gewoon html</p>', false) === 'classic', 'classic gedetecteerd');
ok(IL_Text::detect_editor('<p>x</p>', true) === 'elementor', 'elementor gedetecteerd');

/* ---------------------------------------------------- fase 2: plaatsing ---
   Hieronder het tekstgereedschap dat de planner en de inserter gebruiken. */

// plain_text: blok-einden worden spaties, entiteiten gaan terug naar tekens
ok(IL_Text::plain_text('<p>Een.</p><p>Twee.</p>') === 'Een. Twee.', 'plain_text plakt alinea\'s niet aan elkaar');
ok(IL_Text::plain_text('<p>SEO &amp; SEA</p>') === 'SEO & SEA', 'plain_text decodeert entiteiten');
ok(IL_Text::plain_text("<p>harde\u{00A0}spatie</p>") === 'harde spatie', 'plain_text normaliseert een NBSP');

// woordgrenzen zijn Unicode-bewust
ok(IL_Text::contains_phrase('Ons kantoor in Enter is open', 'Enter'), 'contains_phrase vindt een los woord');
ok(!IL_Text::contains_phrase('Ons kantoor in Deventer is open', 'Enter'), 'contains_phrase matcht niet binnen een woord');
ok(IL_Text::contains_phrase('Bezoek ons café vandaag', 'café'), 'contains_phrase werkt met accenten');
ok(!IL_Text::contains_phrase('Bezoek ons cafétaria vandaag', 'café'), 'contains_phrase breekt niet af op een accent');

// zinnen splitsen, NL-afkortingen meegerekend
$z = IL_Text::split_sentences('Dit is zin een. Dit is zin twee! En drie?');
ok(count($z) === 3, 'split_sentences knipt op punt, uitroep- en vraagteken');
ok($z[0] === 'Dit is zin een.', 'split_sentences houdt het eindleesteken erbij');

$a = IL_Text::split_sentences('Denk aan bijv. interne links. Dat scheelt.');
ok(count($a) === 2, 'split_sentences knipt niet op de afkorting "bijv."');

$b = IL_Text::split_sentences('Volgens J. de Vries werkt het. Klaar.');
ok(count($b) === 2, 'split_sentences knipt niet op een initiaal');

// woordgroepen uit een titel
$g = IL_Text::ngrams('10 tips voor betere interne links', 2, 4);
ok(in_array('interne links', $g, true), 'ngrams levert de kernfrase');
ok(!in_array('voor betere', $g), 'ngrams begint niet met een stopwoord');

// beschermde bereiken
$html = '<p>Zie de <a href="/x">gids</a> en <code>de code</code> hier.</p>';
$r = IL_Text::protected_ranges($html);
$hit = IL_Text::phrase_offsets($html, 'gids');
ok(IL_Text::in_protected_range($hit[0]['offset'], 4, $r), 'tekst in een bestaande link is beschermd');
$hit = IL_Text::phrase_offsets($html, 'hier');
ok(!IL_Text::in_protected_range($hit[0]['offset'], 4, $r), 'gewone lopende tekst is niet beschermd');

// ankerclassificatie, voor de diversiteitsgate
ok(IL_Text::anchor_prefix_class('onze aanpak') === 'possessive', 'anchor_prefix_class herkent een bezittelijk voornaamwoord');
ok(IL_Text::anchor_prefix_class('meer over linkbuilding') === 'comparative', 'anchor_prefix_class herkent een vergelijkende opening');
ok(IL_Text::anchor_prefix_class('tips voor linkbuilding') === 'guide_noun', 'anchor_prefix_class herkent een gidswoord');
ok(IL_Text::anchor_prefix_class('interne links') === 'bare_noun', 'anchor_prefix_class herkent een kaal zelfstandig naamwoord');

ok(IL_Text::normalize_anchor('  Interne Links.  ') === 'interne links', 'normalize_anchor maakt ankers vergelijkbaar');

// links die we zelf plaatsten zijn herkenbaar aan hun id
$eigen = IL_Text::extract_internal_hrefs('<p><a href="/x" data-rr-il="u1">x</a></p>', 'example.com');
ok($eigen[0]['uid'] === 'u1', 'extract_internal_hrefs geeft het data-rr-il-id terug');

echo "ALL PASS\n";
