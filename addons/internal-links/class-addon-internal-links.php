<?php
/**
 * Interne Links Add-on
 * Detecteert blogs/pagina's met te weinig inkomende interne links en stelt er links voor.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-il-text.php';
require_once __DIR__ . '/class-il-matcher.php';
require_once __DIR__ . '/class-il-graph-scanner.php';
require_once __DIR__ . '/class-il-suggester.php';

class RR_Addon_Internal_Links extends RR_Addon_Base {

    protected function init() {
        $this->slug        = 'internal-links';
        $this->name        = __('Interne Links', 'rankrepair');
        $this->description = __('Vind pagina\'s met te weinig inkomende interne links en krijg linksuggesties.', 'rankrepair');
        $this->icon        = 'dashicons-admin-links';

        add_action('wp_ajax_rr_il_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_rr_il_stats', [$this, 'ajax_stats']);
        add_action('wp_ajax_rr_il_suggest', [$this, 'ajax_suggest']);
        add_action('wp_ajax_rr_il_export', [$this, 'ajax_export']);
    }

    /** Batched graaf-scan. Client stuurt 'offset'; server verwerkt 1 batch. */
    public function ajax_scan() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(120);

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch  = 25;

        $all = IL_Graph_Scanner::all_post_ids();
        if ($offset === 0) {
            IL_Graph_Scanner::reset();
        }

        $slice = array_slice($all, $offset, $batch);
        $processed = IL_Graph_Scanner::scan_batch($slice);

        $next = $offset + count($slice);
        wp_send_json_success([
            'total'     => count($all),
            'processed' => $next,
            'done'      => $next >= count($all),
        ]);
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'internal-links') === false) {
            return;
        }
        wp_enqueue_style('rr-internal-links', RR_PLUGIN_URL . 'addons/internal-links/internal-links.css', [], RR_VERSION);
        wp_enqueue_script('rr-internal-links', RR_PLUGIN_URL . 'addons/internal-links/internal-links.js', ['jquery', 'rr-admin-script'], RR_VERSION, true);
    }

    public function ajax_stats() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }

        $ids   = IL_Graph_Scanner::all_post_ids();
        $rows  = [];
        $sum_in = 0;
        $orphans = 0;
        $thin = 0;

        foreach ($ids as $id) {
            $in  = IL_Graph_Scanner::inbound_count($id);
            $sum_in += $in;
            if ($in <= 1) {
                $post = get_post($id);
                $rows[] = [
                    'id'       => $id,
                    'title'    => get_the_title($id),
                    'type'     => $post ? $post->post_type : '',
                    'inbound'  => $in,
                    'outbound' => IL_Graph_Scanner::outbound_count($id),
                ];
                if ($in === 0) { $orphans++; } else { $thin++; }
            }
        }

        // Orphans eerst (inbound oplopend), dan op titel.
        usort($rows, function ($a, $b) {
            if ($a['inbound'] === $b['inbound']) {
                return strcasecmp($a['title'], $b['title']);
            }
            return $a['inbound'] <=> $b['inbound'];
        });

        $total = count($ids);
        wp_send_json_success([
            'orphans'     => $orphans,
            'thin'        => $thin,
            'total'       => $total,
            'avg_inbound' => $total > 0 ? round($sum_in / $total, 1) : 0,
            'rows'        => $rows,
        ]);
    }

    public function ajax_suggest() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(120);

        $target_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        if ($target_id <= 0) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
        }
        $force = !empty($_POST['force']);
        $suggestions = IL_Suggester::for_target($target_id, $force);

        wp_send_json_success([
            'target_id'   => $target_id,
            'has_ai'      => !empty(rr_decrypt_key(get_option('rr_gemini_api_key', ''))),
            'suggestions' => $suggestions,
        ]);
    }

    public function ajax_export() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(300);

        $ids = IL_Graph_Scanner::all_post_ids();
        $rows = [['doel_id', 'doel_titel', 'bron_id', 'bron_titel', 'type', 'score', 'ankertekst', 'context_zin', 'plaatsing']];

        foreach ($ids as $id) {
            if (IL_Graph_Scanner::inbound_count($id) > 1) {
                continue;
            }
            // Cache-only: genereer hier NIET (zou tot 3 sequentiele AI-calls per doel triggeren
            // en de request laten timeouten). Alleen reeds gecachte suggesties opnemen.
            $cached = get_transient('rr_il_suggestions_' . $id);
            if (!is_array($cached) || empty($cached)) {
                continue;
            }
            foreach ($cached as $s) {
                $rows[] = [
                    $s['target_id'], $s['target_title'], $s['source_id'], $s['source_title'],
                    $s['source_type'], $s['score'], $s['anchor_text'], $s['context_sentence'], $s['placement_hint'],
                ];
            }
        }

        if (count($rows) <= 1) {
            wp_send_json_error(['message' => __('Nog geen suggesties gegenereerd. Open eerst suggesties per pagina.', 'rankrepair')]);
        }

        $csv = '';
        foreach ($rows as $r) {
            $escaped = array_map(function ($v) {
                $v = (string) $v;
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                    $v = "'" . $v;
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, $r);
            $csv .= implode(';', $escaped) . "\r\n";
        }

        wp_send_json_success([
            'filename' => 'interne-links-suggesties-' . wp_date('Ymd-His') . '.csv',
            'csv'      => $csv,
        ]);
    }

    public function get_stats() {
        return ['label' => __('Interne Links', 'rankrepair'), 'value' => ''];
    }

    public function render_page() {
        $this->render_header();
        ?>
        <div class="rr-il-wrap">
            <div class="rr-il-toolbar">
                <button id="rr-il-scan-btn" class="button button-primary"><?php esc_html_e('Scan interne links', 'rankrepair'); ?></button>
                <button id="rr-il-export-btn" class="button"><?php esc_html_e('Exporteer CSV', 'rankrepair'); ?></button>
                <div id="rr-il-progress" class="rr-il-progress" style="display:none;">
                    <div class="rr-il-progress-bar"><span id="rr-il-progress-fill"></span></div>
                    <span id="rr-il-progress-txt">0 / 0</span>
                </div>
            </div>

            <div class="rr-il-stats" id="rr-il-stats">
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-orphans">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Orphans (0 inkomend)', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-thin">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Thin (1 inkomend)', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-avg">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Gem. inkomend', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-total">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Totaal gescand', 'rankrepair'); ?></span></div>
            </div>

            <table class="rr-il-table widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Titel', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Type', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Inkomend', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Uitgaand', 'rankrepair'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rr-il-tbody">
                    <tr><td colspan="5"><?php esc_html_e('Klik op "Scan interne links" om te beginnen.', 'rankrepair'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
        $this->render_footer();
    }
}

new RR_Addon_Internal_Links();
