<?php
/**
 * Structured Data Add-on
 * Injects JSON-LD schemas on the front-end as a complement to existing SEO plugins (Yoast, Rank Math).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Structured_Data extends RR_Addon_Base {

    protected function init(): void {
        $this->slug        = 'structured-data';
        $this->name        = __('Structured Data', 'rankrepair');
        $this->description = __('Vul bestaande JSON-LD aan: Organization, BreadcrumbList, Product, FAQPage, ProductGroup.', 'rankrepair');
        $this->icon        = 'structured-data';

        add_filter('rr_settings_sections', [$this, 'register_settings_section']);
        add_action('wp_head',              [$this, 'inject_schema'], 100); // ná Yoast (priority 1)

        // Defensief: alleen requiren als bestanden daadwerkelijk bestaan,
        // zodat een halve sync niet tot een fatal error leidt.
        $builder_file  = __DIR__ . '/class-sd-builder.php';
        $scanner_file  = __DIR__ . '/class-sd-scanner.php';
        $template_file = __DIR__ . '/class-sd-template.php';
        if (file_exists($builder_file))  require_once $builder_file;
        if (file_exists($scanner_file))  require_once $scanner_file;
        if (file_exists($template_file)) require_once $template_file;
    }

    public function get_stats(): array {
        return ['total' => 0, 'issues' => 0];
    }

    public function enqueue_assets($hook): void {
        if (strpos($hook, 'rankrepair-structured-data') === false) return;

        $css_path = RR_PLUGIN_DIR . 'addons/structured-data/structured-data.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'rr-structured-data',
                RR_PLUGIN_URL . 'addons/structured-data/structured-data.css',
                ['rr-admin-style'],
                filemtime($css_path) ?: RR_VERSION
            );
        }
    }

    public function register_settings_section(array $sections): array {
        $sections[] = [
            'id'          => 'structured-data',
            'title'       => __('Structured Data', 'rankrepair'),
            'icon'        => 'structured-data',
            'priority'    => 30,
            'description' => __('Organization-info en schema toggles voor JSON-LD injectie', 'rankrepair'),
            'fields'      => [
                [
                    'name'        => 'rr_sd_enabled',
                    'label'       => __('Injectie aan', 'rankrepair'),
                    'type'        => 'select',
                    'default'     => '1',
                    'options'     => ['1' => __('Ja — schemas toevoegen aan wp_head', 'rankrepair'), '0' => __('Nee — uit', 'rankrepair')],
                    'description' => __('Als dit uit staat wordt er niks geïnjecteerd, ongeacht de schema toggles hieronder.', 'rankrepair'),
                ],
                [
                    'name'    => 'rr_sd_org_name',
                    'label'   => __('Bedrijfsnaam', 'rankrepair'),
                    'type'    => 'text',
                    'default' => get_bloginfo('name'),
                ],
                [
                    'name'    => 'rr_sd_org_legal_name',
                    'label'   => __('Juridische naam', 'rankrepair'),
                    'type'    => 'text',
                    'description' => __('Bijv. "Sabé Verpakkingen BV". Laat leeg om bedrijfsnaam te gebruiken.', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_logo',
                    'label' => __('Logo URL', 'rankrepair'),
                    'type'  => 'text',
                    'description' => __('Volledige URL naar logo (SVG of PNG).', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_image',
                    'label'       => __('Afbeelding (image)', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Representatieve afbeelding van het bedrijf (JPG/PNG, liefst 1200×630).', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_description',
                    'label'       => __('Beschrijving', 'rankrepair'),
                    'type'        => 'textarea',
                    'rows'        => 3,
                    'description' => __('Korte bedrijfsomschrijving (2-3 zinnen).', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_phone',
                    'label' => __('Telefoonnummer', 'rankrepair'),
                    'type'  => 'text',
                    'description' => __('Bijv. +31318520298 (internationaal formaat).', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_org_email',
                    'label' => __('E-mailadres', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_street',
                    'label' => __('Straat + nummer', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_postal',
                    'label' => __('Postcode', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'  => 'rr_sd_org_city',
                    'label' => __('Plaats', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'    => 'rr_sd_org_country',
                    'label'   => __('Landcode', 'rankrepair'),
                    'type'    => 'text',
                    'default' => 'NL',
                ],
                [
                    'name'        => 'rr_sd_org_kvk',
                    'label'       => __('KvK nummer', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Optioneel. Wordt als identifier in Organization-schema gezet.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_founding',
                    'label'       => __('Opgericht in', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Jaar (YYYY).', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_org_social',
                    'label'       => __('Social links (sameAs)', 'rankrepair'),
                    'type'        => 'textarea',
                    'rows'        => 4,
                    'description' => __('Eén URL per regel. Bijv. Facebook, LinkedIn, Instagram profielen.', 'rankrepair'),
                ],
                [
                    'name'        => 'rr_sd_rating_value',
                    'label'       => __('Gemiddelde beoordeling', 'rankrepair'),
                    'type'        => 'text',
                    'description' => __('Bijv. 4.8. Leeg = geen aggregateRating in schema.', 'rankrepair'),
                ],
                [
                    'name'  => 'rr_sd_rating_count',
                    'label' => __('Aantal beoordelingen', 'rankrepair'),
                    'type'  => 'text',
                ],
                [
                    'name'        => 'rr_sd_custom_hint',
                    'label'       => __('Eigen JSON-LD', 'rankrepair'),
                    'type'        => 'html',
                    'html'        => '<p style="color:#6b7280;font-size:13px;background:#f9fafb;border:1px solid #e5e7eb;padding:12px 14px;border-radius:8px;margin:0">' .
                        esc_html__('Wil je eigen JSON-LD per pagina-type plakken (bv. Reviews voor homepage)? Ga naar ', 'rankrepair') .
                        '<a href="' . esc_url(admin_url('admin.php?page=rankrepair-structured-data')) . '" style="color:#7c3aed;font-weight:600">' . esc_html__('Structured Data → Inspector', 'rankrepair') . '</a>' .
                        esc_html__(' — daar vind je per pagina-type een eigen veld.', 'rankrepair') . '</p>',
                ],
                [
                    'name'        => 'rr_sd_only_custom',
                    'label'       => __('Modus', 'rankrepair'),
                    'type'        => 'select',
                    'default'     => '0',
                    'options'     => [
                        '0' => __('Slim aanvullen — auto-build wat niet in eigen JSON staat (aanbevolen)', 'rankrepair'),
                        '1' => __('Alleen eigen JSON — geen auto-build (handmatig per pagina-type)', 'rankrepair'),
                    ],
                    'description' => __('Bij "Alleen eigen JSON" worden Organization / Breadcrumb / ProductGroup niet automatisch toegevoegd — alleen wat jij in de inspector plakt.', 'rankrepair'),
                ],
                [
                    'name'    => 'rr_sd_schemas',
                    'label'   => __('Schemas aan/uit', 'rankrepair'),
                    'type'    => 'checkbox_group',
                    'options' => [
                        'rr_sd_schema_organization' => [__('Organization', 'rankrepair'),   __('Op elke pagina — bedrijfsgegevens uit bovenstaande velden', 'rankrepair')],
                        'rr_sd_schema_website'      => [__('WebSite + SearchAction', 'rankrepair'), __('Alleen homepage — sitelink searchbox', 'rankrepair')],
                        'rr_sd_schema_breadcrumb'   => [__('BreadcrumbList', 'rankrepair'), __('Auto uit URL-hiërarchie / WC categorie-ouders', 'rankrepair')],
                        'rr_sd_schema_product'      => [__('Product + Offer', 'rankrepair'), __('WooCommerce productpagina\'s — prijs, voorraad, reviews', 'rankrepair')],
                        'rr_sd_schema_productgroup' => [__('ProductGroup', 'rankrepair'),   __('WooCommerce categorie-pagina\'s met sub-categorieën', 'rankrepair')],
                        'rr_sd_schema_faqpage'      => [__('FAQPage', 'rankrepair'),        __('Pagina\'s met Gutenberg FAQ-block of ACF "faqs" repeater', 'rankrepair')],
                    ],
                ],
            ],
        ];
        return $sections;
    }

    /**
     * Inject schemas in wp_head. Draait ná Yoast (priority 100) zodat we niet conflicteren.
     */
    public function inject_schema(): void {
        if (get_option('rr_sd_enabled', '1') !== '1') return;
        if (is_admin()) return;

        $graph = [];

        $context = $this->detect_context();
        $builder = new RR_SD_Builder();

        // ── Eerst custom JSON-LD parsen zodat we weten welke types al gedekt zijn ──
        $tpl = class_exists('RR_SD_Template') ? new RR_SD_Template() : null;

        $type_opt = 'rr_sd_custom_jsonld_' . $context['type'];
        $raw      = get_option($type_opt, '');
        // Backward-compat: als depth-specifieke leeg is, val terug op generieke product_cat
        if (trim($raw) === '' && strpos($context['type'], 'product_cat_') === 0) {
            $raw = get_option('rr_sd_custom_jsonld_product_cat', '');
        }
        if ($tpl && trim($raw) !== '') $raw = $tpl->apply($raw, $context);
        $custom = $this->parse_custom_jsonld($raw);

        $raw_all = get_option('rr_sd_custom_jsonld_all', '');
        if ($tpl && trim($raw_all) !== '') $raw_all = $tpl->apply($raw_all, $context);
        $custom_all = $this->parse_custom_jsonld($raw_all);

        // Verzamel @types die al door de gebruiker zijn ingevoerd → niet auto-bouwen
        $covered_types = [];
        foreach (array_merge($custom, $custom_all) as $item) {
            if (!empty($item['@type'])) {
                $t = is_array($item['@type']) ? reset($item['@type']) : $item['@type'];
                $covered_types[$t] = true;
            }
        }

        // "Alleen eigen JSON" modus → álle auto-build skippen
        $only_custom = get_option('rr_sd_only_custom', '0') === '1';
        if ($only_custom) {
            foreach ($custom as $item)     $graph[] = $item;
            foreach ($custom_all as $item) $graph[] = $item;

            /**
             * Laat thema's/plugins nodes toevoegen aan de @graph.
             * Draait bewust vóór de empty-check: juist op pagina's waar RankRepair
             * zelf niets opbouwt wil een thema vaak alsnog iets toevoegen.
             *
             * @param array $graph   Lijst met schema-nodes.
             * @param array $context Context van de huidige pagina (zie detect_context()).
             */
            $graph = apply_filters('rr_sd_graph', $graph, $context);

            if (empty($graph)) return;
            $output = ['@context' => 'https://schema.org', '@graph' => $graph];
            echo "\n<!-- RankRepair Structured Data -->\n";
            echo '<script type="application/ld+json">' . wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
            return;
        }

        // ── Auto-build schema's: skip wat al in custom JSON staat ──

        // Organization
        if (get_option('rr_sd_schema_organization', '1') === '1' && empty($covered_types['Organization']) && empty($covered_types['LocalBusiness'])) {
            $org = $builder->build_organization($context);
            if ($org) $graph[] = $org;
        }

        // WebSite + SearchAction — alleen homepage
        if ($context['type'] === 'home' && get_option('rr_sd_schema_website', '1') === '1' && empty($covered_types['WebSite'])) {
            $graph[] = $builder->build_website();
        }

        // ── Custom JSON-LD invoegen ──
        foreach ($custom as $item)     $graph[] = $item;
        foreach ($custom_all as $item) $graph[] = $item;

        // BreadcrumbList — overal behalve homepage
        if ($context['type'] !== 'home' && get_option('rr_sd_schema_breadcrumb', '1') === '1' && empty($covered_types['BreadcrumbList'])) {
            $bc = $builder->build_breadcrumbs($context);
            if ($bc) $graph[] = $bc;
        }

        // Product — WooCommerce single product
        if ($context['type'] === 'product' && get_option('rr_sd_schema_product', '1') === '1' && empty($covered_types['Product'])) {
            $product = $builder->build_product($context);
            if ($product) $graph[] = $product;
        }

        // ProductGroup — WC productcategorie (alle dieptes l1/l2/l3)
        if (strpos($context['type'], 'product_cat') === 0 && get_option('rr_sd_schema_productgroup', '1') === '1' && empty($covered_types['ProductGroup']) && empty($covered_types['ItemList'])) {
            $pg = $builder->build_product_group($context);
            if ($pg) $graph[] = $pg;
        }

        // FAQPage — Gutenberg block of ACF repeater
        if (in_array($context['type'], ['post', 'page'], true) && get_option('rr_sd_schema_faqpage', '1') === '1') {
            $faq = $builder->build_faqpage($context);
            if ($faq) $graph[] = $faq;
        }

        /** Zie toelichting bij de filter in de "alleen eigen JSON"-modus hierboven. */
        $graph = apply_filters('rr_sd_graph', $graph, $context);

        if (empty($graph)) return;

        $output = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        echo "\n<!-- RankRepair Structured Data -->\n";
        echo '<script type="application/ld+json">' . wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }

    /**
     * Parse een custom JSON-LD string uit de settings en geef een lijst schemas terug
     * die in onze @graph gemerged kunnen worden.
     * Accepteert:
     *   - Heel blok: {"@context":"...","@graph":[{...},{...}]}
     *   - Alleen array: [{...},{...}]
     *   - Single schema: {"@type":"Review", …}
     *   - Ruwe <script type="application/ld+json">…</script> block (strip tag)
     */
    private function parse_custom_jsonld(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') return [];

        // Strip <script> tag als die er omheen zit
        if (stripos($raw, '<script') !== false) {
            if (preg_match('#<script[^>]*>(.*?)</script>#is', $raw, $m)) {
                $raw = trim($m[1]);
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            // Filter array zodat alleen valide schema-entries overblijven
            return array_values(array_filter($decoded['@graph'], 'is_array'));
        }
        if (isset($decoded['@type'])) {
            return [$decoded];
        }
        // Indexed array van items
        if (array_keys($decoded) === range(0, count($decoded) - 1)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        return [];
    }

    /**
     * Detect welke pagina-context we nu renderen.
     */
    private function detect_context(): array {
        if (is_front_page()) {
            return ['type' => 'home', 'url' => home_url('/')];
        }
        // is_home() = blog overzichtspagina (de "Posts page" in WP settings)
        if (is_home()) {
            $blog_id = (int) get_option('page_for_posts');
            return [
                'type'    => 'blog_index',
                'post_id' => $blog_id ?: 0,
                'url'     => $blog_id ? get_permalink($blog_id) : home_url('/'),
            ];
        }
        if (function_exists('is_product') && is_product()) {
            global $post;
            return ['type' => 'product', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (function_exists('is_product_category') && is_product_category()) {
            $term  = get_queried_object();
            $depth = self::term_depth($term);
            // L1 = subcategorie (top), L2 = sub-sub, L3 = sub-sub-sub, daarna 'deep'
            $level = $depth >= 3 ? 'l3' : ($depth === 2 ? 'l2' : 'l1');
            return [
                'type'     => 'product_cat_' . $level,
                'term_id'  => $term->term_id,
                'term'     => $term,
                'depth'    => $depth,
                'url'      => get_term_link($term),
            ];
        }
        // Blog categorie krijgt eigen type zodat de 'category' inspector tab matcht
        if (is_category()) {
            $term  = get_queried_object();
            return [
                'type'     => 'category',
                'term_id'  => $term->term_id,
                'term'     => $term,
                'taxonomy' => $term->taxonomy,
                'depth'    => self::term_depth($term),
                'url'      => get_term_link($term),
            ];
        }
        if (is_tax() || is_tag()) {
            $term  = get_queried_object();
            return [
                'type'     => 'tax',
                'term_id'  => $term->term_id,
                'term'     => $term,
                'taxonomy' => $term->taxonomy,
                'depth'    => self::term_depth($term),
                'url'      => get_term_link($term),
            ];
        }
        if (is_singular('post')) {
            global $post;
            return ['type' => 'post', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (is_page()) {
            global $post;
            // Detecteer contactpagina via slug/title (of via filter override)
            $contact_id = (int) apply_filters('rr_sd_contact_page_id', self::detect_contact_page_id());
            if ($contact_id && (int) $post->ID === $contact_id) {
                return ['type' => 'contact', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
            }
            return ['type' => 'page', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        if (is_singular()) {
            global $post;
            return ['type' => 'singular', 'post_id' => $post->ID, 'url' => get_permalink($post->ID)];
        }
        return ['type' => 'other', 'url' => home_url(add_query_arg(null, null))];
    }

    /**
     * Detecteer de contactpagina (via slug of title).
     * Cached in een transient zodat we niet elke pageload de DB doorzoeken.
     */
    public static function detect_contact_page_id(): int {
        $cached = get_transient('rr_sd_contact_page_id');
        if ($cached !== false) return (int) $cached;

        $page = get_page_by_path('contact', OBJECT, 'page');
        if (!$page) {
            // Probeer titel "Contact" / "Contactpagina"
            $q = new WP_Query([
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'title'          => 'Contact',
            ]);
            if ($q->have_posts()) $page = $q->posts[0];
        }

        $id = $page ? (int) $page->ID : 0;
        set_transient('rr_sd_contact_page_id', $id, HOUR_IN_SECONDS);
        return $id;
    }

    /**
     * Bepaal de hiërarchische diepte van een term: 1 = top-level, 2 = sub, 3 = sub-sub.
     */
    public static function term_depth($term): int {
        if (!$term || empty($term->term_id)) return 1;
        $depth = 1;
        $current = $term;
        while (!empty($current->parent)) {
            $depth++;
            $current = get_term($current->parent, $term->taxonomy);
            if (!$current || is_wp_error($current)) break;
            if ($depth > 10) break; // safety
        }
        return $depth;
    }

    public function render_page(): void {
        $scanner = new RR_SD_Scanner();
        $scanner->render();
    }
}

new RR_Addon_Structured_Data();
