<?php
/**
 * Integratietest voor de Interne Links-keten in een echte WordPress.
 *
 * De losse tests in tests/internal-links/ dekken de pure logica. Dit dekt wat
 * daar per definitie buiten valt: of de adapters de echte editors herkennen, of
 * er daadwerkelijk naar de database wordt geschreven, en of terugdraaien de
 * pagina exact teruggeeft.
 *
 * Draaien: tests/wordpress/run.sh
 */

// Let op: wp eval-file voert dit bestand uit BINNEN een functie. Een gewone
// $failures hier is dus lokaal, terwijl check() met `global` een andere variabele
// zou ophogen — dan meldt de test ALL PASS terwijl er checks falen. Vandaar
// overal expliciet $GLOBALS.
$GLOBALS['il_failures'] = 0;

function check($cond, $msg) {
    if ($cond) { echo "ok: $msg\n"; return; }
    $GLOBALS['il_failures']++;
    fwrite(STDERR, "FAIL: $msg\n");
}

function plain_of($id) {
    clean_post_cache($id);
    $t = '';
    foreach (IL_Content::segments($id) as $s) { $t .= $s['text'] . ' '; }
    return IL_Text::normalize_ws($t);
}

function html_of($id) {
    clean_post_cache($id);
    $h = '';
    foreach (IL_Content::segments($id) as $s) { $h .= $s['html']; }
    return $h;
}

function post_by_title($title) {
    $q = new WP_Query(['post_type' => 'post', 'title' => $title, 'posts_per_page' => 1, 'fields' => 'ids']);
    return $q->posts ? (int) $q->posts[0] : 0;
}

/* ------------------------------------------------------------------ opzet */

$TARGET    = post_by_title('Interne links: de complete gids');
$GUTENBERG = post_by_title('SEO basis voor beginners');
$CLASSIC   = post_by_title('Contentstrategie in 2026');
$ELEMENTOR = post_by_title('Technische SEO checklist');
$KNOP      = post_by_title('Onze diensten op een rij');
$EL_KNOP   = post_by_title('Aanpak in het kort');

check($TARGET && $GUTENBERG && $CLASSIC && $ELEMENTOR, 'testcontent gevonden (draai eerst seed.php)');
if (!$TARGET) { exit(1); }

// Schone lei.
foreach (IL_Suggestions::query(['status' => IL_Suggestions::STATUS_APPLIED]) as $r) {
    IL_Applier::undo($r['id']);
}
IL_Graph_Scanner::reset();
foreach (array_chunk(IL_Graph_Scanner::all_post_ids(), 25) as $chunk) {
    IL_Graph_Scanner::scan_batch($chunk);
}

/* -------------------------------------------------------------- adapters */

foreach ([$GUTENBERG => 'gutenberg', $CLASSIC => 'classic', $ELEMENTOR => 'elementor'] as $id => $expected) {
    $adapter = IL_Content::adapter_for($id);
    check($adapter && $adapter->slug() === $expected, "adapter voor #$id is $expected");
    check(count(IL_Content::linkable_segments($id)) > 0, "#$id levert linkbare alinea's");
}

check(count(IL_Index::corpus()) === count(IL_Graph_Scanner::all_post_ids()), 'de index dekt elke gescande pagina');

/* -------------------------------------------- links buiten de lopende tekst */

// Een link in een knopblok of een Elementor-knop staat niet in de segmenten die
// de adapters teruggeven. Telt hij niet mee, dan heet een gelinkte pagina ten
// onrechte orphan én mag de planner er een tweede link vanaf dezelfde bron bij zetten.
check(in_array($TARGET, IL_Graph_Scanner::targets_of($KNOP), true),
      'een link in een Gutenberg-knopblok telt mee in de graaf');
check(in_array($TARGET, IL_Graph_Scanner::targets_of($EL_KNOP), true),
      'een link in een Elementor-knopwidget telt mee in de graaf');
check(IL_Graph_Scanner::inbound_count($TARGET) === 2,
      'het doel heeft twee inkomende links, allebei van buiten de lopende tekst');

foreach (IL_Planner::candidate_sources(get_post($TARGET), 25) as $hit) {
    check((int) $hit['id'] !== $KNOP && (int) $hit['id'] !== $EL_KNOP,
          "kandidaat #{$hit['id']} is niet een bron die al via een knop linkt");
}

// Voor de rest van de test willen we het doel weer als orphan.
foreach ([$KNOP, $EL_KNOP] as $id) { wp_trash_post($id); }
IL_Graph_Scanner::reset();
foreach (array_chunk(IL_Graph_Scanner::all_post_ids(), 25) as $chunk) {
    IL_Graph_Scanner::scan_batch($chunk);
}
check(IL_Graph_Scanner::inbound_count($TARGET) === 0, 'het doel begint als orphan');

// En een pagina in de prullenbak hoort geen bron meer te zijn.
foreach (IL_Planner::candidate_sources(get_post($TARGET), 25) as $hit) {
    check(get_post_status($hit['id']) === 'publish',
          "kandidaat-bron #{$hit['id']} is gepubliceerd");
}

/* ------------------------------------------------- plannen en wegschrijven */

$plan = IL_Suggester::for_target($TARGET, true);
$suggestions = $plan['suggestions'];
check(count($suggestions) >= 3, 'er zijn minstens drie suggesties, één per editor');

$editors = [];
foreach ($suggestions as $s) { $editors[$s['editor']] = true; }
check(count($editors) >= 3, 'de suggesties komen uit meerdere editors: ' . implode(', ', array_keys($editors)));

$before = [];
foreach ($suggestions as $s) { $before[(int) $s['source_id']] = plain_of((int) $s['source_id']); }

$applied = [];
foreach ($suggestions as $s) {
    IL_Suggestions::update($s['id'], ['status' => IL_Suggestions::STATUS_APPROVED]);
    $r = IL_Applier::apply($s['id']);
    check($r['ok'], "link geplaatst vanuit #{$s['source_id']} ({$s['editor']})");
    if ($r['ok']) { $applied[] = $s; }
}

foreach ($applied as $s) {
    $sid = (int) $s['source_id'];
    $row = IL_Suggestions::get($s['id']);
    check(strpos(html_of($sid), 'data-rr-il="' . $row['link_uid'] . '"') !== false,
          "de link staat in de opgeslagen content van #$sid");
    check(plain_of($sid) === $before[$sid],
          "de platte tekst van #$sid is onveranderd (alleen een link toegevoegd)");
    check(count(wp_get_post_revisions($sid)) >= 1, "#$sid heeft een revisie van vóór de wijziging");
}

check(IL_Graph_Scanner::inbound_count($TARGET) === count($applied), 'het doel is geen orphan meer');

/* ------------------------------------------------------------ terugdraaien */

foreach ($applied as $s) {
    $sid = (int) $s['source_id'];
    $r = IL_Applier::undo($s['id']);
    check($r['ok'], "teruggedraaid vanuit #$sid");
    check(plain_of($sid) === $before[$sid], "#$sid staat exact terug zoals het was");
    check(strpos(html_of($sid), 'data-rr-il') === false, "#$sid heeft geen restanten van de link");
}

/* ----------------------------------- de gates op een slechte LLM-respons */

$basis = 'Wie aan interne links werkt, versterkt alle drie tegelijk, omdat je zowel de vindbaarheid als de samenhang van je site verbetert.';
$slecht = [
    'een verzonnen getal'      => 'Wie aan goede interne links werkt, versterkt alle drie tegelijk in 30 dagen, omdat je zowel de vindbaarheid als de samenhang van je site verbetert.',
    'een CTA erachter'         => 'Wie aan interne links werkt, versterkt alle drie tegelijk. Bekijk ook onze goede interne links.',
    'de helft van de zin weg'  => 'Wie aan goede interne links werkt.',
    'het anker ingeklemd'      => 'Wie aan – goede interne links – werkt, versterkt alle drie tegelijk, omdat je de vindbaarheid en de samenhang van je site verbetert.',
];

foreach ($slecht as $naam => $na) {
    foreach (IL_Suggestions::query(['target_id' => $TARGET, 'status' => IL_Suggestions::STATUS_APPLIED]) as $r) {
        IL_Applier::undo($r['id']);
    }
    IL_Graph_Scanner::flush();

    $id = IL_Suggestions::insert([
        'target_id' => $TARGET, 'source_id' => $GUTENBERG, 'score' => 0.5,
        'mode' => 'rewrite', 'anchor' => 'goede interne links', 'segment_ref' => 'b:4',
        'sentence_before' => $basis, 'sentence_after' => $na,
        'status' => IL_Suggestions::STATUS_APPROVED,
    ]);
    $voor = plain_of($GUTENBERG);
    $r = IL_Applier::apply($id);
    check(!$r['ok'], "geweigerd: $naam");
    check(plain_of($GUTENBERG) === $voor, "content onveranderd na weigering ($naam)");
}

/* ------------------------------ en de goede herschrijving mag er wél door */

foreach (IL_Suggestions::query(['target_id' => $TARGET, 'status' => IL_Suggestions::STATUS_APPLIED]) as $r) {
    IL_Applier::undo($r['id']);
}
IL_Graph_Scanner::flush();

$voor = plain_of($GUTENBERG);
$id = IL_Suggestions::insert([
    'target_id' => $TARGET, 'source_id' => $GUTENBERG, 'score' => 0.5,
    'mode' => 'rewrite', 'anchor' => 'goede interne links', 'segment_ref' => 'b:4',
    'sentence_before' => $basis,
    'sentence_after'  => 'Wie aan goede interne links werkt, versterkt alle drie tegelijk, omdat je zowel de vindbaarheid als de samenhang van je site verbetert.',
    'status' => IL_Suggestions::STATUS_APPROVED,
]);
$r = IL_Applier::apply($id);
check($r['ok'], 'een nette herschrijving wordt wél geplaatst');
check(strpos(plain_of($GUTENBERG), 'Wie aan goede interne links werkt') !== false, 'de herschreven zin staat in de pagina');
IL_Applier::undo($id);
check(plain_of($GUTENBERG) === $voor, 'ook een herschrijving is exact terug te draaien');

/* ------------------------------------------------------------------ slot */

echo "\n";
if ($GLOBALS['il_failures'] > 0) {
    fwrite(STDERR, $GLOBALS['il_failures'] . " FAILURES\n");
    exit(1);
}
echo "ALL PASS\n";
