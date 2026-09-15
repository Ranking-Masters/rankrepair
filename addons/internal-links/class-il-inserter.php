<?php
/**
 * Bouwt de nieuwe segment-HTML voor één link — en haalt hem er ook weer uit.
 *
 * Puur stringwerk, geen database, geen WordPress-state. Dat maakt het testbaar
 * en het houdt de enige plek waar we andermans content wijzigen zo klein mogelijk.
 *
 * Elke geplaatste link krijgt een eigen id in data-rr-il. Daarmee kunnen we later
 * precies díe ene link terugdraaien, ook als de pagina intussen met de hand is
 * bewerkt en een snapshot dus niet meer klopt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Inserter {

    const ATTR = 'data-rr-il';

    /** Nieuw, kort id voor een geplaatste link. */
    public static function new_uid() {
        return substr(md5(uniqid('rril', true)), 0, 12);
    }

    /**
     * Bouwt de gewijzigde segment-HTML.
     *
     * @param array $cand  mode, anchor, url, uid, html, offset/length (wrap),
     *                     sentence_before/sentence_after (rewrite, clause)
     * @return array ['html' => string] of ['error' => string]
     */
    public static function build(array $cand) {
        $mode = isset($cand['mode']) ? $cand['mode'] : 'wrap';

        if ($mode === 'wrap') {
            return self::build_wrap($cand);
        }
        if ($mode === 'rewrite' || $mode === 'clause') {
            return self::build_sentence($cand);
        }
        return ['error' => 'onbekende modus: ' . $mode];
    }

    /* ----------------------------------------------------------- wrap-modus */

    /**
     * Zet <a> om een frase die al in de tekst staat. Nul tekstwijziging.
     * De planner heeft de byte-offset al gekozen en gecontroleerd; we bouwen hier
     * alleen, en controleren nog één keer dat de offset klopt.
     */
    private static function build_wrap(array $cand) {
        $html   = (string) $cand['html'];
        $offset = isset($cand['offset']) ? (int) $cand['offset'] : -1;
        $length = isset($cand['length']) ? (int) $cand['length'] : 0;

        if ($offset < 0 || $length <= 0 || $offset + $length > strlen($html)) {
            return ['error' => 'ankerpositie valt buiten het segment'];
        }

        $found = substr($html, $offset, $length);
        if (IL_Text::normalize_anchor($found) !== IL_Text::normalize_anchor($cand['anchor'])) {
            return ['error' => 'ankertekst staat niet meer op de verwachte positie'];
        }

        $new = substr($html, 0, $offset)
             . self::link_html($cand['url'], $cand['uid'], $found)
             . substr($html, $offset + $length);

        return ['html' => $new];
    }

    /* ------------------------------------------- herschrijf- en bijzin-modus */

    /**
     * Vervangt één bestaande zin door een nieuwe zin met het anker erin.
     *
     * We zoeken de oude zin in de RUWE HTML (entiteitsbewust) en eisen dat er
     * geen tags in staan. Een zin die door <strong> of <em> wordt onderbroken
     * slaan we over: dan zouden we opmaak weggooien, en dat is het niet waard.
     */
    private static function build_sentence(array $cand) {
        $html   = (string) $cand['html'];
        $before = IL_Text::normalize_ws(isset($cand['sentence_before']) ? $cand['sentence_before'] : '');
        $after  = IL_Text::normalize_ws(isset($cand['sentence_after']) ? $cand['sentence_after'] : '');
        $anchor = IL_Text::normalize_ws($cand['anchor']);

        if ($before === '' || $after === '') {
            return ['error' => 'zin voor of na ontbreekt'];
        }
        if (!IL_Text::contains_phrase($after, $anchor)) {
            return ['error' => 'nieuwe zin bevat de ankertekst niet'];
        }

        $body = IL_Text::html_pattern_for($before);
        if ($body === '' || !preg_match('/' . $body . '/u', $html, $m, PREG_OFFSET_CAPTURE)) {
            return ['error' => 'de te herschrijven zin staat niet (meer) in de tekst'];
        }

        $start  = $m[0][1];
        $length = strlen($m[0][0]);

        if (strpos($m[0][0], '<') !== false) {
            return ['error' => 'de zin bevat opmaak; herschrijven zou die weggooien'];
        }
        if (IL_Text::in_protected_range($start, $length, IL_Text::protected_ranges($html))) {
            return ['error' => 'de zin staat in een beschermd blok'];
        }

        // Nieuwe zin veilig opbouwen: tekst escapen, daarna het anker wrappen.
        $escaped = esc_html($after);
        $pattern = IL_Text::boundary_pattern($anchor, 'iu');
        $wrapped = preg_replace_callback($pattern, function ($mm) use ($cand) {
            return self::link_html($cand['url'], $cand['uid'], $mm[0]);
        }, $escaped, 1, $count);

        if ($count !== 1) {
            return ['error' => 'ankertekst kon niet in de nieuwe zin worden gemarkeerd'];
        }

        return ['html' => substr($html, 0, $start) . $wrapped . substr($html, $start + $length)];
    }

    /* --------------------------------------------------------- terugdraaien */

    /**
     * Haalt één geplaatste link weg.
     *
     * Eerst het <a>-element eruit (tekst blijft staan). Is de link met een
     * herschreven of aangevulde zin geplaatst, dan zetten we daarna ook de
     * oorspronkelijke zin terug.
     *
     * @return array ['html' => string] of ['error' => string]
     */
    public static function remove(array $args) {
        $html = (string) $args['html'];
        $uid  = (string) $args['uid'];

        $pattern = '/<a\b[^>]*\b' . preg_quote(self::ATTR, '/') . '\s*=\s*(["\'])' . preg_quote($uid, '/') . '\1[^>]*>(.*?)<\/a\s*>/is';
        $count   = 0;
        $html    = preg_replace($pattern, '$2', $html, 1, $count);

        if ($count !== 1) {
            return ['error' => 'de geplaatste link is niet meer gevonden'];
        }

        $before = IL_Text::normalize_ws(isset($args['sentence_before']) ? $args['sentence_before'] : '');
        $after  = IL_Text::normalize_ws(isset($args['sentence_after']) ? $args['sentence_after'] : '');

        if ($before !== '' && $after !== '' && $before !== $after) {
            $body = IL_Text::html_pattern_for($after);
            if ($body !== '') {
                $restored = preg_replace('/' . $body . '/u', self::escape_replacement(esc_html($before)), $html, 1, $n);
                if ($n === 1) {
                    $html = $restored;
                }
                // Lukt dat niet, dan is de zin daarna met de hand bewerkt. De link
                // is dan al weg; de zin laten we staan in plaats van te gokken.
            }
        }

        return ['html' => $html];
    }

    /* ----------------------------------------------------------- hulpmiddel */

    private static function link_html($url, $uid, $text) {
        return '<a href="' . esc_url($url) . '" ' . self::ATTR . '="' . esc_attr($uid) . '">' . $text . '</a>';
    }

    /** $-tekens in een preg_replace-vervanging mogen niet als backreference gelden. */
    private static function escape_replacement($text) {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $text);
    }
}
