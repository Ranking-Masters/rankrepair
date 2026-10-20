<?php
/**
 * Schema Builder — bouwt JSON-LD arrays voor Organization, BreadcrumbList, Product etc.
 * Placeholder implementaties; v1 core builders worden stapsgewijs uitgewerkt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Builder {

    /**
     * Mag de vaste bedrijfsafbeelding (rr_sd_org_image) bij de Organization van deze pagina?
     * Niet op term-pagina's (productcategorieën, blogcategorieën, tags). Aan te passen met
     * het filter rr_sd_org_image_on_term_pages.
     */
    public static function org_image_allowed(array $context): bool {
        if (empty($context['term'])) {
            return true;
        }
        return (bool) apply_filters('rr_sd_org_image_on_term_pages', false, $context);
    }

    public function build_organization(array $context): ?array {
        $name = trim(get_option('rr_sd_org_name', '') ?: get_bloginfo('name'));
        if ($name === '') return null;

        $org = [
            '@type' => 'Organization',
            '@id'   => home_url('/') . '#organization',
            'name'  => $name,
            'url'   => home_url('/'),
        ];

        $legal = trim(get_option('rr_sd_org_legal_name', ''));
        if ($legal) $org['legalName'] = $legal;

        $logo = trim(get_option('rr_sd_org_logo', ''));
        if ($logo) $org['logo'] = $logo;

        // Op term-pagina's (categorieën) hoort bij de Organization alleen het logo: een vaste
        // bedrijfsafbeelding zou daar op elke categorie dezelfde foto als "afbeelding van de pagina" geven.
        $image = trim(get_option('rr_sd_org_image', ''));
        if ($image && self::org_image_allowed($context)) $org['image'] = $image;

        $desc = trim(get_option('rr_sd_org_description', ''));
        if ($desc) $org['description'] = $desc;

        $phone = trim(get_option('rr_sd_org_phone', ''));
        if ($phone) $org['telephone'] = $phone;

        $email = trim(get_option('rr_sd_org_email', ''));
        if ($email) $org['email'] = $email;

        $street  = trim(get_option('rr_sd_org_street', ''));
        $postal  = trim(get_option('rr_sd_org_postal', ''));
        $city    = trim(get_option('rr_sd_org_city', ''));
        $country = trim(get_option('rr_sd_org_country', 'NL'));
        if ($street || $postal || $city) {
            $org['address'] = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $street ?: null,
                'postalCode'      => $postal ?: null,
                'addressLocality' => $city   ?: null,
                'addressCountry'  => $country ?: null,
            ]);
        }

        $kvk = trim(get_option('rr_sd_org_kvk', ''));
        if ($kvk) {
            $org['identifier'] = [[
                '@type'      => 'PropertyValue',
                'propertyID' => 'KvK nummer',
                'value'      => $kvk,
            ]];
        }

        $founding = trim(get_option('rr_sd_org_founding', ''));
        if ($founding) $org['foundingDate'] = $founding;

        $rating_value = trim(get_option('rr_sd_rating_value', ''));
        $rating_count = trim(get_option('rr_sd_rating_count', ''));
        if ($rating_value && $rating_count) {
            $org['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $rating_value,
                'reviewCount' => (int) $rating_count,
            ];
        }

        $social_raw = trim(get_option('rr_sd_org_social', ''));
        if ($social_raw) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $social_raw))));
            if (!empty($lines)) $org['sameAs'] = $lines;
        }

        return $org;
    }

    /**
     * Bouw losse Review items uit de handmatig ingevoerde reviews.
     */
    public function build_reviews(): array {
        $raw = get_option('rr_sd_reviews', '[]');
        $reviews = json_decode($raw, true);
        if (!is_array($reviews)) return [];

        $org_name = trim(get_option('rr_sd_org_name', '') ?: get_bloginfo('name'));
        $org_url  = home_url('/');

        $items = [];
        foreach ($reviews as $r) {
            if (empty($r['name']) && empty($r['reviewBody'])) continue;

            $author_type = ($r['author_type'] ?? 'Organization') === 'Person' ? 'Person' : 'Organization';
            $author_name = $r['author_name'] ?? '';
            if (!$author_name) continue;

            $review = [
                '@type'      => 'Review',
                'name'       => $r['name'] ?? '',
                'reviewBody' => $r['reviewBody'] ?? '',
                'author'     => [
                    '@type' => $author_type,
                    'name'  => $author_name,
                ],
                'itemReviewed' => [
                    '@type' => 'Organization',
                    'name'  => $org_name,
                    'url'   => $org_url,
                ],
            ];
            if (!empty($r['datePublished'])) $review['datePublished'] = $r['datePublished'];
            if (!empty($r['ratingValue'])) {
                $review['reviewRating'] = [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) $r['ratingValue'],
                    'bestRating'  => '5',
                ];
            }
            $items[] = $review;
        }
        return $items;
    }

    public function build_website(): array {
        return [
            '@type'           => 'WebSite',
            'url'             => home_url('/'),
            'name'            => get_bloginfo('name'),
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url('/?s={search_term_string}'),
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    public function build_breadcrumbs(array $context): ?array {
        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => __('Home', 'rankrepair'),
            'item'     => home_url('/'),
        ]];
        $pos = 2;

        if ($context['type'] === 'product' && !empty($context['post_id']) && function_exists('wc_get_product')) {
            $product   = wc_get_product($context['post_id']);
            $terms     = $product ? wc_get_product_terms($product->get_id(), 'product_cat', ['orderby' => 'parent', 'order' => 'DESC']) : [];
            if (!empty($terms)) {
                $main_term = $terms[0];
                // Ouder-terms eerst
                $ancestors = array_reverse(get_ancestors($main_term->term_id, 'product_cat'));
                foreach ($ancestors as $anc_id) {
                    $anc = get_term($anc_id, 'product_cat');
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
                    'name'     => $main_term->name,
                    'item'     => get_term_link($main_term),
                ];
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => get_the_title($context['post_id']),
                'item'     => $context['url'],
            ];
        } elseif ((strpos($context['type'], 'product_cat') === 0 || $context['type'] === 'tax') && !empty($context['term'])) {
            $term = $context['term'];
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
        } elseif (in_array($context['type'], ['post', 'page'], true) && !empty($context['post_id'])) {
            $post = get_post($context['post_id']);
            if ($post && $post->post_parent) {
                $ancestors = array_reverse(get_post_ancestors($post->ID));
                foreach ($ancestors as $anc_id) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => get_the_title($anc_id),
                        'item'     => get_permalink($anc_id),
                    ];
                }
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => get_the_title($context['post_id']),
                'item'     => $context['url'],
            ];
        }

        if (count($items) < 2) return null;

        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    public function build_product(array $context): ?array {
        if (empty($context['post_id']) || !function_exists('wc_get_product')) return null;
        $product = wc_get_product($context['post_id']);
        if (!$product) return null;

        $img_ids = array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids() ?: []));
        $images  = array_values(array_filter(array_map(fn($id) => wp_get_attachment_url($id), $img_ids)));

        $schema = [
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'url'         => $context['url'],
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description()),
        ];
        if ($images)              $schema['image']    = count($images) === 1 ? $images[0] : $images;
        if ($product->get_sku())  $schema['sku']      = $product->get_sku();

        $cats = wc_get_product_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
        if (!empty($cats)) $schema['category'] = $cats[0];

        // Offer
        $price = $product->get_price();
        if ($price !== '' && $price !== null) {
            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
            $schema['offers'] = [
                '@type'         => 'Offer',
                'url'           => $context['url'],
                'price'         => (string) $price,
                'priceCurrency' => $currency,
                'availability'  => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'seller'        => ['@id' => home_url('/') . '#organization'],
            ];
        }

        // AggregateRating uit WC reviews
        if ($product->get_review_count() > 0 && $product->get_average_rating() > 0) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $product->get_average_rating(),
                'reviewCount' => (int) $product->get_review_count(),
            ];
        }

        return $schema;
    }

    public function build_product_group(array $context): ?array {
        if (empty($context['term_id'])) return null;
        $term = $context['term'];
        if (!$term) return null;

        $group_id = trailingslashit($context['url']) . '#productgroup';

        $schema = [
            '@type'          => 'ProductGroup',
            '@id'            => $group_id,
            'name'           => $term->name,
            'productGroupID' => $term->slug,
            'url'            => $context['url'],
            'description'    => wp_strip_all_tags($term->description ?: ''),
            'brand'          => ['@id' => trailingslashit(home_url('/')) . '#organization'],
        ];

        // Haal sub-categorieën op
        $children = get_terms([
            'taxonomy'   => $term->taxonomy,
            'parent'     => $term->term_id,
            'hide_empty' => false,
        ]);

        if (!is_wp_error($children) && !empty($children)) {
            // Niet-leaf categorie: kinderen als ProductGroup-variants
            $schema['hasVariant'] = array_values(array_map(function($child) {
                $thumb_id = function_exists('get_term_meta') ? (int) get_term_meta($child->term_id, 'thumbnail_id', true) : 0;
                $img      = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
                $variant = [
                    '@type'       => 'Product',
                    'name'        => $child->name,
                    'description' => wp_strip_all_tags($child->description ?: $child->name),
                    'url'         => get_term_link($child),
                    'sku'         => $child->slug,
                ];
                if ($img) $variant['image'] = $img;
                return $variant;
            }, $children));
            return $schema;
        }

        // Leaf categorie: haal echte WooCommerce producten op met prijzen
        $rows = self::light_products($term->slug, 50);
        if (empty($rows)) return $schema;

        $variants = [];
        foreach ($rows as $row) {
            $variants[] = self::light_row_to_variant($row, $group_id, $term);
        }
        $schema['hasVariant'] = $variants;
        return $schema;
    }

    /**
     * Lichte productlijst voor hasVariant: alleen de velden die het schema nodig heeft.
     *
     * wc_get_products() laadt per product de volledige postmeta (op sites met veel
     * ACF-velden ~1000 rijen per product, tientallen MB bij 50 producten) en liet
     * categoriepagina's met veel producten het PHP-geheugen overschrijden. Hier
     * halen we de producten op zonder meta-cache en lezen we de vier benodigde
     * meta-keys met één query.
     */
    public static function light_products(string $term_slug, int $limit): array {
        global $wpdb;

        $ids = get_posts([
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'orderby'                => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'no_found_rows'          => true,
            'suppress_filters'       => false, // zoals wc_get_products(): taal-/WPML-filters blijven werken
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query'              => [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $term_slug,
            ]],
        ]);
        if (empty($ids)) return [];
        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);

        $posts = [];
        foreach ($wpdb->get_results("SELECT ID, post_title, post_excerpt FROM {$wpdb->posts} WHERE ID IN ($in)") as $p) {
            $posts[(int) $p->ID] = $p;
        }
        $meta = [];
        $keys = "'_sku','_price','_stock_status','_thumbnail_id'";
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ($keys)") as $m) {
            $meta[(int) $m->post_id][$m->meta_key] = $m->meta_value;
        }

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($posts[$id])) continue;
            $rows[] = [
                'id'      => $id,
                'name'    => (string) $posts[$id]->post_title,
                'excerpt' => (string) $posts[$id]->post_excerpt,
                'sku'     => (string) ($meta[$id]['_sku'] ?? ''),
                'price'   => $meta[$id]['_price'] ?? '',
                'stock'   => (string) ($meta[$id]['_stock_status'] ?? 'instock'),
                'thumb'   => (int) ($meta[$id]['_thumbnail_id'] ?? 0),
                'url'     => get_permalink($id),
            ];
        }
        return $rows;
    }

    /**
     * Product-variant (zelfde uitvoer als wc_product_to_variant) uit een rij van light_products().
     */
    public static function light_row_to_variant(array $row, string $group_id, $group_term): array {
        $image = $row['thumb'] ? wp_get_attachment_image_url($row['thumb'], 'medium') : '';

        $variant = [
            '@type'       => 'Product',
            'name'        => $row['name'],
            'description' => wp_strip_all_tags($row['excerpt'] ?: $row['name']),
            'sku'         => $row['sku'] ?: 'product-' . $row['id'],
            'url'         => $row['url'],
        ];
        if ($image) $variant['image'] = $image;

        $variant['isVariantOf'] = [
            '@type'          => 'ProductGroup',
            '@id'            => $group_id,
            'name'           => $group_term->name,
            'productGroupID' => $group_term->slug,
        ];

        if ($row['price'] !== '' && $row['price'] !== null) {
            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
            $variant['offers'] = [
                '@type'           => 'Offer',
                'url'             => $row['url'],
                'priceCurrency'   => $currency,
                'price'           => number_format((float) $row['price'], 2, '.', ''),
                'availability'    => $row['stock'] !== 'outofstock'
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'priceValidUntil' => date('Y-12-31', strtotime('+2 years')),
            ];
        }

        return $variant;
    }

    /**
     * Converteer een WooCommerce product naar een Product-variant binnen een ProductGroup.
     */
    private function wc_product_to_variant($product, string $group_id, $group_term): array {
        $url   = $product->get_permalink();
        $image = '';
        $img_id = $product->get_image_id();
        if ($img_id) $image = wp_get_attachment_image_url($img_id, 'medium');

        $variant = [
            '@type'       => 'Product',
            'name'        => $product->get_name(),
            'description' => wp_strip_all_tags($product->get_short_description() ?: $product->get_name()),
            'sku'         => $product->get_sku() ?: 'product-' . $product->get_id(),
            'url'         => $url,
        ];
        if ($image) $variant['image'] = $image;

        $variant['isVariantOf'] = [
            '@type'          => 'ProductGroup',
            '@id'            => $group_id,
            'name'           => $group_term->name,
            'productGroupID' => $group_term->slug,
        ];

        // Prijs + voorraadstatus
        $price = $product->get_price();
        if ($price !== '' && $price !== null) {
            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
            $variant['offers'] = [
                '@type'           => 'Offer',
                'url'             => $url,
                'priceCurrency'   => $currency,
                'price'           => number_format((float) $price, 2, '.', ''),
                'availability'    => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'priceValidUntil' => date('Y-12-31', strtotime('+2 years')),
            ];
        }

        return $variant;
    }

    public function build_faqpage(array $context): ?array {
        if (empty($context['post_id'])) return null;
        $post = get_post($context['post_id']);
        if (!$post) return null;

        $faqs = [];

        // 1. Gutenberg FAQ-block (wp/core-faq of custom)
        if (has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);
            $faqs = array_merge($faqs, $this->extract_faq_from_blocks($blocks));
        }

        // 2. ACF repeater "faqs" met subfields question/answer
        if (function_exists('have_rows') && have_rows('faqs', $post->ID)) {
            while (have_rows('faqs', $post->ID)) {
                the_row();
                $q = get_sub_field('question') ?? get_sub_field('vraag');
                $a = get_sub_field('answer')   ?? get_sub_field('antwoord');
                if ($q && $a) {
                    $faqs[] = ['q' => $q, 'a' => $a];
                }
            }
        }

        if (empty($faqs)) return null;

        return [
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(function($f) {
                return [
                    '@type'          => 'Question',
                    'name'           => wp_strip_all_tags($f['q']),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text'  => wp_strip_all_tags($f['a']),
                    ],
                ];
            }, $faqs),
        ];
    }

    private function extract_faq_from_blocks(array $blocks): array {
        $out = [];
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? '';
            // Common FAQ-achtige blocks
            if (in_array($name, ['yoast/faq-block', 'rank-math/faq-block'], true)) {
                $questions = $block['attrs']['questions'] ?? [];
                foreach ($questions as $q) {
                    if (!empty($q['jsonQuestion']) && !empty($q['jsonAnswer'])) {
                        $out[] = ['q' => $q['jsonQuestion'], 'a' => $q['jsonAnswer']];
                    }
                }
            }
            // Recursief in inner blocks
            if (!empty($block['innerBlocks'])) {
                $out = array_merge($out, $this->extract_faq_from_blocks($block['innerBlocks']));
            }
        }
        return $out;
    }
}
