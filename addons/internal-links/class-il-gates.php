<?php
/**
 * De gates: deterministische controles op één linkkandidaat.
 *
 * Het uitgangspunt komt uit het interne-linksysteem van ferienhausniederlande.de:
 * de LLM doet de oordelen (welke zin, welk anker, welke vorm), de code bewaakt de
 * invarianten (bestaat de zin echt, staat het anker er letterlijk in, verdwijnt er
 * geen tekst, is het geen zelf-link, wordt het geen sjabloon).
 *
 * Alles hier is puur: kandidaat + context in, oordeel uit. Geen database, geen
 * get_post(). De tellingen die uit de database komen zitten in de context.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Gates {

    /** Ankerteksten die nooit goed zijn, hoe relevant de pagina ook is. */
    private static $forbidden_anchors = [
        'klik hier', 'hier', 'hier klikken', 'klik', 'lees meer', 'meer lezen', 'lees verder',
        'bekijk ook', 'lees ook', 'zie ook', 'zie hier', 'kijk hier', 'meer informatie',
        'voor meer informatie', 'deze pagina', 'deze link', 'link', 'website', 'pagina',
        'click here', 'read more', 'this page', 'learn more',
    ];

    /** Frasen die van een link een advertentie maken. */
    private static $forbidden_phrases = [
        '/\bklik(t|ken)?\s+(hier|op)\b/iu',
        '/\blees\s+(hier\s+)?(meer|ook|verder)\b/iu',
        '/\bbekijk\s+(hier\s+)?(ook|onze|alle)\b/iu',
        '/\bvoor\s+meer\s+informatie\b/iu',
        '/\bneem\s+(gerust\s+)?een\s+kijkje\b/iu',
        '/\bcheck\s+(dit|onze|hier)\b/iu',
        '/\bwil\s+je\s+meer\s+weten\b/iu',
        '/\bbenieuwd\s+naar\b/iu',
    ];

    /**
     * Woorden waar een zin mee begint, niet een ankertekst.
     *
     * Woordgroepen uit een titel leveren anders brokstukken op: "Hoe werkt",
     * "zo belangrijk", "hoe gebruik". Grammaticaal incompleet, en voor een lezer
     * zegt het niets over waar de link heen gaat.
     */
    private static $anchor_openers = [
        'hoe', 'wat', 'waarom', 'wanneer', 'welke', 'wie', 'waar', 'waarmee', 'waardoor',
        'zo', 'dus', 'want', 'omdat', 'terwijl', 'zodat', 'maar', 'echter', 'toch',
        'werkt', 'werken', 'doe', 'doet', 'maak', 'maakt', 'krijg', 'krijgt', 'zorg', 'zorgt',
        'gebruik', 'gebruikt', 'kies', 'kiest', 'begin', 'begint', 'ontdek', 'leer',
    ];

    /** Woorden die een claim toevoegen die er niet stond. */
    private static $superlatives = [
        'beste', 'grootste', 'goedkoopste', 'snelste', 'mooiste', 'nummer 1', 'meest',
        'gegarandeerd', 'altijd', 'nooit', 'iedereen', 'perfecte', 'ultieme', 'onmisbare',
        'belangrijkste', 'krachtigste', 'sterkste', 'slimste', 'enige', 'grootste',
    ];

    /**
     * Nederlandse overtreffende trap eindigt op -ste. Een lijst blijft altijd
     * incompleet, dus vangen we de vorm; deze woorden zijn de uitzonderingen die
     * geen claim zijn maar volgorde of nuance aanduiden.
     */
    private static $superlative_exceptions = [
        'eerste', 'tweede', 'laatste', 'vaste', 'juiste', 'volgende', 'vorige',
        'naaste', 'kaste', 'kuste', 'rustte', 'passte', 'beste',
    ];

    /** Woorden die een hoeveelheid aanduiden; die mogen niet zomaar verdwijnen. */
    private static $quantity_words = [
        'honderden', 'duizenden', 'tientallen', 'miljoen', 'miljard', 'procent',
        'dubbel', 'helft', 'kwart', 'meerdere', 'talloze', 'enkele', 'diverse',
    ];

    /**
     * Toetst één kandidaat.
     *
     * @return array ['ok' => bool, 'gate' => string, 'reason' => string]
     */
    public static function check(array $cand, array $ctx) {
        $checks = [
            'G1'  => 'gate_self_link',
            'G2'  => 'gate_already_linked',
            'G3'  => 'gate_forbidden_context',
            'G4'  => 'gate_anchor_verbatim',
            'G5'  => 'gate_anchor_form',
            'G6'  => 'gate_intro',
            'G7'  => 'gate_first_sentence',
            'G8'  => 'gate_topic_match',
            'G9'  => 'gate_one_per_paragraph',
            'G10' => 'gate_density',
            'G11' => 'gate_anchor_overuse',
            'G12' => 'gate_anchor_diversity',
            'G13' => 'gate_forbidden_phrasing',
            'G14' => 'gate_no_new_claims',
            'G15' => 'gate_rewrite_limits',
            'G16' => 'gate_integrity',
            'G17' => 'gate_anchor_ambiguity',
        ];

        foreach ($checks as $id => $method) {
            $reason = self::$method($cand, $ctx);
            if ($reason !== null) {
                return ['ok' => false, 'gate' => $id, 'reason' => $reason];
            }
        }
        return ['ok' => true, 'gate' => '', 'reason' => ''];
    }

    /* ------------------------------------------------------------------ G1 */

    private static function gate_self_link(array $c, array $ctx) {
        if ((int) $c['source_id'] === (int) $c['target_id']) {
            return __('bron en doel zijn dezelfde pagina', 'rankrepair');
        }
        return null;
    }

    /* ------------------------------------------------------------------ G2 */

    private static function gate_already_linked(array $c, array $ctx) {
        $existing = isset($ctx['existing_targets']) ? (array) $ctx['existing_targets'] : [];
        if (in_array((int) $c['target_id'], array_map('intval', $existing), true)) {
            return __('deze bron linkt al naar dit doel', 'rankrepair');
        }
        return null;
    }

    /* ------------------------------------------------------------------ G3 */

    private static function gate_forbidden_context(array $c, array $ctx) {
        $kind = isset($c['segment_kind']) ? $c['segment_kind'] : 'paragraph';
        if ($kind === 'heading') {
            return __('koppen krijgen geen interne links', 'rankrepair');
        }
        if ($c['mode'] !== 'wrap') {
            return null; // de zinsmodi controleren dit in de inserter
        }
        $ranges = IL_Text::protected_ranges($c['html']);
        if (IL_Text::in_protected_range((int) $c['offset'], (int) $c['length'], $ranges)) {
            return __('de ankerpositie ligt in een link, code of shortcode', 'rankrepair');
        }
        return null;
    }

    /* ------------------------------------------------------------------ G4 */

    private static function gate_anchor_verbatim(array $c, array $ctx) {
        if (empty($c['result_html'])) {
            return __('er is geen resultaat om te controleren', 'rankrepair');
        }
        $uid = preg_quote((string) $c['uid'], '/');
        if (!preg_match('/<a\b[^>]*data-rr-il\s*=\s*(["\'])' . $uid . '\1[^>]*>(.*?)<\/a\s*>/is', $c['result_html'], $m)) {
            return __('de link is niet in het resultaat terechtgekomen', 'rankrepair');
        }
        $inner = IL_Text::normalize_anchor(IL_Text::plain_text($m[2]));
        if ($inner !== IL_Text::normalize_anchor($c['anchor'])) {
            return __('de ankertekst in het resultaat wijkt af', 'rankrepair');
        }
        return null;
    }

    /* ------------------------------------------------------------------ G5 */

    private static function gate_anchor_form(array $c, array $ctx) {
        $anchor = IL_Text::normalize_ws($c['anchor']);
        $words  = IL_Text::word_count($anchor);
        $chars  = mb_strlen($anchor, 'UTF-8');

        if ($words < 1 || $words > 8) {
            return sprintf(__('ankertekst is %d woorden (toegestaan: 1–8)', 'rankrepair'), $words);
        }
        if ($chars < 3 || $chars > 80) {
            return sprintf(__('ankertekst is %d tekens (toegestaan: 3–80)', 'rankrepair'), $chars);
        }
        if (!preg_match('/\p{L}/u', $anchor)) {
            return __('ankertekst bevat geen letters', 'rankrepair');
        }
        if (preg_match('#^(https?://|www\.)#i', $anchor)) {
            return __('een kale URL is geen ankertekst', 'rankrepair');
        }
        if (in_array(IL_Text::normalize_anchor($anchor), self::$forbidden_anchors, true)) {
            return sprintf(__('"%s" zegt niets over de bestemming', 'rankrepair'), $anchor);
        }
        $inhoud = IL_Text::remove_stopwords(IL_Text::tokenize($anchor));
        if (count($inhoud) === 0) {
            return __('ankertekst bestaat alleen uit stopwoorden', 'rankrepair');
        }

        $woorden = preg_split('/\s+/u', $anchor);
        $eerste  = mb_strtolower($woorden[0], 'UTF-8');
        $laatste = mb_strtolower(rtrim($woorden[count($woorden) - 1], ',.:;!?'), 'UTF-8');

        // Deze twee gelden altijd, ook voor het focus-keyword. Een keyword dat
        // als vraag is ingevuld ("Hoe werkt Google Ads?") komt op echte sites
        // gewoon voor, en is als ankertekst nog steeds onbruikbaar.
        if (in_array($eerste, self::$anchor_openers, true)) {
            return sprintf(__('"%s" is een zinsbegin, geen ankertekst', 'rankrepair'), $anchor);
        }
        // Zelfde probleem aan de achterkant: "google ads en waarom" loopt door
        // in de zin en is als losse verwijzing onaf.
        if (in_array($laatste, self::$anchor_openers, true) || IL_Text::is_stopword($laatste)) {
            return sprintf(__('"%s" loopt halverwege een zin af', 'rankrepair'), $anchor);
        }

        // Eén inhoudswoord mag wél, maar alleen als het exact het focus-keyword
        // van de doelpagina is — dan is het per definitie waar die pagina over gaat.
        if (count($inhoud) < 2) {
            $kw = isset($ctx['target_keyword']) ? IL_Text::normalize_anchor($ctx['target_keyword']) : '';
            if ($kw !== '' && IL_Text::normalize_anchor($anchor) === $kw) {
                return null;
            }
            return sprintf(__('"%s" is te weinig om een bestemming mee aan te duiden', 'rankrepair'), $anchor);
        }
        return null;
    }

    /* ------------------------------------------------------------------ G6 */

    private static function gate_intro(array $c, array $ctx) {
        $intro = isset($ctx['intro_ref']) ? $ctx['intro_ref'] : null;
        if ($intro !== null && (string) $c['segment_ref'] === (string) $intro) {
            return __('links horen niet in de introductie', 'rankrepair');
        }
        return null;
    }

    /* ------------------------------------------------------------------ G7 */

    /**
     * Geen link in de eerste zin van een alinea — die zin zet de alinea op en
     * een link leidt de lezer meteen weg. Tenzij die zin echt over het
     * ankeronderwerp gaat; dan is de link juist logisch.
     */
    private static function gate_first_sentence(array $c, array $ctx) {
        $host = self::host_sentence($c);
        if ($host === '') {
            return null;
        }
        $sentences = IL_Text::split_sentences(IL_Text::plain_text($c['html']));
        if (empty($sentences)) {
            return null;
        }
        if (IL_Text::normalize_ws($sentences[0]) !== IL_Text::normalize_ws($host)) {
            return null;
        }

        $terms  = isset($ctx['target_terms']) ? (array) $ctx['target_terms'] : [];
        $shared = self::shared_terms($host, $terms);
        if (count($shared) >= 2) {
            return null;
        }
        $kw = isset($ctx['target_keyword']) ? (string) $ctx['target_keyword'] : '';
        if ($kw !== '' && IL_Text::contains_phrase($host, $kw)) {
            return null;
        }
        return __('eerste zin van de alinea, en die gaat niet over het onderwerp', 'rankrepair');
    }

    /* ------------------------------------------------------------------ G8 */

    private static function gate_topic_match(array $c, array $ctx) {
        $host = self::host_sentence($c);
        if ($host === '') {
            return null;
        }
        // Een losse kopregel of bijschrift van een paar woorden is geen zin om
        // een link aan op te hangen, ook al matcht het onderwerp.
        $woorden = IL_Text::word_count($host);
        if ($woorden < 6) {
            return __('de zin is te kort om een link te dragen', 'rankrepair');
        }
        // En andersom: veertig woorden zonder eindleesteken is geen zin maar een
        // platgeslagen tabel of opsomming. Kwam boven op een vergelijkingstabel
        // die als één blok tekst werd gelezen.
        if ($woorden > 40 && IL_Text::terminal_punctuation($host) === '') {
            return __('dit is geen lopende zin maar een tabel of opsomming', 'rankrepair');
        }
        $terms = isset($ctx['target_terms']) ? (array) $ctx['target_terms'] : [];
        if (empty($terms)) {
            return null;
        }
        if (count(self::shared_terms($host, $terms)) >= 1) {
            return null;
        }
        return __('de zin gaat inhoudelijk niet over de doelpagina', 'rankrepair');
    }

    /* ------------------------------------------------------------------ G9 */

    private static function gate_one_per_paragraph(array $c, array $ctx) {
        $per_segment = isset($ctx['added_per_segment']) ? (array) $ctx['added_per_segment'] : [];
        $ref = (string) $c['segment_ref'];
        if (!empty($per_segment[$ref])) {
            return __('er staat al een nieuwe link in deze alinea', 'rankrepair');
        }
        return null;
    }

    /* ----------------------------------------------------------------- G10 */

    private static function gate_density(array $c, array $ctx) {
        $added = isset($ctx['added_in_source']) ? (int) $ctx['added_in_source'] : 0;
        $max   = isset($ctx['max_links_per_source']) ? (int) $ctx['max_links_per_source'] : 2;
        if ($added >= $max) {
            return sprintf(__('deze bron heeft er al %d bij gekregen', 'rankrepair'), $added);
        }
        $headroom = isset($ctx['density_headroom']) ? (int) $ctx['density_headroom'] : PHP_INT_MAX;
        if ($headroom - $added <= 0) {
            return __('linkdichtheid van de bronpagina is al aan het maximum', 'rankrepair');
        }
        return null;
    }

    /* ----------------------------------------------------------------- G11 */

    private static function gate_anchor_overuse(array $c, array $ctx) {
        $used = isset($ctx['anchor_usage']) ? (int) $ctx['anchor_usage'] : 0;
        $max  = isset($ctx['max_same_anchor']) ? (int) $ctx['max_same_anchor'] : 3;
        if ($used >= $max) {
            return sprintf(__('deze ankertekst wijst al %dx naar deze pagina', 'rankrepair'), $used);
        }
        return null;
    }

    /* ----------------------------------------------------------------- G12 */

    private static function gate_anchor_diversity(array $c, array $ctx) {
        $used  = isset($ctx['added_prefix_classes']) ? (array) $ctx['added_prefix_classes'] : [];
        $class = IL_Text::anchor_prefix_class($c['anchor']);
        if ($class !== 'bare_noun' && in_array($class, $used, true)) {
            return __('deze bron heeft al een anker dat zo begint', 'rankrepair');
        }
        return null;
    }

    /* ----------------------------------------------------------------- G13 */

    private static function gate_forbidden_phrasing(array $c, array $ctx) {
        $subject = $c['anchor'];
        if ($c['mode'] !== 'wrap') {
            $subject .= ' ' . (isset($c['sentence_after']) ? $c['sentence_after'] : '');
        }
        foreach (self::$forbidden_phrases as $re) {
            if (preg_match($re, $subject)) {
                return __('frasering leest als een advertentie', 'rankrepair');
            }
        }
        return null;
    }

    /* ----------------------------------------------------------------- G14 */

    /**
     * Wat de LLM toevoegt mag geen feit zijn dat er niet stond. Getallen,
     * bedragen, jaartallen en superlatieven zijn precies waar een taalmodel
     * onbedoeld iets verzint.
     */
    private static function gate_no_new_claims(array $c, array $ctx) {
        if ($c['mode'] === 'wrap') {
            return null;
        }
        $before = mb_strtolower(IL_Text::normalize_ws($c['sentence_before']), 'UTF-8');
        $after  = mb_strtolower(IL_Text::normalize_ws($c['sentence_after']), 'UTF-8');

        $new_words = array_diff(
            preg_split('/\s+/u', $after, -1, PREG_SPLIT_NO_EMPTY),
            preg_split('/\s+/u', $before, -1, PREG_SPLIT_NO_EMPTY)
        );
        $added = implode(' ', $new_words);

        if (preg_match('/\d/u', $added) && !preg_match('/\d/u', $before)) {
            return __('er wordt een getal toegevoegd dat er niet stond', 'rankrepair');
        }
        if (preg_match('/[€$£%]/u', $added)) {
            return __('er wordt een bedrag of percentage toegevoegd', 'rankrepair');
        }
        foreach (self::$superlatives as $word) {
            if (strpos($added, $word) !== false && strpos($before, $word) === false) {
                return sprintf(__('er wordt een claim toegevoegd ("%s")', 'rankrepair'), $word);
            }
        }
        // En de vorm, voor alles wat niet in de lijst staat.
        if (preg_match_all('/\b(\p{L}{4,}ste)\b/u', $added, $mm)) {
            foreach ($mm[1] as $woord) {
                if (in_array($woord, self::$superlative_exceptions, true)) { continue; }
                if (strpos($before, $woord) !== false) { continue; }
                return sprintf(__('er wordt een claim toegevoegd ("%s")', 'rankrepair'), $woord);
            }
        }

        /*
         * De andere kant op: wat er stond mag niet stilletjes verdwijnen.
         *
         * Uit een proefronde op echte content: "bepaald door honderden factoren"
         * werd "bepaald door de belangrijkste ranking factors". Het woordaantal
         * bleef binnen de marge, maar "honderden" was weg — een feit minder, zonder
         * dat iemand dat aan de lengte zou zien.
         */
        $anchor_tokens = array_flip(IL_Text::tokenize($c['anchor']));
        $na            = array_flip(IL_Text::tokenize($after));
        $weg           = [];
        foreach (IL_Text::remove_stopwords(IL_Text::tokenize($before)) as $token) {
            if (isset($na[$token]) || isset($anchor_tokens[$token])) { continue; }
            $weg[$token] = true;
            if (in_array($token, self::$quantity_words, true) || preg_match('/^\d/', $token)) {
                return sprintf(__('"%s" verdwijnt uit de zin; dat is een feit minder', 'rankrepair'), $token);
            }
        }
        if (count($weg) > 2) {
            return sprintf(__('er verdwijnen %d woorden uit de zin; dit is geen minimale aanpassing', 'rankrepair'), count($weg));
        }

        return null;
    }

    /* ----------------------------------------------------------------- G15 */

    private static function gate_rewrite_limits(array $c, array $ctx) {
        if ($c['mode'] === 'wrap') {
            return null;
        }
        $before = IL_Text::normalize_ws($c['sentence_before']);
        $after  = IL_Text::normalize_ws($c['sentence_after']);

        $wb = IL_Text::word_count($before);
        $wa = IL_Text::word_count($after);
        $max_extra = $c['mode'] === 'clause' ? 12 : 8;

        if ($wa < $wb - 2 || $wa > $wb + $max_extra) {
            return sprintf(__('de nieuwe zin wijkt te veel af (%d → %d woorden)', 'rankrepair'), $wb, $wa);
        }
        if (IL_Text::terminal_punctuation($before) !== IL_Text::terminal_punctuation($after)) {
            return __('het eindleesteken van de zin is veranderd', 'rankrepair');
        }
        // Anker tussen gedachtestreepjes leest als ingeplakt, niet als taal.
        $anchor = preg_quote(IL_Text::normalize_ws($c['anchor']), '/');
        if (preg_match('/[–—-]\s*' . $anchor . '\s*[–—-]/u', $after)) {
            return __('het anker staat tussen gedachtestreepjes ingeklemd', 'rankrepair');
        }
        // Tautologie: hetzelfde inhoudswoord twee keer in één zin.
        foreach (IL_Text::remove_stopwords(IL_Text::tokenize($c['anchor'])) as $token) {
            if (mb_strlen($token, 'UTF-8') < 4) {
                continue;
            }
            if (preg_match_all(IL_Text::boundary_pattern($token), $after) >= 2) {
                return sprintf(__('"%s" staat twee keer in dezelfde zin', 'rankrepair'), $token);
            }
        }
        if ($c['mode'] === 'clause' && strpos($after, $before) !== 0) {
            $stem = rtrim($before, '.!?:;');
            if (strpos($after, $stem) !== 0) {
                return __('een bijzin hoort achter de bestaande zin te komen, niet ervoor', 'rankrepair');
            }
        }
        return null;
    }

    /* ----------------------------------------------------------------- G17 */

    /**
     * Eén ankertekst, één bestemming.
     *
     * Staat "Google Ads" in het ene artikel naar pagina A en in het volgende naar
     * pagina B, dan weet een lezer niet waar hij op klikt en weet een zoekmachine
     * niet welke pagina het onderwerp draagt. Dit kwam boven bij het draaien op
     * echte content: zes van de acht voorstellen gebruikten hetzelfde anker, naar
     * twee verschillende pagina's.
     */
    private static function gate_anchor_ambiguity(array $c, array $ctx) {
        $ander = isset($ctx['anchor_claimed_by']) ? (int) $ctx['anchor_claimed_by'] : 0;
        if ($ander > 0) {
            return sprintf(__('deze ankertekst wijst elders al naar pagina #%d', 'rankrepair'), $ander);
        }
        return null;
    }

    /* ----------------------------------------------------------------- G16 */

    /**
     * De harde veiligheidscheck: er mag geen tekst verdwijnen.
     *
     * Bij wrap moet de platte tekst identiek zijn. Bij de zinsmodi moet de
     * platte tekst gelijk zijn aan het origineel met precies die ene zin
     * vervangen — niets anders.
     */
    private static function gate_integrity(array $c, array $ctx) {
        $before = IL_Text::plain_text($c['html']);
        $after  = IL_Text::plain_text($c['result_html']);

        if ($c['mode'] === 'wrap') {
            return $before === $after
                ? null
                : __('de tekst is veranderd terwijl alleen een link werd toegevoegd', 'rankrepair');
        }

        $expected = self::replace_once($before, IL_Text::normalize_ws($c['sentence_before']), IL_Text::normalize_ws($c['sentence_after']));
        if ($expected === null) {
            return __('de oorspronkelijke zin is niet terug te vinden in het segment', 'rankrepair');
        }
        return IL_Text::normalize_ws($expected) === IL_Text::normalize_ws($after)
            ? null
            : __('er is meer veranderd dan alleen de bedoelde zin', 'rankrepair');
    }

    /* -------------------------------------------------------------- helpers */

    /** De bestaande zin waar de link aan hangt. */
    private static function host_sentence(array $c) {
        if ($c['mode'] !== 'wrap') {
            return IL_Text::normalize_ws(isset($c['sentence_before']) ? $c['sentence_before'] : '');
        }
        $text   = IL_Text::plain_text($c['html']);
        $anchor = IL_Text::normalize_ws($c['anchor']);
        foreach (IL_Text::split_sentences($text) as $sentence) {
            if (IL_Text::contains_phrase($sentence, $anchor)) {
                return IL_Text::normalize_ws($sentence);
            }
        }
        return '';
    }

    /** Betekenisvolle woorden die zin en doelpagina delen. */
    private static function shared_terms($sentence, array $terms) {
        $tokens = array_flip(IL_Text::remove_stopwords(IL_Text::tokenize($sentence)));
        $shared = [];
        foreach ($terms as $term) {
            $term = mb_strtolower($term, 'UTF-8');
            if (isset($tokens[$term])) {
                $shared[] = $term;
            }
        }
        return array_unique($shared);
    }

    /** @return string|null */
    private static function replace_once($haystack, $needle, $replacement) {
        $pos = mb_strpos($haystack, $needle, 0, 'UTF-8');
        if ($pos === false) {
            return null;
        }
        return mb_substr($haystack, 0, $pos, 'UTF-8')
             . $replacement
             . mb_substr($haystack, $pos + mb_strlen($needle, 'UTF-8'), null, 'UTF-8');
    }
}
