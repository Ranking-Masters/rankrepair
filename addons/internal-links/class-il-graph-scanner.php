<?php
/**
 * Bouwt de interne-linkgraaf: wie linkt naar wie, met welke ankertekst.
 *
 * De scan gaat toch al langs elke post, dus hij vult meteen de tekstindex
 * (IL_Index). Daarmee hoeft de planner later niet elke pagina opnieuw te parsen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Graph_Scanner {

    private static $inbound_map  = null;
    private static $outbound_map = null;

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rr_internal_links';
    }

    public static function post_types() {
        $types = apply_filters('rr_internal_links_post_types', ['post', 'page']);
        $types = array_values(array_filter(array_map('sanitize_key', (array) $types)));
        return empty($types) ? ['post'] : $types;
    }

    public static function all_post_ids() {
        $q = new WP_Query([
            'post_type'      => self::post_types(),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        return array_map('intval', $q->posts);
    }

    public static function reset() {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::table());
        // Ook de index leeg: anders blijven pagina's die inmiddels verwijderd of
        // op concept gezet zijn als bron in de kandidatenlijst opduiken.
        IL_Index::purge();
        self::flush();
    }

    /**
     * Bouwt graafrijen en index voor de opgegeven bron-posts.
     * Idempotent: een post opnieuw scannen vervangt zijn eerdere rijen.
     */
    public static function scan_batch(array $ids) {
        global $wpdb;
        $table     = self::table();
        $home_host = (string) parse_url(home_url(), PHP_URL_HOST);
        $now       = current_time('mysql');
        $processed = 0;

        foreach ($ids as $source_id) {
            $source_id = (int) $source_id;
            $post = get_post($source_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $wpdb->delete($table, ['source_id' => $source_id], ['%d']);

            // Álles waar een link in kan zitten, niet alleen de lopende tekst:
            // knoppen, tabellen, afbeeldingen en blokken van andere plugins
            // tellen net zo goed mee voor de vraag of een pagina een orphan is.
            $links        = IL_Text::extract_internal_hrefs(IL_Content::link_html($post), $home_host);
            $seen_targets = [];

            foreach ($links as $link) {
                $target_id = (int) url_to_postid($link['href']);
                if ($target_id <= 0 || $target_id === $source_id) {
                    continue;
                }
                $target = get_post($target_id);
                if (!$target || $target->post_status !== 'publish') {
                    continue;
                }
                if (isset($seen_targets[$target_id])) {
                    continue; // dedup per (bron, doel)
                }
                $seen_targets[$target_id] = true;

                $wpdb->insert($table, [
                    'source_id'   => $source_id,
                    'source_type' => $post->post_type,
                    'target_id'   => $target_id,
                    'target_type' => $target->post_type,
                    'anchor'      => $link['anchor'],
                    'scanned_at'  => $now,
                ], ['%d', '%s', '%d', '%s', '%s', '%s']);
            }

            IL_Index::build_for($source_id);
            $processed++;
        }

        self::flush();
        return $processed;
    }

    /* --------------------------------------------------------------- tellen */

    /** target_id => aantal unieke bronnen, in één query. */
    public static function inbound_map() {
        if (self::$inbound_map !== null) {
            return self::$inbound_map;
        }
        global $wpdb;
        $rows = $wpdb->get_results('SELECT target_id, COUNT(DISTINCT source_id) AS n FROM ' . self::table() . ' GROUP BY target_id', ARRAY_A);
        $map  = [];
        foreach ($rows as $r) {
            $map[(int) $r['target_id']] = (int) $r['n'];
        }
        self::$inbound_map = $map;
        return $map;
    }

    /** source_id => aantal unieke doelen, in één query. */
    public static function outbound_map() {
        if (self::$outbound_map !== null) {
            return self::$outbound_map;
        }
        global $wpdb;
        $rows = $wpdb->get_results('SELECT source_id, COUNT(DISTINCT target_id) AS n FROM ' . self::table() . ' GROUP BY source_id', ARRAY_A);
        $map  = [];
        foreach ($rows as $r) {
            $map[(int) $r['source_id']] = (int) $r['n'];
        }
        self::$outbound_map = $map;
        return $map;
    }

    public static function inbound_count($post_id) {
        $map = self::inbound_map();
        return isset($map[(int) $post_id]) ? $map[(int) $post_id] : 0;
    }

    public static function outbound_count($post_id) {
        $map = self::outbound_map();
        return isset($map[(int) $post_id]) ? $map[(int) $post_id] : 0;
    }

    /** Bron-post-ids die al naar het doel linken. */
    public static function sources_linking_to($target_id) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT source_id FROM ' . self::table() . ' WHERE target_id = %d',
            (int) $target_id
        ));
        return array_map('intval', $rows);
    }

    /** Doel-post-ids waar deze bron al naartoe linkt. */
    public static function targets_of($source_id) {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT target_id FROM ' . self::table() . ' WHERE source_id = %d',
            (int) $source_id
        ));
        return array_map('intval', $rows);
    }

    /** Alle randen, voor het Data-scherm. */
    public static function edges($limit = 5000) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT source_id, target_id FROM ' . self::table() . ' LIMIT %d',
            (int) $limit
        ), ARRAY_A);
        return $rows ? $rows : [];
    }

    public static function flush() {
        self::$inbound_map  = null;
        self::$outbound_map = null;
        IL_Index::flush();
        IL_Profile::flush();
        IL_Content::flush_segments();
    }
}
