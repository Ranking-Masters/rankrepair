<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Suggester {

    const CACHE_PREFIX = 'rr_il_suggestions_';
    const CACHE_TTL    = 43200; // 12 uur

    public static function for_target($target_id, $force = false) {
        $target_id = (int) $target_id;
        $cache_key = self::CACHE_PREFIX . $target_id;

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $target = get_post($target_id);
        if (!$target || $target->post_status !== 'publish') {
            return [];
        }

        $max = (int) apply_filters('rr_internal_links_max_suggestions', 3);
        $candidates = self::gather_candidates($target);

        $target_bundle = self::bundle($target);
        $scored = IL_Matcher::score_candidates($target_bundle, $candidates['tokens'], $max);

        $out = [];
        foreach ($scored as $hit) {
            $source = get_post($hit['id']);
            if (!$source) { continue; }
            $ai = self::ai_anchor($source, $target);
            $out[] = [
                'target_id'        => $target_id,
                'target_title'     => get_the_title($target_id),
                'source_id'        => $hit['id'],
                'source_title'     => get_the_title($hit['id']),
                'source_type'      => $source->post_type,
                'score'            => $hit['score'],
                'anchor_text'      => $ai['anchor_text'],
                'context_sentence' => $ai['context_sentence'],
                'placement_hint'   => $ai['placement_hint'],
                'editor'           => $ai['editor'],
            ];
        }

        set_transient($cache_key, $out, self::CACHE_TTL);
        return $out;
    }

    /** Kandidaat-bronnen: zelfde type, publish, niet zichzelf, linken nog niet naar target. */
    private static function gather_candidates(WP_Post $target) {
        $already = IL_Graph_Scanner::sources_linking_to($target->ID);
        $exclude = array_merge([$target->ID], $already);

        $q = new WP_Query([
            'post_type'      => $target->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => $exclude,
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $tokens = [];
        foreach ($q->posts as $p) {
            $b = self::bundle($p);
            $tokens[$p->ID] = ['tokens' => $b['tokens'], 'keyword' => $b['keyword']];
        }
        return ['tokens' => $tokens];
    }

    private static function bundle(WP_Post $p) {
        $raw   = $p->post_title . ' ' . wp_strip_all_tags(function_exists('do_blocks') ? do_blocks($p->post_content) : $p->post_content);
        $tok   = IL_Text::remove_stopwords(IL_Text::tokenize($raw));
        $kw    = (string) get_post_meta($p->ID, '_yoast_wpseo_focuskw', true);
        return ['tokens' => $tok, 'keyword' => $kw];
    }

    /** Vraag de AI om ankertekst + context-zin; val terug op heuristiek zonder key/bij fout. */
    private static function ai_anchor(WP_Post $source, WP_Post $target) {
        $has_elementor = (bool) get_post_meta($source->ID, '_elementor_data', true);
        $editor = IL_Text::detect_editor($source->post_content, $has_elementor);
        $placement = in_array($editor, ['gutenberg', 'classic'], true) ? 'inline' : 'block';

        $source_text = wp_strip_all_tags(function_exists('do_blocks') ? do_blocks($source->post_content) : $source->post_content);
        $source_text = mb_substr($source_text, 0, 1500, 'UTF-8');
        $target_title = get_the_title($target->ID);
        $target_kw = (string) get_post_meta($target->ID, '_yoast_wpseo_focuskw', true);

        $prompt = "Je helpt bij interne SEO-links op een Nederlandse website.\n"
            . "DOELPAGINA titel: \"{$target_title}\"\n"
            . ($target_kw !== '' ? "DOELPAGINA focus-keyword: \"{$target_kw}\"\n" : '')
            . "BRONTEKST (fragment):\n\"\"\"\n{$source_text}\n\"\"\"\n\n"
            . "Kies uit de BRONTEKST één bestaande zin waarin een link naar de DOELPAGINA natuurlijk past, "
            . "en stel een korte, natuurlijke ankertekst voor (2-5 woorden) die in die zin voorkomt of past.\n"
            . "Antwoord EXACT in dit formaat, zonder extra uitleg:\n"
            . "ANKER: <ankertekst>\n"
            . "ZIN: <de gekozen zin uit de brontekst>";

        $fallback = [
            'anchor_text'      => $target_kw !== '' ? $target_kw : $target_title,
            'context_sentence' => '',
            'placement_hint'   => $placement,
            'editor'           => $editor,
        ];

        if (!function_exists('rr_ai_complete')) {
            return $fallback;
        }
        $resp = rr_ai_complete($prompt);
        if (is_wp_error($resp)) {
            return $fallback;
        }

        $anchor = '';
        $sentence = '';
        if (preg_match('/ANKER:\s*(.+)/i', $resp, $m)) { $anchor = trim($m[1]); }
        if (preg_match('/ZIN:\s*(.+)/is', $resp, $m)) { $sentence = trim($m[1]); }

        return [
            'anchor_text'      => $anchor !== '' ? $anchor : $fallback['anchor_text'],
            'context_sentence' => $sentence,
            'placement_hint'   => $placement,
            'editor'           => $editor,
        ];
    }
}
