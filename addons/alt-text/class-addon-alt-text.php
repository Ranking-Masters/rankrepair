<?php
/**
 * Alt-teksten (AI) Add-on
 *
 * Vindt afbeeldingen zonder alt-tekst, stelt met vision-AI een korte Nederlandse
 * beschrijving voor, en schrijft die na goedkeuring weg naar het alt-veld
 * (`_wp_attachment_image_alt`). Hergebruikt de gedeelde AI-instellingen van RankRepair.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-rr-alt-text.php';

class RR_Addon_Alt_Text extends RR_Addon_Base {

    const PER_PAGE   = 24;
    const MAX_BYTES  = 6000000;   // 6 MB payload-grens voor de AI
    const RESIZE_OVER = 1500000;  // boven ~1,5 MB: verkleind kopietje sturen

    /** Toegestane rasterformaten voor vision-analyse. */
    private static $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    protected function init() {
        $this->slug        = 'alt-text';
        $this->name        = __('Alt-teksten', 'rankrepair');
        $this->description = __('Genereer met AI alt-teksten voor afbeeldingen die er nog geen hebben.', 'rankrepair');
        $this->icon        = 'dashicons-format-image';

        add_action('wp_ajax_rr_alt_scan',     [$this, 'ajax_scan']);
        add_action('wp_ajax_rr_alt_generate', [$this, 'ajax_generate']);
        add_action('wp_ajax_rr_alt_apply',    [$this, 'ajax_apply']);
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'rankrepair-alt-text') === false) {
            return;
        }
        $css = __DIR__ . '/alt-text.css';
        $js  = __DIR__ . '/alt-text.js';
        wp_enqueue_style('rr-alt-text', plugins_url('alt-text.css', __FILE__), [], file_exists($css) ? filemtime($css) : RR_VERSION);
        wp_enqueue_script('rr-alt-text', plugins_url('alt-text.js', __FILE__), ['jquery', 'rr-admin-script'], file_exists($js) ? filemtime($js) : RR_VERSION, true);
    }

    // =====================================================================
    // Statistiek
    // =====================================================================

    public function get_stats() {
        return [
            'total'  => $this->count_images(false),
            'issues' => $this->count_images(true),
        ];
    }

    /** Telt afbeeldingen; $missing_only = alleen zonder alt-tekst. */
    private function count_images(bool $missing_only): int {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::$allowed_mime,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'cache_results'  => false,
        ];
        if ($missing_only) {
            $args['meta_query'] = $this->missing_alt_meta_query();
        }
        $q = new WP_Query($args);
        return (int) $q->found_posts;
    }

    private function missing_alt_meta_query(): array {
        return [
            'relation' => 'OR',
            ['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
            ['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
        ];
    }

    // =====================================================================
    // AJAX: scannen
    // =====================================================================

    public function ajax_scan() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $paged = max(1, (int) ($_POST['paged'] ?? 1));
        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => self::$allowed_mime,
            'posts_per_page' => self::PER_PAGE,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'meta_query'     => $this->missing_alt_meta_query(),
        ]);

        $items = [];
        foreach ($q->posts as $id) {
            $items[] = [
                'id'       => $id,
                'thumb'    => wp_get_attachment_image_url($id, 'thumbnail') ?: wp_get_attachment_image_url($id, 'full') ?: '',
                'filename' => wp_basename(get_attached_file($id) ?: ''),
                'title'    => get_the_title($id),
                'edit'     => get_edit_post_link($id, 'raw'),
            ];
        }

        wp_send_json_success([
            'items'       => $items,
            'paged'       => $paged,
            'total'       => (int) $q->found_posts,
            'total_pages' => (int) $q->max_num_pages,
        ]);
    }

    // =====================================================================
    // AJAX: alt-tekst genereren (slaat niets op)
    // =====================================================================

    public function ajax_generate() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id || get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('Ongeldige afbeelding.', 'rankrepair'));
        }

        $alt = $this->generate_alt_for($id);
        if (is_wp_error($alt)) {
            wp_send_json_error($alt->get_error_message());
        }
        wp_send_json_success(['id' => $id, 'alt' => $alt]);
    }

    // =====================================================================
    // AJAX: goedgekeurde alt-tekst opslaan
    // =====================================================================

    public function ajax_apply() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $id  = (int) ($_POST['id'] ?? 0);
        $alt = isset($_POST['alt']) ? sanitize_text_field(wp_unslash($_POST['alt'])) : '';
        if (!$id || get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('Ongeldige afbeelding.', 'rankrepair'));
        }
        if ($alt === '') {
            wp_send_json_error(__('Lege alt-tekst wordt niet opgeslagen.', 'rankrepair'));
        }

        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        wp_send_json_success(['id' => $id, 'alt' => $alt]);
    }

    // =====================================================================
    // AI-aanroep (vision)
    // =====================================================================

    /** @return string|WP_Error de opgeschoonde alt-tekst, of een fout. */
    private function generate_alt_for(int $id) {
        $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
        if (empty($api_key)) {
            return new WP_Error('no_key', __('Er is nog geen AI-sleutel ingesteld. Stel die in bij RankRepair → Instellingen.', 'rankrepair'));
        }

        $image = $this->image_data($id);
        if (is_wp_error($image)) {
            return $image;
        }
        list($mime, $b64) = $image;

        $provider = get_option('rr_ai_provider', 'google');
        $model    = trim(get_option('rr_ai_model', ''));
        $hint     = RR_Alt_Text::filename_hint(wp_basename((string) get_attached_file($id)));
        $prompt   = RR_Alt_Text::build_prompt($hint);

        if ($provider === 'openrouter') {
            if ($model === '') { $model = 'google/gemini-2.0-flash-001'; }
            $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => get_bloginfo('name'),
                ],
                'body' => wp_json_encode([
                    'model'    => $model,
                    'messages' => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => RR_Alt_Text::data_uri($mime, $b64)]],
                        ],
                    ]],
                    'temperature' => 0.4,
                    // Ruim budget: "thinking"-modellen (bv. gemini-3.x) besteden tokens aan
                    // redeneren; bij een te krap budget wordt de alt-tekst afgekapt. Reasoning
                    // uitzetten mag bij sommige modellen niet, dus geven we gewoon ruimte.
                    // Je betaalt alleen voor daadwerkelijk gebruikte tokens.
                    'max_tokens'  => 1500,
                ]),
                'timeout' => 45,
            ]);
            $parser = 'parse_openrouter';
        } else {
            if ($model === '') { $model = 'gemini-1.5-flash'; }
            $endpoint = add_query_arg('key', $api_key, 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent');
            $response = wp_remote_post($endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode([
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature'     => 0.4,
                        // Ruim budget: thinking-tokens tellen mee; te krap kapt de alt-tekst af.
                        'maxOutputTokens' => 1500,
                    ],
                ]),
                'timeout' => 45,
            ]);
            $parser = 'parse_google';
        }

        if (is_wp_error($response)) {
            return new WP_Error('ai_request', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('ai_api', RR_Alt_Text::error_message($body, $code));
        }

        $raw = call_user_func(['RR_Alt_Text', $parser], $body);
        $alt = RR_Alt_Text::sanitize_alt($raw);
        if ($alt === '') {
            return new WP_Error('empty', __('De AI gaf geen bruikbare alt-tekst terug. Probeer het opnieuw.', 'rankrepair'));
        }
        return $alt;
    }

    /**
     * Levert [mime, base64] van een geschaalde afbeelding op. Voorkeur voor een
     * bestaand tussenformaat; bij alleen een grote originele wordt een tijdelijk
     * verkleind kopietje gebruikt. @return array|WP_Error
     */
    private function image_data(int $id) {
        $mime = get_post_mime_type($id);
        if (!in_array($mime, self::$allowed_mime, true)) {
            return new WP_Error('mime', sprintf(__('Bestandstype %s wordt niet ondersteund voor AI-analyse.', 'rankrepair'), $mime ?: '?'));
        }

        $full = get_attached_file($id);
        if (!$full || !file_exists($full)) {
            return new WP_Error('file', __('Afbeeldingsbestand niet gevonden.', 'rankrepair'));
        }

        $meta  = wp_get_attachment_metadata($id);
        $sizes = ($meta && !empty($meta['sizes'])) ? array_keys($meta['sizes']) : [];
        $pick  = RR_Alt_Text::pick_size($sizes);

        $path = $full;
        if ($pick !== 'full' && !empty($meta['sizes'][$pick]['file'])) {
            $cand = trailingslashit(dirname($full)) . $meta['sizes'][$pick]['file'];
            if (file_exists($cand)) {
                $path = $cand;
            }
        }

        // Alleen een grote originele? Stuur een tijdelijk verkleind kopietje.
        $tmp = '';
        if ($path === $full && filesize($full) > self::RESIZE_OVER) {
            $editor = wp_get_image_editor($full);
            if (!is_wp_error($editor)) {
                $editor->resize(1024, 1024, false);
                $target = get_temp_dir() . uniqid('rr-alt-' . $id . '-', true) . '.' . $this->ext_for($mime);
                $saved  = $editor->save($target);
                if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                    $path = $saved['path'];
                    $tmp  = $saved['path'];
                }
            }
        }

        $bytes = @file_get_contents($path);
        if ($tmp && file_exists($tmp)) {
            @unlink($tmp);
        }
        if ($bytes === false) {
            return new WP_Error('read', __('Kon de afbeelding niet lezen.', 'rankrepair'));
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return new WP_Error('size', __('Afbeelding te groot voor AI-analyse.', 'rankrepair'));
        }
        return [$mime, base64_encode($bytes)];
    }

    private function ext_for(string $mime): string {
        $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        return $map[$mime] ?? 'jpg';
    }

    // =====================================================================
    // Admin-pagina
    // =====================================================================

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $has_key = rr_decrypt_key(get_option('rr_gemini_api_key', '')) !== '';
        $missing = $this->count_images(true);

        $this->render_header(
            __('Alt-teksten', 'rankrepair'),
            __('Genereer met AI alt-teksten voor afbeeldingen die er nog geen hebben.', 'rankrepair')
        );

        echo '<div class="rr-alt">';

        if (!$has_key) {
            echo '<div class="rr-notice rr-notice-warning"><span class="dashicons dashicons-warning"></span> ';
            echo wp_kses_post(sprintf(
                /* translators: %s = link naar instellingen */
                __('Er is nog geen AI-sleutel ingesteld. Stel die eerst in bij <a href="%s">RankRepair → Instellingen</a>.', 'rankrepair'),
                esc_url(admin_url('admin.php?page=rankrepair-settings'))
            ));
            echo '</div>';
        }

        echo '<p class="rr-alt-intro">' . sprintf(
            /* translators: %d = aantal afbeeldingen */
            esc_html__('Afbeeldingen zonder alt-tekst: %d', 'rankrepair'),
            (int) $missing
        ) . '</p>';

        echo '<div class="rr-alt-actions">';
        echo '<button class="button button-primary" id="rr-alt-scan"' . ($has_key ? '' : ' disabled') . '>' . esc_html__('Scan afbeeldingen', 'rankrepair') . '</button> ';
        echo '<button class="button" id="rr-alt-generate-all" disabled>' . esc_html__('Genereer voor alle (deze pagina)', 'rankrepair') . '</button> ';
        echo '<button class="button button-primary" id="rr-alt-apply-all" disabled>' . esc_html__('Keur alle goedgekeurde goed', 'rankrepair') . '</button>';
        echo '</div>';

        echo '<div id="rr-alt-status" class="rr-alt-status" aria-live="polite"></div>';
        echo '<div id="rr-alt-list" class="rr-alt-list"></div>';
        echo '<div id="rr-alt-pager" class="rr-alt-pager"></div>';

        echo '</div>';
        $this->render_footer();
    }
}

new RR_Addon_Alt_Text();
