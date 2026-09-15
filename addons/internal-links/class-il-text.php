<?php
/**
 * Tekstgereedschap voor de Interne Links add-on.
 *
 * Alles hier is puur: tekst in, tekst uit. Geen WordPress-afhankelijkheden buiten
 * een paar optionele helpers, zodat de gates en de inserter los te testen zijn.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Text {

    /** NL-stopwoorden (compacte, praktische set). */
    private static $stopwords = [
        'de','het','een','en','van','te','dat','die','in','op','voor','met','als','zijn','er','maar',
        'om','door','over','ze','uit','aan','bij','nog','kan','naar','wordt','wat','worden','deze',
        'dit','is','was','ook','tot','je','jij','wij','we','ik','hij','zij','u','uw','ons','onze',
        'niet','geen','wel','meer','veel','heel','zeer','dan','of','omdat','want','dus','al','hier',
    ];

    /**
     * Afkortingen die op een punt eindigen zonder dat de zin eindigt.
     * Zonder deze lijst knipt split_sentences() "bijv. een link" in tweeën.
     */
    private static $abbreviations = [
        'bijv','bijvb','o.a','oa','d.w.z','dwz','etc','enz','nr','ca','incl','excl','t.o.v','tov',
        'm.b.t','mbt','i.v.m','ivm','a.u.b','aub','z.o.z','dhr','mevr','drs','ir','ing','prof','dr',
        'nl','vs','max','min','ong','blz','pag','fig','art','lid','red','o.b.v','obv','t.b.v','tbv',
    ];

    /* ---------------------------------------------------------------- tokens */

    public static function tokenize($text) {
        $text  = mb_strtolower((string) $text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out   = [];
        foreach ($parts as $p) {
            if (mb_strlen($p, 'UTF-8') >= 3) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function remove_stopwords(array $tokens) {
        $stop = array_flip(self::$stopwords);
        return array_values(array_filter($tokens, function ($t) use ($stop) {
            return !isset($stop[$t]);
        }));
    }

    public static function is_stopword($word) {
        return in_array(mb_strtolower((string) $word, 'UTF-8'), self::$stopwords, true);
    }

    public static function word_count($text) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/u', $text));
    }

    public static function normalize_ws($text) {
        // NBSP en andere Unicode-spaties tellen als gewone spatie, anders matcht
        // een zin uit de editor nooit op een zin uit de LLM-respons.
        $text = preg_replace('/[\x{00A0}\x{2007}\x{202F}\x{2009}]/u', ' ', (string) $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /* ------------------------------------------------------------------ html */

    public static function plain_text($html) {
        $html = (string) $html;
        // Blok-einden worden spaties, anders plakken "…einde.Volgende…" aan elkaar.
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/td|/tr)\s*/?>#i', ' ', $html);
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::normalize_ws($html);
    }

    /* -------------------------------------------------------------- matching */

    /**
     * Unicode-bewuste woordgrens-regex voor een letterlijke frase.
     *
     * PHP's \b kent geen accenten, dus "Enter" zou binnen "Deventer" matchen en
     * "café" zou op de é afbreken. Vandaar lookarounds op letters en cijfers.
     */
    public static function boundary_pattern($phrase, $flags = 'iu') {
        $quoted = preg_quote(self::normalize_ws($phrase), '/');
        // Elke spatie in de frase mag op meerdere witruimtetekens matchen.
        $quoted = preg_replace('/\\\\?\s+/', '\s+', $quoted);
        return '/(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])/' . $flags;
    }

    /** Staat de frase als zelfstandig woord in de tekst? */
    public static function contains_phrase($haystack, $phrase) {
        if (trim((string) $phrase) === '' || trim((string) $haystack) === '') {
            return false;
        }
        return (bool) preg_match(self::boundary_pattern($phrase), (string) $haystack);
    }

    /** Alle byte-offsets waar de frase als zelfstandig woord staat. */
    public static function phrase_offsets($haystack, $phrase) {
        if (trim((string) $phrase) === '' || trim((string) $haystack) === '') {
            return [];
        }
        if (!preg_match_all(self::boundary_pattern($phrase), (string) $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $hit) {
            $out[] = ['offset' => $hit[1], 'match' => $hit[0]];
        }
        return $out;
    }

    /**
     * Byte-bereiken in de HTML waar geen link in mag landen: bestaande links,
     * code- en scriptblokken, de tags zelf, en shortcodes.
     *
     * @return array lijst van [start, eind)
     */
    public static function protected_ranges($html) {
        $html   = (string) $html;
        $ranges = [];

        $blocks = '/<(a|code|pre|script|style|kbd|samp|textarea)\b[^>]*>.*?<\/\1\s*>/is';
        if (preg_match_all($blocks, $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $ranges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }

        // Losse tags (attributen bevatten vaak dezelfde woorden als de tekst).
        if (preg_match_all('/<[^>]+>/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $ranges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }

        // Shortcodes: [gallery ids="1,2"] en [/caption].
        if (preg_match_all('/\[\/?[a-z0-9_\-]+[^\]]*\]/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $ranges[] = [$hit[1], $hit[1] + strlen($hit[0])];
            }
        }

        // Entiteiten staan bewust NIET in deze lijst. Ze kunnen niet half
        // geraakt worden — phrase_offsets zoekt letterlijke tekst, en die
        // matcht nooit midden in een &amp;. Ze wél opnemen zou elke zin met
        // een entiteit erin onaanraakbaar maken.

        return $ranges;
    }

    /**
     * Regex-body die een stuk PLATTE tekst terugvindt in RUWE HTML.
     *
     * Nodig omdat plain_text() entiteiten decodeert: een zin die wij als
     * "fris & fruitig" kennen staat in de bron als "fris &amp; fruitig", en een
     * gewone preg_quote vindt die dus nooit. Witruimte mag vrij variëren
     * (inclusief &nbsp; en regeleindes).
     */
    public static function html_pattern_for($text) {
        $text = self::normalize_ws($text);
        if ($text === '') {
            return '';
        }

        $variants = [
            '&' => '(?:&amp;|&#0*38;|&)',
            '"' => '(?:&quot;|&#0*34;|[""])',
            "'" => '(?:&#0*39;|&apos;|[\x{2018}\x{2019}\'])',
            '<' => '(?:&lt;|&#0*60;)',
            '>' => '(?:&gt;|&#0*62;)',
            '-' => '(?:&#0*45;|-)',
        ];

        $out   = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($chars as $ch) {
            if ($ch === ' ') {
                $out .= '(?:\s|&nbsp;|&#0*160;)+';
            } elseif (isset($variants[$ch])) {
                $out .= $variants[$ch];
            } else {
                $out .= preg_quote($ch, '/');
            }
        }
        return $out;
    }

    /** Valt [start, start+len) binnen een van de beschermde bereiken? */
    public static function in_protected_range($start, $len, array $ranges) {
        $end = $start + $len;
        foreach ($ranges as $r) {
            if ($start < $r[1] && $end > $r[0]) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------- sentences */

    /**
     * Splitst platte tekst in zinnen. NL-bewust: afkortingen breken de zin niet af.
     * Retourneert zinnen inclusief hun eindleesteken, zonder omringende witruimte.
     */
    public static function split_sentences($text) {
        $text = self::normalize_ws($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return [$text];
        }

        $abbr = array_flip(self::$abbreviations);
        $out  = [];
        $buf  = '';

        foreach ($parts as $part) {
            $buf = ($buf === '') ? $part : $buf . ' ' . $part;

            // Eindigt dit stuk op een afkorting? Dan hoort het volgende stuk erbij.
            $tail = '';
            if (preg_match('/([\p{L}\.]+)\.$/u', $buf, $m)) {
                $tail = mb_strtolower(rtrim($m[1], '.'), 'UTF-8');
            }
            if ($tail !== '' && isset($abbr[$tail])) {
                continue;
            }
            // Een losse initiaal ("J. de Vries") is ook geen zinseinde.
            if (preg_match('/(?<![\p{L}])\p{Lu}\.$/u', $buf)) {
                continue;
            }

            $out[] = $buf;
            $buf   = '';
        }
        if ($buf !== '') {
            $out[] = $buf;
        }
        return $out;
    }

    /** Eindleesteken van een zin, of '' als die er niet is. */
    public static function terminal_punctuation($sentence) {
        $sentence = rtrim((string) $sentence);
        if ($sentence === '') {
            return '';
        }
        $last = mb_substr($sentence, -1, 1, 'UTF-8');
        return in_array($last, ['.', '!', '?', ':', ';'], true) ? $last : '';
    }

    /* ---------------------------------------------------------------- ngrams */

    /**
     * Aaneengesloten woordgroepen van $min..$max woorden, zonder leidende of
     * afsluitende stopwoorden. Dit levert de kandidaat-ankerteksten waar we in de
     * brontekst naar zoeken: "10 gemaakte SEO fouten en oplossingen" geeft o.a.
     * "gemaakte SEO fouten" en "SEO fouten".
     */
    public static function ngrams($text, $min = 2, $max = 6) {
        $text  = self::normalize_ws(preg_replace('/[|•·–—]+/u', ' ', (string) $text));
        $text  = preg_replace('/[\[\]\(\)"“”„\'’]+/u', '', $text);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $n     = count($words);
        if ($n === 0) {
            return [];
        }

        $out = [];
        for ($len = $max; $len >= $min; $len--) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $slice = array_slice($words, $i, $len);

                $first = rtrim(mb_strtolower($slice[0], 'UTF-8'), ',.:;!?');
                $last  = rtrim(mb_strtolower($slice[$len - 1], 'UTF-8'), ',.:;!?');
                if (self::is_stopword($first) || self::is_stopword($last)) {
                    continue;
                }
                // Een groep die alleen uit cijfers bestaat is geen ankertekst.
                $phrase = trim(implode(' ', $slice), " \t\n\r\0\x0B,.:;!?");
                if ($phrase === '' || !preg_match('/\p{L}/u', $phrase)) {
                    continue;
                }
                $out[mb_strtolower($phrase, 'UTF-8')] = $phrase;
            }
        }
        return array_values($out);
    }

    /* --------------------------------------------------------------- anchors */

    /**
     * Prefixklasse van een ankertekst. Twee ankers in dezelfde post met dezelfde
     * klasse lezen als een sjabloon; gate G12 dwingt variatie af.
     */
    public static function anchor_prefix_class($anchor) {
        $a = mb_strtolower(self::normalize_ws($anchor), 'UTF-8');
        if ($a === '') {
            return 'other';
        }
        $map = [
            'comparative' => '/^(meer|andere|overige|verdere|nog meer|extra)\b/u',
            'possessive'  => '/^(onze|ons|mijn|hun)\b/u',
            'demonstr'    => '/^(dit|deze|dat|die)\b/u',
            'indefinite'  => '/^(een|eens)\b/u',
            'definite'    => '/^(de|het)\b/u',
            'guide_noun'  => '/^(tips|gids|handleiding|stappenplan|checklist|uitleg|alles over|inspiratie)\b/u',
            'prepos'      => '/^(over|voor|bij|met|in|op|naar)\b/u',
        ];
        foreach ($map as $class => $re) {
            if (preg_match($re, $a)) {
                return $class;
            }
        }
        return 'bare_noun';
    }

    /** Normaliseer een anker voor vergelijking (over-optimalisatie tellen). */
    public static function normalize_anchor($anchor) {
        $a = mb_strtolower(self::normalize_ws($anchor), 'UTF-8');
        return trim($a, " \t\n\r\0\x0B.,:;!?-–—");
    }

    /* ----------------------------------------------------------------- links */

    public static function extract_internal_hrefs($html, $home_host) {
        $html = (string) $html;
        if (trim($html) === '') {
            return [];
        }
        $home_host = strtolower(preg_replace('/^www\./i', '', (string) $home_host));

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if (in_array($scheme, ['mailto', 'tel', 'javascript'], true)) {
                continue;
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            $host = preg_replace('/^www\./i', '', $host);

            $is_internal = ($host === '' || $host === $home_host);
            if (!$is_internal) {
                continue;
            }
            $out[] = [
                'href'   => $href,
                'anchor' => trim($a->textContent),
                'uid'    => $a->getAttribute('data-rr-il'),
            ];
        }
        return $out;
    }

    public static function detect_editor($post_content, $has_elementor) {
        if ($has_elementor) {
            return 'elementor';
        }
        if (strpos((string) $post_content, '<!-- wp:') !== false) {
            return 'gutenberg';
        }
        return 'classic';
    }
}
