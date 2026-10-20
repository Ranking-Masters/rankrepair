<?php
/**
 * Structured Data Scanner — fetcht een sample pagina per type, parseert alle JSON-LD,
 * rendert elk schema als uitklapbaar blok met volledige property-tabel + validatie.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Scanner {

    /** Schema.org required/recommended velden per type (basis-set). */
    private const VALIDATION_RULES = [
        'Organization'    => ['required' => ['name', 'url'],                'recommended' => ['logo', 'sameAs', 'contactPoint']],
        'LocalBusiness'   => ['required' => ['name', 'address'],            'recommended' => ['telephone', 'openingHours']],
        'WebSite'         => ['required' => ['url', 'name'],                'recommended' => ['potentialAction']],
        'WebPage'         => ['required' => ['@id', 'url'],                 'recommended' => ['name', 'description', 'inLanguage']],
        'Product'         => ['required' => ['name'],                       'recommended' => ['image', 'description', 'offers', 'sku']],
        'Offer'           => ['required' => ['price', 'priceCurrency'],     'recommended' => ['availability', 'url']],
        'BreadcrumbList'  => ['required' => ['itemListElement'],            'recommended' => []],
        'FAQPage'         => ['required' => ['mainEntity'],                 'recommended' => []],
        'Article'         => ['required' => ['headline'],                   'recommended' => ['author', 'datePublished', 'image']],
        'AggregateRating' => ['required' => ['ratingValue', 'reviewCount'], 'recommended' => []],
    ];

    public function render(): void {
        $types    = $this->get_page_types();
        $selected = $_GET['sd_type'] ?? 'home';
        if (!isset($types[$selected])) $selected = array_key_first($types);

        // Save custom JSON-LD voor dit pagina-type
        $saved_msg = '';
        if (isset($_POST['rr_sd_save_custom']) && check_admin_referer('rr_sd_custom_' . $selected)) {
            $raw = wp_unslash($_POST['rr_sd_custom_jsonld'] ?? '');
            update_option('rr_sd_custom_jsonld_' . $selected, $raw);
            $saved_msg = __('Opgeslagen. Ververs sample pagina en de inspector om veranderingen te zien (wis eventueel caches).', 'rankrepair');
        }

        $custom_value = get_option('rr_sd_custom_jsonld_' . $selected, '');
        $sample_url   = $types[$selected]['url'] ?? home_url('/');
        $schemas      = $this->scan_url($sample_url);
        $totals       = $this->aggregate_totals($schemas);
        ?>
        <div class="wrap rr-wrap">
            <div class="rr-header">
                <div class="rr-header-content">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair')); ?>" class="rr-back-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        <?php _e('Terug naar Dashboard', 'rankrepair'); ?>
                    </a>
                    <h1><?php _e('Structured Data', 'rankrepair'); ?></h1>
                    <p class="rr-subtitle"><?php _e('Inspecteer alle JSON-LD schemas per pagina-type.', 'rankrepair'); ?></p>
                </div>
            </div>

            <div class="rr-sd-scanner">
                <aside class="rr-sd-typelist">
                    <?php foreach ($types as $key => $info): ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'rankrepair-structured-data', 'sd_type' => $key], admin_url('admin.php'))); ?>"
                       class="rr-sd-type <?php echo $selected === $key ? 'active' : ''; ?>">
                        <span class="rr-sd-type-label"><?php echo esc_html($info['label']); ?></span>
                        <?php if (!empty($info['count'])): ?>
                        <span class="rr-sd-type-count"><?php echo esc_html($info['count']); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </aside>

                <div class="rr-sd-detail">
                    <div class="rr-sd-detail-head">
                        <h2><?php echo esc_html($types[$selected]['label'] ?? $selected); ?></h2>
                        <p class="rr-sd-sample">
                            <?php _e('Sample URL:', 'rankrepair'); ?>
                            <a href="<?php echo esc_url($sample_url); ?>" target="_blank"><?php echo esc_html($sample_url); ?></a>
                        </p>
                        <div class="rr-sd-totals">
                            <span class="rr-sd-total"><strong><?php echo count($schemas); ?></strong> <?php _e('schemas', 'rankrepair'); ?></span>
                            <span class="rr-sd-total rr-sd-total--danger"><strong><?php echo (int) $totals['errors']; ?></strong> <?php _e('FOUTEN', 'rankrepair'); ?></span>
                            <span class="rr-sd-total rr-sd-total--warning"><strong><?php echo (int) $totals['warnings']; ?></strong> <?php _e('WAARSCHUWINGEN', 'rankrepair'); ?></span>
                            <?php $suggested = $this->suggested_schemas_for($selected, $schemas); ?>
                            <?php if (!empty($suggested)): ?>
                            <span class="rr-sd-total rr-sd-total--add"><strong>+<?php echo count($suggested); ?></strong> <?php _e('aan te vullen', 'rankrepair'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($saved_msg): ?>
                    <div class="notice notice-success" style="margin:0 0 16px;padding:10px 14px"><p><?php echo esc_html($saved_msg); ?></p></div>
                    <?php endif; ?>

                    <?php $this->render_custom_jsonld_box($selected, $custom_value, $types[$selected]['label'] ?? $selected, $types); ?>

                    <?php if (!empty($suggested)): ?>
                    <div class="rr-sd-suggest">
                        <h3><?php _e('Wat RankRepair kan aanvullen', 'rankrepair'); ?></h3>
                        <ul>
                            <?php foreach ($suggested as $s): ?>
                            <li><span class="rr-sd-plus">+</span> <strong><?php echo esc_html($s['type']); ?></strong> <span class="rr-sd-note"><?php echo esc_html($s['note']); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair-settings')); ?>" class="button button-secondary">
                            <?php _e('Configureer in Instellingen →', 'rankrepair'); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($schemas)): ?>
                        <p class="rr-sd-empty"><?php _e('Geen JSON-LD gevonden op deze pagina.', 'rankrepair'); ?></p>
                    <?php else: ?>
                        <div class="rr-sd-schemas">
                            <?php foreach ($schemas as $schema): ?>
                                <?php $this->render_schema_block($schema); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render één schema als accordion-blok met volledige property-tabel.
     */
    private function render_schema_block(array $schema): void {
        $type    = $this->get_type_label($schema['data']['@type'] ?? '(Unknown)');
        $id      = $schema['data']['@id'] ?? '';
        $valid   = $this->validate_schema($schema['data']);
        $actions = $this->get_edit_actions($schema);
        ?>
        <details class="rr-sd-schema">
            <summary class="rr-sd-schema-head">
                <span class="rr-sd-schema-chev">›</span>
                <strong class="rr-sd-schema-type"><?php echo esc_html($type); ?></strong>
                <span class="rr-sd-schema-counts">
                    <span class="rr-sd-count rr-sd-count--danger"><strong><?php echo count($valid['errors']); ?></strong> <?php _e('FOUTEN', 'rankrepair'); ?></span>
                    <span class="rr-sd-count rr-sd-count--warning"><strong><?php echo count($valid['warnings']); ?></strong> <?php _e('WAARSCHUWINGEN', 'rankrepair'); ?></span>
                </span>
                <?php if (!empty($schema['source'])): ?>
                <span class="rr-sd-schema-source"><?php echo esc_html($schema['source']); ?></span>
                <?php endif; ?>
            </summary>
            <div class="rr-sd-schema-body">
                <?php if (!empty($actions)): ?>
                <div class="rr-sd-actions">
                    <?php foreach ($actions as $a): ?>
                    <a href="<?php echo esc_url($a['url']); ?>" class="rr-sd-action-btn" <?php echo !empty($a['new_tab']) ? 'target="_blank"' : ''; ?>>
                        <span class="rr-sd-action-icon">✎</span>
                        <?php echo esc_html($a['label']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($id): ?>
                <p class="rr-sd-schema-id">ID: <?php echo esc_html($id); ?></p>
                <?php endif; ?>

                <?php if (!empty($valid['errors']) || !empty($valid['warnings'])): ?>
                <div class="rr-sd-issues">
                    <?php foreach ($valid['errors'] as $msg): ?>
                    <div class="rr-sd-issue rr-sd-issue--error">✗ <?php echo esc_html($msg); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($valid['warnings'] as $msg): ?>
                    <div class="rr-sd-issue rr-sd-issue--warn">⚠ <?php echo esc_html($msg); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <table class="rr-sd-table">
                    <tbody>
                        <?php $this->render_data_rows($schema['data'], 0); ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    /**
     * Render het "Eigen JSON-LD" paste-veld voor dit pagina-type.
     */
    private function render_custom_jsonld_box(string $selected, string $value, string $human_label = '', array $types = []): void {
        if (empty($human_label)) $human_label = $selected;
        // Validatie
        $valid = true; $count = 0;
        if (trim($value) !== '') {
            $test = trim($value);
            if (preg_match('#<script[^>]*>(.*?)</script>#is', $test, $m)) $test = trim($m[1]);
            $decoded = json_decode($test, true);
            $valid = is_array($decoded);
            if ($valid) {
                if (isset($decoded['@graph']) && is_array($decoded['@graph']))     $count = count($decoded['@graph']);
                elseif (isset($decoded['@type']))                                  $count = 1;
                elseif (array_keys($decoded) === range(0, count($decoded) - 1))    $count = count($decoded);
            }
        }
        ?>
        <details class="rr-sd-custom" <?php echo trim($value) !== '' ? 'open' : ''; ?>>
            <summary class="rr-sd-custom-head">
                <span class="rr-sd-custom-chev">›</span>
                <strong><?php _e('Eigen JSON-LD voor dit pagina-type', 'rankrepair'); ?></strong>
                <?php if (trim($value) === ''): ?>
                    <span class="rr-sd-custom-state"><?php _e('Leeg', 'rankrepair'); ?></span>
                <?php elseif ($valid): ?>
                    <span class="rr-sd-custom-state rr-sd-custom-state--ok">✓ <?php echo esc_html(sprintf(__('%d schema%s ingevoerd', 'rankrepair'), $count, $count === 1 ? '' : '\'s')); ?></span>
                <?php else: ?>
                    <span class="rr-sd-custom-state rr-sd-custom-state--err">✗ <?php _e('Ongeldige JSON', 'rankrepair'); ?></span>
                <?php endif; ?>
            </summary>
            <?php
            // Controle: vaste afbeeldingen in templates die voor meerdere pagina's gelden.
            $audit_templates = [];
            foreach (array_keys($types) as $audit_type) {
                $audit_templates[$audit_type] = (string) get_option('rr_sd_custom_jsonld_' . $audit_type, '');
            }
            $audit_templates['all'] = (string) get_option('rr_sd_custom_jsonld_all', '');
            $audit = class_exists('RR_SD_Template') ? RR_SD_Template::audit_templates($audit_templates) : [];
            if ($audit): ?>
            <div class="notice notice-warning inline rr-sd-audit">
                <p><strong><?php _e('Let op: vaste afbeelding in een template voor meerdere pagina\'s', 'rankrepair'); ?></strong><br>
                <?php _e('Elke pagina van zo\'n type krijgt dan dezelfde afbeelding. Gebruik {{term_image}} (categorieën), {{featured_image}} (berichten en pagina\'s) of {{product_images}} (producten).', 'rankrepair'); ?></p>
                <ul style="margin:0 0 .5em 1.2em;list-style:disc">
                <?php foreach ($audit as $audit_type => $hits): ?>
                    <?php foreach ($hits as $hit): ?>
                        <li><code><?php echo esc_html($audit_type); ?></code> → <code><?php echo esc_html($hit['path']); ?></code>: <?php echo esc_html($hit['value']); ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" class="rr-sd-custom-form">
                <?php wp_nonce_field('rr_sd_custom_' . $selected); ?>
                <p class="rr-sd-custom-hint">
                    <?php _e('Plak hier JSON-LD die alleen op dit pagina-type (', 'rankrepair'); ?><strong><?php echo esc_html($human_label); ?></strong><?php _e(') moet verschijnen. Gebruik <code>{{placeholders}}</code> zodat 1 template voor álle pagina\'s van dit niveau werkt.', 'rankrepair'); ?>
                </p>

                <?php if (class_exists('RR_SD_Template')):
                    $example = RR_SD_Template::get_example_template($selected);
                    $placeholders = RR_SD_Template::available_placeholders();
                ?>
                <details class="rr-sd-placeholders">
                    <summary><strong><?php _e('Beschikbare placeholders', 'rankrepair'); ?></strong> <span class="rr-sd-ph-count"><?php echo count($placeholders); ?></span></summary>
                    <div class="rr-sd-placeholders-grid">
                        <?php foreach ($placeholders as $key => $desc): ?>
                            <div class="rr-sd-ph-row">
                                <code class="rr-sd-ph-key">{{<?php echo esc_html($key); ?>}}</code>
                                <span class="rr-sd-ph-desc"><?php echo esc_html($desc); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>

                <textarea name="rr_sd_custom_jsonld" class="rr-json-textarea" rows="16" spellcheck="false" placeholder='{"@context":"https://schema.org","@graph":[…]}'><?php echo esc_textarea($value); ?></textarea>
                <div class="rr-sd-custom-actions">
                    <?php if (class_exists('RR_SD_Template') && !empty($example)): ?>
                    <button type="button" class="button rr-sd-load-example" data-example="<?php echo esc_attr($example); ?>">
                        <?php _e('Voorbeeld-template invoegen', 'rankrepair'); ?>
                    </button>
                    <?php endif; ?>
                    <button type="submit" name="rr_sd_save_custom" class="button button-primary"><?php _e('JSON-LD opslaan', 'rankrepair'); ?></button>
                </div>
            </form>
            <script>
            (function(){
                document.querySelectorAll('.rr-sd-load-example').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var ta = btn.closest('form').querySelector('textarea[name="rr_sd_custom_jsonld"]');
                        if (!ta) return;
                        if (ta.value.trim() !== '' && !confirm('Huidige inhoud overschrijven met voorbeeld-template?')) return;
                        ta.value = btn.getAttribute('data-example');
                        ta.focus();
                    });
                });
            })();
            </script>
        </details>
        <?php
    }

    /**
     * Bepaal bewerk-acties per schema op basis van source + type.
     * Stuurt gebruiker naar de juiste plek (RR instellingen / Yoast / WooCommerce product edit).
     */
    private function get_edit_actions(array $schema): array {
        $data   = $schema['data'] ?? [];
        $type   = $this->get_type_label($data['@type'] ?? '');
        $source = $schema['source'] ?? '';
        $id     = $data['@id'] ?? '';
        $url    = $data['url'] ?? '';
        $actions = [];

        // Organization/LocalBusiness bewerken (alleen onze eigen schema; Yoast heeft eigen setting)
        if (in_array($type, ['Organization', 'LocalBusiness'], true)) {
            if ($source === 'RankRepair') {
                $actions[] = [
                    'label' => __('Bewerk in RankRepair instellingen', 'rankrepair'),
                    'url'   => admin_url('admin.php?page=rankrepair-settings#rr-sd-open'),
                ];
            } elseif ($source === 'Yoast SEO') {
                $actions[] = [
                    'label' => __('Bewerk in Yoast (Algemeen → Site-representatie)', 'rankrepair'),
                    'url'   => admin_url('admin.php?page=wpseo_page_settings#/site-representation'),
                ];
            }
        }

        // BreadcrumbList → Yoast algemene breadcrumb-instellingen of per post
        if ($type === 'BreadcrumbList' && $source === 'Yoast SEO') {
            $actions[] = [
                'label' => __('Breadcrumb instellingen (Yoast)', 'rankrepair'),
                'url'   => admin_url('admin.php?page=wpseo_page_settings#/breadcrumbs'),
            ];
        }

        // Product → WooCommerce product edit page
        if ($type === 'Product') {
            $post_id = url_to_postid($url);
            if ($post_id) {
                $actions[] = [
                    'label' => __('Bewerk product', 'rankrepair'),
                    'url'   => get_edit_post_link($post_id, 'edit'),
                ];
            }
        }

        // ProductGroup → term edit
        if (in_array($type, ['ProductGroup', 'CollectionPage'], true) && $url) {
            // Probeer taxonomy term te vinden
            $term = $this->url_to_term($url);
            if ($term) {
                $actions[] = [
                    'label' => __('Bewerk categorie', 'rankrepair'),
                    'url'   => get_edit_term_link($term->term_id, $term->taxonomy),
                ];
            }
        }

        // WebPage / Article / PostalAddress / generiek: link naar post/page edit
        if (empty($actions) && in_array($type, ['WebPage', 'Article', 'ItemPage', 'Blog', 'Product'], true) && $url) {
            $post_id = url_to_postid($url);
            if ($post_id) {
                $actions[] = [
                    'label' => __('Bewerk pagina', 'rankrepair'),
                    'url'   => get_edit_post_link($post_id, 'edit'),
                ];
            }
        }

        // FAQPage → naar post edit (blocks) of ACF
        if ($type === 'FAQPage' && $url) {
            $post_id = url_to_postid($url);
            if ($post_id) {
                $actions[] = [
                    'label' => __('Bewerk FAQ content', 'rankrepair'),
                    'url'   => get_edit_post_link($post_id, 'edit'),
                ];
            }
        }

        // Source-specifieke fallbacks
        if (empty($actions) && $source === 'RankRepair') {
            $actions[] = [
                'label' => __('Bewerk in RankRepair instellingen', 'rankrepair'),
                'url'   => admin_url('admin.php?page=rankrepair-settings#rr-sd-open'),
            ];
        }
        if (empty($actions) && $source === 'Yoast SEO' && $url) {
            $post_id = url_to_postid($url);
            if ($post_id) {
                $actions[] = [
                    'label' => __('Bewerk in Yoast (via pagina)', 'rankrepair'),
                    'url'   => get_edit_post_link($post_id, 'edit'),
                ];
            }
        }

        return $actions;
    }

    private function url_to_term(string $url): ?object {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) return null;
        foreach (['product_cat', 'category', 'product_tag', 'post_tag'] as $tax) {
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
            if (is_wp_error($terms)) continue;
            foreach ($terms as $t) {
                $term_link = get_term_link($t);
                if (!is_wp_error($term_link) && rtrim(wp_parse_url($term_link, PHP_URL_PATH), '/') === rtrim($path, '/')) {
                    return $t;
                }
            }
        }
        return null;
    }

    /**
     * Recursief data renderen in tabel-rijen. Nested objects krijgen meer indent.
     */
    private function render_data_rows($data, int $depth): void {
        if (!is_array($data)) {
            echo '<tr><td colspan="2">' . esc_html((string) $data) . '</td></tr>';
            return;
        }
        foreach ($data as $key => $value) {
            $indent_style = 'padding-left:' . (12 + $depth * 16) . 'px';
            if (is_array($value)) {
                // Assoc array = nested object
                if ($this->is_assoc($value)) {
                    echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val rr-sd-val--nested"></td></tr>';
                    $this->render_data_rows($value, $depth + 1);
                } else {
                    // Indexed array van primitives of objects
                    $all_primitive = true;
                    foreach ($value as $v) { if (is_array($v)) { $all_primitive = false; break; } }
                    if ($all_primitive) {
                        echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val">' . esc_html(implode(', ', array_map(fn($v) => (string) $v, $value))) . '</td></tr>';
                    } else {
                        echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val rr-sd-val--nested">[' . count($value) . ' items]</td></tr>';
                        foreach ($value as $i => $item) {
                            echo '<tr><td class="rr-sd-key rr-sd-key--idx" style="padding-left:' . (12 + ($depth + 1) * 16) . 'px">' . esc_html('[' . $i . ']') . '</td><td></td></tr>';
                            $this->render_data_rows($item, $depth + 2);
                        }
                    }
                }
            } else {
                $display = (string) $value;
                if (mb_strlen($display) > 300) {
                    $display = mb_substr($display, 0, 300) . '…';
                }
                echo '<tr><td class="rr-sd-key" style="' . esc_attr($indent_style) . '">' . esc_html((string) $key) . '</td><td class="rr-sd-val">' . esc_html($display) . '</td></tr>';
            }
        }
    }

    private function is_assoc(array $arr): bool {
        if ($arr === []) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function get_type_label($type): string {
        if (is_array($type)) return implode(', ', $type);
        return (string) $type;
    }

    /**
     * Valideer een schema tegen basis-rules. Returns ['errors' => [], 'warnings' => []].
     */
    private function validate_schema(array $data): array {
        $errors   = [];
        $warnings = [];

        $type = $data['@type'] ?? null;
        if (!$type) return ['errors' => ['@type ontbreekt'], 'warnings' => []];

        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            $rules = self::VALIDATION_RULES[$t] ?? null;
            if (!$rules) continue;

            foreach ($rules['required'] as $req) {
                if (!isset($data[$req]) || $data[$req] === '' || $data[$req] === []) {
                    $errors[] = sprintf(__('%s: verplicht veld "%s" ontbreekt', 'rankrepair'), $t, $req);
                }
            }
            foreach ($rules['recommended'] as $rec) {
                if (!isset($data[$rec]) || $data[$rec] === '' || $data[$rec] === []) {
                    $warnings[] = sprintf(__('%s: aanbevolen veld "%s" ontbreekt', 'rankrepair'), $t, $rec);
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function aggregate_totals(array $schemas): array {
        $errors = 0; $warnings = 0;
        foreach ($schemas as $s) {
            $v = $this->validate_schema($s['data']);
            $errors   += count($v['errors']);
            $warnings += count($v['warnings']);
        }
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function get_page_types(): array {
        $types = [
            'home' => [
                'label' => __('Homepage', 'rankrepair'),
                'url'   => home_url('/'),
            ],
        ];

        // Blog overzichtspagina (de page die als "Posts page" is ingesteld)
        $blog_page_id = (int) get_option('page_for_posts');
        if ($blog_page_id && get_post($blog_page_id)) {
            $types['blog_index'] = [
                'label' => __('Blog overzicht', 'rankrepair'),
                'url'   => get_permalink($blog_page_id),
            ];
        }

        $recent_post = get_posts(['post_type' => 'post', 'numberposts' => 1, 'post_status' => 'publish']);
        if ($recent_post) {
            $types['post'] = [
                'label' => __('Blog post', 'rankrepair'),
                'url'   => get_permalink($recent_post[0]->ID),
                'count' => (int) wp_count_posts('post')->publish,
            ];
        }

        // Contactpagina (auto-detect via slug/title)
        if (class_exists('RR_Addon_Structured_Data')) {
            $contact_id = RR_Addon_Structured_Data::detect_contact_page_id();
            if ($contact_id && get_post($contact_id)) {
                $types['contact'] = [
                    'label' => __('Contactpagina', 'rankrepair'),
                    'url'   => get_permalink($contact_id),
                ];
            }
        }

        $recent_page = get_posts(['post_type' => 'page', 'numberposts' => 1, 'post_status' => 'publish']);
        if ($recent_page) {
            $types['page'] = [
                'label' => __('Pagina', 'rankrepair'),
                'url'   => get_permalink($recent_page[0]->ID),
                'count' => (int) wp_count_posts('page')->publish,
            ];
        }

        if (post_type_exists('product')) {
            $recent_prod = get_posts(['post_type' => 'product', 'numberposts' => 1, 'post_status' => 'publish']);
            if ($recent_prod) {
                $types['product'] = [
                    'label' => __('Product', 'rankrepair'),
                    'url'   => get_permalink($recent_prod[0]->ID),
                    'count' => (int) wp_count_posts('product')->publish,
                ];
            }
            // Productcategorieën — gesplitst per diepte (L1/L2/L3)
            $all_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
            if (!is_wp_error($all_cats) && !empty($all_cats)) {
                $by_depth = ['l1' => [], 'l2' => [], 'l3' => []];
                foreach ($all_cats as $cat) {
                    $d = class_exists('RR_Addon_Structured_Data')
                        ? RR_Addon_Structured_Data::term_depth($cat)
                        : 1;
                    $key = $d >= 3 ? 'l3' : ($d === 2 ? 'l2' : 'l1');
                    $by_depth[$key][] = $cat;
                }
                $level_labels = [
                    'l1' => __('Productcat. (L1 hoofdniveau)', 'rankrepair'),
                    'l2' => __('Productcat. (L2 sub)', 'rankrepair'),
                    'l3' => __('Productcat. (L3 sub-sub)', 'rankrepair'),
                ];
                foreach (['l1', 'l2', 'l3'] as $lvl) {
                    if (!empty($by_depth[$lvl])) {
                        $types['product_cat_' . $lvl] = [
                            'label' => $level_labels[$lvl],
                            'url'   => get_term_link($by_depth[$lvl][0]),
                            'count' => count($by_depth[$lvl]),
                        ];
                    }
                }
            }
        }

        $cats = get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'number' => 1]);
        if (!is_wp_error($cats) && !empty($cats)) {
            $types['category'] = [
                'label' => __('Blog categorie', 'rankrepair'),
                'url'   => get_term_link($cats[0]),
                'count' => count(get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids'])),
            ];
        }

        return $types;
    }

    /**
     * Fetch URL en parse alle <script type="application/ld+json"> tags.
     * Returns: [['data' => array, 'source' => 'Yoast|Rank Math|RankRepair|...'], ...]
     */
    private function scan_url(string $url): array {
        $response = wp_remote_get($url, [
            'timeout'    => 15,
            'user-agent' => 'RankRepair/1.0 Structured-Data-Scanner',
            'sslverify'  => apply_filters('https_local_ssl_verify', false),
            'headers'    => ['X-RankRepair-Internal' => '1'],
        ]);
        if (is_wp_error($response)) return [];
        $html = wp_remote_retrieve_body($response);
        if (!$html) return [];

        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches, PREG_OFFSET_CAPTURE);

        $found = [];
        foreach ($matches[0] as $idx => $full_tag) {
            $offset    = $full_tag[1];
            $json_body = trim($matches[1][$idx][0]);
            $decoded   = json_decode($json_body, true);
            if (!is_array($decoded)) continue;

            // Source detection via HTML-context rond de tag
            $source  = $this->detect_source($html, $full_tag[0], $offset);

            // Flatten: als er een @graph is, elk graph-item wordt een los schema
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $entry) {
                    if (is_array($entry)) $found[] = ['data' => $entry, 'source' => $source];
                }
            } else {
                $found[] = ['data' => $decoded, 'source' => $source];
            }
        }
        return $found;
    }

    private function detect_source(string $html, string $tag, int $offset): string {
        // Zoek in ~200 chars vóór de tag naar bekende class-namen / comments
        $before = substr($html, max(0, $offset - 300), 300);
        if (stripos($before, 'yoast-schema-graph') !== false || stripos($before, 'yoast') !== false)    return 'Yoast SEO';
        if (stripos($before, 'rank-math') !== false || stripos($before, 'rankmath') !== false)          return 'Rank Math';
        if (stripos($before, 'RankRepair') !== false)                                                    return 'RankRepair';
        if (stripos($before, 'woocommerce') !== false)                                                   return 'WooCommerce';
        if (stripos($tag,    'yoast') !== false)                                                         return 'Yoast SEO';
        return '';
    }

    private function suggested_schemas_for(string $type, array $existing): array {
        $existing_types = [];
        foreach ($existing as $s) {
            $t = $s['data']['@type'] ?? '';
            if (is_array($t)) { foreach ($t as $tt) $existing_types[strtolower($tt)] = true; }
            else              { $existing_types[strtolower((string)$t)] = true; }
        }
        $has = fn(string $t) => isset($existing_types[strtolower($t)]);

        $out = [];
        if (!$has('Organization') && !$has('LocalBusiness') && get_option('rr_sd_schema_organization', '1') === '1') {
            $out[] = ['type' => 'Organization', 'note' => __('globaal — bedrijfsgegevens uit instellingen', 'rankrepair')];
        }
        if ($type === 'home' && !$has('WebSite') && get_option('rr_sd_schema_website', '1') === '1') {
            $out[] = ['type' => 'WebSite + SearchAction', 'note' => __('sitelink search box', 'rankrepair')];
        }
        if ($type !== 'home' && !$has('BreadcrumbList') && get_option('rr_sd_schema_breadcrumb', '1') === '1') {
            $out[] = ['type' => 'BreadcrumbList', 'note' => __('auto uit URL-hiërarchie', 'rankrepair')];
        }
        if ($type === 'product' && !$has('Product') && get_option('rr_sd_schema_product', '1') === '1') {
            $out[] = ['type' => 'Product + Offer', 'note' => __('uit WooCommerce data', 'rankrepair')];
        }
        if ($type === 'product_cat' && !$has('ProductGroup') && !$has('ItemList') && get_option('rr_sd_schema_productgroup', '1') === '1') {
            $out[] = ['type' => 'ProductGroup', 'note' => __('varianten uit sub-categorieën', 'rankrepair')];
        }
        if (in_array($type, ['post', 'page'], true) && !$has('FAQPage') && get_option('rr_sd_schema_faqpage', '1') === '1') {
            $out[] = ['type' => 'FAQPage', 'note' => __('indien FAQ-block aanwezig', 'rankrepair')];
        }
        return $out;
    }
}
