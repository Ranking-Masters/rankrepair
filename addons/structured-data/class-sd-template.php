<?php
/**
 * Template-substitutie voor custom JSON-LD per pagina-type.
 * Vervangt {{placeholders}} in pasted JSON met live data van de huidige pagina.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Template {

    /**
     * Apply placeholder substitution to a JSON string.
     *
     * Scalar placeholders worden simpel vervangen: {{home_url}} → string.
     * Array placeholders worden vervangen door JSON-encoded array, alleen
     * als ze als string staan (bv "itemListElement": "{{breadcrumb_items}}").
     */
    public function apply(string $json, array $context): string {
        if (trim($json) === '') return $json;

        $array_vars  = $this->build_array_vars($context, $json);
        $scalar_vars = $this->build_scalar_vars($context, $json);

        // Stap 0: placeholders zonder waarde → de hele key weglaten (geen "image": "").
        $json = self::drop_empty_placeholders($json, $scalar_vars, $array_vars);

        // Stap 1: array tokens (vervang quoted token met JSON-array)
        foreach ($array_vars as $key => $value) {
            $needle      = '"{{' . $key . '}}"';
            $replacement = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($replacement === false) continue;
            $json = str_replace($needle, $replacement, $json);
        }

        // Stap 2: scalar tokens — JSON-escape de waarde zodat newlines, quotes en
        // backslashes binnen string-slots geldige JSON blijven opleveren.
        foreach ($scalar_vars as $key => $value) {
            $escaped = wp_json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // wp_json_encode wraps de string in quotes — strip die omdat onze
            // placeholder al binnen "..." staat in de template.
            if (is_string($escaped) && strlen($escaped) >= 2) {
                $escaped = substr($escaped, 1, -1);
            } else {
                $escaped = (string) $value;
            }
            $json = str_replace('{{' . $key . '}}', $escaped, $json);
        }

        return $json;
    }

    /**
     * Haalt keys weg waarvan de waarde precies één placeholder is die leeg uitkomt
     * ("image": "{{term_image}}" zonder afbeelding). Een lege string of lege lijst
     * is ongeldig/nutteloos schema, dus de key hoort er dan niet te staan.
     *
     * - Alleen waarden die uit niets anders dan de placeholder bestaan; een placeholder
     *   midden in een tekst blijft zoals hij was.
     * - Wordt een object daardoor een leeg omhulsel (alleen @type/@id/@context over,
     *   bv. een ImageObject zonder url), dan gaat dat object ook weg.
     * - Verandert er niets, dan komt de invoer byte-voor-byte terug (geen herformattering).
     * - Ongeldige JSON blijft ongemoeid.
     *
     * @param array<string,mixed> $scalars
     * @param array<string,mixed> $arrays
     */
    public static function drop_empty_placeholders(string $json, array $scalars, array $arrays): string {
        $empty = [];
        foreach ($scalars as $key => $value) {
            if (trim((string) $value) === '') $empty['{{' . $key . '}}'] = true;
        }
        foreach ($arrays as $key => $value) {
            if ($value === [] || $value === null || $value === '') $empty['{{' . $key . '}}'] = true;
        }
        if (!$empty) return $json;

        $any = false;
        foreach (array_keys($empty) as $token) {
            if (strpos($json, $token) !== false) { $any = true; break; }
        }
        if (!$any) return $json;

        $data = json_decode($json, true);
        if (!is_array($data)) return $json;

        $pruned = self::prune_empty($data, $empty);
        if ($pruned === $data) return $json;

        $out = wp_json_encode($pruned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($out) ? $out : $json;
    }

    /**
     * @param array<string,bool> $empty Tokens ("{{x}}") die leeg uitkomen.
     */
    public static function prune_empty(array $node, array $empty): array {
        $is_list = $node === [] || array_keys($node) === range(0, count($node) - 1);
        $out     = [];
        foreach ($node as $key => $value) {
            if (is_string($value) && isset($empty[trim($value)])) {
                continue;
            }
            if (is_array($value)) {
                $child = self::prune_empty($value, $empty);
                if ($child !== $value && !array_diff(array_keys($child), ['@type', '@id', '@context'])) {
                    continue; // leeg omhulsel (of lege lijst) na het weglaten
                }
                $value = $child;
            }
            if ($is_list) {
                $out[] = $value;
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Lijst met beschikbare placeholders voor de inspector hint.
     */
    public static function available_placeholders(): array {
        return [
            'site_name'         => 'Site naam (bv. "Sabé Verpakkingen")',
            'home_url'          => 'Homepage URL (bv. "https://sabe-verpakkingen.nl/")',
            'org_id'            => 'Organization @id (bv. "https://sabe-verpakkingen.nl/#organization")',
            'url'               => 'Huidige pagina URL',
            'name'              => 'Naam van huidige categorie/term/post',
            'slug'              => 'Slug van huidige term',
            'description'       => 'Omschrijving van huidige term',
            'group_id'          => 'ProductGroup @id (URL + #productgroup)',
            'breadcrumb_id'     => 'BreadcrumbList @id (URL + #breadcrumbs)',
            'breadcrumb_items'  => '[ARRAY] BreadcrumbList itemListElement — auto opgebouwd uit hiërarchie',
            'children_variants'      => '[ARRAY] sub-categorieën — basic (name/url/sku/image)',
            'children_variants_rich' => '[ARRAY] sub-categorieën met volledige Offer + Shipping + Return policy',
            'product_variants'       => '[ARRAY] WooCommerce producten met prijs (voor L3 leaf-categorieën)',

            // Blog post / page placeholders
            'date_published'         => 'ISO 8601 publicatie-datum (BlogPosting)',
            'date_modified'          => 'ISO 8601 laatste-gewijzigd datum',
            'author_name'            => 'Naam van post-auteur',
            'featured_image'         => 'URL van uitgelichte afbeelding (large)',
            'org_logo'               => 'URL van het bedrijfslogo (instelling "Logo" van de Structured Data-add-on). Leeg = key wordt weggelaten',
            'term_image'             => 'URL van de categorieafbeelding (WC-thumbnail; is die leeg of een logo → grootste subcategorie → eerste product). Leeg = key wordt weggelaten',

            // Productpagina-placeholders (alleen gevuld op single product)
            'product_name'                 => 'Naam van WooCommerce product',
            'product_sku'                  => 'SKU van WooCommerce product',
            'product_category'             => 'Naam van primaire WC-categorie',
            'product_description'          => 'Korte beschrijving van product',
            'product_price_excl'           => 'Productprijs excl. BTW',
            'product_availability'         => 'In/Out of stock URL (schema.org/InStock of /OutOfStock)',
            'product_images'               => '[ARRAY] alle productafbeeldingen (featured + gallery)',
            'product_attributes'           => '[ARRAY] zichtbare productattributen als PropertyValue[]',
            'product_price_specifications' => '[ARRAY] UnitPriceSpecification ex/incl BTW',
        ];
    }

    private function build_scalar_vars(array $context, ?string $json = null): array {
        $term = $context['term'] ?? null;
        $url  = $context['url'] ?? home_url('/');

        // name/slug/description: bij term uit term, anders uit post (page/post/blog_index)
        $name = $slug = $description = '';
        if ($term) {
            $name        = $term->name;
            $slug        = $term->slug;
            $description = wp_strip_all_tags($term->description ?: $term->name);
        } elseif (!empty($context['post_id'])) {
            $post = get_post($context['post_id']);
            if ($post) {
                $name = get_the_title($post);
                $slug = $post->post_name;
                // Voorkeur: Yoast metadesc → excerpt → trimmed content
                $yoast_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
                if (!empty($yoast_desc)) {
                    $description = $yoast_desc;
                } elseif (!empty($post->post_excerpt)) {
                    $description = wp_strip_all_tags($post->post_excerpt);
                } else {
                    $description = wp_trim_words(wp_strip_all_tags($post->post_content), 30);
                }
            }
        }

        $vars = [
            'site_name'     => get_bloginfo('name'),
            'home_url'      => trailingslashit(home_url('/')),
            'org_id'        => trailingslashit(home_url('/')) . '#organization',
            'url'           => $url,
            'name'          => $name,
            'slug'          => $slug,
            'description'   => $description,
            'group_id'        => trailingslashit($url) . '#productgroup',
            'breadcrumb_id'   => trailingslashit($url) . '#breadcrumbs',
            'date_published'  => '',
            'date_modified'   => '',
            'author_name'     => '',
            'featured_image'  => '',
            'term_image'      => '',
            'org_logo'        => trim((string) get_option('rr_sd_org_logo', '')),
        ];

        // Categorieafbeelding: pas uitrekenen als de template hem ook gebruikt (dit kost queries).
        if ($term && class_exists('RR_SD_Term_Image') && ($json === null || strpos($json, '{{term_image}}') !== false)) {
            $vars['term_image'] = RR_SD_Term_Image::for_term($term);
        }

        // Post-specifieke data (voor blog posts / pages)
        if (!empty($context['post_id']) && empty($vars['product_name'])) {
            $post = get_post($context['post_id']);
            if ($post) {
                $vars['date_published'] = mysql2date('c', $post->post_date_gmt ?: $post->post_date, false);
                $vars['date_modified']  = mysql2date('c', $post->post_modified_gmt ?: $post->post_modified, false);
                $author = get_userdata((int) $post->post_author);
                $vars['author_name']    = $author ? $author->display_name : '';
                $thumb_id = get_post_thumbnail_id($post->ID);
                if ($thumb_id) {
                    $img = wp_get_attachment_image_url($thumb_id, 'large');
                    if ($img) $vars['featured_image'] = $img;
                }
            }
        }

        if (!empty($context['post_id']) && function_exists('wc_get_product')) {
            $product = wc_get_product($context['post_id']);
            if ($product) {
                $cats       = wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
                $price_excl = wc_get_price_excluding_tax($product);

                $vars['product_name']         = $product->get_name();
                $vars['product_sku']          = $product->get_sku() ?: 'product-' . $product->get_id();
                $vars['product_category']     = $cats ? end($cats) : '';
                $vars['product_description']  = wp_strip_all_tags($product->get_short_description() ?: $product->get_name());
                $vars['product_price_excl']   = is_numeric($price_excl) ? number_format((float) $price_excl, 2, '.', '') : '';
                $vars['product_availability'] = $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock';
            }
        }

        return $vars;
    }

    /**
     * Bouw alle array-vars op basis van context.
     */
    private function build_array_vars(array $context, ?string $json = null): array {
        $builders = [
            'breadcrumb_items'       => fn() => $this->build_breadcrumb_items($context),
            'children_variants'      => fn() => $this->build_children_variants($context),
            'children_variants_rich' => fn() => $this->build_children_variants_rich($context),
            'product_variants'       => fn() => $this->build_product_variants($context),
        ];

        // Alleen bouwen wat de JSON gebruikt: product_variants laadt tot 100 producten
        // en kostte op categorieën met veel producten het PHP-geheugen, ook als de
        // template de placeholder helemaal niet bevat.
        $vars = [];
        foreach ($builders as $key => $build) {
            if ($json !== null && strpos($json, '{{' . $key . '}}') === false) continue;
            $vars[$key] = $build();
        }

        $needs_product = $json === null
            || strpos($json, '{{product_images}}') !== false
            || strpos($json, '{{product_attributes}}') !== false
            || strpos($json, '{{product_price_specifications}}') !== false;

        if ($needs_product && !empty($context['post_id']) && function_exists('wc_get_product')) {
            $product = wc_get_product($context['post_id']);
            if ($product) {
                $vars['product_images']               = $this->build_product_images($product);
                $vars['product_attributes']           = $this->build_product_attributes($product);
                $vars['product_price_specifications'] = $this->build_product_price_specifications($product);
            }
        }

        return $vars;
    }

    private function build_product_images($product): array {
        $images = [];
        if ($product->get_image_id()) {
            $images[] = wp_get_attachment_image_url($product->get_image_id(), 'large');
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $images[] = wp_get_attachment_image_url($gid, 'large');
        }
        return array_values(array_filter($images));
    }

    private function build_product_attributes($product): array {
        $props = [];
        foreach ($product->get_attributes() as $attr) {
            if (!is_object($attr) || !$attr->get_visible()) continue;
            $label = wc_attribute_label($attr->get_name(), $product);
            $value = $product->get_attribute($attr->get_name());
            if ($label !== '' && $value !== '') {
                $props[] = ['@type' => 'PropertyValue', 'name' => $label, 'value' => $value];
            }
        }
        return $props;
    }

    private function build_product_price_specifications($product): array {
        $excl = (float) wc_get_price_excluding_tax($product);
        $incl = (float) wc_get_price_including_tax($product);
        if ($excl <= 0 && $incl <= 0) return [];
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        return [
            ['@type' => 'UnitPriceSpecification', 'priceCurrency' => $currency, 'price' => number_format($excl, 2, '.', ''), 'valueAddedTaxIncluded' => false],
            ['@type' => 'UnitPriceSpecification', 'priceCurrency' => $currency, 'price' => number_format($incl, 2, '.', ''), 'valueAddedTaxIncluded' => true],
        ];
    }

    private function build_breadcrumb_items(array $context): array {
        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => __('Home', 'rankrepair'),
            'item'     => trailingslashit(home_url('/')),
        ]];
        $pos = 2;

        $term = $context['term'] ?? null;
        if ($term && !empty($term->term_id)) {
            $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy));
            foreach ($ancestors as $anc_id) {
                $anc = get_term($anc_id, $term->taxonomy);
                if ($anc && !is_wp_error($anc)) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => $anc->name,
                        'item'     => get_term_link($anc),
                    ];
                }
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $term->name,
                'item'     => get_term_link($term),
            ];
        } elseif (!empty($context['post_id']) && ($context['type'] ?? '') === 'product' && function_exists('wc_get_product_terms')) {
            $cats = wc_get_product_terms($context['post_id'], 'product_cat', [
                'orderby' => 'parent',
                'order'   => 'ASC',
            ]);
            if (!empty($cats) && !is_wp_error($cats)) {
                $primary   = end($cats);
                $ancestors = array_reverse(get_ancestors($primary->term_id, 'product_cat'));
                foreach ($ancestors as $aid) {
                    $a = get_term($aid, 'product_cat');
                    if ($a && !is_wp_error($a)) {
                        $items[] = [
                            '@type'    => 'ListItem',
                            'position' => $pos++,
                            'name'     => $a->name,
                            'item'     => get_term_link($a),
                        ];
                    }
                }
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $primary->name,
                    'item'     => get_term_link($primary),
                ];
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => get_the_title($context['post_id']),
                'item'     => $context['url'] ?? get_permalink($context['post_id']),
            ];
        } elseif (!empty($context['post_id'])) {
            $type    = $context['type'] ?? '';
            $post_id = (int) $context['post_id'];
            $post    = get_post($post_id);

            // Voor blog posts: Home → Blog overzicht → Post titel
            if ($post && $type === 'post') {
                $blog_id = (int) get_option('page_for_posts');
                if ($blog_id && get_post($blog_id)) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => get_the_title($blog_id),
                        'item'     => get_permalink($blog_id),
                    ];
                }
            }

            // Page-parents (geneste pagina-hiërarchie)
            if ($post) {
                $ancestors = array_reverse(get_post_ancestors($post_id));
                foreach ($ancestors as $anc_id) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => get_the_title($anc_id),
                        'item'     => get_permalink($anc_id),
                    ];
                }
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => get_the_title($post),
                    'item'     => get_permalink($post),
                ];
            }
        }
        return $items;
    }

    /**
     * Bouw hasVariant array van sub-categorieën (basic versie — alleen name/url/sku/image).
     */
    private function build_children_variants(array $context): array {
        return $this->build_children_variants_internal($context, false);
    }

    /**
     * Bouw hasVariant array met volledige Offer + ShippingDetails + ReturnPolicy.
     * Gebruik {{children_variants_rich}} in templates voor uitgebreide variant-data.
     */
    private function build_children_variants_rich(array $context): array {
        return $this->build_children_variants_internal($context, true);
    }

    private function build_children_variants_internal(array $context, bool $rich): array {
        $term = $context['term'] ?? null;
        if (!$term || empty($term->term_id)) return [];

        $children = get_terms([
            'taxonomy'   => $term->taxonomy,
            'parent'     => $term->term_id,
            'hide_empty' => false,
        ]);
        if (is_wp_error($children) || empty($children)) return [];

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
        $country  = trim(get_option('rr_sd_org_country', 'NL')) ?: 'NL';
        $valid_until = date('Y-12-31', strtotime('+2 years'));

        $variants = [];
        foreach ($children as $child) {
            $thumb_id = (int) get_term_meta($child->term_id, 'thumbnail_id', true);
            $img      = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
            $url      = get_term_link($child);

            $v = [
                '@type'       => 'Product',
                'name'        => $child->name,
                'description' => wp_strip_all_tags($child->description ?: $child->name),
                'url'         => $url,
                'sku'         => $child->slug,
            ];
            if ($img) $v['image'] = $img;

            if ($rich) {
                $v['offers'] = [
                    '@type'           => 'Offer',
                    'url'             => $url,
                    'priceCurrency'   => $currency,
                    'price'           => '0.00',
                    'availability'    => 'https://schema.org/InStock',
                    'priceValidUntil' => $valid_until,
                    'shippingDetails' => [
                        '@type' => 'OfferShippingDetails',
                        'shippingDestination' => [
                            '@type'          => 'DefinedRegion',
                            'addressCountry' => $country,
                        ],
                    ],
                    'hasMerchantReturnPolicy' => [
                        '@type'                 => 'MerchantReturnPolicy',
                        'applicableCountry'     => $country,
                        'returnPolicyCategory'  => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                        'merchantReturnDays'    => 14,
                    ],
                ];
            }
            $variants[] = $v;
        }
        return $variants;
    }

    /**
     * Bouw hasVariant array van WooCommerce producten (voor L3 leaf-categorieën).
     */
    private function build_product_variants(array $context): array {
        $term = $context['term'] ?? null;
        if (!$term || empty($term->term_id)) return [];
        if (!class_exists('RR_SD_Builder')) return [];

        $group_id = trailingslashit($context['url'] ?? '') . '#productgroup';

        // Lichte query i.p.v. wc_get_products(): zie RR_SD_Builder::light_products().
        $variants = [];
        foreach (RR_SD_Builder::light_products($term->slug, 100) as $row) {
            $variants[] = RR_SD_Builder::light_row_to_variant($row, $group_id, $term);
        }
        return $variants;
    }

    /**
     * Voorbeeld-templates per pagina-type. Gebruikt placeholders die op
     * render-tijd worden vervangen met live data.
     */
    public static function get_example_template(string $type): string {
        switch ($type) {
            case 'product_cat_l1':
                return self::example_l1();
            case 'product_cat_l2':
                return self::example_l2();
            case 'product_cat_l3':
                return self::example_l3();
            case 'product':
                return self::example_product();
            case 'home':
                return self::example_home();
            case 'blog_index':
                return self::example_blog_index();
            case 'post':
                return self::example_blog_post();
            case 'category':
                return self::example_blog_category();
            case 'page':
                return self::example_page();
            case 'contact':
                return self::example_contact();
            default:
                return '';
        }
    }

    private static function example_contact(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'         => 'ContactPage',
                    '@id'           => '{{url}}#contactpage',
                    'name'          => '{{name}}',
                    'url'           => '{{url}}',
                    'description'   => '{{description}}',
                    'isPartOf'      => ['@id' => '{{home_url}}'],
                    'mainEntity'    => [
                        '@type'         => 'Organization',
                        '@id'           => '{{org_id}}',
                        'name'          => '{{site_name}}',
                        'url'           => '{{home_url}}',
                        'contactPoint'  => [
                            '@type'             => 'ContactPoint',
                            'contactType'       => 'Customer Service',
                            'telephone'         => '+31318520298',
                            'email'             => 'info@sabeverpakkingen.nl',
                            'areaServed'        => 'NL',
                            'availableLanguage' => ['Dutch', 'English'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private static function example_blog_index(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'       => 'Blog',
                    '@id'         => '{{url}}#blog',
                    'name'        => '{{name}}',
                    'url'         => '{{url}}',
                    'description' => '{{description}}',
                    'publisher'   => ['@id' => '{{org_id}}'],
                ],
            ],
        ]);
    }

    private static function example_blog_post(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'            => 'BlogPosting',
                    '@id'              => '{{url}}#article',
                    'headline'         => '{{name}}',
                    'url'              => '{{url}}',
                    'description'      => '{{description}}',
                    'mainEntityOfPage' => ['@id' => '{{url}}'],
                    'publisher'        => ['@id' => '{{org_id}}'],
                ],
            ],
        ]);
    }

    private static function example_blog_category(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'       => 'CollectionPage',
                    '@id'         => '{{url}}#collection',
                    'name'        => '{{name}}',
                    'url'         => '{{url}}',
                    'image'       => '{{term_image}}',
                    'description' => '{{description}}',
                    'isPartOf'    => ['@id' => '{{home_url}}#blog'],
                ],
            ],
        ]);
    }

    private static function example_page(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'       => 'WebPage',
                    '@id'         => '{{url}}',
                    'name'        => '{{name}}',
                    'url'         => '{{url}}',
                    'description' => '{{description}}',
                    'isPartOf'    => ['@id' => '{{home_url}}'],
                    'publisher'   => ['@id' => '{{org_id}}'],
                ],
            ],
        ]);
    }

    private static function example_home(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
            ],
        ]);
    }

    private static function example_l1(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
            ],
        ]);
    }

    private static function example_l2(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'          => 'ProductGroup',
                    '@id'            => '{{group_id}}',
                    'name'           => '{{name}}',
                    'productGroupID' => '{{slug}}',
                    'url'            => '{{url}}',
                    'description'    => '{{description}}',
                    'image'          => '{{term_image}}',
                    'brand'          => ['@id' => '{{org_id}}'],
                    'hasVariant'     => '{{children_variants}}',
                ],
            ],
        ]);
    }

    private static function example_product(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'              => 'Product',
                    'name'               => '{{product_name}}',
                    'url'                => '{{url}}',
                    'sku'                => '{{product_sku}}',
                    'category'           => '{{product_category}}',
                    'image'              => '{{product_images}}',
                    'description'        => '{{product_description}}',
                    'additionalProperty' => '{{product_attributes}}',
                    'offers'             => [
                        '@type'              => 'Offer',
                        'url'                => '{{url}}',
                        'availability'       => '{{product_availability}}',
                        'priceCurrency'      => 'EUR',
                        'price'              => '{{product_price_excl}}',
                        'seller'             => ['@id' => '{{org_id}}'],
                        'priceSpecification' => '{{product_price_specifications}}',
                    ],
                ],
            ],
        ]);
    }

    private static function example_l3(): string {
        return self::pretty_json([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Organization',
                    '@id'   => '{{org_id}}',
                    'name'  => '{{site_name}}',
                    'url'   => '{{home_url}}',
                    'logo'  => '{{org_logo}}',
                ],
                [
                    '@type'           => 'BreadcrumbList',
                    '@id'             => '{{breadcrumb_id}}',
                    'itemListElement' => '{{breadcrumb_items}}',
                ],
                [
                    '@type'          => 'ProductGroup',
                    '@id'            => '{{group_id}}',
                    'name'           => '{{name}}',
                    'productGroupID' => '{{slug}}',
                    'url'            => '{{url}}',
                    'description'    => '{{description}}',
                    'image'          => '{{term_image}}',
                    'brand'          => ['@id' => '{{org_id}}'],
                    'hasVariant'     => '{{product_variants}}',
                ],
            ],
        ]);
    }

    private static function pretty_json(array $data): string {
        return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
