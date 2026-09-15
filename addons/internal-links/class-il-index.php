<?php
/**
 * De tekstindex: per post een compacte woordtelling.
 *
 * Zonder index moet je voor elke doelpagina de volledige inhoud van elke andere
 * pagina inlezen om relevantie te bepalen. Op een site met 850 berichten en 555
 * weespagina's zijn dat honderdduizenden content-loads — daar loopt elke
 * batchverwerking op vast.
 *
 * De index wordt gevuld tijdens de scan, die toch al elke post langsgaat, en
 * staat als postmeta bij de post zelf. Verdwijnt de post, dan verdwijnt de index.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Index {

    const META = '_rr_il_index';

    /** Hoeveel verschillende woorden we per post bewaren. */
    const MAX_TERMS = 60;

    private static $corpus = null;
    private static $idf    = null;

    /** Bouwt (of ververst) de index van één post. */
    public static function build_for($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $text = $post->post_title . ' ';
        foreach (IL_Content::segments($post) as $seg) {
            $text .= $seg['text'] . ' ';
        }

        $tokens = IL_Text::remove_stopwords(IL_Text::tokenize($text));
        $tf     = [];
        foreach ($tokens as $t) {
            $tf[$t] = isset($tf[$t]) ? $tf[$t] + 1 : 1;
        }
        arsort($tf);
        $tf = array_slice($tf, 0, self::MAX_TERMS, true);

        $entry = [
            't' => $tf,
            'w' => count($tokens),
            'k' => self::focus_keyword($post_id),
            'p' => $post->post_type,
        ];

        update_post_meta($post_id, self::META, wp_json_encode($entry));
        self::$corpus = null;
        self::$idf    = null;
        return true;
    }

    /**
     * De hele index in één query. Voor 850 posts is dat één SELECT van een paar
     * honderd kilobyte — goedkoper dan één post opnieuw parsen.
     */
    public static function corpus() {
        if (self::$corpus !== null) {
            return self::$corpus;
        }
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::META
        ), ARRAY_A);

        $out = [];
        foreach ($rows as $r) {
            $entry = json_decode($r['meta_value'], true);
            if (is_array($entry) && !empty($entry['t'])) {
                $out[(int) $r['post_id']] = $entry;
            }
        }
        self::$corpus = $out;
        return $out;
    }

    public static function get($post_id) {
        $corpus = self::corpus();
        return isset($corpus[(int) $post_id]) ? $corpus[(int) $post_id] : null;
    }

    /** Inverse documentfrequentie over de hele index. */
    public static function idf() {
        if (self::$idf !== null) {
            return self::$idf;
        }
        $corpus = self::corpus();
        $n      = max(1, count($corpus));

        $df = [];
        foreach ($corpus as $entry) {
            foreach (array_keys($entry['t']) as $term) {
                $df[$term] = isset($df[$term]) ? $df[$term] + 1 : 1;
            }
        }

        $idf = [];
        foreach ($df as $term => $count) {
            $idf[$term] = log(($n + 1) / ($count + 1)) + 1;
        }
        self::$idf = $idf;
        return $idf;
    }

    /** Focus-keyword uit Yoast of Rank Math. */
    public static function focus_keyword($post_id) {
        $kw = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if ($kw === '') {
            $kw = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
            // Rank Math bewaart meerdere keywords komma-gescheiden; de eerste telt.
            if (strpos($kw, ',') !== false) {
                $kw = trim(strtok($kw, ','));
            }
        }
        return trim($kw);
    }

    public static function flush() {
        self::$corpus = null;
        self::$idf    = null;
    }

    /** Verwijdert de hele index (bij deinstallatie of een volledige herscan). */
    public static function purge() {
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, ['meta_key' => self::META], ['%s']);
        self::flush();
    }
}
