<?php
/**
 * Het wegschrijven — de enige plek waar deze add-on andermans content wijzigt.
 *
 * Twee dingen staan hier voorop:
 *
 * 1. Een suggestie gaat vlak vóór het schrijven nóg een keer door alle gates.
 *    Tussen genereren en toepassen kan de pagina bewerkt zijn; dan klopt de
 *    oude beoordeling niet meer en slaan we hem over in plaats van te gokken.
 * 2. Elke plaatsing is terug te draaien, ook los van elkaar en ook nadat de
 *    pagina daarna met de hand is bewerkt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Applier {

    /**
     * Past één goedgekeurde suggestie toe.
     *
     * @return array ['ok' => bool, 'message' => string, 'gate' => string]
     */
    public static function apply($suggestion_id) {
        $row = IL_Suggestions::get($suggestion_id);
        if (!$row) {
            return self::fail(null, __('Suggestie niet gevonden.', 'rankrepair'));
        }
        if ($row['status'] === IL_Suggestions::STATUS_APPLIED) {
            return ['ok' => true, 'message' => __('Was al geplaatst.', 'rankrepair'), 'gate' => ''];
        }

        $source = get_post((int) $row['source_id']);
        $target = get_post((int) $row['target_id']);
        if (!$source || !$target || $source->post_status !== 'publish' || $target->post_status !== 'publish') {
            return self::fail($row, __('Bron- of doelpagina bestaat niet meer of is niet gepubliceerd.', 'rankrepair'));
        }

        $seg = self::find_segment($source, $row['segment_ref']);
        if (!$seg) {
            return self::fail($row, __('De alinea is niet meer terug te vinden; genereer de suggestie opnieuw.', 'rankrepair'));
        }

        $cand = self::rebuild_candidate($row, $seg, $source, $target);
        if (isset($cand['error'])) {
            return self::fail($row, $cand['error']);
        }

        $ctx     = self::context($row, $source, $target, $seg);
        $verdict = IL_Planner::evaluate($cand, $ctx);
        if (!$verdict['ok']) {
            return self::fail($row, $verdict['reason'], $verdict['gate']);
        }

        $built = IL_Inserter::build($cand);
        if (isset($built['error'])) {
            return self::fail($row, $built['error']);
        }

        $snapshot = IL_Content::snapshot($source);
        $written  = IL_Content::apply($source, [$seg['ref'] => $built['html']]);
        if (is_wp_error($written)) {
            return self::fail($row, $written->get_error_message());
        }

        IL_Suggestions::update($row['id'], [
            'status'         => IL_Suggestions::STATUS_APPLIED,
            'reason'         => '',
            'link_uid'       => $cand['uid'],
            'anchor'         => $cand['anchor'],
            'content_before' => wp_json_encode($snapshot),
            'content_hash'   => md5(wp_json_encode($snapshot)),
            'applied_at'     => current_time('mysql'),
        ]);

        self::record_edge((int) $row['source_id'], (int) $row['target_id'], $cand['anchor']);
        self::refresh($source, $target);

        return ['ok' => true, 'message' => __('Link geplaatst.', 'rankrepair'), 'gate' => ''];
    }

    /**
     * Draait één plaatsing terug.
     *
     * We knippen het <a>-element met het bijbehorende id weg in plaats van het
     * hele snapshot terug te zetten: dat werkt ook als iemand de pagina daarna
     * nog heeft bewerkt, en gooit dan niet diens werk overboord.
     */
    public static function undo($suggestion_id) {
        $row = IL_Suggestions::get($suggestion_id);
        if (!$row) {
            return self::fail(null, __('Suggestie niet gevonden.', 'rankrepair'));
        }
        if ($row['status'] !== IL_Suggestions::STATUS_APPLIED) {
            return self::fail($row, __('Deze suggestie staat niet als geplaatst geregistreerd.', 'rankrepair'));
        }

        $source = get_post((int) $row['source_id']);
        if (!$source) {
            return self::fail($row, __('Bronpagina bestaat niet meer.', 'rankrepair'));
        }

        $uid   = (string) $row['link_uid'];
        $seg   = self::find_segment_with_uid($source, $uid);
        if (!$seg) {
            // De link is al weg — administratie bijwerken en klaar.
            IL_Suggestions::update($row['id'], ['status' => IL_Suggestions::STATUS_UNDONE, 'reason' => '']);
            self::remove_edge((int) $row['source_id'], (int) $row['target_id']);
            self::refresh($source, null);
            return ['ok' => true, 'message' => __('De link stond er al niet meer.', 'rankrepair'), 'gate' => ''];
        }

        $result = IL_Inserter::remove([
            'html'            => $seg['html'],
            'uid'             => $uid,
            'sentence_before' => (string) $row['sentence_before'],
            'sentence_after'  => (string) $row['sentence_after'],
        ]);
        if (isset($result['error'])) {
            return self::fail($row, $result['error']);
        }

        $written = IL_Content::apply($source, [$seg['ref'] => $result['html']]);
        if (is_wp_error($written)) {
            return self::fail($row, $written->get_error_message());
        }

        IL_Suggestions::update($row['id'], ['status' => IL_Suggestions::STATUS_UNDONE, 'reason' => '']);
        self::remove_edge((int) $row['source_id'], (int) $row['target_id']);
        self::refresh($source, null);

        return ['ok' => true, 'message' => __('Link teruggedraaid.', 'rankrepair'), 'gate' => ''];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Bouwt de kandidaat opnieuw op uit wat er nú in de pagina staat. Voor de
     * wrap-modus zoeken we de ankertekst opnieuw op — een opgeslagen byte-positie
     * uit het moment van genereren zou na een bewerking naar het verkeerde woord wijzen.
     */
    private static function rebuild_candidate(array $row, array $seg, WP_Post $source, WP_Post $target) {
        $cand = [
            'source_id'       => (int) $row['source_id'],
            'target_id'       => (int) $row['target_id'],
            'mode'            => (string) $row['mode'],
            'anchor'          => (string) $row['anchor'],
            'url'             => get_permalink($target->ID),
            'uid'             => $row['link_uid'] !== '' ? (string) $row['link_uid'] : IL_Inserter::new_uid(),
            'html'            => $seg['html'],
            'segment_ref'     => $seg['ref'],
            'segment_kind'    => $seg['kind'],
            'sentence_before' => (string) $row['sentence_before'],
            'sentence_after'  => (string) $row['sentence_after'],
            'score'           => (float) $row['score'],
        ];

        if ($cand['mode'] === 'wrap') {
            $ranges = IL_Text::protected_ranges($seg['html']);
            foreach (IL_Text::phrase_offsets($seg['html'], $cand['anchor']) as $hit) {
                if (IL_Text::in_protected_range($hit['offset'], strlen($hit['match']), $ranges)) {
                    continue;
                }
                $cand['offset'] = $hit['offset'];
                $cand['length'] = strlen($hit['match']);
                $cand['anchor'] = $hit['match'];
                return $cand;
            }
            return ['error' => __('De ankertekst staat niet meer in deze alinea.', 'rankrepair')];
        }

        return $cand;
    }

    private static function context(array $row, WP_Post $source, WP_Post $target, array $seg) {
        $load = IL_Suggestions::source_load((int) $row['source_id']);

        // De suggestie zelf zit in die telling; anders blokkeert hij zichzelf.
        $per_segment = $load['per_segment'];
        $ref = (string) $seg['ref'];
        if (!empty($per_segment[$ref])) {
            $per_segment[$ref]--;
            if ($per_segment[$ref] <= 0) {
                unset($per_segment[$ref]);
            }
        }
        $prefixes = $load['prefixes'];
        $own      = IL_Text::anchor_prefix_class($row['anchor']);
        $pos      = array_search($own, $prefixes, true);
        if ($pos !== false) {
            unset($prefixes[$pos]);
        }

        $segments  = IL_Content::segments($source);
        $intro_ref = null;
        foreach ($segments as $s) {
            if ($s['kind'] !== 'heading') {
                $intro_ref = $s['ref'];
                break;
            }
        }

        return [
            'existing_targets'     => IL_Graph_Scanner::targets_of((int) $row['source_id']),
            'added_in_source'      => max(0, $load['count'] - 1),
            'added_per_segment'    => $per_segment,
            'added_prefix_classes' => array_values($prefixes),
            'density_headroom'     => IL_Profile::density_headroom((int) $row['source_id']),
            'max_links_per_source' => (int) IL_Config::get('max_links_per_source'),
            'max_same_anchor'      => (int) IL_Config::get('max_same_anchor'),
            'target_terms'         => IL_Text::remove_stopwords(IL_Text::tokenize(
                get_the_title($target->ID) . ' ' . IL_Index::focus_keyword($target->ID)
            )),
            'target_keyword'       => IL_Index::focus_keyword($target->ID),
            'intro_ref'            => $intro_ref,
        ];
    }

    private static function find_segment(WP_Post $post, $ref) {
        foreach (IL_Content::segments($post) as $seg) {
            if ((string) $seg['ref'] === (string) $ref) {
                return $seg;
            }
        }
        return null;
    }

    private static function find_segment_with_uid(WP_Post $post, $uid) {
        if ($uid === '') {
            return null;
        }
        foreach (IL_Content::segments($post) as $seg) {
            if (strpos($seg['html'], $uid) !== false) {
                return $seg;
            }
        }
        return null;
    }

    /** Houdt de graaf-tabel actueel zonder een volledige herscan. */
    private static function record_edge($source_id, $target_id, $anchor) {
        global $wpdb;
        $table  = $wpdb->prefix . 'rr_internal_links';
        $source = get_post($source_id);
        $target = get_post($target_id);
        if (!$source || !$target) {
            return;
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE source_id = %d AND target_id = %d",
            $source_id, $target_id
        ));
        if ($exists > 0) {
            return;
        }
        $wpdb->insert($table, [
            'source_id'   => $source_id,
            'source_type' => $source->post_type,
            'target_id'   => $target_id,
            'target_type' => $target->post_type,
            'anchor'      => $anchor,
            'scanned_at'  => current_time('mysql'),
        ], ['%d', '%s', '%d', '%s', '%s', '%s']);
    }

    private static function remove_edge($source_id, $target_id) {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'rr_internal_links',
            ['source_id' => (int) $source_id, 'target_id' => (int) $target_id],
            ['%d', '%d']
        );
    }

    private static function refresh(WP_Post $source, $target) {
        IL_Graph_Scanner::flush();
        IL_Index::build_for($source->ID);
        if ($target instanceof WP_Post) {
            IL_Index::build_for($target->ID);
        }
    }

    private static function fail($row, $message, $gate = '') {
        if ($row) {
            IL_Suggestions::update($row['id'], [
                'status' => IL_Suggestions::STATUS_FAILED,
                'reason' => mb_substr(($gate ? $gate . ': ' : '') . $message, 0, 250, 'UTF-8'),
            ]);
        }
        return ['ok' => false, 'message' => $message, 'gate' => $gate];
    }
}
