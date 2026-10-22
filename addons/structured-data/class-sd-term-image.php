<?php
/**
 * Dynamische categorieafbeelding voor de placeholder {{term_image}}.
 *
 * Volgorde (eerste bruikbare afbeelding wint, een logo telt nooit als bruikbaar):
 *   1. de eigen afbeelding van de term (WooCommerce `thumbnail_id`)
 *   2. de afbeelding van de grootste subcategorie (daarna de volgende, en dieper)
 *   3. de afbeelding van het eerste product in de categorie (incl. subcategorieën)
 * Is er niets, dan geeft de placeholder '' terug en laat RR_SD_Template de hele
 * key uit de JSON-LD weg.
 *
 * De keuzelogica (RR_SD_Term_Image) kent geen WordPress en is los te testen; alle
 * WordPress-aanroepen zitten in RR_SD_Term_Image_WP_Source.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface RR_SD_Term_Image_Source {
    /** Yoast social image van de term (URL), of ''. */
    public function yoast_image(int $term_id): string;

    /** Eigen termafbeelding (WooCommerce thumbnail_id) als URL, of ''. */
    public function thumbnail_url(int $term_id): string;

    /** Directe subcategorieën, grootste (meeste producten) eerst. */
    public function child_ids_by_size(int $term_id): array;

    /** Maximaal $limit afbeeldings-URL's van de eerste producten in de term (incl. subtermen). */
    public function product_image_urls(int $term_id, int $limit): array;

    /** Is deze afbeelding een logo (en dus niet geschikt als categorieafbeelding)? */
    public function is_logo(string $url): bool;
}

class RR_SD_Term_Image {

    const MAX_DEPTH          = 5;
    const PRODUCT_CANDIDATES = 5;
    const CACHE_TTL          = 43200; // 12 uur; wijzigingen resetten de cache eerder via bump_version()

    private $source;
    private $use_yoast;

    public function __construct(RR_SD_Term_Image_Source $source, bool $use_yoast = false) {
        $this->source    = $source;
        $this->use_yoast = $use_yoast;
    }

    public function resolve(int $term_id): string {
        $seen = [];
        $url  = $this->from_term_tree($term_id, 0, $seen);
        if ($url !== '') {
            return $url;
        }
        return $this->from_products($term_id);
    }

    private function own_image(int $term_id): string {
        if ($this->use_yoast) {
            $url = trim($this->source->yoast_image($term_id));
            if ($url !== '' && !$this->source->is_logo($url)) {
                return $url;
            }
        }
        $url = trim($this->source->thumbnail_url($term_id));
        if ($url !== '' && !$this->source->is_logo($url)) {
            return $url;
        }
        return '';
    }

    private function from_term_tree(int $term_id, int $depth, array &$seen): string {
        if (isset($seen[$term_id])) {
            return '';
        }
        $seen[$term_id] = true;

        $own = $this->own_image($term_id);
        if ($own !== '') {
            return $own;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '';
        }
        foreach ($this->source->child_ids_by_size($term_id) as $child_id) {
            $url = $this->from_term_tree((int) $child_id, $depth + 1, $seen);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private function from_products(int $term_id): string {
        foreach ($this->source->product_image_urls($term_id, self::PRODUCT_CANDIDATES) as $url) {
            $url = trim((string) $url);
            if ($url !== '' && !$this->source->is_logo($url)) {
                return $url;
            }
        }
        return '';
    }

    // ── WordPress-laag ───────────────────────────────────────────────────

    /**
     * URL van de categorieafbeelding voor een WP_Term, met cache.
     *
     * Filters:
     *  - rr_sd_term_image_use_yoast (bool, standaard false): Yoast social image eerst proberen.
     *  - rr_sd_term_image_cache     (bool, standaard true)
     *  - rr_sd_term_image           (string $url, WP_Term $term): eindresultaat bijsturen.
     */
    public static function for_term($term): string {
        if (!is_object($term) || empty($term->term_id)) {
            return '';
        }

        $cache = (bool) apply_filters('rr_sd_term_image_cache', true);
        $key   = 'rr_sd_ti_' . self::version() . '_' . (int) $term->term_id;
        if ($cache) {
            $hit = get_transient($key);
            if (is_array($hit) && isset($hit['u'])) {
                return (string) $hit['u'];
            }
        }

        $use_yoast = (bool) apply_filters('rr_sd_term_image_use_yoast', false);
        $resolver  = new self(new RR_SD_Term_Image_WP_Source((string) $term->taxonomy), $use_yoast);
        $url       = (string) apply_filters('rr_sd_term_image', $resolver->resolve((int) $term->term_id), $term);

        if ($cache) {
            set_transient($key, ['u' => $url], self::CACHE_TTL);
        }
        return $url;
    }

    private static function version(): int {
        return max(1, (int) get_option('rr_sd_ti_ver', 1));
    }

    /** Maakt alle bewaarde termafbeeldingen ongeldig (de sleutel bevat het versienummer). */
    public static function bump_version(...$args): void {
        update_option('rr_sd_ti_ver', self::version() + 1, false);
    }

    public static function on_save_post($post_id, $post = null): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        self::bump_version();
    }

    // ── Yoast social image (opt-in) ──────────────────────────────────────

    /**
     * Beslist welke afbeelding als social image (og:image) aan een term wordt toegevoegd.
     * Alleen als de instelling aan staat én het social-imageveld van de term in Yoast leeg is;
     * een door de redacteur gekozen afbeelding wordt dus nooit overschreven.
     */
    public static function decide_social_image(bool $enabled, string $own_field, string $resolved): string {
        if (!$enabled || trim($own_field) !== '') {
            return '';
        }
        return trim($resolved);
    }

    /**
     * Yoast-hook wpseo_add_opengraph_images: zet de categorieafbeelding bovenaan de og:image-lijst
     * (het filter wpseo_opengraph_image zelf vervangt alleen een afbeelding die er al is).
     * Twitter volgt in Yoast de eerste og:image.
     *
     * @param object $image_container WPSEO_OpenGraph_Image
     * @return object
     */
    public static function yoast_add_social_image($image_container) {
        if (get_option('rr_sd_yoast_social_image', '0') !== '1' || !is_object($image_container) || !method_exists($image_container, 'add_image_by_url')) {
            return $image_container;
        }
        if (!(is_category() || is_tag() || is_tax())) {
            return $image_container;
        }
        $term = get_queried_object();
        if (!is_object($term) || empty($term->term_id) || empty($term->taxonomy)) {
            return $image_container;
        }
        $own = (new RR_SD_Term_Image_WP_Source((string) $term->taxonomy))->yoast_image((int) $term->term_id);
        $url = self::decide_social_image(true, $own, self::for_term($term));
        if ($url !== '') {
            $image_container->add_image_by_url($url);
        }
        return $image_container;
    }

    // ── Yoast schema-afbeelding gelijktrekken (opt-in) ───────────────────

    /**
     * Welke afbeelding hoort als schema-afbeelding bij een term? Dezelfde als de og:image:
     * de social image uit Yoast als die is ingesteld, anders (alleen als het vullen van lege
     * social images aan staat) de categorieafbeelding. Anders '' = niets aanpassen.
     */
    public static function schema_target_image(bool $fill_enabled, string $own_field, string $resolved): string {
        if (trim($own_field) !== '') {
            return trim($own_field);
        }
        return $fill_enabled ? trim($resolved) : '';
    }

    /**
     * Zet de hoofdafbeelding (ImageObject met "#primaryimage") in een Yoast-schemagraaf op $url.
     * Yoast kiest zelf een afbeelding (op een categorie meestal de eerste productfoto) en die
     * wijkt vaak af van de og:image. Breedte, hoogte en bijschrift van de oude afbeelding worden
     * vervangen door die in $info, of weggelaten: een verkeerde maat is erger dan geen maat.
     * Een graaf zonder hoofdafbeelding blijft ongewijzigd (er wordt niets bijgemaakt).
     *
     * @param array<int,mixed>   $graph
     * @param array<string,mixed> $info  Optioneel: width, height, caption
     */
    public static function align_primary_image(array $graph, string $url, array $info = []): array {
        if (trim($url) === '') {
            return $graph;
        }
        $changed = false;
        foreach ($graph as $i => $piece) {
            if (!is_array($piece)) {
                continue;
            }
            $is_image = ($piece['@type'] ?? null) === 'ImageObject' && strpos((string) ($piece['@id'] ?? ''), '#primaryimage') !== false;
            if ($is_image) {
                $piece['url']        = $url;
                $piece['contentUrl'] = $url;
                unset($piece['width'], $piece['height'], $piece['caption']);
                foreach (['width', 'height', 'caption'] as $key) {
                    if (isset($info[$key]) && $info[$key] !== '' && $info[$key] !== null) {
                        $piece[$key] = $info[$key];
                    }
                }
                $graph[$i] = $piece;
                $changed   = true;
            }
        }
        if (!$changed) {
            return $graph;
        }
        foreach ($graph as $i => $piece) {
            if (is_array($piece) && ($piece['@type'] ?? null) !== 'ImageObject' && !empty($piece['thumbnailUrl'])) {
                $graph[$i]['thumbnailUrl'] = $url;
            }
        }
        return $graph;
    }

    /**
     * Yoast-filter wpseo_schema_graph (opt-in via rr_sd_yoast_schema_image): op categorieën,
     * tags en andere termen krijgt de hoofdafbeelding in het Yoast-schema dezelfde afbeelding
     * als de og:image.
     *
     * @param array $graph
     * @return array
     */
    public static function yoast_schema_graph($graph, $context = null) {
        if (!is_array($graph) || get_option('rr_sd_yoast_schema_image', '0') !== '1') {
            return $graph;
        }
        if (!(is_category() || is_tag() || is_tax())) {
            return $graph;
        }
        $term = get_queried_object();
        if (!is_object($term) || empty($term->term_id) || empty($term->taxonomy)) {
            return $graph;
        }
        $own    = (new RR_SD_Term_Image_WP_Source((string) $term->taxonomy))->yoast_image((int) $term->term_id);
        $fill   = get_option('rr_sd_yoast_social_image', '0') === '1';
        $target = self::schema_target_image($fill, $own, $fill ? self::for_term($term) : '');
        if ($target === '') {
            return $graph;
        }

        $info = [];
        $id   = (int) attachment_url_to_postid($target);
        if ($id > 0) {
            $alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
            $info['caption'] = $alt !== '' ? $alt : trim((string) get_the_title($id));
            $full = wp_get_attachment_image_src($id, 'full');
            if (is_array($full) && isset($full[0], $full[1], $full[2]) && $full[0] === $target) {
                $info['width']  = (int) $full[1];
                $info['height'] = (int) $full[2];
            }
        }
        return self::align_primary_image($graph, $target, $info);
    }

    public static function register_hooks(): void {
        foreach (['created_term', 'edited_term', 'delete_term', 'deleted_post', 'customize_save_after'] as $hook) {
            add_action($hook, [__CLASS__, 'bump_version']);
        }
        add_action('save_post', [__CLASS__, 'on_save_post'], 10, 2);
        add_filter('wpseo_add_opengraph_images', [__CLASS__, 'yoast_add_social_image']);
        add_filter('wpseo_schema_graph', [__CLASS__, 'yoast_schema_graph'], 20, 2);
        foreach (['rr_sd_org_logo', 'wpseo_titles', 'wpseo_taxonomy_meta', 'site_icon'] as $option) {
            add_action('update_option_' . $option, [__CLASS__, 'bump_version']);
        }
    }
}

class RR_SD_Term_Image_WP_Source implements RR_SD_Term_Image_Source {

    private $taxonomy;
    private $logo_names = null;
    private $logo_ids   = null;
    private $memo       = [];

    public function __construct(string $taxonomy) {
        $this->taxonomy = $taxonomy;
    }

    private function size(): string {
        return (string) apply_filters('rr_sd_term_image_size', 'large');
    }

    public function yoast_image(int $term_id): string {
        $all = get_option('wpseo_taxonomy_meta', []);
        $url = $all[$this->taxonomy][$term_id]['wpseo_opengraph-image'] ?? '';
        return is_string($url) ? trim($url) : '';
    }

    public function thumbnail_url(int $term_id): string {
        $thumb_id = (int) get_term_meta($term_id, 'thumbnail_id', true);
        if (!$thumb_id) {
            return '';
        }
        $url = wp_get_attachment_image_url($thumb_id, $this->size());
        return $url ? (string) $url : '';
    }

    public function child_ids_by_size(int $term_id): array {
        $children = get_terms([
            'taxonomy'   => $this->taxonomy,
            'parent'     => $term_id,
            'hide_empty' => true,
        ]);
        if (is_wp_error($children) || !$children) {
            return [];
        }
        $sizes = [];
        foreach ($children as $child) {
            // WooCommerce bewaart het aantal incl. subcategorieën in product_count_product_cat.
            $wc_count = (int) get_term_meta($child->term_id, 'product_count_product_cat', true);
            $sizes[(int) $child->term_id] = $wc_count > 0 ? $wc_count : (int) $child->count;
        }
        arsort($sizes); // stabiel genoeg: gelijke aantallen houden de volgorde van get_terms
        return array_keys($sizes);
    }

    public function product_image_urls(int $term_id, int $limit): array {
        $taxonomy = get_taxonomy($this->taxonomy);
        if (!$taxonomy || empty($taxonomy->object_type)) {
            return [];
        }
        $ids = get_posts([
            'post_type'      => $taxonomy->object_type,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => ['menu_order' => 'ASC', 'ID' => 'ASC'],
            'no_found_rows'  => true,
            'tax_query'      => [[
                'taxonomy'         => $this->taxonomy,
                'field'            => 'term_id',
                'terms'            => $term_id,
                'include_children' => true,
            ]],
            'meta_query'     => [[
                'key'     => '_thumbnail_id',
                'compare' => 'EXISTS',
            ]],
        ]);
        $urls = [];
        foreach ($ids as $post_id) {
            $url = get_the_post_thumbnail_url($post_id, $this->size());
            if ($url) {
                $urls[] = (string) $url;
            }
        }
        return $urls;
    }

    public function is_logo(string $url): bool {
        if (isset($this->memo[$url])) {
            return $this->memo[$url];
        }
        $this->load_logos();

        $base   = self::normalize_basename($url);
        $result = false;
        if ($base !== '' && isset($this->logo_names[$base])) {
            $result = true;
        } elseif ($this->logo_ids) {
            $id     = (int) attachment_url_to_postid($url);
            $result = $id > 0 && isset($this->logo_ids[$id]);
        }
        // Bestandsnaam met het los woord "logo" (sabe-logo.png, logo_wit.svg), niet "technologo".
        if (!$result && $base !== '' && preg_match('/(?:^|[^a-z0-9])logo(?:[^a-z0-9]|$)/', $base)) {
            $result = true;
        }
        $result = (bool) apply_filters('rr_sd_term_image_is_logo', $result, $url);
        return $this->memo[$url] = $result;
    }

    private function load_logos(): void {
        if ($this->logo_names !== null) {
            return;
        }
        $this->logo_names = [];
        $this->logo_ids   = [];

        $urls = [];
        $ids  = [];

        $own = trim((string) get_option('rr_sd_org_logo', ''));
        if ($own !== '') {
            $urls[] = $own;
        }
        $titles = get_option('wpseo_titles', []);
        if (is_array($titles)) {
            if (!empty($titles['company_logo']))    $urls[] = (string) $titles['company_logo'];
            if (!empty($titles['company_logo_id'])) $ids[]  = (int) $titles['company_logo_id'];
        }
        $ids[] = (int) get_theme_mod('custom_logo');
        $ids[] = (int) get_option('site_icon');

        $urls = array_merge($urls, (array) apply_filters('rr_sd_term_image_logo_urls', []));

        foreach (array_filter($ids) as $id) {
            $this->logo_ids[$id] = true;
            $url = wp_get_attachment_url($id);
            if ($url) {
                $urls[] = $url;
            }
        }
        foreach ($urls as $url) {
            $name = self::normalize_basename((string) $url);
            if ($name !== '') {
                $this->logo_names[$name] = true;
            }
            $id = (int) attachment_url_to_postid((string) $url);
            if ($id > 0) {
                $this->logo_ids[$id] = true;
            }
        }
    }

    /** Bestandsnaam zonder pad, query, formaat-suffix (-300x200, -scaled) en extensie, in kleine letters. */
    public static function normalize_basename(string $url): string {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = strtolower(basename($path));
        $name = preg_replace('/\.[a-z0-9]{2,5}$/', '', $name);
        $name = preg_replace('/-(?:\d+x\d+|scaled)$/', '', $name);
        return (string) $name;
    }
}
