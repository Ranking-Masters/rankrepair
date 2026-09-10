<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Graph_Scanner {

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
    }

    /** Bouwt graafrijen voor de opgegeven bron-posts. */
    public static function scan_batch(array $ids) {
        global $wpdb;
        $table = self::table();
        $home_host = (string) parse_url(home_url(), PHP_URL_HOST);
        $now = current_time('mysql');
        $processed = 0;

        foreach ($ids as $source_id) {
            $source_id = (int) $source_id;
            $post = get_post($source_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            // Verwijder eerdere rijen van deze bron (idempotente herscan).
            $wpdb->delete($table, ['source_id' => $source_id], ['%d']);

            $source_type = $post->post_type;
            $content = $post->post_content;
            $rendered = function_exists('do_blocks') ? do_blocks($content) : $content;

            // Elementor-content zit in postmeta; voeg toe voor href-extractie.
            $elementor = get_post_meta($source_id, '_elementor_data', true);
            if (!empty($elementor)) {
                $rendered .= ' ' . wp_json_encode($elementor);
            }

            $links = IL_Text::extract_internal_hrefs($rendered, $home_host);
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
                // Alleen binnen dezelfde type-silo relevant, maar sla alle interne links op;
                // de silo-filtering gebeurt bij het tellen/suggereren.
                $key = $target_id;
                if (isset($seen_targets[$key])) {
                    continue; // dedup per (source,target)
                }
                $seen_targets[$key] = true;

                $wpdb->insert($table, [
                    'source_id'   => $source_id,
                    'source_type' => $source_type,
                    'target_id'   => $target_id,
                    'target_type' => $target->post_type,
                    'anchor'      => $link['anchor'],
                    'scanned_at'  => $now,
                ], ['%d', '%s', '%d', '%s', '%s', '%s']);
            }
            $processed++;
        }
        return $processed;
    }

    public static function inbound_count($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT source_id) FROM $table WHERE target_id = %d",
            (int) $post_id
        ));
    }

    public static function outbound_count($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT target_id) FROM $table WHERE source_id = %d",
            (int) $post_id
        ));
    }

    /** Bron-post-ids die al naar het doel linken (voor uitsluiting bij matching). */
    public static function sources_linking_to($target_id) {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT source_id FROM $table WHERE target_id = %d",
            (int) $target_id
        ));
        return array_map('intval', $rows);
    }
}
