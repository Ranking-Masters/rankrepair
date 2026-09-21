<?php
/**
 * Zet een testsite op met content in drie editors.
 * Draaien met: wp eval-file seed.php
 */

function seed_post($title, $content, $kw = '', $meta = []) {
    $id = wp_insert_post([
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ]);
    if ($kw !== '') {
        update_post_meta($id, '_yoast_wpseo_focuskw', $kw);
    }
    foreach ($meta as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    echo "  #$id  $title\n";
    return $id;
}

function gb($paragraphs) {
    $out = '';
    foreach ($paragraphs as $p) {
        if (strpos($p, '<h2>') === 0) {
            $out .= "<!-- wp:heading -->\n$p\n<!-- /wp:heading -->\n\n";
        } else {
            $out .= "<!-- wp:paragraph -->\n<p>$p</p>\n<!-- /wp:paragraph -->\n\n";
        }
    }
    return trim($out);
}

echo "Content aanmaken:\n";

// ---------------------------------------------------------------- DOELPAGINA
$target = seed_post(
    'Interne links: de complete gids',
    gb([
        'Interne links zijn de verbindingen tussen pagina\'s op je eigen website. Ze bepalen mede hoe Google je site begrijpt.',
        '<h2>Waarom het uitmaakt</h2>',
        'Een pagina zonder inkomende interne links is voor een zoekmachine moeilijk te vinden en krijgt nauwelijks autoriteit doorgegeven vanuit de rest van de site.',
        'In deze gids lopen we langs de opbouw van een gezonde structuur, de rol van ankerteksten en de veelgemaakte fouten die we in de praktijk tegenkomen.',
    ]),
    'interne links'
);

// ---------------------------------------------------- BRON 1 — Gutenberg
$gb_source = seed_post(
    'SEO basis voor beginners',
    gb([
        'Zoekmachineoptimalisatie klinkt ingewikkeld, maar de basis is goed te leren. In dit artikel lopen we de onderdelen langs die er echt toe doen.',
        '<h2>De drie pijlers</h2>',
        'Techniek, content en autoriteit vormen samen de basis. Wie aan interne links werkt, versterkt alle drie tegelijk, omdat je zowel de vindbaarheid als de samenhang van je site verbetert.',
        'Begin bij de belangrijkste pagina\'s en werk van daaruit naar buiten. Dat levert sneller resultaat op dan overal tegelijk beginnen.',
        'Techniek gaat over de vraag of een zoekmachine je pagina kan bereiken en begrijpen. Denk aan laadtijd, aan een logische URL-structuur en aan een sitemap die klopt met wat er werkelijk op de site staat.',
        'Content gaat over de vraag of je het antwoord geeft waar iemand naar zocht. Dat is minder een kwestie van zoekwoorden tellen en meer een kwestie van de vraag achter de zoekopdracht begrijpen.',
        'Autoriteit tenslotte bouw je op met de tijd. Verwijzingen van andere sites helpen, maar de manier waarop je je eigen pagina\'s aan elkaar knoopt telt net zo goed mee in het geheel.',
    ]),
    'seo basis'
);

// ------------------------------------------------ BRON 2 — klassieke editor
$classic_source = seed_post(
    'Contentstrategie in 2026',
    "Een contentstrategie is meer dan een planning met onderwerpen. Het is de afspraak over waar je wel en niet over schrijft.\n\n"
    . "Publiceren zonder structuur levert een archief op dat niemand doorzoekt. Met goede interne links maak je van losse artikelen een samenhangend geheel dat lezers verder helpt.\n\n"
    . "Plan daarom niet alleen wat je schrijft, maar ook waar het in je site komt te hangen.\n\n"
    . "Begin met een uitdraai van alles wat er al staat. In bijna elk archief blijkt een derde van de artikelen nooit bezocht te worden, en een deel daarvan verdient een herschrijving in plaats van een opvolger.\n\n"
    . "Kies daarna per onderwerp één pagina die het hoofdverhaal draagt. De rest verwijst daarnaartoe, zodat lezers en zoekmachines allebei weten waar het echte antwoord staat.\n\n"
    . "Zet tot slot in je planning wie de tekst schrijft, wie hem nakijkt en wanneer hij opnieuw tegen het licht gaat. Zonder die derde afspraak veroudert je archief ongemerkt.",
    'contentstrategie'
);

// ------------------------------------------------------ BRON 3 — Elementor
$elementor_data = [
    ['id' => 'sec001', 'elType' => 'section', 'elements' => [
        ['id' => 'col001', 'elType' => 'column', 'elements' => [
            ['id' => 'wid001', 'elType' => 'widget', 'widgetType' => 'heading',
             'settings' => ['title' => 'Technische SEO checklist']],
            ['id' => 'wid002', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Voor elke oplevering lopen we een vaste lijst na. Die begint bij de indexeerbaarheid en eindigt bij de snelheid van de pagina.</p>']],
            ['id' => 'wid003', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Controleer daarna of elke belangrijke pagina bereikbaar is. Wie interne links goed inricht, voorkomt dat waardevolle pagina\'s onvindbaar in het archief verdwijnen.</p>']],
            ['id' => 'wid004', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Sluit af met een crawl en vergelijk die met je sitemap. Verschillen tussen die twee wijzen bijna altijd op een structuurprobleem.</p>']],
            ['id' => 'wid005', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Let bij de indexeerbaarheid op meer dan alleen robots.txt. Een canonical die naar de verkeerde pagina wijst haalt een goed werkende pagina net zo hard uit de resultaten, en dat zie je pas weken later terug in je cijfers.</p>']],
            ['id' => 'wid006', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Snelheid meten we op de echte pagina, niet op de homepage. Een productpagina met twintig afbeeldingen gedraagt zich anders dan een landingspagina met één blok tekst, en juist die eerste bepaalt wat bezoekers ervaren.</p>']],
        ]],
    ]],
];

$elementor_source = seed_post(
    'Technische SEO checklist',
    '<p>Deze pagina is met Elementor gemaakt.</p>',
    'technische seo',
    [
        '_elementor_edit_mode' => 'builder',
        '_elementor_data'      => wp_slash(wp_json_encode($elementor_data)),
    ]
);

// -------------------------------------------- Vulling, zodat IDF iets voorstelt
seed_post('Linkbuilding uitgelegd', gb([
    'Linkbuilding is het verwerven van verwijzingen vanaf andere websites naar de jouwe.',
    'Kwaliteit gaat boven aantal. Eén verwijzing van een relevante site doet meer dan tien van willekeurige plekken.',
    'Werk daarom vanuit onderwerpen waar je echt iets over te zeggen hebt, in plaats vanuit een lijstje domeinen.',
]), 'linkbuilding');

seed_post('Google Analytics 4 instellen', gb([
    'GA4 werkt anders dan Universal Analytics. De belangrijkste verandering zit in het gebeurtenismodel.',
    'Meet eerst wat je echt gebruikt in je rapportage. Alles meten levert vooral ruis op.',
    'Leg per meetwaarde vast wie hem gebruikt en welk besluit erop volgt.',
]), 'ga4');

seed_post('Een website migreren zonder verkeersverlies', gb([
    'Een migratie is het moment waarop technische schulden zichtbaar worden. Bereid hem daarom voor als een project.',
    'Maak vooraf een volledige URL-uitdraai van de oude site en bepaal per URL wat er gebeurt.',
    'Meet na de livegang dagelijks, niet wekelijks: problemen los je in de eerste week het goedkoopst op.',
]), 'website migreren');

// ----------------------------------------------- BRON 4 — links buiten de tekst
// Een knop en een tabel: precies de plekken die de adapters NIET als lopende
// tekst lezen, maar die wel een echte interne link bevatten. Het doel mag
// daardoor geen orphan meer heten, en er mag geen tweede link bij komen.
$knop_bron = seed_post(
    'Onze diensten op een rij',
    "<!-- wp:paragraph -->\n<p>Van techniek tot content: dit is wat we doen en hoe we dat aanpakken voor onze klanten.</p>\n<!-- /wp:paragraph -->\n\n"
    . "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n"
    . "<div class=\"wp-block-button\"><a class=\"wp-block-button__link\" href=\"" . get_permalink($target) . "\">Lees de gids over interne links</a></div>\n"
    . "<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n"
    . "<!-- wp:paragraph -->\n<p>Heb je vragen over een van deze onderdelen, dan denken we graag even mee voordat je iets vastlegt.</p>\n<!-- /wp:paragraph -->",
    'diensten'
);

// En hetzelfde bij Elementor: een knop-widget met de URL in settings.link.url.
$elementor_knop = [
    ['id' => 'sec900', 'elType' => 'section', 'elements' => [
        ['id' => 'col900', 'elType' => 'column', 'elements' => [
            ['id' => 'wid900', 'elType' => 'widget', 'widgetType' => 'text-editor',
             'settings' => ['editor' => '<p>Een korte introductie op deze pagina, zodat er ook lopende tekst is om mee te werken in de tests.</p>']],
            ['id' => 'wid901', 'elType' => 'widget', 'widgetType' => 'button',
             'settings' => ['text' => 'Naar de gids', 'link' => ['url' => get_permalink($target), 'is_external' => '']]],
        ]],
    ]],
];
$elementor_knop_bron = seed_post(
    'Aanpak in het kort',
    '<p>Deze pagina is met Elementor gemaakt.</p>',
    'aanpak',
    [
        '_elementor_edit_mode' => 'builder',
        '_elementor_data'      => wp_slash(wp_json_encode($elementor_knop)),
    ]
);

echo "\nDoel (orphan): #$target\n";
echo "Bronnen: gutenberg #$gb_source, classic #$classic_source, elementor #$elementor_source\n";
echo "Links buiten de lopende tekst: knopblok #$knop_bron, elementor-knop #$elementor_knop_bron\n";
