<?php
/**
 * Gate-fixtures.
 *
 * Elke test hieronder is een situatie die in de praktijk misging — de meeste
 * komen uit het interne-linktraject voor ferienhausniederlande.de, waar een
 * SEO-team er per batch doorheen ging en aanwees wat er niet deugde.
 *
 * Bewust DAMP geschreven: elke test bouwt zijn eigen kandidaat op, zodat je aan
 * één blok kunt zien wat de situatie is zonder helpers uit te pluizen.
 */

require __DIR__ . '/bootstrap.php';
il_require('class-il-text.php');
il_require('class-il-inserter.php');
il_require('class-il-gates.php');

$URL = 'https://voorbeeld.nl/linkbuilding/';

/** Bouwt een wrap-kandidaat en laat de gates erover oordelen. */
function judge_wrap($html, $anchor, array $cand_extra = [], array $ctx_extra = []) {
    global $URL;

    $uid  = 'uid0000000001';
    $hits = IL_Text::phrase_offsets($html, $anchor);
    if (empty($hits)) {
        return ['ok' => false, 'gate' => 'geen-match', 'reason' => 'ankertekst niet gevonden'];
    }

    $cand = array_merge([
        'source_id'    => 10,
        'target_id'    => 20,
        'mode'         => 'wrap',
        'anchor'       => $hits[0]['match'],
        'url'          => $URL,
        'uid'          => $uid,
        'html'         => $html,
        'offset'       => $hits[0]['offset'],
        'length'       => strlen($hits[0]['match']),
        'segment_ref'  => 'c:2',
        'segment_kind' => 'paragraph',
    ], $cand_extra);

    $built = IL_Inserter::build($cand);
    if (isset($built['error'])) {
        return ['ok' => false, 'gate' => 'bouw', 'reason' => $built['error']];
    }
    $cand['result_html'] = $built['html'];

    $ctx = array_merge([
        'existing_targets'     => [],
        'added_in_source'      => 0,
        'added_per_segment'    => [],
        'added_prefix_classes' => [],
        'density_headroom'     => 10,
        'anchor_usage'         => 0,
        'max_links_per_source' => 2,
        'max_same_anchor'      => 3,
        'target_terms'         => ['linkbuilding', 'backlinks'],
        'target_keyword'       => 'linkbuilding',
        'intro_ref'            => 'c:0',
    ], $ctx_extra);

    return IL_Gates::check($cand, $ctx);
}

/** Idem voor een herschreven zin. */
function judge_rewrite($html, $anchor, $before, $after, array $ctx_extra = [], $mode = 'rewrite') {
    global $URL;

    $cand = [
        'source_id'       => 10,
        'target_id'       => 20,
        'mode'            => $mode,
        'anchor'          => $anchor,
        'url'             => $URL,
        'uid'             => 'uid0000000002',
        'html'            => $html,
        'segment_ref'     => 'c:2',
        'segment_kind'    => 'paragraph',
        'sentence_before' => $before,
        'sentence_after'  => $after,
    ];

    $built = IL_Inserter::build($cand);
    if (isset($built['error'])) {
        return ['ok' => false, 'gate' => 'bouw', 'reason' => $built['error']];
    }
    $cand['result_html'] = $built['html'];

    $ctx = array_merge([
        'existing_targets'     => [],
        'added_in_source'      => 0,
        'added_per_segment'    => [],
        'added_prefix_classes' => [],
        'density_headroom'     => 10,
        'anchor_usage'         => 0,
        'max_links_per_source' => 2,
        'max_same_anchor'      => 3,
        'target_terms'         => ['linkbuilding', 'backlinks'],
        'target_keyword'       => 'linkbuilding',
        'intro_ref'            => 'c:0',
    ], $ctx_extra);

    return IL_Gates::check($cand, $ctx);
}

/* ======================================================= de gelukkige route */

$goed = '<p>Autoriteit opbouwen kost tijd. Wie serieus aan linkbuilding doet, bouwt aan '
      . 'een profiel dat jaren meegaat en niet in één update omvalt.</p>';

ok(judge_wrap($goed, 'linkbuilding')['ok'], 'een natuurlijke wrap-link wordt geaccepteerd');

/* ============================================================ G1 self-link */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', ['target_id' => 10]),
    'G1',
    'G1 blokkeert een link van een pagina naar zichzelf'
);

/* ======================================================= G2 al gelinkt */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['existing_targets' => [20, 33]]),
    'G2',
    'G2 blokkeert een tweede link naar hetzelfde doel'
);

/* ================================================== G3 beschermde context */

$in_link = '<p>Autoriteit kost tijd. Zie onze <a href="/gids/">gids over linkbuilding</a> voor de basis en meer.</p>';
gate_rejects(
    judge_wrap($in_link, 'linkbuilding'),
    'G3',
    'G3 blokkeert een anker dat al binnen een link staat'
);

gate_rejects(
    judge_wrap($goed, 'linkbuilding', ['segment_kind' => 'heading']),
    'G3',
    'G3 blokkeert links in koppen'
);

/* ============================================================ G5 ankervorm */

$klik = '<p>Autoriteit kost tijd. Wil je meer weten over ons vak, klik hier voor de complete uitleg.</p>';
gate_rejects(
    judge_wrap($klik, 'klik hier', [], ['target_terms' => ['klik']]),
    'G5',
    'G5 blokkeert "klik hier" als ankertekst'
);

$lang = '<p>Eerste zin. Autoriteit opbouwen met linkbuilding backlinks content techniek strategie analyse rapportage doorlooptijd is werk.</p>';
gate_rejects(
    judge_wrap($lang, 'linkbuilding backlinks content techniek strategie analyse rapportage doorlooptijd'),
    'G5',
    'G5 blokkeert een ankertekst van negen woorden'
);

// Woordgroepen uit een titel leveren makkelijk brokstukken op. Deze drie kwamen
// letterlijk uit een proefronde op de echte site.

$vraag = '<p>Autoriteit kost tijd. Hoe werkt dat dan precies in de praktijk van alledag, vraagt men zich af.</p>';
gate_rejects(
    judge_wrap($vraag, 'Hoe werkt', [], ['target_terms' => ['werkt'], 'target_keyword' => '']),
    'G5',
    'G5 blokkeert een anker dat met een vraagwoord begint'
);

$kort = '<p>Autoriteit kost tijd. Daarom is linkbuilding zo belangrijk voor elke site die wil groeien.</p>';
gate_rejects(
    judge_wrap($kort, 'zo belangrijk', [], ['target_terms' => ['belangrijk'], 'target_keyword' => '']),
    'G5',
    'G5 blokkeert een anker dat met een bijwoord begint'
);

$staart = '<p>Autoriteit kost tijd. Je vraagt je af wat linkbuilding doet en waarom dat zo lang duurt.</p>';
gate_rejects(
    judge_wrap($staart, 'linkbuilding doet en', [], ['target_terms' => ['linkbuilding'], 'target_keyword' => '']),
    'G5',
    'G5 blokkeert een anker dat op een voegwoord eindigt'
);

// Maar het focus-keyword van de doelpagina mag altijd, ook als het kort is.
ok(judge_wrap($goed, 'linkbuilding', [], ['target_keyword' => 'linkbuilding'])['ok'],
   'G5 laat het focus-keyword van het doel door, ook al is het één woord');

/* =============================================================== G6 intro */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', ['segment_ref' => 'c:0']),
    'G6',
    'G6 blokkeert een link in de introductie-alinea'
);

/* ========================================================= G7 eerste zin */

$eerste = '<p>Interne structuur bepaalt hoeveel geduld je nodig hebt bij dit werk. De rest van deze alinea gaat '
        . 'over iets heel anders, namelijk de indeling van je navigatie en hoe je die opbouwt.</p>';
gate_rejects(
    judge_wrap($eerste, 'Interne structuur', [], ['target_terms' => ['structuur'], 'target_keyword' => '']),
    'G7',
    'G7 blokkeert een link in de eerste zin van een alinea bij zwakke topic-match'
);

// Maar als die eerste zin écht over het onderwerp gaat, mag het wel.
$eerste_ok = '<p>Bij linkbuilding draait alles om relevante backlinks. Verderop gaan we in op de techniek '
           . 'en op hoe je dat meet in de praktijk.</p>';
ok(judge_wrap($eerste_ok, 'linkbuilding')['ok'],
   'G7 laat de eerste zin door als die over het ankeronderwerp gaat');

/* ========================================================= G8 topic-match */

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. De keuken is volledig uitgerust met een vaatwasser en een oven.</p>',
        'onze linkbuilding aanpak',
        'De keuken is volledig uitgerust met een vaatwasser en een oven.',
        'De keuken is volledig uitgerust, net als onze linkbuilding aanpak.'
    ),
    'G8',
    'G8 blokkeert een anker dat aan een inhoudelijk losstaande zin wordt gehangen'
);

// Een losse kopregel is geen zin om een link aan op te hangen.
$fragment = '<p>Autoriteit kost tijd en aandacht en volhouden, dat weet elke marketeer. Linkbuilding uitgelegd.</p>';
gate_rejects(
    judge_wrap($fragment, 'Linkbuilding uitgelegd'),
    'G8',
    'G8 blokkeert een link in een zin van drie woorden'
);

/* ==================================================== G9 één per alinea */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['added_per_segment' => ['c:2' => 1]]),
    'G9',
    'G9 blokkeert een tweede toegevoegde link in dezelfde alinea'
);

/* ========================================================== G10 dichtheid */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['added_in_source' => 2, 'max_links_per_source' => 2]),
    'G10',
    'G10 blokkeert zodra de bronpagina zijn maximum heeft'
);

gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['density_headroom' => 0]),
    'G10',
    'G10 blokkeert als de linkdichtheid al aan het plafond zit'
);

/* =============================================== G11 anker-overoptimalisatie */

gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['anchor_usage' => 3, 'max_same_anchor' => 3]),
    'G11',
    'G11 blokkeert dezelfde ankertekst naar hetzelfde doel voor de vierde keer'
);

/* ====================================================== G12 ankerdiversiteit */

$onze = '<p>Autoriteit kost tijd. Kijk vooral naar onze linkbuilding aanpak, want die is in tien jaar gegroeid.</p>';
gate_rejects(
    judge_wrap($onze, 'onze linkbuilding aanpak', [], ['added_prefix_classes' => ['possessive']]),
    'G12',
    'G12 blokkeert een tweede anker dat met hetzelfde soort woord begint'
);

/* ====================================================== G13 frasering */

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'onze linkbuilding aanpak',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je op met goede content en geduld, bekijk ook onze linkbuilding aanpak.'
    ),
    'G13',
    'G13 blokkeert een toegevoegde zin die als advertentie leest'
);

/* ==================================================== G14 nieuwe claims */

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'linkbuilding voor backlinks',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je met linkbuilding voor backlinks in 30 dagen op.'
    ),
    'G14',
    'G14 blokkeert een getal dat het model erbij verzint'
);

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'linkbuilding voor backlinks',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je op met de beste linkbuilding voor backlinks en geduld.'
    ),
    'G14',
    'G14 blokkeert een superlatief die er niet stond'
);

/* ================================================= G15 herschrijfbegrenzing */

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'linkbuilding voor backlinks',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je op met goede content en geduld, en dat gaat over linkbuilding voor backlinks, '
        . 'wat wij al jaren doen voor klanten door heel Nederland en ver daarbuiten.'
    ),
    'G15',
    'G15 blokkeert een zin die veel te veel langer wordt'
);

gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'linkbuilding voor backlinks',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je op met linkbuilding voor backlinks en geduld'
    ),
    'G15',
    'G15 blokkeert een gewijzigd eindleesteken'
);

// Tautologie: het inhoudswoord uit het anker staat twee keer in de nieuwe zin.
gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Aan linkbuilding werk je jarenlang met goede content.</p>',
        'onze linkbuilding aanpak',
        'Aan linkbuilding werk je jarenlang met goede content.',
        'Aan linkbuilding werk je jarenlang, zie onze linkbuilding aanpak.'
    ),
    'G15',
    'G15 blokkeert hetzelfde inhoudswoord twee keer in één zin'
);

// Anker ingeklemd tussen gedachtestreepjes leest als ingeplakt.
gate_rejects(
    judge_rewrite(
        '<p>Inleiding hier. Backlinks bouw je op met goede content en geduld.</p>',
        'goede linkbuilding',
        'Backlinks bouw je op met goede content en geduld.',
        'Backlinks bouw je – goede linkbuilding – op met content en geduld.'
    ),
    'G15',
    'G15 blokkeert een anker tussen gedachtestreepjes'
);

/* =================================================== G17 anker-ambiguïteit */

// Kwam boven bij het draaien op echte content: hetzelfde anker naar twee pagina's.
gate_rejects(
    judge_wrap($goed, 'linkbuilding', [], ['anchor_claimed_by' => 99]),
    'G17',
    'G17 blokkeert een ankertekst die elders al naar een andere pagina wijst'
);

ok(judge_wrap($goed, 'linkbuilding', [], ['anchor_claimed_by' => 0])['ok'],
   'G17 laat een ankertekst door die nog geen andere bestemming heeft');

/* ========================================================= G16 integriteit */

// De inserter zelf laat dit niet gebeuren, dus we voeren het handmatig op:
// een resultaat waarin de halve alinea is verdwenen.
$hit  = IL_Text::phrase_offsets($goed, 'linkbuilding');
$cand = [
    'source_id' => 10, 'target_id' => 20, 'mode' => 'wrap',
    'anchor' => 'linkbuilding', 'url' => $URL, 'uid' => 'uid0000000003',
    'html' => $goed, 'offset' => $hit[0]['offset'], 'length' => strlen($hit[0]['match']),
    'segment_ref' => 'c:2', 'segment_kind' => 'paragraph',
    'result_html' => '<p>Wie serieus aan <a href="' . $URL . '" data-rr-il="uid0000000003">linkbuilding</a> doet.</p>',
];
$verdict = IL_Gates::check($cand, [
    'existing_targets' => [], 'added_in_source' => 0, 'added_per_segment' => [],
    'added_prefix_classes' => [], 'density_headroom' => 10, 'anchor_usage' => 0,
    'max_links_per_source' => 2, 'max_same_anchor' => 3,
    'target_terms' => ['linkbuilding'], 'target_keyword' => 'linkbuilding', 'intro_ref' => 'c:0',
]);
gate_rejects($verdict, 'G16', 'G16 blokkeert een resultaat waarin tekst is verdwenen');

done();
