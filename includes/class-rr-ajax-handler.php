<?php
/**
 * AJAX Handler Class
 * Handles all AJAX requests for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Ajax_Handler {

    public function __construct() {
        // PageSpeed
        add_action('wp_ajax_rr_run_pagespeed', [$this, 'run_pagespeed']);

        // Meta Manager
        add_action('wp_ajax_rr_fetch_h1s', [$this, 'fetch_h1s']);
        add_action('wp_ajax_rr_import_meta_csv', [$this, 'import_meta_csv']);
        add_action('wp_ajax_rr_scan_wordpress_meta', [$this, 'scan_wordpress_meta']);
        add_action('wp_ajax_rr_save_meta', [$this, 'save_meta']);
        add_action('wp_ajax_rr_apply_meta', [$this, 'apply_meta']);
        add_action('wp_ajax_rr_delete_meta', [$this, 'delete_meta']);
        add_action('wp_ajax_rr_clear_all_meta', [$this, 'clear_all_meta']);

        // SE Ranking API
        add_action('wp_ajax_rr_seranking_get_sites', [$this, 'seranking_get_sites']);
        add_action('wp_ajax_rr_seranking_import_pages', [$this, 'seranking_import_pages']);

        // Gemini AI
        add_action('wp_ajax_rr_gemini_generate',      [$this, 'gemini_generate']);
        add_action('wp_ajax_rr_gemini_generate_bulk', [$this, 'gemini_generate_bulk']);
        add_action('wp_ajax_rr_save_bulk_meta',        [$this, 'save_bulk_meta']);

        // CSV upload & mapping
        add_action('wp_ajax_rr_parse_upload_csv',  [$this, 'parse_upload_csv']);
        add_action('wp_ajax_rr_apply_upload_csv',  [$this, 'apply_upload_csv']);

        // Addon aan/uit
        add_action('wp_ajax_rr_toggle_addon', [$this, 'toggle_addon']);

        // Live HTML scan (haalt gerenderde <title> + <meta description> op)
        add_action('wp_ajax_rr_html_scan_chunk', [$this, 'html_scan_chunk']);
        add_action('wp_ajax_rr_html_scan_clear', [$this, 'html_scan_clear']);
    }

    /**
     * Live HTML scan — haalt gerenderde HTML op voor een chunk URLs en parsed
     * <title> + <meta name="description">. Resultaten worden opgeslagen in
     * de option 'rr_html_scan_results' zodat compute_stats ze kan gebruiken.
     */
    public function html_scan_chunk() {
        $this->verify_nonce();

        $ids = isset($_POST['ids']) ? json_decode(wp_unslash($_POST['ids']), true) : [];
        if (!is_array($ids) || empty($ids)) {
            wp_send_json_error(['message' => __('Geen IDs ontvangen.', 'rankrepair')]);
        }

        $results = get_option('rr_html_scan_results', []);
        if (!is_array($results)) $results = [];

        $processed = [];
        foreach ($ids as $id) {
            $id = sanitize_text_field($id);
            if (empty($id) || strpos($id, ':') === false) continue;

            list($type, $entity_id) = explode(':', $id, 2);
            $entity_id = (int) $entity_id;
            $url = '';

            if ($type === 'post' && $entity_id) {
                $url = get_permalink($entity_id);
            } elseif ($type === 'term' && $entity_id) {
                $term_link = get_term_link($entity_id);
                if (!is_wp_error($term_link)) $url = $term_link;
            }

            if (empty($url)) {
                $processed[] = ['id' => $id, 'error' => 'URL niet gevonden'];
                continue;
            }

            $resp = wp_remote_get($url, [
                'timeout'     => 15,
                'redirection' => 5,
                'sslverify'   => false,
                'user-agent'  => 'RankRepair/1.0 (+SEO scan)',
                'headers'     => ['Accept' => 'text/html,application/xhtml+xml'],
            ]);

            if (is_wp_error($resp)) {
                $processed[] = [
                    'id'    => $id,
                    'error' => $resp->get_error_message(),
                ];
                continue;
            }

            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);

            if ($code !== 200 || empty($body)) {
                $processed[] = [
                    'id'    => $id,
                    'error' => 'HTTP ' . $code,
                ];
                continue;
            }

            $title = '';
            $desc  = '';

            // <title> tag — pak alleen die in <head> (eerste match)
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
                $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            // <meta name="description"> — accepteer ook property="og:description" als fallback
            if (preg_match('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']#is', $body, $m)) {
                $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } elseif (preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']#is', $body, $m)) {
                // content komt voor name in attribuut-volgorde
                $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            $results[$id] = [
                'html_title' => $title,
                'html_desc'  => $desc,
                'scanned_at' => time(),
                'url'        => $url,
            ];

            $processed[] = [
                'id'         => $id,
                'html_title' => $title,
                'html_desc'  => $desc,
                'title_len'  => mb_strlen($title),
                'desc_len'   => mb_strlen($desc),
            ];
        }

        update_option('rr_html_scan_results', $results, false);

        wp_send_json_success([
            'processed' => $processed,
            'count'     => count($processed),
        ]);
    }

    public function html_scan_clear() {
        $this->verify_nonce();
        delete_option('rr_html_scan_results');
        wp_send_json_success(['message' => __('Live HTML scan resultaten gewist.', 'rankrepair')]);
    }

    public function toggle_addon() {
        $this->verify_nonce();

        $slug    = isset($_POST['slug'])    ? sanitize_key($_POST['slug'])             : '';
        $enabled = isset($_POST['enabled']) ? (sanitize_text_field($_POST['enabled']) === '1') : false;
        $available = ['meta-manager', 'structured-data', 'redirects-checker', 'image-optimizer', 'form-tester'];
        if (!in_array($slug, $available, true)) {
            wp_send_json_error(['message' => __('Onbekende addon.', 'rankrepair')]);
        }

        update_option('rr_addon_' . $slug . '_enabled', $enabled ? '1' : '0');

        wp_send_json_success([
            'enabled' => $enabled,
            'message' => $enabled ? __('Addon ingeschakeld.', 'rankrepair') : __('Addon uitgeschakeld.', 'rankrepair'),
        ]);
    }

    private function verify_nonce() {
        if (!check_ajax_referer('rr_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Beveiligingscontrole mislukt.', 'rankrepair')]);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
    }

    /**
     * Parse een composite entity-ID zoals "post:123" of "term:45".
     * Fallback: als er geen prefix is, behandelen we het als post-ID (backwards compat).
     * Returns ['type' => 'post'|'term', 'id' => int] of null bij invalid input.
     */
    private function parse_entity_id($raw): ?array {
        if (is_numeric($raw)) {
            return ['type' => 'post', 'id' => (int) $raw];
        }
        if (!is_string($raw) || !str_contains($raw, ':')) return null;
        [$type, $id] = explode(':', $raw, 2);
        $type = sanitize_key($type);
        $id   = (int) $id;
        if (!in_array($type, ['post', 'term'], true) || $id <= 0) return null;
        return ['type' => $type, 'id' => $id];
    }

    /**
     * Schrijf Yoast/Rank Math title/description naar de juiste entity.
     * Werkt voor posts én termen.
     */
    private function write_entity_meta(string $type, int $id, ?string $title, ?string $desc): void {
        // Invalideer Live HTML scan cache zodat het dashboard direct de nieuwe
        // waardes ziet (anders blijft de gescande oude titel/desc geladen).
        $entity_id_str = $type . ':' . $id;
        $html_scan = get_option('rr_html_scan_results', []);
        if (is_array($html_scan) && isset($html_scan[$entity_id_str])) {
            unset($html_scan[$entity_id_str]);
            update_option('rr_html_scan_results', $html_scan, false);
        }

        if ($type === 'post') {
            if ($title !== null) {
                if (defined('WPSEO_VERSION'))     update_post_meta($id, '_yoast_wpseo_title',   $title);
                if (defined('RANK_MATH_VERSION')) update_post_meta($id, 'rank_math_title',      $title);
            }
            if ($desc !== null) {
                if (defined('WPSEO_VERSION'))     update_post_meta($id, '_yoast_wpseo_metadesc', $desc);
                if (defined('RANK_MATH_VERSION')) update_post_meta($id, 'rank_math_description', $desc);
            }
        } elseif ($type === 'term') {
            if ($title !== null) {
                if (defined('WPSEO_VERSION'))     update_term_meta($id, '_yoast_wpseo_title',   $title);
                if (defined('RANK_MATH_VERSION')) update_term_meta($id, 'rank_math_title',      $title);
            }
            if ($desc !== null) {
                if (defined('WPSEO_VERSION'))     update_term_meta($id, '_yoast_wpseo_metadesc', $desc);
                if (defined('RANK_MATH_VERSION')) update_term_meta($id, 'rank_math_description', $desc);
            }

            // Yoast leest voor sommige taxonomieën (bv. 'category') primair uit
            // de legacy 'wpseo_taxonomy_meta' optie. Schrijf óók daar zodat de
            // wijzigingen consistent zichtbaar zijn op het frontend.
            if (defined('WPSEO_VERSION')) {
                $term = get_term($id);
                if ($term && !is_wp_error($term)) {
                    $tax_meta = get_option('wpseo_taxonomy_meta', []);
                    if (!is_array($tax_meta)) $tax_meta = [];
                    if (!isset($tax_meta[$term->taxonomy])) $tax_meta[$term->taxonomy] = [];
                    if (!isset($tax_meta[$term->taxonomy][$id])) $tax_meta[$term->taxonomy][$id] = [];
                    if ($title !== null) $tax_meta[$term->taxonomy][$id]['wpseo_title'] = $title;
                    if ($desc  !== null) $tax_meta[$term->taxonomy][$id]['wpseo_desc']  = $desc;
                    update_option('wpseo_taxonomy_meta', $tax_meta);

                    // Yoast Indexables (14+) cachen meta. Trigger 'edit_term' zodat
                    // Yoast de bijbehorende indexable rebuild — anders blijft de
                    // oude title/desc op het frontend staan.
                    do_action('edit_term', $id, $term->term_taxonomy_id, $term->taxonomy);
                    do_action('edited_term', $id, $term->term_taxonomy_id, $term->taxonomy);
                    clean_term_cache($id, $term->taxonomy);
                }
            }
        }
    }

    /**
     * Haal H1s op via een echte HTTP-request naar de frontend.
     * Als de loopback request mislukt, valt terug op server-side extractie
     * (post_content blocks + Elementor widgets).
     */
    public function fetch_h1s() {
        $this->verify_nonce();

        $raw = $_POST['post_id'] ?? '';
        $parsed = $this->parse_entity_id($raw);
        if (!$parsed) {
            wp_send_json_error(['message' => 'Ongeldig ID']);
        }

        $url = ($parsed['type'] === 'post')
            ? get_permalink($parsed['id'])
            : get_term_link($parsed['id']);
        if (is_wp_error($url)) $url = '';
        $h1s = [];

        // Probeer frontend HTML op te halen
        if ($url) {
            $response = wp_remote_get($url, [
                'timeout'    => 15,
                'user-agent' => 'RankRepair/1.0 H1-checker',
                'sslverify'  => apply_filters('https_local_ssl_verify', false),
                'blocking'   => true,
                'headers'    => ['X-RankRepair-Internal' => '1'],
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
                if (!empty($html)) {
                    preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m);
                    foreach ($m[1] as $text) {
                        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
                        if ($text !== '' && !in_array($text, $h1s, true)) {
                            $h1s[] = $text;
                        }
                    }
                    wp_send_json_success(['h1s' => $h1s, 'source' => 'frontend']);
                    return;
                }
            }
        }

        // Fallback: server-side extractie uit post_content + Elementor
        $h1s = $this->extract_h1s_from_post($post_id);
        wp_send_json_success(['h1s' => $h1s, 'source' => 'server']);
    }

    /** Server-side H1 extractie als fallback voor mislukte loopback requests */
    private function extract_h1s_from_post(int $post_id): array {
        $h1s    = [];
        $post   = get_post($post_id);
        if (!$post) return $h1s;

        // Gutenberg / Classic Editor
        if (!empty($post->post_content)) {
            $rendered = function_exists('do_blocks') ? do_blocks($post->post_content) : $post->post_content;
            preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $rendered, $m);
            foreach ($m[1] as $text) {
                $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
                if ($text !== '' && !in_array($text, $h1s, true)) $h1s[] = $text;
            }
        }

        // Elementor widgets
        $el_data = get_post_meta($post_id, '_elementor_data', true);
        if (!empty($el_data)) {
            $this->extract_elementor_h1s_recursive(json_decode($el_data, true) ?: [], $h1s);
        }

        // ACF Flexible Content: 'content' layout 'hero' → sub-field 'title'
        // (thema rendert dit als <h1 class="hero__title">)
        if (empty($h1s) && function_exists('have_rows')) {
            $h1s = $this->extract_h1s_from_acf_hero($post_id);
        }

        // Laatste fallback: post_title
        if (empty($h1s) && !empty($post->post_title)) {
            $h1s[] = trim(wp_strip_all_tags($post->post_title));
        }

        return $h1s;
    }

    /** ACF Flexible Content 'hero' layout uitlezen voor het title sub-veld */
    private function extract_h1s_from_acf_hero(int $post_id): array {
        $h1s = [];
        if (!function_exists('have_rows') || !have_rows('content', $post_id)) {
            return $h1s;
        }
        while (have_rows('content', $post_id)) {
            the_row();
            if (get_row_layout() !== 'hero') continue;
            $title = get_sub_field('title');
            if (!empty($title)) {
                $text = trim(wp_strip_all_tags($title));
                if ($text !== '') $h1s[] = $text;
            }
        }
        return $h1s;
    }

    private function extract_elementor_h1s_recursive(array $elements, array &$h1s): void {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            if (($el['elType'] ?? '') === 'widget' && ($el['widgetType'] ?? '') === 'heading' && strtolower($el['settings']['header_size'] ?? '') === 'h1') {
                $text = trim(wp_strip_all_tags($el['settings']['title'] ?? ''));
                if ($text !== '' && !in_array($text, $h1s, true)) $h1s[] = $text;
            }
            foreach (['elements', 'children'] as $key) {
                if (!empty($el[$key]) && is_array($el[$key])) {
                    $this->extract_elementor_h1s_recursive($el[$key], $h1s);
                }
            }
        }
    }

    // ==========================================
    // PageSpeed
    // ==========================================

    public function run_pagespeed() {
        $this->verify_nonce();

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : home_url('/');
        $strategy = isset($_POST['strategy']) ? sanitize_text_field($_POST['strategy']) : 'mobile';

        if (empty($url)) {
            wp_send_json_error(['message' => __('Geen URL opgegeven.', 'rankrepair')]);
        }

        $pagespeed = new RR_PageSpeed();
        $result = $pagespeed->analyze($url, $strategy);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'scores'        => $result['scores'],
            'opportunities' => $result['opportunities'],
            'diagnostics'   => $result['diagnostics'],
            'addon_mappings' => $result['addon_mappings'],
            'timestamp'     => $result['timestamp'],
        ]);
    }

    // ==========================================
    // Meta Manager
    // ==========================================

    /**
     * Import CSV/Excel met meta data uit SE Ranking
     */
    public function import_meta_csv() {
        $this->verify_nonce();

        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(['message' => __('Geen bestand geüpload.', 'rankrepair')]);
        }

        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            wp_send_json_error(['message' => __('Alleen CSV en Excel bestanden zijn toegestaan.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Probeer het bestand te openen
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('Kan bestand niet openen.', 'rankrepair')]);
        }

        // Detecteer scheidingsteken: probeer eerst puntkomma, dan komma
        $first_line = fgets($handle);
        rewind($handle);

        $delimiter = (substr_count($first_line, ';') >= substr_count($first_line, ',')) ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            wp_send_json_error(['message' => __('Kan header niet lezen uit bestand.', 'rankrepair')]);
        }

        // Normaliseer header namen
        $header = array_map(function($h) {
            return strtolower(trim(str_replace(["\xEF\xBB\xBF", '"'], '', $h)));
        }, $header);

        $imported = 0;
        $all_titles = [];
        $all_descriptions = [];
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) < 2) continue;

            // Zorg dat header en row dezelfde lengte hebben
            $row_count = min(count($header), count($row));
            $data = array_combine(array_slice($header, 0, $row_count), array_slice($row, 0, $row_count));

            // Flexibele kolomnamen herkenning (SE Ranking, Screaming Frog, Ahrefs, etc.)
            $url = $data['url'] ?? $data['page url'] ?? $data['address'] ?? $data['page'] ?? '';
            $title = $data['title'] ?? $data['meta title'] ?? $data['page title'] ?? $data['title 1'] ?? $data['title tag'] ?? '';
            $description = $data['description'] ?? $data['meta description'] ?? $data['description 1'] ?? $data['meta description 1'] ?? '';

            if (empty($url)) continue;

            // Detecteer paginatie-URL: /page/N/ patroon
            $page_num = 0;
            $resolved_url = $url;
            if (preg_match('#/page/(\d+)/?$#i', $url, $m)) {
                $page_num     = (int) $m[1];
                $resolved_url = preg_replace('#/page/\d+/?$#i', '/', $url);
                $resolved_url = rtrim($resolved_url, '/') . '/';
            }

            $title_len = mb_strlen($title);
            $desc_len = mb_strlen($description);

            if (!empty($title)) {
                $all_titles[$title][] = $resolved_url;
            }
            if (!empty($description)) {
                $all_descriptions[$description][] = $resolved_url;
            }

            $rows[] = [
                'url'                => $resolved_url,
                'original_url'       => $url,
                'page_num'           => $page_num,
                'title'              => $title,
                'description'        => $description,
                'title_length'       => $title_len,
                'description_length' => $desc_len,
            ];
        }
        fclose($handle);

        // Dedupleer op resolved URL: als dezelfde parent al eerder in de CSV staat, sla de paginatie-rij over
        $seen_urls = [];
        $rows = array_filter($rows, function($r) use (&$seen_urls) {
            if (isset($seen_urls[$r['url']])) return false;
            $seen_urls[$r['url']] = true;
            return true;
        });

        // Detecteer duplicaten
        $dup_titles = array_filter($all_titles, function($urls) { return count($urls) > 1; });
        $dup_descs = array_filter($all_descriptions, function($urls) { return count($urls) > 1; });

        // Sla op in database
        $paginated_count = 0;
        foreach ($rows as $row_data) {
            $is_dup_title = isset($dup_titles[$row_data['title']]) ? 1 : 0;
            $is_dup_desc  = isset($dup_descs[$row_data['description']]) ? 1 : 0;

            // Probeer post_id te vinden op basis van URL
            $post_id = url_to_postid($row_data['url']);

            $insert = [
                'url'                      => $row_data['url'],
                'current_title'            => $row_data['title'],
                'current_description'      => $row_data['description'],
                'title_length'             => $row_data['title_length'],
                'description_length'       => $row_data['description_length'],
                'is_duplicate_title'       => $is_dup_title,
                'is_duplicate_description' => $is_dup_desc,
                'status'                   => 'pending',
            ];
            if ($post_id) {
                $insert['post_id'] = $post_id;
            }

            if ($row_data['page_num'] > 0) {
                $paginated_count++;
            }

            // Upsert op URL zodat herhaald importeren veilig is
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE url = %s", $row_data['url']));
            if ($existing) {
                $wpdb->update($table, $insert, ['url' => $row_data['url']]);
            } else {
                $wpdb->insert($table, $insert);
            }
            $imported++;
        }

        $msg = sprintf(
            __('%d pagina\'s geïmporteerd. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
            $imported, count($dup_titles), count($dup_descs)
        );
        if ($paginated_count > 0) {
            $msg .= ' ' . sprintf(
                __('%d paginatie-URL\'s automatisch herleid naar hun bovenliggende pagina.', 'rankrepair'),
                $paginated_count
            );
        }

        wp_send_json_success([
            'message'                => $msg,
            'imported'               => $imported,
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
            'paginated'              => $paginated_count,
        ]);
    }

    /**
     * Sla nieuwe titel en beschrijving op
     */
    public function save_meta() {
        $this->verify_nonce();

        $parsed = $this->parse_entity_id($_POST['id'] ?? '');
        if (!$parsed) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
            return;
        }
        $entity_type = $parsed['type'];
        $entity_id   = $parsed['id'];
        $post_id     = $entity_id; // backwards compat variabele

        $new_title       = isset($_POST['new_title'])       ? sanitize_text_field($_POST['new_title'])           : '';
        $new_description = isset($_POST['new_description']) ? sanitize_textarea_field($_POST['new_description']) : '';
        $current_h1      = (isset($_POST['current_h1']) && $_POST['current_h1'] !== '')
                           ? sanitize_text_field($_POST['current_h1'])
                           : null;

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // H1 opslaan via ACF hero sub-field (alleen voor posts)
        if ($entity_type === 'post' && $current_h1 !== null && function_exists('have_rows') && have_rows('content', $entity_id)) {
            while (have_rows('content', $entity_id)) {
                the_row();
                if (get_row_layout() === 'hero') {
                    update_sub_field('title', $current_h1, $entity_id);
                    break;
                }
            }
        }

        $data = [
            'new_title'       => $new_title,
            'new_description' => $new_description,
            'status'          => 'applied',
            'entity_type'     => $entity_type,
        ];
        if ($current_h1 !== null) {
            $data['current_h1'] = json_encode([$current_h1], JSON_UNESCAPED_UNICODE);
        }

        // Upsert op (entity_type, post_id)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE post_id = %d AND entity_type = %s",
            $entity_id, $entity_type
        ));
        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => $existing]);
        } else {
            $url = ($entity_type === 'post') ? get_permalink($entity_id) : (get_term_link($entity_id) ?: '');
            if (is_wp_error($url)) $url = '';
            $result = $wpdb->insert($table, array_merge($data, [
                'post_id' => $entity_id,
                'url'     => $url,
            ]));
        }

        if ($result === false) {
            wp_send_json_error(['message' => __('Fout bij opslaan: ' . $wpdb->last_error, 'rankrepair')]);
            return;
        }

        // Schrijf direct naar Yoast / Rank Math (post of term)
        $this->write_entity_meta(
            $entity_type,
            $entity_id,
            !empty($new_title)       ? $new_title       : null,
            !empty($new_description) ? $new_description : null
        );


        // Verse stats voor realtime UI-update
        $stats     = [];
        $item_data = null;
        $addon     = rankrepair()->get_addon('meta-manager');
        if ($addon) {
            $all_items = $addon->load_live_items();
            $stats     = $addon->compute_stats($all_items);
            foreach ($all_items as $row) {
                if ($row['entity_type'] === $entity_type && (int) $row['post_id'] === $entity_id) {
                    $item_data = [
                        'id'                       => $row['id'], // composite "post:123" / "term:45"
                        'entity_type'              => $row['entity_type'],
                        'current_title'            => $row['current_title'],
                        'current_description'      => $row['current_description'],
                        'title_length'             => (int) $row['title_length'],
                        'description_length'       => (int) $row['description_length'],
                        'is_duplicate_title'       => (int) $row['is_duplicate_title'],
                        'is_duplicate_description' => (int) $row['is_duplicate_description'],
                    ];
                    break;
                }
            }
        }

        wp_send_json_success([
            'message' => __('Opgeslagen!', 'rankrepair'),
            'stats'   => $stats,
            'item'    => $item_data,
        ]);
    }

    /**
     * Pas meta data toe op de WordPress pagina
     * Werkt met Yoast SEO, Rank Math, of standaard WordPress
     */
    public function apply_meta() {
        $this->verify_nonce();

        $post_id         = isset($_POST['id'])              ? absint($_POST['id'])                               : 0;
        $new_title       = isset($_POST['new_title'])       ? sanitize_text_field($_POST['new_title'])           : '';
        $new_description = isset($_POST['new_description']) ? sanitize_textarea_field($_POST['new_description']) : '';

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(['message' => __('Pagina niet gevonden.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Sla veldwaarden op (upsert) en markeer als applied
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
        if ($existing) {
            $wpdb->update($table, [
                'new_title'       => $new_title,
                'new_description' => $new_description,
                'status'          => 'applied',
            ], ['post_id' => $post_id]);
        } else {
            $wpdb->insert($table, [
                'post_id'         => $post_id,
                'url'             => get_permalink($post_id),
                'new_title'       => $new_title,
                'new_description' => $new_description,
                'status'          => 'applied',
            ]);
        }

        // Gebruik $new_title / $new_description direct uit $_POST
        $meta = ['new_title' => $new_title, 'new_description' => $new_description];

        $applied = false;

        // Titel toepassen
        if (!empty($meta['new_title'])) {
            // Yoast SEO
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_title', $meta['new_title']);
                $applied = true;
            }
            // Rank Math
            if (defined('RANK_MATH_VERSION')) {
                update_post_meta($post_id, 'rank_math_title', $meta['new_title']);
                $applied = true;
            }
            // Fallback: WordPress post title
            if (!$applied) {
                wp_update_post(['ID' => $post_id, 'post_title' => $meta['new_title']]);
                $applied = true;
            }
        }

        // Beschrijving toepassen
        if (!empty($meta['new_description'])) {
            if (defined('WPSEO_VERSION')) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta['new_description']);
            }
            if (defined('RANK_MATH_VERSION')) {
                update_post_meta($post_id, 'rank_math_description', $meta['new_description']);
            }
            // Altijd ook als custom field opslaan
            update_post_meta($post_id, '_rr_meta_description', $meta['new_description']);
        }

        wp_send_json_success(['message' => __('Meta data toegepast op de pagina!', 'rankrepair')]);
    }

    /**
     * Verwijder opgeslagen AI-data voor een post
     */
    public function delete_meta() {
        $this->verify_nonce();

        $parsed = $this->parse_entity_id($_POST['id'] ?? '');
        if (!$parsed) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $wpdb->delete($table, [
            'post_id'     => $parsed['id'],
            'entity_type' => $parsed['type'],
        ]);

        wp_send_json_success(['message' => __('Verwijderd.', 'rankrepair')]);
    }

    /**
     * Wis alle meta data
     */
    public function clear_all_meta() {
        $this->verify_nonce();

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $wpdb->query("TRUNCATE TABLE $table");

        wp_send_json_success(['message' => __('Alle meta data gewist.', 'rankrepair')]);
    }

    // ==========================================
    // WordPress Scan
    // ==========================================

    /**
     * Scan WordPress direct: leest Yoast meta uit postmeta
     * Geen API nodig — werkt altijd, altijd actueel
     */
    public function scan_wordpress_meta() {
        $this->verify_nonce();

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // Haal alle gepubliceerde posts/pagina's/producten op met hun Yoast meta
        $post_types = apply_filters('rr_scan_post_types', ['post', 'page', 'product']);
        $post_types = array_filter(array_map('sanitize_key', $post_types));
        if (empty($post_types)) {
            $post_types = ['post', 'page', 'product'];
        }
        $post_type_in = "'" . implode("','", $post_types) . "'";

        $posts = $wpdb->get_results("
            SELECT
                p.ID,
                p.post_type,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_title'   THEN pm.meta_value END) as yoast_title,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_metadesc' THEN pm.meta_value END) as yoast_desc
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id
                AND pm.meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc')
            WHERE p.post_status = 'publish'
              AND p.post_type IN ($post_type_in)
            GROUP BY p.ID
            ORDER BY p.post_type, p.ID ASC
        ");

        if (empty($posts)) {
            wp_send_json_error(['message' => __('Geen gepubliceerde pagina\'s gevonden.', 'rankrepair')]);
        }

        $rows        = [];
        $all_titles  = [];
        $all_descs   = [];

        foreach ($posts as $post) {
            $url     = get_permalink($post->ID);
            $title   = $post->yoast_title ?? '';
            $desc    = $post->yoast_desc  ?? '';
            $wp_post = get_post($post->ID);

            $h1s = $this->extract_h1s_from_post($post->ID);
            $h1  = json_encode($h1s, JSON_UNESCAPED_UNICODE);

            // Yoast slaat soms templates op zoals %%title%% — probeer die te resolven
            if (defined('WPSEO_VERSION') && class_exists('WPSEO_Replace_Vars')) {
                $replacer = new WPSEO_Replace_Vars();
                if ($wp_post) {
                    if (!empty($title)) {
                        $title = $replacer->replace($title, $wp_post);
                    }
                    if (!empty($desc)) {
                        $desc = $replacer->replace($desc, $wp_post);
                    }
                }
            }

            // RankMath: lees rank_math_* wanneer Yoast leeg is (mirror van de write-kant).
            if ((empty($title) || empty($desc)) && defined('RANK_MATH_VERSION')) {
                if (empty($title)) { $rm = get_post_meta($post->ID, 'rank_math_title', true);       if (!empty($rm)) $title = $this->rankmath_resolve($rm, $wp_post); }
                if (empty($desc))  { $rm = get_post_meta($post->ID, 'rank_math_description', true); if (!empty($rm)) $desc  = $this->rankmath_resolve($rm, $wp_post); }
            }

            // Fallback: als Yoast geen expliciete beschrijving heeft, gebruik wat Yoast zelf zou genereren
            if (empty($desc) && $wp_post) {
                // Probeer eerst Yoast's eigen meta surface (Yoast v14+)
                if (function_exists('YoastSEO')) {
                    try {
                        $meta_surface = YoastSEO()->meta->for_post($post->ID);
                        if ($meta_surface && !empty($meta_surface->description)) {
                            $desc = $meta_surface->description;
                        }
                    } catch (Exception $e) {
                        // Yoast meta surface niet beschikbaar, ga door naar fallback
                    }
                }
                // Tweede fallback: gebruik het excerpt van de post
                if (empty($desc)) {
                    $excerpt = get_the_excerpt($post->ID);
                    if (!empty($excerpt)) {
                        $desc = $excerpt;
                    }
                }
            }

            if (!empty($title)) $all_titles[$title][] = $url;
            if (!empty($desc))  $all_descs[$desc][]   = $url;

            $rows[] = [
                'post_id'   => $post->ID,
                'url'       => $url,
                'h1'        => $h1,
                'title'     => $title,
                'desc'      => $desc,
                'title_len' => mb_strlen($title),
                'desc_len'  => mb_strlen($desc),
            ];
        }

        // Detecteer duplicaten
        $dup_titles = array_filter($all_titles, fn($u) => count($u) > 1);
        $dup_descs  = array_filter($all_descs,  fn($u) => count($u) > 1);

        // Leeg de tabel en vul opnieuw (transactioneel zodat geen data verloren gaat bij fouten)
        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM $table");

        $insert_ok = true;
        foreach ($rows as $row) {
            $res = $wpdb->insert($table, [
                'post_id'                  => $row['post_id'],
                'url'                      => $row['url'],
                'current_h1'               => $row['h1'],
                'current_title'            => $row['title'],
                'current_description'      => $row['desc'],
                'title_length'             => $row['title_len'],
                'description_length'       => $row['desc_len'],
                'is_duplicate_title'       => isset($dup_titles[$row['title']]) && !empty($row['title']) ? 1 : 0,
                'is_duplicate_description' => isset($dup_descs[$row['desc']])   && !empty($row['desc'])  ? 1 : 0,
                'status'                   => 'pending',
            ]);
            if ($res === false) {
                $insert_ok = false;
                break;
            }
        }

        if (!$insert_ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => __('Databasefout bij opslaan van scan resultaten.', 'rankrepair')]);
            return;
        }
        $wpdb->query('COMMIT');

        wp_send_json_success([
            'message' => sprintf(
                __('%d pagina\'s gescand uit WordPress. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
                count($rows), count($dup_titles), count($dup_descs)
            ),
            'scanned'                => count($rows),
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
        ]);
    }

    // ==========================================
    // SE Ranking API
    // ==========================================

    private function seranking_request($endpoint) {
        $api_key = rr_decrypt_key(get_option('rr_seranking_api_key', ''));
        if (empty($api_key)) {
            return new WP_Error('no_key', __('Geen SE Ranking API key ingesteld. Ga naar Instellingen.', 'rankrepair'));
        }

        $response = wp_remote_get('https://api4.seranking.com/' . ltrim($endpoint, '/'), [
            'headers' => [
                'Authorization' => 'Token ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return new WP_Error('unauthorized', __('Ongeldige SE Ranking API key.', 'rankrepair'));
        }
        if ($code !== 200) {
            $msg = $body['message'] ?? $body['detail'] ?? "HTTP $code";
            return new WP_Error('api_error', "SE Ranking API: $msg");
        }

        return $body;
    }

    /**
     * Haal lijst van SE Ranking sites/projecten op
     */
    public function seranking_get_sites() {
        $this->verify_nonce();

        $result = $this->seranking_request('/sites/');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Normaliseer: verwacht array van sites
        $sites = [];
        $data  = isset($result['results']) ? $result['results'] : (array) $result;
        foreach ($data as $site) {
            if (!isset($site['id'])) continue;
            $sites[] = [
                'id'  => $site['id'],
                'name' => $site['title'] ?? $site['domain'] ?? $site['url'] ?? 'Site ' . $site['id'],
            ];
        }

        if (empty($sites)) {
            wp_send_json_error(['message' => __('Geen sites gevonden in je SE Ranking account.', 'rankrepair')]);
        }

        wp_send_json_success(['sites' => $sites]);
    }

    /**
     * Importeer pagina-meta van een SE Ranking site audit
     */
    public function seranking_import_pages() {
        $this->verify_nonce();

        $site_id = isset($_POST['site_id']) ? absint($_POST['site_id']) : 0;
        if (!$site_id) {
            wp_send_json_error(['message' => __('Geen site geselecteerd.', 'rankrepair')]);
        }

        // SE Ranking on-page / audit pages endpoint
        $result = $this->seranking_request("/sites/{$site_id}/pages/");

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $data = isset($result['results']) ? $result['results'] : (array) $result;

        if (empty($data)) {
            wp_send_json_error(['message' => __('Geen pagina\'s gevonden. Zorg dat je een site audit hebt gedraaid in SE Ranking.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        $rows       = [];
        $all_titles = [];
        $all_descs  = [];

        foreach ($data as $page) {
            $url   = $page['url']              ?? $page['page']        ?? '';
            $title = $page['title']            ?? $page['meta_title']  ?? '';
            $desc  = $page['description']      ?? $page['meta_description'] ?? '';

            if (empty($url)) continue;

            if (!empty($title)) $all_titles[$title][] = $url;
            if (!empty($desc))  $all_descs[$desc][]   = $url;

            $rows[] = [
                'url'       => $url,
                'title'     => $title,
                'desc'      => $desc,
                'title_len' => mb_strlen($title),
                'desc_len'  => mb_strlen($desc),
            ];
        }

        $dup_titles = array_filter($all_titles, fn($u) => count($u) > 1);
        $dup_descs  = array_filter($all_descs,  fn($u) => count($u) > 1);

        $wpdb->query('START TRANSACTION');
        $wpdb->query("DELETE FROM $table");

        $insert_ok = true;
        foreach ($rows as $row) {
            $res = $wpdb->insert($table, [
                'url'                      => $row['url'],
                'current_title'            => $row['title'],
                'current_description'      => $row['desc'],
                'title_length'             => $row['title_len'],
                'description_length'       => $row['desc_len'],
                'is_duplicate_title'       => isset($dup_titles[$row['title']]) && !empty($row['title']) ? 1 : 0,
                'is_duplicate_description' => isset($dup_descs[$row['desc']])   && !empty($row['desc'])  ? 1 : 0,
                'status'                   => 'pending',
            ]);
            if ($res === false) {
                $insert_ok = false;
                break;
            }
        }

        if (!$insert_ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => __('Databasefout bij opslaan van geïmporteerde pagina\'s.', 'rankrepair')]);
            return;
        }
        $wpdb->query('COMMIT');

        wp_send_json_success([
            'message' => sprintf(
                __('%d pagina\'s geïmporteerd via SE Ranking. %d dubbele titels, %d dubbele beschrijvingen gevonden.', 'rankrepair'),
                count($rows), count($dup_titles), count($dup_descs)
            ),
            'imported'               => count($rows),
            'duplicate_titles'       => count($dup_titles),
            'duplicate_descriptions' => count($dup_descs),
        ]);
    }
    // ==========================================
    // Gemini AI
    // ==========================================

    /**
     * Genereer meta titel en beschrijving via Gemini voor één rij
     */
    public function gemini_generate() {
        $this->verify_nonce();

        $parsed = $this->parse_entity_id($_POST['id'] ?? '');
        if (!$parsed) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
        }

        $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Geen Gemini API key ingesteld. Ga naar Instellingen.', 'rankrepair')]);
        }

        $meta = $this->build_meta($parsed['type'], $parsed['id']);
        if (!$meta) {
            wp_send_json_error(['message' => __('Pagina niet gevonden.', 'rankrepair')]);
        }

        $result = $this->call_gemini_for_meta($meta, $api_key);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'title'       => $result['title'],
            'description' => $result['description'],
        ]);
    }

    /**
     * Genereer meta voor meerdere rijen tegelijk (bulk).
     * Slaat NIETS op — retourneert alleen de AI-suggesties zodat de gebruiker
     * ze eerst kan controleren via het review-overzicht in de frontend.
     */
    public function gemini_generate_bulk() {
        $this->verify_nonce();
        set_time_limit(120);

        $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : [];
        if (empty($raw_ids)) {
            wp_send_json_error(['message' => __('Geen rijen opgegeven.', 'rankrepair')]);
        }

        $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Geen Gemini API key ingesteld. Ga naar Instellingen.', 'rankrepair')]);
        }

        $fields = isset($_POST['fields']) ? array_map('sanitize_key', (array) $_POST['fields']) : ['title', 'desc'];
        $fields = array_values(array_intersect(['title', 'desc'], $fields));
        if (empty($fields)) $fields = ['title', 'desc'];

        $results        = [];
        $errors_detail  = [];
        $first_error    = null;
        $throttle_us    = 4100000; // 4.1 s tussen calls — Gemini free tier = 15 rpm (1 call/4s)

        foreach ($raw_ids as $raw_id) {
            $parsed = $this->parse_entity_id($raw_id);
            if (!$parsed) {
                $errors_detail[] = ['id' => $raw_id, 'name' => (string)$raw_id, 'reason' => 'Ongeldig ID'];
                continue;
            }
            $composite_id = $parsed['type'] . ':' . $parsed['id'];

            $meta = $this->build_meta($parsed['type'], $parsed['id']);
            if (!$meta) {
                $errors_detail[] = ['id' => $composite_id, 'name' => $composite_id, 'reason' => 'Pagina niet gevonden'];
                continue;
            }

            $attempts = 0;
            $result   = null;
            while ($attempts < 3) {
                $attempts++;
                $result = $this->call_gemini_for_meta($meta, $api_key);
                if (!is_wp_error($result)) break;
                $msg = $result->get_error_message();
                if (preg_match('/\b(429|RESOURCE_EXHAUSTED|rate.?limit|503|500|overloaded)\b/i', $msg)) {
                    sleep(pow(2, $attempts));
                    continue;
                }
                break;
            }

            $display_name = $meta['name'] ?? ($meta['url'] ?? $composite_id);

            if (is_wp_error($result)) {
                if ($first_error === null) $first_error = $result->get_error_message();
                $errors_detail[] = [
                    'id'     => $composite_id,
                    'name'   => $display_name,
                    'reason' => $result->get_error_message(),
                ];
            } else {
                $results[] = [
                    'id'            => $composite_id,
                    'entity_type'   => $parsed['type'],
                    'title'         => in_array('title', $fields, true) ? $result['title']       : '',
                    'description'   => in_array('desc',  $fields, true) ? $result['description'] : '',
                    'current_title' => $meta['current_title'] ?? '',
                    'current_desc'  => $meta['current_description'] ?? '',
                    'name'          => $display_name,
                    'fields'        => $fields,
                ];
            }
            usleep($throttle_us);
        }

        $message = sprintf(__('%d gegenereerd, %d mislukt.', 'rankrepair'), count($results), count($errors_detail));
        if ($first_error) {
            $message .= ' Fout: ' . $first_error;
        }

        wp_send_json_success([
            'results'       => $results,
            'errors'        => array_column($errors_detail, 'id'),
            'errors_detail' => $errors_detail,
            'message'       => $message,
            'first_error'   => $first_error,
        ]);
    }

    /**
     * Sla een bulk-set van bewerkte AI-suggesties op na de review-stap.
     * Verwacht $_POST['items'] = [ ['id'=>…,'title'=>…,'desc'=>…], … ]
     */
    public function save_bulk_meta() {
        $this->verify_nonce();

        $items = isset($_POST['items']) ? (array) $_POST['items'] : [];
        if (empty($items)) {
            wp_send_json_error(['message' => __('Geen items opgegeven.', 'rankrepair')]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';
        $saved = 0;

        $saved_keys = []; // map van 'type:id' voor response filtering

        foreach ($items as $item) {
            $parsed = $this->parse_entity_id($item['id'] ?? '');
            if (!$parsed) continue;
            $entity_type = $parsed['type'];
            $entity_id   = $parsed['id'];

            $has_title = array_key_exists('title', $item);
            $has_desc  = array_key_exists('desc',  $item);
            if (!$has_title && !$has_desc) continue;

            $new_title = $has_title ? sanitize_text_field($item['title'])     : null;
            $new_desc  = $has_desc  ? sanitize_textarea_field($item['desc'])  : null;

            $data = [
                'status'      => 'applied',
                'entity_type' => $entity_type,
            ];
            if ($has_title) $data['new_title']       = $new_title;
            if ($has_desc)  $data['new_description'] = $new_desc;

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE post_id = %d AND entity_type = %s",
                $entity_id, $entity_type
            ));
            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing]);
            } else {
                $url = ($entity_type === 'post') ? get_permalink($entity_id) : (get_term_link($entity_id) ?: '');
                if (is_wp_error($url)) $url = '';
                $wpdb->insert($table, array_merge($data, [
                    'post_id' => $entity_id,
                    'url'     => $url,
                ]));
            }

            // Schrijf naar Yoast/Rank Math (post of term)
            $this->write_entity_meta($entity_type, $entity_id, $new_title, $new_desc);

            $saved_keys[] = $entity_type . ':' . $entity_id;
            $saved++;
        }

        // Verse stats + row-data voor realtime UI-update
        $addon      = rankrepair()->get_addon('meta-manager');
        $stats      = [];
        $items_data = [];
        if ($addon) {
            $all_items = $addon->load_live_items();
            $stats     = $addon->compute_stats($all_items);

            foreach ($all_items as $row) {
                $key = $row['entity_type'] . ':' . $row['post_id'];
                if (in_array($key, $saved_keys, true)) {
                    $items_data[] = [
                        'id'                       => $row['id'], // composite
                        'entity_type'              => $row['entity_type'],
                        'current_title'            => $row['current_title'],
                        'current_description'      => $row['current_description'],
                        'title_length'             => (int) $row['title_length'],
                        'description_length'       => (int) $row['description_length'],
                        'is_duplicate_title'       => (int) $row['is_duplicate_title'],
                        'is_duplicate_description' => (int) $row['is_duplicate_description'],
                    ];
                }
            }
        }

        wp_send_json_success([
            'saved'   => $saved,
            'message' => sprintf(_n('%d pagina opgeslagen.', '%d pagina\'s opgeslagen.', $saved, 'rankrepair'), $saved),
            'stats'   => $stats,
            'items'   => $items_data,
        ]);
    }

    /**
     * Bouw een meta-array op vanuit WordPress live data (vervangt DB-lookup)
     */
    private function build_meta_from_post(int $post_id): ?array {
        return $this->build_meta('post', $post_id);
    }

    /**
     * Lost RankMath-template-variabelen op als RankMath beschikbaar is; anders ruwe waarde.
     */
    private function rankmath_resolve($value, $object) {
        if ($value === '' || $value === null) {
            return '';
        }
        if (class_exists('RankMath\Helper') && method_exists('RankMath\Helper', 'replace_vars')) {
            try {
                return \RankMath\Helper::replace_vars($value, $object);
            } catch (\Throwable $e) {
                // Val terug op de ruwe waarde.
            }
        }
        return $value;
    }

    /**
     * Bouw meta-array voor een post OF term. Retourneert null als entity niet bestaat.
     */
    private function build_meta(string $type, int $id): ?array {
        $replacer     = (defined('WPSEO_VERSION') && class_exists('WPSEO_Replace_Vars')) ? new WPSEO_Replace_Vars() : null;
        $wpseo_titles = get_option('wpseo_titles', []);

        if ($type === 'post') {
            $wp_post = get_post($id);
            if (!$wp_post) return null;

            $url   = get_permalink($id);
            $title = get_post_meta($id, '_yoast_wpseo_title',   true) ?: '';
            $desc  = get_post_meta($id, '_yoast_wpseo_metadesc', true) ?: '';

            if ($replacer) {
                if (!empty($title)) $title = $replacer->replace($title, $wp_post);
                if (!empty($desc))  $desc  = $replacer->replace($desc,  $wp_post);
            }
            if ($replacer && (empty($title) || empty($desc))) {
                $pt = $wp_post->post_type;
                if (empty($title) && !empty($wpseo_titles["title-{$pt}"])) {
                    $title = $replacer->replace($wpseo_titles["title-{$pt}"], $wp_post);
                }
                if (empty($desc) && !empty($wpseo_titles["metadesc-{$pt}"])) {
                    $desc = $replacer->replace($wpseo_titles["metadesc-{$pt}"], $wp_post);
                }
            }
            if ((empty($title) || empty($desc)) && function_exists('YoastSEO')) {
                try {
                    $ms = YoastSEO()->meta->for_post($id);
                    if ($ms) {
                        if (empty($title) && !empty($ms->title))       $title = $ms->title;
                        if (empty($desc)  && !empty($ms->description)) $desc  = $ms->description;
                    }
                } catch (Exception $e) {}
            }
            // RankMath: lees rank_math_* wanneer Yoast niets opleverde (mirror van de write-kant).
            if ((empty($title) || empty($desc)) && defined('RANK_MATH_VERSION')) {
                if (empty($title)) { $rm = get_post_meta($id, 'rank_math_title', true);       if (!empty($rm)) $title = $this->rankmath_resolve($rm, $wp_post); }
                if (empty($desc))  { $rm = get_post_meta($id, 'rank_math_description', true); if (!empty($rm)) $desc  = $this->rankmath_resolve($rm, $wp_post); }
            }
            if (empty($desc)) {
                $excerpt = get_the_excerpt($id);
                if (!empty($excerpt)) $desc = $excerpt;
            }

            return [
                'entity_type'         => 'post',
                'entity_id'           => $id,
                'post_id'             => $id,
                'url'                 => $url,
                'current_title'       => $title,
                'current_description' => $desc,
                'wp_entity'           => $wp_post,
                'name'                => $wp_post->post_title,
            ];
        }

        if ($type === 'term') {
            $term = get_term($id);
            if (!$term || is_wp_error($term)) return null;

            $url   = get_term_link($term);
            if (is_wp_error($url)) $url = '';

            $title = get_term_meta($id, '_yoast_wpseo_title',   true) ?: '';
            $desc  = get_term_meta($id, '_yoast_wpseo_metadesc', true) ?: '';

            if (empty($title) || empty($desc)) {
                $tax_meta = get_option('wpseo_taxonomy_meta', []);
                $saved    = $tax_meta[$term->taxonomy][$id] ?? [];
                if (empty($title) && !empty($saved['wpseo_title'])) $title = $saved['wpseo_title'];
                if (empty($desc)  && !empty($saved['wpseo_desc']))  $desc  = $saved['wpseo_desc'];
            }

            if ($replacer) {
                if (!empty($title)) $title = $replacer->replace($title, $term);
                if (!empty($desc))  $desc  = $replacer->replace($desc,  $term);
            }
            if ($replacer && (empty($title) || empty($desc))) {
                $tx = $term->taxonomy;
                if (empty($title) && !empty($wpseo_titles["title-tax-{$tx}"])) {
                    $title = $replacer->replace($wpseo_titles["title-tax-{$tx}"], $term);
                }
                if (empty($desc) && !empty($wpseo_titles["metadesc-tax-{$tx}"])) {
                    $desc = $replacer->replace($wpseo_titles["metadesc-tax-{$tx}"], $term);
                }
            }
            if ((empty($title) || empty($desc)) && function_exists('YoastSEO')) {
                try {
                    $ms = YoastSEO()->meta->for_term($id);
                    if ($ms) {
                        if (empty($title) && !empty($ms->title))       $title = $ms->title;
                        if (empty($desc)  && !empty($ms->description)) $desc  = $ms->description;
                    }
                } catch (Exception $e) {}
            }
            // RankMath term-meta (mirror van de write-kant).
            if ((empty($title) || empty($desc)) && defined('RANK_MATH_VERSION')) {
                if (empty($title)) { $rm = get_term_meta($id, 'rank_math_title', true);       if (!empty($rm)) $title = $this->rankmath_resolve($rm, $term); }
                if (empty($desc))  { $rm = get_term_meta($id, 'rank_math_description', true); if (!empty($rm)) $desc  = $this->rankmath_resolve($rm, $term); }
            }
            if (empty($desc) && !empty($term->description)) {
                $desc = wp_strip_all_tags($term->description);
            }

            return [
                'entity_type'         => 'term',
                'entity_id'           => $id,
                'post_id'             => $id,
                'url'                 => $url,
                'current_title'       => $title,
                'current_description' => $desc,
                'wp_entity'           => $term,
                'name'                => $term->name,
            ];
        }

        return null;
    }

    /**
     * Roep Gemini API aan voor één meta record
     */
    private function call_gemini_for_meta(array $meta, string $api_key) {
        $entity_type  = $meta['entity_type'] ?? 'post';
        $entity_id    = (int) ($meta['entity_id'] ?? $meta['post_id'] ?? 0);
        $post_content = '';
        $post_title   = $meta['name'] ?? '';
        $post_h1      = '';

        if ($entity_type === 'post' && $entity_id) {
            $post = get_post($entity_id);
            if ($post) {
                $post_title   = $post->post_title;
                $post_content = wp_strip_all_tags($post->post_content);
                $post_content = preg_replace('/\s+/', ' ', $post_content);
                $post_content = trim(mb_substr($post_content, 0, 800));

                if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $post->post_content, $h1_match)) {
                    $post_h1 = wp_strip_all_tags($h1_match[1]);
                }
            }
        } elseif ($entity_type === 'term' && $entity_id) {
            $term = get_term($entity_id);
            if ($term && !is_wp_error($term)) {
                $post_title   = $term->name;
                $post_h1      = $term->name;
                $post_content = trim(mb_substr(wp_strip_all_tags($term->description ?? ''), 0, 800));
            }
        }

        $url        = $meta['url'] ?? '';
        $brand_rule = 'GEEN bedrijfsnaam in de titel (Google verwijdert deze toch uit de SERP).';

        // Haal opgeslagen template op, of gebruik de standaard
        $stored = trim(get_option('rr_gemini_prompt', ''));
        $template = !empty($stored) ? $stored : rr_get_default_prompt_template();

        // Vervang {{placeholders}} met werkelijke waarden
        $h1_line           = !empty($post_h1)                    ? "H1: " . $post_h1 . "\n"                                            : (!empty($post_title) ? "Paginatitel: " . $post_title . "\n" : '');
        $current_desc_line  = !empty($meta['current_description']) ? "Huidige meta beschrijving: " . $meta['current_description'] . "\n" : '';
        $content_line       = !empty($post_content)               ? "Pagina-inhoud (fragment): " . $post_content . "\n"                 : '';

        // Strip de merknaam/separator uit de current_title zodat de AI de stijl niet kopieert.
        // Yoast lost %%title%% _ Merknaam op voordat het hier aankomt; zonder strippen bootst
        // de AI het " _ Merknaam"-suffix na ook al staat er in de prompt dat het niet mag.
        $current_title_context = $meta['current_title'] ?? '';
        $allow_brand = false; // Merknaam altijd strippen (prompt verbiedt het al expliciet)
        if (!$allow_brand && !empty($current_title_context)) {
            $site_name = get_bloginfo('name');
            if (!empty($site_name)) {
                $current_title_context = preg_replace(
                    '/\s*[\|\-_–—]\s*' . preg_quote($site_name, '/') . '\s*$/iu',
                    '',
                    $current_title_context
                );
            }
        }
        $current_title_line = !empty($current_title_context) ? "Huidige meta titel: " . $current_title_context . "\n" : '';

        $prompt = str_replace(
            ['{{url}}', '{{brand_rule}}', '{{h1_line}}', '{{current_title_line}}', '{{current_desc_line}}', '{{content_line}}'],
            [$url, $brand_rule, $h1_line, $current_title_line, $current_desc_line, $content_line],
            $template
        );

        $provider = get_option('rr_ai_provider', 'google');
        $model    = trim(get_option('rr_ai_model', ''));

        if ($provider === 'openrouter') {
            // ── OpenRouter (OpenAI-compatibel) ──────────────────────────────────
            if (empty($model)) {
                $model = 'google/gemini-2.0-flash-001';
            }

            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => get_bloginfo('name'),
                ],
                'body' => wp_json_encode([
                    'model'    => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.7,
                    'max_tokens'  => 300,
                    'plugins'     => [
                        ['id' => 'web', 'enabled' => false],
                    ],
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('ai_request', $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $msg = $body['error']['message'] ?? "HTTP $code";
                return new WP_Error('ai_api', 'OpenRouter: ' . $msg);
            }

            $text = $body['choices'][0]['message']['content'] ?? '';

        } else {
            // ── Google AI Studio ────────────────────────────────────────────────
            if (empty($model)) {
                $model = 'gemini-1.5-flash';
            }

            $endpoint = add_query_arg('key', $api_key, 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent');

            $response = wp_remote_post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.7,
                        'maxOutputTokens' => 300,
                    ],
                ]),
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return new WP_Error('ai_request', $response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $msg = $body['error']['message'] ?? "HTTP $code";
                return new WP_Error('ai_api', 'Gemini API: ' . $msg);
            }

            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        if (empty($text)) {
            return new WP_Error('gemini_empty', __('Gemini gaf geen resultaat terug.', 'rankrepair'));
        }

        // Parseer TITEL: en BESCHRIJVING: uit de response
        $title       = '';
        $description = '';

        // Titel: eerste regel na "TITEL:"
        if (preg_match('/TITEL:\s*(.+)/i', $text, $m)) {
            $title = trim(explode("\n", $m[1])[0]);
        }
        // Beschrijving: alles na "BESCHRIJVING:" t/m einde of volgende lege regel
        if (preg_match('/BESCHRIJVING:\s*(.+?)(?:\n\s*\n|$)/si', $text, $m)) {
            $description = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        if (empty($title) && empty($description)) {
            return new WP_Error('gemini_parse', __('Gemini response kon niet worden gelezen. Probeer het opnieuw.', 'rankrepair'));
        }

        return [
            'title'       => $title,
            'description' => $description,
        ];
    }
    /**
     * Stap 1: ontvang CSV-upload, parse kolommen + rijen terug naar JS
     */
    public function parse_upload_csv() {
        $this->verify_nonce();

        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Bestand niet ontvangen of upload-fout.', 'rankrepair')]);
            return;
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if (!$handle) {
            wp_send_json_error(['message' => __('Kan bestand niet lezen.', 'rankrepair')]);
            return;
        }

        // Lees eerste regel om delimiter te detecteren
        $first_line = fgets($handle);
        rewind($handle);

        // Strip UTF-8 BOM
        $first_line = ltrim($first_line, "\xEF\xBB\xBF");
        $delimiter  = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

        // Lees header
        $raw_header = fgetcsv($handle, 0, $delimiter);
        if (!$raw_header) {
            fclose($handle);
            wp_send_json_error(['message' => __('Kan kolomnamen niet lezen.', 'rankrepair')]);
            return;
        }
        // Verwijder BOM en spaties
        $columns = array_map(fn($c) => trim(ltrim($c, "\xEF\xBB\xBF")), $raw_header);

        // Lees max 2000 rijen
        $rows = [];
        while (count($rows) < 2000 && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($row) === count($columns)) {
                $rows[] = array_combine($columns, $row);
            }
        }
        fclose($handle);

        // Auto-detect: welke kolom is waarschijnlijk de URL / nieuwe titel / nieuwe beschrijving?
        $guess_url   = '';
        $guess_title = '';
        $guess_desc  = '';
        foreach ($columns as $col) {
            $l = strtolower($col);
            if (!$guess_url   && preg_match('/(^url$|page url|address|pagina url|slug)/i', $col))  $guess_url = $col;
            if (!$guess_title && preg_match('/nieuwe.*(titel|title)|new.*(title|meta)/i', $col))     $guess_title = $col;
            if (!$guess_desc  && preg_match('/nieuwe.*(omschrijving|description)|new.*(desc)/i', $col)) $guess_desc = $col;
        }
        // Fallback voor URL
        if (!$guess_url) {
            foreach ($columns as $col) {
                if (preg_match('/(url|link|adres)/i', $col)) { $guess_url = $col; break; }
            }
        }

        wp_send_json_success([
            'columns'     => $columns,
            'rows'        => $rows,
            'guess_url'   => $guess_url,
            'guess_title' => $guess_title,
            'guess_desc'  => $guess_desc,
            'total'       => count($rows),
        ]);
    }

    /**
     * Stap 2: ontvang de mapping + rijen, pas toe op Yoast en DB
     */
    public function apply_upload_csv() {
        $this->verify_nonce();

        $col_url   = sanitize_text_field($_POST['col_url']   ?? '');
        $col_title = sanitize_text_field($_POST['col_title'] ?? '');
        $col_desc  = sanitize_text_field($_POST['col_desc']  ?? '');
        $rows_json = wp_unslash($_POST['rows'] ?? '[]');
        $rows      = json_decode($rows_json, true);

        if (empty($col_url) || !is_array($rows)) {
            wp_send_json_error(['message' => __('Ongeldige invoer.', 'rankrepair')]);
            return;
        }

        global $wpdb;
        $table   = $wpdb->prefix . 'rr_meta_data';
        $applied = 0;
        $skipped = [];

        foreach ($rows as $row) {
            $raw_url   = trim($row[$col_url]   ?? '');
            $new_title = !empty($col_title) ? sanitize_text_field(trim($row[$col_title] ?? ''))        : '';
            $new_desc  = !empty($col_desc)  ? sanitize_textarea_field(trim($row[$col_desc]  ?? ''))   : '';

            if (empty($raw_url) || (empty($new_title) && empty($new_desc))) continue;

            // Probeer post te vinden via url_to_postid (werkt met volledige URL en pad)
            $post_id = url_to_postid($raw_url);

            // Fallback: voeg home_url toe als het een pad is
            if (!$post_id && str_starts_with($raw_url, '/')) {
                $post_id = url_to_postid(home_url($raw_url));
            }

            // Fallback: zoek op slug
            if (!$post_id) {
                $slug    = trim(basename(rtrim($raw_url, '/')));
                $post    = get_page_by_path($slug, OBJECT, ['post', 'page', 'product']);
                $post_id = $post ? $post->ID : 0;
            }

            if (!$post_id) {
                $skipped[] = $raw_url;
                continue;
            }

            // Schrijf naar Yoast SEO
            if (!empty($new_title)) update_post_meta($post_id, '_yoast_wpseo_title',   $new_title);
            if (!empty($new_desc))  update_post_meta($post_id, '_yoast_wpseo_metadesc', $new_desc);

            // Sla ook op in RR tabel (upsert)
            $data = array_filter([
                'new_title'       => $new_title ?: null,
                'new_description' => $new_desc  ?: null,
                'status'          => 'applied',
            ], fn($v) => $v !== null);

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE post_id = %d", $post_id));
            if ($existing) {
                $wpdb->update($table, $data, ['post_id' => $post_id]);
            } else {
                $wpdb->insert($table, array_merge($data, [
                    'post_id' => $post_id,
                    'url'     => get_permalink($post_id),
                ]));
            }

            $applied++;
        }

        wp_send_json_success([
            'applied' => $applied,
            'skipped' => count($skipped),
            'message' => sprintf(
                __('%d pagina\'s bijgewerkt, %d niet gevonden.', 'rankrepair'),
                $applied, count($skipped)
            ),
            'not_found' => array_slice($skipped, 0, 10), // max 10 tonen
        ]);
    }
}

// Initialiseer de AJAX handler
new RR_Ajax_Handler();
