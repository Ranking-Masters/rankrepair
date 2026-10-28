<?php
/**
 * Meta Manager Add-on — AI Meta Rewriter
 * Two-panel layout: page list (left) + edit area (right)
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Meta_Manager extends RR_Addon_Base {

    // SEO drempels (matchen SE Ranking + Yoast best practice)
    const TITLE_MIN = 30;
    const TITLE_MAX = 60;
    const DESC_MIN  = 70;
    const DESC_MAX  = 158;

    protected function init() {
        $this->slug        = 'meta-manager';
        $this->name        = __('AI Meta Rewriter', 'rankrepair');
        $this->description = __('Herschrijf meta titles en beschrijvingen automatisch met AI. Geoptimaliseerd voor hogere CTR.', 'rankrepair');
        $this->icon        = 'dashicons-edit-page';

        add_filter('rr_settings_sections', [$this, 'register_settings_section']);
    }

    public function register_settings_section(array $sections): array {
        $default_prompt = function_exists('rr_get_default_prompt_template') ? rr_get_default_prompt_template() : '';

        $sections[] = [
            'id'          => 'meta-manager',
            'title'       => __('AI Meta Rewriter', 'rankrepair'),
            'icon'        => 'meta-manager',
            'priority'    => 20,
            'description' => __('AI provider, prompt en paginatie instellingen', 'rankrepair'),
            'fields'   => [
                [
                    'name'        => 'rr_ai_provider',
                    'label'       => __('AI Provider', 'rankrepair'),
                    'type'        => 'select',
                    'default'     => 'google',
                    'options'     => [
                        'google'     => 'Google AI Studio',
                        'openrouter' => 'OpenRouter',
                    ],
                    'description' => __('<strong>Google AI Studio:</strong> gratis API key via <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com</a>.<br><strong>OpenRouter:</strong> via <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai</a> — geeft toegang tot honderden modellen.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_gemini_api_key',
                    'label'       => __('AI API Key', 'rankrepair'),
                    'type'        => 'apikey',
                    'placeholder' => 'AIza... of sk-or-v1-...',
                    'description' => __('Wordt gebruikt om automatisch meta titels en beschrijvingen te genereren. Laat leeg om de bestaande sleutel te bewaren.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_ai_model',
                    'label'       => __('AI Model', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Laat leeg voor de standaard. OpenRouter voorbeelden: <code>google/gemini-2.0-flash-001</code>, <code>google/gemini-2.5-pro-exp-03-25</code>.<br>Google AI Studio voorbeelden: <code>gemini-1.5-flash</code>, <code>gemini-1.5-pro</code>.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_gemini_prompt',
                    'label'       => __('AI Prompt', 'rankrepair'),
                    'type'        => 'textarea',
                    'rows'        => 22,
                    'default'     => $default_prompt,
                    'description' => __('Het volledige AI-prompt. Pas de sectie <strong>TOON &amp; STIJL</strong> aan voor jouw tone of voice.', 'rankrepair'),
                    'after'       => '<p><a href="#" id="rr-reset-prompt">↺ ' . esc_html__('Reset naar standaard', 'rankrepair') . '</a></p>',
                ],
                [
                    'name'    => 'rr_pagination_sep',
                    'label'   => __('Paginatie scheidingsteken', 'rankrepair'),
                    'type'    => 'radio',
                    'default' => '-',
                    'options' => [
                        '-' => __('streepje', 'rankrepair'),
                        '|' => __('pipe', 'rankrepair'),
                        '•' => __('bol', 'rankrepair'),
                        '*' => __('ster', 'rankrepair'),
                    ],
                ],
                [
                    'name'        => 'rr_pagination_label',
                    'label'       => __('Paginatie label', 'rankrepair'),
                    'type'        => 'text',
                    'default'     => 'Pagina',
                    'description' => sprintf(
                        /* translators: %s = live preview voorbeeld */
                        __('Wordt toegevoegd aan de meta titel op pagina 2+. Voorbeeld: %s', 'rankrepair'),
                        '<strong id="rr-pagination-preview">Blog titel ' . esc_html(get_option('rr_pagination_sep', '-')) . ' ' . esc_html(get_option('rr_pagination_label', 'Pagina')) . ' 2</strong>'
                    ),
                ],
            ],
        ];

        return $sections;
    }

    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return ['total' => 0, 'issues' => 0];
        }

        $total      = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $duplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_duplicate_title = 1 OR is_duplicate_description = 1");
        $pending    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        $missing    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE current_title = '' OR current_title IS NULL OR current_description = '' OR current_description IS NULL");
        $ai_ready   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE new_title != '' AND new_title IS NOT NULL AND status != 'applied'");
        $applied    = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'applied'");

        return [
            'total'      => $total,
            'issues'     => $duplicates + $missing,
            'duplicates' => $duplicates,
            'pending'    => $pending,
            'missing'    => $missing,
            'ai_ready'   => $ai_ready,
            'applied'    => $applied,
        ];
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'rankrepair-meta-manager') === false) return;

        $css_ver = filemtime(RR_PLUGIN_DIR . 'addons/meta-manager/meta-manager.css') ?: RR_VERSION;

        wp_enqueue_style(
            'rr-meta-manager',
            RR_PLUGIN_URL . 'addons/meta-manager/meta-manager.css',
            ['rr-admin-style'],
            $css_ver
        );

        // Registreer een leeg script als anker voor inline JS (Cloudflare kan inline scripts niet cachen)
        wp_register_script('rr-meta-manager', false, ['jquery', 'rr-admin-script'], null, true);
        wp_enqueue_script('rr-meta-manager');
        $js_content = file_get_contents(RR_PLUGIN_DIR . 'addons/meta-manager/meta-manager-v2.js');
        wp_add_inline_script('rr-meta-manager', $js_content);

        // URL-filter toggle
        wp_add_inline_script('rr-meta-manager', "
document.addEventListener('DOMContentLoaded', function() {
    var btn   = document.getElementById('rr-url-filter-toggle');
    var panel = document.getElementById('rr-url-filter-panel');
    if (!btn || !panel) return;
    btn.addEventListener('click', function() {
        panel.classList.toggle('open');
    });
});
        ");

        wp_localize_script('rr-meta-manager', 'rrMetaManager', [
            'hasAI'      => !empty(get_option('rr_gemini_api_key', '')),
            'settingsUrl' => admin_url('admin.php?page=rankrepair-settings'),
            'nonce'      => wp_create_nonce('rr_admin_nonce'),
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'strings'    => [
                'generating'     => __('Genereren...', 'rankrepair'),
                'generateTitle'  => __('✨ AI suggestie', 'rankrepair'),
                'bulkGenerating' => __('Bezig met genereren...', 'rankrepair'),
                'bulkGenerate'   => __('Alles herschrijven met AI', 'rankrepair'),
                'noKey'          => __('Geen AI API key ingesteld. Ga naar Instellingen.', 'rankrepair'),
                'confirmBulk'    => __('AI wordt aangeroepen voor alle pagina\'s. Doorgaan?', 'rankrepair'),
                'unsaved'        => __('Niet opgeslagen wijzigingen op', 'rankrepair'),
                'saved'          => __('Wijzigingen opgeslagen', 'rankrepair'),
            ],
        ]);
    }

    /**
     * Haal alle H1 tags op voor een post.
     * Ondersteunt: Classic editor, Gutenberg blocks, Elementor.
     * Geeft een array van gevonden H1 teksten terug (kan leeg zijn).
     */
    /**
     * Geeft een snelle initiële H1-schatting terug voor de server-side render.
     * De echte H1s worden lazy via AJAX (fetch_h1s) opgehaald zodra een pagina
     * wordt geselecteerd — dat is de enige betrouwbare methode voor thema-template H1s.
     */
    private function get_h1s(WP_Post $wp_post): array {
        $h1s = [];

        // Probeer H1s in post_content (werkt voor Classic + Gutenberg inline blocks)
        if (!empty($wp_post->post_content)) {
            $rendered = function_exists('do_blocks') ? do_blocks($wp_post->post_content) : $wp_post->post_content;
            preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $rendered, $m);
            foreach ($m[1] as $text) {
                $text = trim(wp_strip_all_tags($text));
                if ($text !== '' && !in_array($text, $h1s, true)) {
                    $h1s[] = $text;
                }
            }
        }

        // Elementor: heading-widgets met header_size = h1
        $el_data = get_post_meta($wp_post->ID, '_elementor_data', true);
        if (!empty($el_data)) {
            $this->extract_elementor_h1s(json_decode($el_data, true) ?: [], $h1s);
        }

        // ACF Flexible Content: 'content' layout 'hero' → sub-field 'title'
        // (thema rendert dit als <h1 class="hero__title">)
        if (empty($h1s) && function_exists('have_rows') && have_rows('content', $wp_post->ID)) {
            while (have_rows('content', $wp_post->ID)) {
                the_row();
                if (get_row_layout() !== 'hero') continue;
                $title = get_sub_field('title');
                if (!empty($title)) {
                    $text = trim(wp_strip_all_tags($title));
                    if ($text !== '') $h1s[] = $text;
                }
            }
        }

        // Laatste fallback: post_title
        if (empty($h1s) && !empty($wp_post->post_title)) {
            $h1s[] = trim(wp_strip_all_tags($wp_post->post_title));
        }

        return $h1s;
    }

    /** Recursief door Elementor JSON lopen en H1-heading widgets verzamelen */
    private function extract_elementor_h1s(array $elements, array &$h1s): void {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            // Heading widget met h1 tag
            if (
                ($el['elType'] ?? '') === 'widget' &&
                ($el['widgetType'] ?? '') === 'heading' &&
                strtolower($el['settings']['header_size'] ?? '') === 'h1'
            ) {
                $text = trim(wp_strip_all_tags($el['settings']['title'] ?? ''));
                if ($text !== '' && !in_array($text, $h1s, true)) {
                    $h1s[] = $text;
                }
            }
            // Recursief in children/elements
            foreach (['elements', 'children'] as $key) {
                if (!empty($el[$key]) && is_array($el[$key])) {
                    $this->extract_elementor_h1s($el[$key], $h1s);
                }
            }
        }
    }

    /**
     * Haal live WP data op voor alle gepubliceerde pagina's,
     * gecombineerd met opgeslagen AI-suggesties uit de RR tabel.
     */
    public function compute_stats(array $all_items): array {
        $total            = count($all_items);
        $missing_h1       = count(array_filter($all_items, fn($i) => empty(json_decode($i['current_h1'] ?? '[]', true) ?: [])));
        $duplicate_h1     = count(array_filter($all_items, fn($i) => count(json_decode($i['current_h1'] ?? '[]', true) ?: []) > 1));
        $missing_title    = count(array_filter($all_items, fn($i) => empty($i['current_title'])));
        $title_too_short  = count(array_filter($all_items, fn($i) => $i['title_length'] > 0 && $i['title_length'] < self::TITLE_MIN));
        $title_too_long   = count(array_filter($all_items, fn($i) => $i['title_length'] > self::TITLE_MAX));
        $duplicate_title  = count(array_filter($all_items, fn($i) => $i['is_duplicate_title']));
        $missing_desc     = count(array_filter($all_items, fn($i) => empty($i['current_description'])));
        $desc_too_short   = count(array_filter($all_items, fn($i) => $i['description_length'] > 0 && $i['description_length'] < self::DESC_MIN));
        $desc_too_long    = count(array_filter($all_items, fn($i) => $i['description_length'] > self::DESC_MAX));
        $duplicate_desc   = count(array_filter($all_items, fn($i) => $i['is_duplicate_description']));
        $ai_ready         = count(array_filter($all_items, fn($i) => !empty($i['new_title']) && $i['status'] !== 'applied'));
        $applied          = count(array_filter($all_items, fn($i) => $i['status'] === 'applied'));
        $issues           = count(array_filter($all_items, fn($i) =>
            empty($i['current_title']) || empty($i['current_description'])
            || $i['is_duplicate_title'] || $i['is_duplicate_description']
            || ($i['title_length'] > 0 && $i['title_length'] < self::TITLE_MIN)
            || ($i['description_length'] > 0 && $i['description_length'] < self::DESC_MIN)
            || $i['title_length'] > self::TITLE_MAX || $i['description_length'] > self::DESC_MAX
        ));

        return [
            'total'           => $total,
            'issues'          => $issues,
            'missing_h1'      => $missing_h1,
            'duplicate_h1'    => $duplicate_h1,
            'h1_ok'           => $total - $missing_h1 - $duplicate_h1,
            'missing_title'   => $missing_title,
            'title_too_short' => $title_too_short,
            'title_too_long'  => $title_too_long,
            'duplicate_title' => $duplicate_title,
            'title_ok'        => $total - $missing_title - $title_too_short - $title_too_long - $duplicate_title,
            'missing_desc'    => $missing_desc,
            'desc_too_short'  => $desc_too_short,
            'desc_too_long'   => $desc_too_long,
            'duplicate_desc'  => $duplicate_desc,
            'desc_ok'         => $total - $missing_desc - $desc_too_short - $desc_too_long - $duplicate_desc,
            'ai_ready'        => $ai_ready,
            'applied'         => $applied,
        ];
    }

    /**
     * Lost RankMath-template-variabelen (%title%, %sep%, …) op als RankMath beschikbaar
     * is; anders de ruwe waarde. Spiegel van de Yoast WPSEO_Replace_Vars-stap.
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

    public function load_live_items() {
        global $wpdb;
        $table = $wpdb->prefix . 'rr_meta_data';

        // 1. Live Yoast meta uit postmeta — alle publieke post-types inclusief CPTs
        $auto_post_types = get_post_types([
            'public'             => true,
            'publicly_queryable' => true,
        ], 'names');
        // Sluit attachment uit (media library, geen SEO-doel)
        unset($auto_post_types['attachment']);
        // Voeg 'page' altijd toe (publicly_queryable=false bij sommige themes)
        if (post_type_exists('page')) $auto_post_types['page'] = 'page';

        $post_types = apply_filters('rr_scan_post_types', array_values($auto_post_types));
        $post_types = array_filter(array_map('sanitize_key', $post_types));
        if (empty($post_types)) {
            $post_types = ['post', 'page', 'product'];
        }
        $post_type_in = "'" . implode("','", $post_types) . "'";

        $raw_posts = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_type,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_title'    THEN pm.meta_value END) AS yoast_title,
                MAX(CASE WHEN pm.meta_key = '_yoast_wpseo_metadesc' THEN pm.meta_value END) AS yoast_desc,
                MAX(CASE WHEN pm.meta_key = 'rank_math_title'       THEN pm.meta_value END) AS rm_title,
                MAX(CASE WHEN pm.meta_key = 'rank_math_description' THEN pm.meta_value END) AS rm_desc
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm
                ON p.ID = pm.post_id
                AND pm.meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description')
            WHERE p.post_status = 'publish'
              AND p.post_type IN ($post_type_in)
            GROUP BY p.ID
            ORDER BY p.post_type, p.ID ASC
        ");

        // 2. Opgeslagen AI-data per entity (composite key: type:id)
        $rr_stored = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $has_entity_col = (bool) $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'entity_type'");
            if ($has_entity_col) {
                $stored_rows = $wpdb->get_results("SELECT post_id, entity_type, new_title, new_description, status FROM $table", ARRAY_A);
            } else {
                $stored_rows = $wpdb->get_results("SELECT post_id, new_title, new_description, status FROM $table", ARRAY_A);
            }
            foreach ($stored_rows as $row) {
                if (empty($row['post_id'])) continue;
                $type = $row['entity_type'] ?? 'post';
                $rr_stored[$type . ':' . (int)$row['post_id']] = $row;
            }
        }

        // 3. Yoast template replacer + post-type templates
        $replacer = (defined('WPSEO_VERSION') && class_exists('WPSEO_Replace_Vars'))
            ? new WPSEO_Replace_Vars()
            : null;
        $wpseo_titles = get_option('wpseo_titles', []);

        // 3b. Live HTML scan resultaten (als beschikbaar) — overschrijven Yoast values
        $html_scan = get_option('rr_html_scan_results', []);
        if (!is_array($html_scan)) $html_scan = [];

        $all_items    = [];
        $title_counts = [];
        $desc_counts  = [];

        foreach ($raw_posts as $raw) {
            $wp_post = get_post($raw->ID);
            if (!$wp_post) continue;

            $url   = get_permalink($raw->ID);
            $title = $raw->yoast_title ?? '';
            $desc  = $raw->yoast_desc  ?? '';

            // Alle H1 tags ophalen (Gutenberg, Classic, Elementor)
            $h1s = $this->get_h1s($wp_post);
            $h1  = json_encode($h1s, JSON_UNESCAPED_UNICODE);

            // Yoast template variabelen oplossen (%%title%% etc.)
            if ($replacer) {
                if (!empty($title)) $title = $replacer->replace($title, $wp_post);
                if (!empty($desc))  $desc  = $replacer->replace($desc,  $wp_post);
            }

            // Fallback 1: Yoast post-type template uit wpseo_titles optie
            // (voor pagina's/producten zonder per-pagina Yoast override)
            if ((empty($title) || empty($desc)) && $replacer) {
                $pt = $wp_post->post_type;
                if (empty($title) && !empty($wpseo_titles["title-{$pt}"])) {
                    $title = $replacer->replace($wpseo_titles["title-{$pt}"], $wp_post);
                }
                if (empty($desc) && !empty($wpseo_titles["metadesc-{$pt}"])) {
                    $desc = $replacer->replace($wpseo_titles["metadesc-{$pt}"], $wp_post);
                }
            }

            // Fallback 2: Yoast surface API
            if (empty($title) || empty($desc)) {
                if (function_exists('YoastSEO')) {
                    try {
                        $ms = YoastSEO()->meta->for_post($raw->ID);
                        if ($ms) {
                            if (empty($title) && !empty($ms->title))       $title = $ms->title;
                            if (empty($desc)  && !empty($ms->description)) $desc  = $ms->description;
                        }
                    } catch (Exception $e) {}
                }
            }

            // RankMath: op RankMath-sites staat de SEO-meta in rank_math_* i.p.v. Yoast
            // (mirror van de write-kant). Vul aan zolang Yoast niets opleverde.
            if ((empty($title) || empty($desc)) && defined('RANK_MATH_VERSION')) {
                if (empty($title) && !empty($raw->rm_title)) $title = $this->rankmath_resolve($raw->rm_title, $wp_post);
                if (empty($desc)  && !empty($raw->rm_desc))  $desc  = $this->rankmath_resolve($raw->rm_desc,  $wp_post);
            }

            // Beschrijving laatste fallback: excerpt
            if (empty($desc)) {
                $excerpt = get_the_excerpt($raw->ID);
                if (!empty($excerpt)) $desc = $excerpt;
            }

            $stored = $rr_stored['post:' . $raw->ID] ?? [];

            // Override met live HTML scan indien beschikbaar
            $entity_id = 'post:' . $raw->ID;
            $html_scanned = false;
            if (isset($html_scan[$entity_id])) {
                $h = $html_scan[$entity_id];
                if (!empty($h['html_title'])) $title = $h['html_title'];
                if (!empty($h['html_desc']))  $desc  = $h['html_desc'];
                $html_scanned = true;
            }

            $item = [
                'id'                       => $entity_id,
                'post_id'                  => $raw->ID,
                'entity_type'              => 'post',
                'entity_subtype'           => $raw->post_type,
                'url'                      => $url,
                'current_h1'               => $h1,
                'current_title'            => $title,
                'current_description'      => $desc,
                'html_scanned'             => $html_scanned,
                'new_title'                => $stored['new_title']       ?? '',
                'new_description'          => $stored['new_description'] ?? '',
                'status'                   => $stored['status']          ?? 'pending',
                'is_duplicate_title'       => 0,
                'is_duplicate_description' => 0,
                'title_length'             => mb_strlen($title),
                'description_length'       => mb_strlen($desc),
            ];

            if (!empty($title)) $title_counts[$title] = ($title_counts[$title] ?? 0) + 1;
            if (!empty($desc))  $desc_counts[$desc]   = ($desc_counts[$desc]   ?? 0) + 1;

            $all_items[] = $item;
        }

        // 4. Termen (taxonomies) — alle publieke taxonomieën inclusief custom (bv. /merk/, /brand/)
        $auto_taxonomies = get_taxonomies([
            'public'             => true,
            'publicly_queryable' => true,
        ], 'names');
        // Sluit interne / niet-relevante taxonomieën uit
        $skip = ['post_format', 'product_shipping_class', 'nav_menu', 'link_category'];
        $auto_taxonomies = array_diff($auto_taxonomies, $skip);
        $taxonomies = apply_filters('rr_scan_taxonomies', array_values($auto_taxonomies));
        $taxonomies = array_filter(array_map('sanitize_key', $taxonomies), fn($t) => taxonomy_exists($t));

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (is_wp_error($terms) || empty($terms)) continue;

            foreach ($terms as $term) {
                $url = get_term_link($term);
                if (is_wp_error($url)) continue;

                // Yoast term meta: eerst directe term_meta (Yoast 14+)
                $title = get_term_meta($term->term_id, '_yoast_wpseo_title',   true) ?: '';
                $desc  = get_term_meta($term->term_id, '_yoast_wpseo_metadesc', true) ?: '';

                // Yoast 13- fallback: wpseo_taxonomy_meta option
                if (empty($title) || empty($desc)) {
                    $tax_meta = get_option('wpseo_taxonomy_meta', []);
                    $saved    = $tax_meta[$taxonomy][$term->term_id] ?? [];
                    if (empty($title) && !empty($saved['wpseo_title']))    $title = $saved['wpseo_title'];
                    if (empty($desc)  && !empty($saved['wpseo_desc']))     $desc  = $saved['wpseo_desc'];
                }

                // Template resolve
                if ($replacer) {
                    if (!empty($title)) $title = $replacer->replace($title, $term);
                    if (!empty($desc))  $desc  = $replacer->replace($desc,  $term);
                }

                // Fallback: taxonomy-template uit wpseo_titles
                if ($replacer && (empty($title) || empty($desc))) {
                    if (empty($title) && !empty($wpseo_titles["title-tax-{$taxonomy}"])) {
                        $title = $replacer->replace($wpseo_titles["title-tax-{$taxonomy}"], $term);
                    }
                    if (empty($desc) && !empty($wpseo_titles["metadesc-tax-{$taxonomy}"])) {
                        $desc = $replacer->replace($wpseo_titles["metadesc-tax-{$taxonomy}"], $term);
                    }
                }

                // Yoast surface API voor termen
                if ((empty($title) || empty($desc)) && function_exists('YoastSEO')) {
                    try {
                        $ms = YoastSEO()->meta->for_term($term->term_id);
                        if ($ms) {
                            if (empty($title) && !empty($ms->title))       $title = $ms->title;
                            if (empty($desc)  && !empty($ms->description)) $desc  = $ms->description;
                        }
                    } catch (Exception $e) {}
                }

                // RankMath term-meta (mirror van de write-kant).
                if ((empty($title) || empty($desc)) && defined('RANK_MATH_VERSION')) {
                    if (empty($title)) {
                        $rm = get_term_meta($term->term_id, 'rank_math_title', true);
                        if (!empty($rm)) $title = $this->rankmath_resolve($rm, $term);
                    }
                    if (empty($desc)) {
                        $rm = get_term_meta($term->term_id, 'rank_math_description', true);
                        if (!empty($rm)) $desc = $this->rankmath_resolve($rm, $term);
                    }
                }

                // Description laatste fallback: term description
                if (empty($desc) && !empty($term->description)) {
                    $desc = wp_strip_all_tags($term->description);
                }

                $stored = $rr_stored['term:' . $term->term_id] ?? [];

                // Override met live HTML scan indien beschikbaar
                $entity_id = 'term:' . $term->term_id;
                $html_scanned = false;
                if (isset($html_scan[$entity_id])) {
                    $h = $html_scan[$entity_id];
                    if (!empty($h['html_title'])) $title = $h['html_title'];
                    if (!empty($h['html_desc']))  $desc  = $h['html_desc'];
                    $html_scanned = true;
                }

                $item = [
                    'id'                       => $entity_id,
                    'post_id'                  => $term->term_id,
                    'entity_type'              => 'term',
                    'entity_subtype'           => $taxonomy,
                    'url'                      => $url,
                    'current_h1'               => json_encode([$term->name], JSON_UNESCAPED_UNICODE),
                    'current_title'            => $title,
                    'current_description'      => $desc,
                    'html_scanned'             => $html_scanned,
                    'new_title'                => $stored['new_title']       ?? '',
                    'new_description'          => $stored['new_description'] ?? '',
                    'status'                   => $stored['status']          ?? 'pending',
                    'is_duplicate_title'       => 0,
                    'is_duplicate_description' => 0,
                    'title_length'             => mb_strlen($title),
                    'description_length'       => mb_strlen($desc),
                ];

                if (!empty($title)) $title_counts[$title] = ($title_counts[$title] ?? 0) + 1;
                if (!empty($desc))  $desc_counts[$desc]   = ($desc_counts[$desc]   ?? 0) + 1;

                $all_items[] = $item;
            }
        }

        // Duplicaten markeren
        foreach ($all_items as &$item) {
            if (!empty($item['current_title']) && ($title_counts[$item['current_title']] ?? 0) > 1) {
                $item['is_duplicate_title'] = 1;
            }
            if (!empty($item['current_description']) && ($desc_counts[$item['current_description']] ?? 0) > 1) {
                $item['is_duplicate_description'] = 1;
            }
        }
        unset($item);

        return $all_items;
    }

    public function render_page() {
        global $wpdb;

        $filter       = isset($_GET['filter'])       ? sanitize_text_field($_GET['filter'])       : 'all';
        $search       = isset($_GET['s'])            ? sanitize_text_field($_GET['s'])            : '';
        // Normaliseer url_patterns: newlines en komma's beide accepteren, opslaan als kommalijst
        $url_patterns_raw = isset($_GET['url_patterns']) ? wp_unslash($_GET['url_patterns']) : '';
        $url_patterns_raw = str_replace(["\r\n", "\r", "\n"], ',', $url_patterns_raw);
        $url_patterns     = implode(',', array_filter(array_map('sanitize_text_field', explode(',', $url_patterns_raw))));

        $all_items = $this->load_live_items();

        // Filter toepassen
        $items       = $all_items;
        $h1_count    = fn($i) => count(json_decode($i['current_h1'] ?? '[]', true) ?: []);
        $filter_map = [
            'issues'           => fn($i) => empty($i['current_title']) || empty($i['current_description']) || $i['is_duplicate_title'] || $i['is_duplicate_description'] || ($i['title_length'] > 0 && $i['title_length'] < self::TITLE_MIN) || ($i['description_length'] > 0 && $i['description_length'] < self::DESC_MIN) || $i['title_length'] > self::TITLE_MAX || $i['description_length'] > self::DESC_MAX,
            'ai_ready'         => fn($i) => !empty($i['new_title']) && $i['status'] !== 'applied',
            'ok'               => fn($i) => $i['status'] === 'applied',
            'missing_h1'       => fn($i) => $h1_count($i) === 0,
            'duplicate_h1'     => fn($i) => $h1_count($i) > 1,
            'h1_ok'            => fn($i) => $h1_count($i) === 1,
            'missing_title'    => fn($i) => empty($i['current_title']),
            'title_too_short'  => fn($i) => $i['title_length'] > 0 && $i['title_length'] < self::TITLE_MIN,
            'title_too_long'   => fn($i) => $i['title_length'] > self::TITLE_MAX,
            'duplicate_title'  => fn($i) => (bool) $i['is_duplicate_title'],
            'title_ok'         => fn($i) => !empty($i['current_title']) && $i['title_length'] >= self::TITLE_MIN && $i['title_length'] <= self::TITLE_MAX && !$i['is_duplicate_title'],
            'missing_desc'     => fn($i) => empty($i['current_description']),
            'desc_too_short'   => fn($i) => $i['description_length'] > 0 && $i['description_length'] < self::DESC_MIN,
            'desc_too_long'    => fn($i) => $i['description_length'] > self::DESC_MAX,
            'duplicate_desc'   => fn($i) => (bool) $i['is_duplicate_description'],
            'desc_ok'          => fn($i) => !empty($i['current_description']) && $i['description_length'] >= self::DESC_MIN && $i['description_length'] <= self::DESC_MAX && !$i['is_duplicate_description'],
        ];
        if (isset($filter_map[$filter])) {
            $items = array_values(array_filter($items, $filter_map[$filter]));
        }

        // Zoekfilter
        if (!empty($search)) {
            $sl = strtolower($search);
            $items = array_values(array_filter($items, function($i) use ($sl) {
                return stripos($i['url'], $sl) !== false || stripos($i['current_title'], $sl) !== false;
            }));
        }

        // URL-patroon filter (kommagescheiden, bijv. "/slaapbank/, /matras/")
        if (!empty($url_patterns)) {
            $patterns = array_filter(array_map('trim', explode(',', $url_patterns)));
            if (!empty($patterns)) {
                $items = array_values(array_filter($items, function($i) use ($patterns) {
                    foreach ($patterns as $p) {
                        if (stripos($i['url'], $p) !== false) return true;
                    }
                    return false;
                }));
            }
        }

        // Paginering
        $per_page       = 10;
        $paged          = max(1, isset($_GET['paged']) ? (int)$_GET['paged'] : 1);
        $total_filtered = count($items);
        $total_pages    = max(1, (int)ceil($total_filtered / $per_page));
        $paged          = min($paged, $total_pages);

        // Alle gefilterde IDs doorgeven aan JS (voor bulk AI over meerdere pagina's)
        // IDs zijn composite strings: "post:123" of "term:45"
        $all_filtered_ids = array_column($items, 'id');

        // Per fout-type: aparte ID-sets voor de bulk AI-knop
        $ids_missing_title = array_values(array_column(array_filter($all_items, fn($i) => empty($i['current_title'])),                                       'id'));
        $ids_missing_desc  = array_values(array_column(array_filter($all_items, fn($i) => empty($i['current_description'])),                                  'id'));
        $ids_dup_title     = array_values(array_column(array_filter($all_items, fn($i) => (bool)$i['is_duplicate_title']),                                    'id'));
        $ids_dup_desc      = array_values(array_column(array_filter($all_items, fn($i) => (bool)$i['is_duplicate_description']),                              'id'));
        $ids_long_title    = array_values(array_column(array_filter($all_items, fn($i) => $i['title_length']       > self::TITLE_MAX),                       'id'));
        $ids_long_desc     = array_values(array_column(array_filter($all_items, fn($i) => $i['description_length'] > self::DESC_MAX),                        'id'));
        $ids_short_title   = array_values(array_column(array_filter($all_items, fn($i) => $i['title_length']       > 0 && $i['title_length']       < self::TITLE_MIN), 'id'));
        $ids_short_desc    = array_values(array_column(array_filter($all_items, fn($i) => $i['description_length'] > 0 && $i['description_length'] < self::DESC_MIN),  'id'));
        $all_issue_ids     = array_values(array_unique(array_merge($ids_missing_title, $ids_missing_desc, $ids_dup_title, $ids_dup_desc, $ids_long_title, $ids_long_desc, $ids_short_title, $ids_short_desc)));

        wp_add_inline_script('rr-meta-manager',
            'rrMetaManager.allFilteredIds = '   . wp_json_encode($all_filtered_ids) . ';' .
            'rrMetaManager.allIssueIds = '      . wp_json_encode($all_issue_ids)    . ';' .
            'rrMetaManager.activeFilter = '     . wp_json_encode($filter)           . ';' .
            'rrMetaManager.issueIds = {'         .
                'missingTitle:'  . wp_json_encode($ids_missing_title) . ',' .
                'missingDesc:'   . wp_json_encode($ids_missing_desc)  . ',' .
                'dupTitle:'      . wp_json_encode($ids_dup_title)     . ',' .
                'dupDesc:'       . wp_json_encode($ids_dup_desc)      . ',' .
                'longTitle:'     . wp_json_encode($ids_long_title)    . ',' .
                'longDesc:'      . wp_json_encode($ids_long_desc)     . ',' .
                'shortTitle:'    . wp_json_encode($ids_short_title)   . ',' .
                'shortDesc:'     . wp_json_encode($ids_short_desc)    .
            '};',
            'before'
        );

        $items = array_slice($items, ($paged - 1) * $per_page, $per_page);

        $stats = $this->compute_stats($all_items);

        $site_domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $has_ai      = !empty(get_option('rr_gemini_api_key', ''));

        // Eerste item standaard geselecteerd
        $selected_id   = isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0;
        $selected_item = null;
        if ($items) {
            if ($selected_id) {
                foreach ($items as $it) {
                    if ((int)$it['post_id'] === $selected_id) { $selected_item = $it; break; }
                }
            }
            if (!$selected_item) $selected_item = $items[0];
        }

        ?>
        <div class="wrap rr-wrap" style="padding:0;max-width:none">

            <!-- ── Top Header Bar ─────────────────────────── -->
            <div class="rr-mm-header">
                <div class="rr-mm-header-left">
                    <img src="<?php echo esc_url(RR_PLUGIN_URL . 'assets/images/logoRankrepair.svg'); ?>" class="rr-logo-img" alt="RankRepair" height="32">
                    <span class="rr-header-divider">—</span>
                    <h1 class="rr-mm-name"><?php _e('AI Meta Rewriter', 'rankrepair'); ?></h1>
                </div>
                <div class="rr-mm-header-right">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair-meta-manager&filter=' . esc_attr($filter))); ?>" class="rr-btn rr-btn-secondary button">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                        <?php _e('Ververs', 'rankrepair'); ?>
                    </a>
                    <button type="button" id="rr-html-scan-btn" class="rr-btn rr-btn-secondary button" title="<?php esc_attr_e('Haal de gerenderde HTML van elke URL op (komt overeen met SE Ranking)', 'rankrepair'); ?>">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        <?php _e('Live HTML scan', 'rankrepair'); ?>
                    </button>
                    <button type="button" id="rr-csv-upload-btn" class="rr-btn rr-btn-secondary button">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?php _e('CSV uploaden', 'rankrepair'); ?>
                    </button>
                    <input type="file" id="rr-csv-upload-input" accept=".csv,.txt" style="display:none">
                    <?php if ($has_ai): ?>
                    <button type="button" id="rr-gemini-bulk" class="rr-btn rr-btn-gradient button">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        <?php _e('Fouten', 'rankrepair'); ?> <span class="rr-bulk-count" data-stat="issues"><?php echo esc_html(count($all_issue_ids)); ?></span> <?php _e('herschrijven met AI', 'rankrepair'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Stats Bar ─────────────────────────────── -->
            <div class="rr-mm-stats">
                <!-- Rij 1: samenvatting -->
                <div class="rr-mm-stats-row rr-mm-stats-row--summary">
                    <?php $base_sum_url = admin_url('admin.php?page=rankrepair-meta-manager'); ?>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'all', $base_sum_url)); ?>" class="rr-mm-stat-sum<?php echo $filter === 'all' ? ' rr-mm-stat-sum--active' : ''; ?>">
                        <span class="rr-mm-stat-sum-num" data-stat="total"><?php echo esc_html($stats['total']); ?></span>
                        <span class="rr-mm-stat-sum-label"><?php _e("Pagina's geanalyseerd", 'rankrepair'); ?></span>
                    </a>
                    <div class="rr-mm-stat-divider"></div>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'issues', $base_sum_url)); ?>" class="rr-mm-stat-sum rr-mm-stat-sum--danger<?php echo $filter === 'issues' ? ' rr-mm-stat-sum--active' : ''; ?>">
                        <span class="rr-mm-stat-sum-num" data-stat="issues"><?php echo esc_html($stats['issues']); ?></span>
                        <span class="rr-mm-stat-sum-label"><?php _e('Problemen gevonden', 'rankrepair'); ?></span>
                    </a>
                    <div class="rr-mm-stat-divider"></div>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'ai_ready', $base_sum_url)); ?>" class="rr-mm-stat-sum rr-mm-stat-sum--indigo<?php echo $filter === 'ai_ready' ? ' rr-mm-stat-sum--active' : ''; ?>">
                        <span class="rr-mm-stat-sum-num" data-stat="ai_ready"><?php echo esc_html($stats['ai_ready']); ?></span>
                        <span class="rr-mm-stat-sum-label"><?php _e('AI suggesties klaar', 'rankrepair'); ?></span>
                    </a>
                    <div class="rr-mm-stat-divider"></div>
                    <a href="<?php echo esc_url(add_query_arg('filter', 'ok', $base_sum_url)); ?>" class="rr-mm-stat-sum rr-mm-stat-sum--success<?php echo $filter === 'ok' ? ' rr-mm-stat-sum--active' : ''; ?>">
                        <span class="rr-mm-stat-sum-num" data-stat="applied"><?php echo esc_html($stats['applied']); ?></span>
                        <span class="rr-mm-stat-sum-label"><?php _e('Toegepast', 'rankrepair'); ?></span>
                    </a>
                </div>
                <!-- Rij 2: uitsplitsing per categorie -->
                <div class="rr-mm-stats-row rr-mm-stats-row--breakdown">
                    <?php
                    $pill_data = [
                        'H1' => [
                            [$stats['missing_h1'],   'Ontbreekt', 'danger',  'missing_h1'],
                            [$stats['duplicate_h1'], 'Dubbel',    'warning', 'duplicate_h1'],
                            [$stats['h1_ok'],        'OK',        'ok',      'h1_ok'],
                        ],
                        __('Meta titel', 'rankrepair') => [
                            [$stats['missing_title'],    'Ontbreekt', 'danger',  'missing_title'],
                            [$stats['title_too_short'],  'Te kort',   'warning', 'title_too_short'],
                            [$stats['title_too_long'],   'Te lang',   'warning', 'title_too_long'],
                            [$stats['duplicate_title'],  'Dubbel',    'warning', 'duplicate_title'],
                            [$stats['title_ok'],         'OK',        'ok',      'title_ok'],
                        ],
                        __('Meta omschrijving', 'rankrepair') => [
                            [$stats['missing_desc'],    'Ontbreekt', 'danger',  'missing_desc'],
                            [$stats['desc_too_short'],  'Te kort',   'warning', 'desc_too_short'],
                            [$stats['desc_too_long'],   'Te lang',   'warning', 'desc_too_long'],
                            [$stats['duplicate_desc'],  'Dubbel',    'warning', 'duplicate_desc'],
                            [$stats['desc_ok'],         'OK',        'ok',      'desc_ok'],
                        ],
                    ];
                    $base_url = admin_url('admin.php?page=rankrepair-meta-manager');
                    foreach ($pill_data as $cat => $pills): ?>
                    <div class="rr-mm-stat-cat">
                        <span class="rr-mm-stat-cat-label"><?php echo esc_html($cat); ?></span>
                        <div class="rr-mm-stat-pills">
                            <?php foreach ($pills as [$num, $label, $type, $fkey]):
                                $pill_url    = esc_url(add_query_arg('filter', $fkey, $base_url));
                                $active_cls  = ($filter === $fkey) ? ' rr-mm-pill--active' : '';
                                $disabled    = $num <= 0;
                            ?>
                            <?php if ($disabled): ?>
                            <span class="rr-mm-pill rr-mm-pill--<?php echo $type; ?><?php echo $active_cls; ?> rr-mm-pill--disabled" data-pill="<?php echo esc_attr($fkey); ?>">
                                <strong data-stat="<?php echo esc_attr($fkey); ?>"><?php echo esc_html($num); ?></strong> <?php echo esc_html($label); ?>
                            </span>
                            <?php else: ?>
                            <a href="<?php echo $pill_url; ?>" class="rr-mm-pill rr-mm-pill--<?php echo $type; ?><?php echo $active_cls; ?>" data-pill="<?php echo esc_attr($fkey); ?>">
                                <strong data-stat="<?php echo esc_attr($fkey); ?>"><?php echo esc_html($num); ?></strong> <?php echo esc_html($label); ?>
                            </a>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Two-Panel Body ─────────────────────────── -->
            <?php if (!empty($items)): ?>
            <div class="rr-mm-body">

                <!-- Left: Page list -->
                <div class="rr-mm-sidebar">
                    <div class="rr-mm-sidebar-header">
                        <span class="rr-mm-sidebar-title"><?php _e('Pagina\'s', 'rankrepair'); ?></span>
                        <span class="rr-mm-sidebar-count"><?php echo esc_html($stats['total']); ?> <?php _e('pagina\'s', 'rankrepair'); ?></span>
                    </div>

                    <!-- Search -->
                    <form method="get" class="rr-mm-search">
                        <input type="hidden" name="page" value="rankrepair-meta-manager">
                        <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                        <?php if (!empty($url_patterns)): ?>
                        <input type="hidden" name="url_patterns" value="<?php echo esc_attr($url_patterns); ?>">
                        <?php endif; ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Zoek pagina...', 'rankrepair'); ?>">
                    </form>

                    <!-- Filter tabs -->
                    <div class="rr-mm-tabs">
                        <?php
                        $base = '?page=rankrepair-meta-manager' . (!empty($url_patterns) ? '&url_patterns=' . urlencode($url_patterns) : '');
                        ?>
                        <a href="<?php echo $base; ?>&filter=all" class="rr-mm-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">Alle (<span data-stat="total"><?php echo (int) $stats['total']; ?></span>)</a>
                        <a href="<?php echo $base; ?>&filter=issues" class="rr-mm-tab <?php echo $filter === 'issues' ? 'active' : ''; ?>">Problemen (<span data-stat="issues"><?php echo (int) $stats['issues']; ?></span>)</a>
                        <a href="<?php echo $base; ?>&filter=ai_ready" class="rr-mm-tab <?php echo $filter === 'ai_ready' ? 'active' : ''; ?>">AI klaar (<span data-stat="ai_ready"><?php echo (int) ($stats['ai_ready'] ?? 0); ?></span>)</a>
                        <a href="<?php echo $base; ?>&filter=ok" class="rr-mm-tab <?php echo $filter === 'ok' ? 'active' : ''; ?>">OK (<span data-stat="applied"><?php echo (int) ($stats['applied'] ?? 0); ?></span>)</a>
                    </div>

                    <!-- URL-patroon filter -->
                    <div class="rr-mm-url-filter">
                        <button type="button" class="rr-mm-url-filter-toggle <?php echo !empty($url_patterns) ? 'active' : ''; ?>" id="rr-url-filter-toggle">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            <?php _e('URL filter', 'rankrepair'); ?>
                            <?php if (!empty($url_patterns)): ?><span class="rr-mm-url-filter-dot"></span><?php endif; ?>
                        </button>
                        <div class="rr-mm-url-filter-panel <?php echo !empty($url_patterns) ? 'open' : ''; ?>" id="rr-url-filter-panel">
                            <form method="get">
                                <input type="hidden" name="page" value="rankrepair-meta-manager">
                                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                                <?php if (!empty($search)): ?>
                                <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                                <?php endif; ?>
                                <textarea name="url_patterns" class="rr-mm-url-filter-input" rows="3" placeholder="/slaapbank/&#10;/matras/&#10;/product/"><?php echo esc_textarea($url_patterns ? implode("\n", array_map('trim', explode(',', $url_patterns))) : ''); ?></textarea>
                                <p class="rr-mm-url-filter-hint"><?php _e('Eén patroon per regel. Pagina\'s die één van de patronen bevatten worden getoond.', 'rankrepair'); ?></p>
                                <div class="rr-mm-url-filter-actions">
                                    <button type="submit" class="rr-btn rr-btn-primary rr-btn-xs button"><?php _e('Toepassen', 'rankrepair'); ?></button>
                                    <?php if (!empty($url_patterns)): ?>
                                    <a href="?page=rankrepair-meta-manager&filter=<?php echo esc_attr($filter); ?>" class="rr-btn rr-btn-ghost rr-btn-xs button"><?php _e('Wissen', 'rankrepair'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Kolom headers -->
                    <div class="rr-mm-col-header">
                        <span class="rr-mm-col-page"><?php _e('Pagina', 'rankrepair'); ?></span>
                        <span class="rr-mm-col-badges">
                            <span>H1</span><span><?php _e('Title', 'rankrepair'); ?></span><span><?php _e('Desc', 'rankrepair'); ?></span>
                        </span>
                    </div>

                    <!-- Page rows -->
                    <div class="rr-mm-page-list" id="rr-mm-page-list">
                        <?php
                        // Bouw peer-maps vanuit $all_items (in-memory, correct berekend)
                        $dup_title_map = [];
                        $dup_desc_map  = [];
                        foreach ($all_items as $ai) {
                            if (!empty($ai['is_duplicate_title']) && !empty($ai['current_title']))
                                $dup_title_map[$ai['current_title']][$ai['id']] = $ai['url'];
                            if (!empty($ai['is_duplicate_description']) && !empty($ai['current_description']))
                                $dup_desc_map[$ai['current_description']][$ai['id']] = $ai['url'];
                        }
                        ?>
                        <?php foreach ($items as $item):
                            $is_selected = ($selected_item && $item['id'] === $selected_item['id']);
                            $h1s_arr  = json_decode($item['current_h1'] ?? '[]', true) ?: [];
                            $h1_count = count($h1s_arr);
                            $has_new  = !empty($item['new_title']);
                            $is_ok    = $item['status'] === 'applied';

                            // H1 badge
                            if ($h1_count === 0)       { $h1b = ['✗', 'danger']; }
                            elseif ($h1_count > 1)     { $h1b = [$h1_count.'×', 'warning']; }
                            else                       { $h1b = ['✓', 'ok']; }

                            // Title badge
                            if (empty($item['current_title']))                        { $tb = ['✗', 'danger']; }
                            elseif ($has_new && !$is_ok)                              { $tb = ['✨', 'indigo']; }
                            elseif ($item['is_duplicate_title'])                      { $tb = ['2×', 'warning']; }
                            elseif ($item['title_length'] > 60)                       { $tb = ['≈', 'warning']; }
                            elseif ($item['title_length'] < 30)                       { $tb = ['≈', 'warning']; }
                            else                                                       { $tb = ['✓', 'ok']; }

                            // Desc badge
                            if (empty($item['current_description']))                  { $db = ['✗', 'danger']; }
                            elseif (!empty($item['new_description']) && !$is_ok)     { $db = ['✨', 'indigo']; }
                            elseif ($item['is_duplicate_description'])               { $db = ['2×', 'warning']; }
                            elseif ($item['description_length'] > 160)              { $db = ['≈', 'warning']; }
                            elseif ($item['description_length'] < 70)                { $db = ['≈', 'warning']; }
                            else                                                      { $db = ['✓', 'ok']; }

                            // Overall dot + icon
                            $any_danger  = $h1b[1]==='danger'  || $tb[1]==='danger'  || $db[1]==='danger';
                            $any_warning = $h1b[1]==='warning' || $tb[1]==='warning' || $db[1]==='warning';
                            $any_indigo  = $h1b[1]==='indigo'  || $tb[1]==='indigo'  || $db[1]==='indigo';
                            if ($is_ok)          { $dot_color = '#10b981'; $overall = ['✓', 'ok']; }
                            elseif ($any_indigo) { $dot_color = '#6366f1'; $overall = ['✨', 'indigo']; }
                            elseif ($any_danger) { $dot_color = '#ef4444'; $overall = ['!', 'danger']; }
                            elseif ($any_warning){ $dot_color = '#f59e0b'; $overall = ['~', 'warning']; }
                            else                 { $dot_color = '#10b981'; $overall = ['✓', 'ok']; }

                            $path   = wp_parse_url($item['url'], PHP_URL_PATH) ?? '/';
                            $parts  = array_filter(explode('/', $path));
                            $name   = !empty($item['current_title'])
                                ? wp_trim_words($item['current_title'], 5, '')
                                : (end($parts) ? ucfirst(str_replace(['-','_'], ' ', end($parts))) : 'Homepage');
                            $display_url = $path ?: '/';
                        ?>
                        <div class="rr-mm-page-row <?php echo $is_selected ? 'active' : ''; ?>"
                             data-id="<?php echo esc_attr($item['id']); ?>"
                             data-h1="<?php echo esc_attr($item['current_h1'] ?? ''); ?>"
                             data-title="<?php echo esc_attr($item['current_title']); ?>"
                             data-desc="<?php echo esc_attr($item['current_description']); ?>"
                             data-new-title="<?php echo esc_attr($item['new_title']); ?>"
                             data-new-desc="<?php echo esc_attr($item['new_description']); ?>"
                             data-url="<?php echo esc_attr($item['url']); ?>"
                             data-path="<?php echo esc_attr($display_url); ?>"
                             data-name="<?php echo esc_attr($name); ?>"
                             data-status="<?php echo esc_attr($item['status']); ?>"
                             data-dup-title-peers="<?php
                                $tp = [];
                                if (!empty($item['is_duplicate_title']) && isset($dup_title_map[$item['current_title']])) {
                                    foreach ($dup_title_map[$item['current_title']] as $uid => $u) {
                                        if ($uid != $item['id']) $tp[] = $u;
                                    }
                                }
                                echo esc_attr(wp_json_encode($tp));
                             ?>"
                             data-dup-desc-peers="<?php
                                $dp = [];
                                if (!empty($item['is_duplicate_description']) && isset($dup_desc_map[$item['current_description']])) {
                                    foreach ($dup_desc_map[$item['current_description']] as $uid => $u) {
                                        if ($uid != $item['id']) $dp[] = $u;
                                    }
                                }
                                echo esc_attr(wp_json_encode($dp));
                             ?>"
                             onclick="rrMMSelectPage(this)">
                            <span class="rr-mm-row-dot" style="background:<?php echo esc_attr($dot_color); ?>"></span>
                            <div class="rr-mm-page-info">
                                <p class="rr-mm-page-name">
                                    <?php echo esc_html($name); ?>
                                    <?php if (($item['entity_type'] ?? 'post') === 'term'): ?>
                                    <?php
                                    $tax_labels = [
                                        'product_cat' => __('Productcat.', 'rankrepair'),
                                        'product_tag' => __('Producttag', 'rankrepair'),
                                        'category'    => __('Categorie', 'rankrepair'),
                                        'post_tag'    => __('Tag', 'rankrepair'),
                                    ];
                                    $label = $tax_labels[$item['entity_subtype'] ?? ''] ?? __('Term', 'rankrepair');
                                    ?>
                                    <span class="rr-mm-type-badge"><?php echo esc_html($label); ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="rr-mm-page-url"><?php echo esc_html($display_url); ?></p>
                            </div>
                            <div class="rr-mm-field-badges">
                                <span class="rr-mm-fbadge rr-mm-fbadge--<?php echo $h1b[1]; ?>"><?php echo $h1b[0]; ?></span>
                                <span class="rr-mm-fbadge rr-mm-fbadge--<?php echo $tb[1]; ?>"><?php echo $tb[0]; ?></span>
                                <span class="rr-mm-fbadge rr-mm-fbadge--<?php echo $db[1]; ?>"><?php echo $db[0]; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Paginering -->
                    <?php if ($total_pages > 1): ?>
                    <div class="rr-mm-pagination">
                        <?php
                        $base_url = add_query_arg([
                            'page'   => 'rankrepair-meta-manager',
                            'filter' => $filter,
                        ] + (!empty($search) ? ['s' => $search] : []), admin_url('admin.php'));

                        // Vorige
                        if ($paged > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $paged - 1, $base_url)); ?>" class="rr-mm-page-num">‹</a>
                        <?php endif;

                        // Nummers (max 7 zichtbaar)
                        $range = 3;
                        $start = max(1, $paged - $range);
                        $end   = min($total_pages, $paged + $range);

                        if ($start > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', 1, $base_url)); ?>" class="rr-mm-page-num">1</a>
                            <?php if ($start > 2): ?><span class="rr-mm-page-ellipsis">…</span><?php endif;
                        endif;

                        for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $p, $base_url)); ?>"
                               class="rr-mm-page-num <?php echo $p === $paged ? 'active' : ''; ?>">
                                <?php echo $p; ?>
                            </a>
                        <?php endfor;

                        if ($end < $total_pages):
                            if ($end < $total_pages - 1): ?><span class="rr-mm-page-ellipsis">…</span><?php endif; ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $total_pages, $base_url)); ?>" class="rr-mm-page-num"><?php echo $total_pages; ?></a>
                        <?php endif;

                        // Volgende
                        if ($paged < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $paged + 1, $base_url)); ?>" class="rr-mm-page-num">›</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Legenda -->
                    <div class="rr-mm-legend">
                        <span class="rr-mm-legend-title"><?php _e('Legenda', 'rankrepair'); ?></span>
                        <div class="rr-mm-legend-items">
                            <span class="rr-mm-legend-item"><span class="rr-mm-fbadge rr-mm-fbadge--danger">✗</span><?php _e('Ontbreekt', 'rankrepair'); ?></span>
                            <span class="rr-mm-legend-item"><span class="rr-mm-fbadge rr-mm-fbadge--warning">≈</span><?php _e('Te lang/kort', 'rankrepair'); ?></span>
                            <span class="rr-mm-legend-item"><span class="rr-mm-fbadge rr-mm-fbadge--warning">2×</span><?php _e('Dubbel', 'rankrepair'); ?></span>
                            <span class="rr-mm-legend-item"><span class="rr-mm-fbadge rr-mm-fbadge--indigo">✨</span>AI klaar</span>
                            <span class="rr-mm-legend-item"><span class="rr-mm-fbadge rr-mm-fbadge--ok">✓</span>OK</span>
                        </div>
                    </div>
                </div>

                <!-- Right: Edit area -->
                <div class="rr-mm-main" id="rr-mm-main">
                    <?php if ($selected_item): ?>
                        <?php $this->render_edit_panel($selected_item, $site_domain, $has_ai, $dup_title_map, $dup_desc_map); ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── Footer Bar ────────────────────────────── -->
            <div class="rr-mm-footer" id="rr-mm-footer">
                <div class="rr-mm-footer-status" id="rr-mm-footer-status">
                    <span class="rr-mm-footer-dot" style="background:var(--rr-gray-300)"></span>
                    <span id="rr-mm-footer-text"><?php _e('Selecteer een pagina om te bewerken', 'rankrepair'); ?></span>
                </div>
                <div class="rr-mm-footer-actions">
                    <button type="button" id="rr-mm-discard" class="rr-btn rr-btn-secondary button" style="display:none">
                        <?php _e('Wijzigingen verwerpen', 'rankrepair'); ?>
                    </button>
                    <button type="button" id="rr-mm-save" class="rr-btn rr-btn-primary button" style="display:none">
                        <?php _e('Opslaan', 'rankrepair'); ?>
                    </button>
                </div>
            </div>

            <?php else: ?>

            <!-- Geen resultaten voor huidig filter -->
            <div style="padding:40px 20px;text-align:center;color:var(--rr-gray-500)">
                <p style="font-size:15px"><?php _e('Geen pagina\'s gevonden voor dit filter.', 'rankrepair'); ?></p>
                <a href="?page=rankrepair-meta-manager&filter=all" class="rr-btn rr-btn-secondary button" style="margin-top:10px"><?php _e('Toon alle pagina\'s', 'rankrepair'); ?></a>
            </div>

            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Render the right-panel edit area for a given item.
     * Called server-side for initial load; JS updates it on page-click.
     */
    private function render_edit_panel($item, $site_domain, $has_ai, $dup_title_map = [], $dup_desc_map = []) {
        // Peer-URLs vanuit in-memory maps (geen extra DB-query nodig)
        $dup_title_peers = [];
        if (!empty($item['is_duplicate_title']) && isset($dup_title_map[$item['current_title']])) {
            foreach ($dup_title_map[$item['current_title']] as $uid => $u) {
                if ($uid != $item['id']) $dup_title_peers[] = ['url' => $u];
            }
        }

        $dup_desc_peers = [];
        if (!empty($item['is_duplicate_description']) && isset($dup_desc_map[$item['current_description']])) {
            foreach ($dup_desc_map[$item['current_description']] as $uid => $u) {
                if ($uid != $item['id']) $dup_desc_peers[] = ['url' => $u];
            }
        }

        $path         = wp_parse_url($item['url'], PHP_URL_PATH) ?? '/';
        $parts        = array_filter(explode('/', $path));
        $name         = !empty($item['current_title'])
            ? wp_trim_words($item['current_title'], 6, '')
            : (end($parts) ? ucfirst(str_replace(['-','_'], ' ', end($parts))) : 'Homepage');

        // H1: opgeslagen als JSON array
        $h1s = json_decode($item['current_h1'] ?? '[]', true) ?: [];
        $h1_count = count($h1s);
        $title_len    = mb_strlen($item['current_title'] ?? '');
        $desc_len     = mb_strlen($item['current_description'] ?? '');
        $new_title    = $item['new_title'] ?? '';
        $new_desc     = $item['new_description'] ?? '';

        $title_char_class = $title_len >= 50 && $title_len <= 60 ? 'rr-mm-char-ok' : ($title_len > 60 ? 'rr-mm-char-over' : ($title_len === 0 ? 'rr-mm-char-missing' : 'rr-mm-char-over'));
        $title_char_label = $title_len === 0 ? __('Ontbreekt ✗', 'rankrepair') : ($title_len . ' ' . __('tekens', 'rankrepair') . ($title_len >= 50 && $title_len <= 60 ? ' ✓' : ''));

        $desc_char_class  = $desc_len >= 150 && $desc_len <= 160 ? 'rr-mm-char-ok' : ($desc_len > 160 ? 'rr-mm-char-over' : ($desc_len === 0 ? 'rr-mm-char-missing' : 'rr-mm-char-over'));
        $desc_char_label  = $desc_len === 0  ? __('Ontbreekt ✗', 'rankrepair') : ($desc_len . ' ' . __('tekens', 'rankrepair') . ($desc_len >= 150 && $desc_len <= 160 ? ' ✓' : ''));

        // Google preview: prefer new values, fall back to current
        $preview_title   = !empty($new_title) ? $new_title : $item['current_title'];
        $preview_desc    = !empty($new_desc)  ? $new_desc  : $item['current_description'];
        ?>
        <?php
        // Issue badges berekenen
        $issue_badges = [];
        if ($h1_count === 0)                                                 $issue_badges[] = ['H1 ontbreekt',           'danger'];
        elseif ($h1_count > 1)                                               $issue_badges[] = ['Meerdere H1\'s',          'warning'];
        if ($title_len === 0)                                                $issue_badges[] = ['Titel ontbreekt',         'danger'];
        elseif ($item['is_duplicate_title'])                                 $issue_badges[] = ['Titel dubbel',           'warning'];
        elseif ($title_len > 60)                                             $issue_badges[] = ['Titel te lang',           'warning'];
        if ($desc_len === 0)                                                 $issue_badges[] = ['Omschrijving ontbreekt',  'danger'];
        elseif ($item['is_duplicate_description'])                           $issue_badges[] = ['Omschrijving dubbel',    'warning'];
        elseif ($desc_len > 160)                                             $issue_badges[] = ['Omschrijving te lang',    'warning'];
        if (!empty($new_title) && ($item['status'] ?? '') !== 'applied')    $issue_badges[] = ['AI suggesties klaar',     'indigo'];
        ?>
        <div class="rr-mm-page-title-bar" id="rr-mm-panel-<?php echo esc_attr($item['id']); ?>">
            <div>
                <h2 class="rr-mm-page-heading"><?php echo esc_html($name); ?></h2>
                <div class="rr-mm-page-url-bar">
                    <input class="rr-mm-slug-input" type="text" value="<?php echo esc_attr($site_domain . $path); ?>" readonly>
                    <?php foreach ($issue_badges as [$label, $type]): ?>
                        <span class="rr-mm-issue-badge rr-mm-issue-badge--<?php echo $type; ?>"><?php echo esc_html($label); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="rr-mm-nav-btns">
                <button type="button" class="rr-btn rr-btn-secondary button rr-mm-prev-btn" data-current="<?php echo esc_attr($item['id']); ?>" onclick="rrMMNavigate(this, -1)">← <?php _e('Vorige', 'rankrepair'); ?></button>
                <button type="button" class="rr-btn rr-btn-secondary button rr-mm-next-btn" data-current="<?php echo esc_attr($item['id']); ?>" onclick="rrMMNavigate(this, 1)"><?php _e('Volgende', 'rankrepair'); ?> →</button>
            </div>
        </div>

        <!-- H1 Field -->
        <div class="rr-mm-field-card" id="rr-mm-card-h1">
            <div class="rr-mm-field-header">
                <div class="rr-mm-field-label-group">
                    <span class="rr-mm-field-label"><?php _e('H1 — Paginatitel', 'rankrepair'); ?></span>
                    <span class="rr-mm-field-type-badge rr-mm-badge-h1">H1</span>
                    <?php if ($h1_count > 1): ?>
                        <span class="rr-badge rr-badge-danger" title="<?php _e('Meerdere H1 tags is slecht voor SEO', 'rankrepair'); ?>">
                            <?php printf(__('%d H1 tags gevonden!', 'rankrepair'), $h1_count); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="rr-mm-field-actions">
                    <span class="rr-mm-char-badge <?php echo $h1_count > 0 ? ($h1_count > 1 ? 'rr-mm-char-over' : 'rr-mm-char-ok') : 'rr-mm-char-missing'; ?>" id="rr-char-h1">
                        <?php echo $h1_count === 0 ? __('Niet gevonden', 'rankrepair') : esc_html($h1_count . ' ' . _n('H1', 'H1\'s', $h1_count, 'rankrepair')); ?>
                    </span>
                </div>
            </div>
            <div class="rr-mm-field-body">
                <?php if (empty($h1s)): ?>
                    <input type="text" class="rr-mm-current-input" id="rr-h1-current-0"
                           value=""
                           placeholder="<?php _e('Geen H1 tag gevonden op deze pagina', 'rankrepair'); ?>"
                           data-id="<?php echo esc_attr($item['id']); ?>">
                <?php else: ?>
                    <?php foreach ($h1s as $i => $h1_text): ?>
                        <input type="text" class="rr-mm-current-input<?php echo $i > 0 ? ' rr-mm-h1-extra' : ''; ?>"
                               id="rr-h1-current-<?php echo $i; ?>"
                               value="<?php echo esc_attr($h1_text); ?>"
                               <?php if ($h1_count > 1): ?>
                               style="border-left: 3px solid <?php echo $i === 0 ? 'var(--rr-warning)' : 'var(--rr-danger)'; ?>; margin-bottom:4px"
                               title="H1 #<?php echo $i + 1; ?>"
                               <?php endif; ?>
                               data-id="<?php echo esc_attr($item['id']); ?>">
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Meta Title Field -->
        <div class="rr-mm-field-card" id="rr-mm-card-title">
            <div class="rr-mm-field-header">
                <div class="rr-mm-field-label-group">
                    <span class="rr-mm-field-label"><?php _e('Meta titel', 'rankrepair'); ?></span>
                    <span class="rr-mm-field-type-badge rr-mm-badge-title"><?php _e('Title tag', 'rankrepair'); ?></span>
                </div>
                <div class="rr-mm-field-actions">
                    <span class="rr-mm-char-badge <?php echo esc_attr($title_char_class); ?>" id="rr-char-title"><?php echo esc_html($title_char_label); ?></span>
                    <?php if ($has_ai): ?>
                    <button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate('<?php echo esc_js($item['id']); ?>', 'title')">
                        ✨ <?php _e('AI suggestie', 'rankrepair'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rr-mm-field-body">
                <input type="text" class="rr-mm-current-input" id="rr-title-current"
                       value="<?php echo esc_attr($item['current_title']); ?>"
                       placeholder="<?php _e('Geen meta titel ingesteld...', 'rankrepair'); ?>"
                       data-id="<?php echo esc_attr($item['id']); ?>">
                <?php if (!empty($dup_title_peers)): ?>
                <div class="rr-mm-dup-notice">
                    <span class="rr-mm-dup-notice-icon">⚠</span>
                    <?php _e('Zelfde titel ook op:', 'rankrepair'); ?>
                    <?php foreach ($dup_title_peers as $peer): ?>
                        <a href="<?php echo esc_url($peer['url']); ?>" target="_blank" class="rr-mm-dup-peer-link"><?php echo esc_html($peer['url']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($new_title)): ?>
                <div class="rr-mm-ai-suggestion">
                    <span class="rr-mm-ai-suggestion-label">✨ <?php _e('AI suggestie', 'rankrepair'); ?></span>
                    <div class="rr-mm-ai-suggestion-box">
                        <p class="rr-mm-suggestion-text" id="rr-title-suggestion"><?php echo esc_html($new_title); ?></p>
                        <div class="rr-mm-suggestion-actions">
                            <button type="button" class="rr-btn rr-btn-primary rr-btn-xs button" data-suggestion="<?php echo esc_attr($new_title); ?>" onclick="rrMMAccept('<?php echo esc_js($item['id']); ?>', 'title', this)">✓ <?php _e('Overnemen', 'rankrepair'); ?></button>
                            <button type="button" class="rr-btn rr-btn-ghost rr-btn-xs button" onclick="rrMMReject('<?php echo esc_js($item['id']); ?>', 'title', this)">✕ <?php _e('Afwijzen', 'rankrepair'); ?></button>
                            <?php if ($has_ai): ?>
                            <button type="button" class="rr-btn rr-btn-secondary rr-btn-xs button" style="border-color:#C4B5FD;color:var(--rr-primary)" onclick="rrMMGenerate('<?php echo esc_js($item['id']); ?>', 'title')">↺ <?php _e('Opnieuw genereren', 'rankrepair'); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Meta Description Field -->
        <div class="rr-mm-field-card" id="rr-mm-card-desc">
            <div class="rr-mm-field-header">
                <div class="rr-mm-field-label-group">
                    <span class="rr-mm-field-label"><?php _e('Meta omschrijving', 'rankrepair'); ?></span>
                    <span class="rr-mm-field-type-badge rr-mm-badge-desc"><?php _e('Description', 'rankrepair'); ?></span>
                </div>
                <div class="rr-mm-field-actions">
                    <span class="rr-mm-char-badge <?php echo esc_attr($desc_char_class); ?>" id="rr-char-desc"><?php echo esc_html($desc_char_label); ?></span>
                    <?php if ($has_ai): ?>
                    <button type="button" class="rr-mm-ai-btn" onclick="rrMMGenerate('<?php echo esc_js($item['id']); ?>', 'description')">
                        ✨ <?php _e('AI suggestie', 'rankrepair'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rr-mm-field-body">
                <textarea class="rr-mm-current-textarea" id="rr-desc-current"
                          placeholder="<?php _e('Geen meta omschrijving ingesteld...', 'rankrepair'); ?>"
                          data-id="<?php echo esc_attr($item['id']); ?>"
                          rows="2"><?php echo esc_textarea($item['current_description']); ?></textarea>
                <?php if (!empty($dup_desc_peers)): ?>
                <div class="rr-mm-dup-notice">
                    <span class="rr-mm-dup-notice-icon">⚠</span>
                    <?php _e('Zelfde omschrijving ook op:', 'rankrepair'); ?>
                    <?php foreach ($dup_desc_peers as $peer): ?>
                        <a href="<?php echo esc_url($peer['url']); ?>" target="_blank" class="rr-mm-dup-peer-link"><?php echo esc_html($peer['url']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($new_desc)): ?>
                <div class="rr-mm-ai-suggestion">
                    <span class="rr-mm-ai-suggestion-label">✨ <?php _e('AI suggestie', 'rankrepair'); ?></span>
                    <div class="rr-mm-ai-suggestion-box">
                        <p class="rr-mm-suggestion-text" id="rr-desc-suggestion"><?php echo esc_html($new_desc); ?></p>
                        <div class="rr-mm-suggestion-actions">
                            <button type="button" class="rr-btn rr-btn-primary rr-btn-xs button" data-suggestion="<?php echo esc_attr($new_desc); ?>" onclick="rrMMAccept('<?php echo esc_js($item['id']); ?>', 'description', this)">✓ <?php _e('Overnemen', 'rankrepair'); ?></button>
                            <button type="button" class="rr-btn rr-btn-ghost rr-btn-xs button" onclick="rrMMReject('<?php echo esc_js($item['id']); ?>', 'description', this)">✕ <?php _e('Afwijzen', 'rankrepair'); ?></button>
                            <?php if ($has_ai): ?>
                            <button type="button" class="rr-btn rr-btn-secondary rr-btn-xs button" style="border-color:#C4B5FD;color:var(--rr-primary)" onclick="rrMMGenerate('<?php echo esc_js($item['id']); ?>', 'description')">↺ <?php _e('Opnieuw genereren', 'rankrepair'); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Google Preview -->
        <div class="rr-mm-preview-card">
            <p class="rr-mm-preview-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?php _e('GOOGLE ZOEKRESULTAAT VOORVERTONING', 'rankrepair'); ?>
            </p>
            <div class="rr-google-preview" id="rr-google-preview">
                <div class="rr-gp-domain-row">
                    <span class="rr-gp-favicon">G</span>
                    <span class="rr-gp-domain"><?php echo esc_html($site_domain); ?></span>
                    <span class="rr-gp-breadcrumb">›&nbsp;<?php echo esc_html(trim($path, '/') ?: 'Home'); ?></span>
                </div>
                <p class="rr-gp-title" id="rr-gp-title"><?php echo esc_html($preview_title ?: __('(geen meta titel)', 'rankrepair')); ?></p>
                <p class="rr-gp-snippet" id="rr-gp-snippet"><?php echo esc_html($preview_desc ?: __('(geen meta omschrijving)', 'rankrepair')); ?></p>
            </div>
        </div>
        <?php
    }
}

// Initialize
new RR_Addon_Meta_Manager();
