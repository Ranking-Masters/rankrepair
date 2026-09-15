<?php
/**
 * Interne Links Add-on
 *
 * Fase 1 (augustus): detectie van pagina's met te weinig inkomende links + suggesties.
 * Fase 2 (september): die suggesties ook echt plaatsen — in elke editor, veilig,
 * terugdraaibaar, en sturend op een natuurlijk linkprofiel.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-il-config.php';
require_once __DIR__ . '/class-il-text.php';
require_once __DIR__ . '/class-il-content.php';
require_once __DIR__ . '/class-il-index.php';
require_once __DIR__ . '/class-il-matcher.php';
require_once __DIR__ . '/class-il-graph-scanner.php';
require_once __DIR__ . '/class-il-suggestions.php';
require_once __DIR__ . '/class-il-profile.php';
require_once __DIR__ . '/class-il-gates.php';
require_once __DIR__ . '/class-il-inserter.php';
require_once __DIR__ . '/class-il-planner.php';
require_once __DIR__ . '/class-il-applier.php';
require_once __DIR__ . '/class-il-suggester.php';

class RR_Addon_Internal_Links extends RR_Addon_Base {

    /** Hoeveel posts per scan-batch. */
    const SCAN_BATCH = 20;

    protected function init() {
        $this->slug        = 'internal-links';
        $this->name        = __('Interne Links', 'rankrepair');
        $this->description = __('Vind pagina\'s met te weinig inkomende interne links, en plaats de goedgekeurde links.', 'rankrepair');
        $this->icon        = 'dashicons-admin-links';

        $endpoints = [
            'scan', 'stats', 'suggest', 'suggest_bulk', 'list', 'update',
            'apply', 'undo', 'graph', 'profile', 'settings', 'export',
        ];
        foreach ($endpoints as $endpoint) {
            add_action('wp_ajax_rr_il_' . $endpoint, [$this, 'ajax_' . $endpoint]);
        }
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'internal-links') === false) {
            return;
        }
        $base = RR_PLUGIN_URL . 'addons/internal-links/';

        wp_enqueue_style('rr-internal-links', $base . 'internal-links.css', [], RR_VERSION);
        wp_enqueue_script('rr-internal-links', $base . 'internal-links.js', ['jquery', 'rr-admin-script'], RR_VERSION, true);

        // De 3D-graaf is ~700 kB; die laden we pas als iemand het Data-tabblad opent.
        wp_localize_script('rr-internal-links', 'rrIL', [
            'graphLib' => $base . 'lib/3d-force-graph.min.js',
            'editUrl'  => admin_url('post.php'),
            'config'   => IL_Config::all(),
            'hasAi'    => IL_Config::ai_available(),
            'i18n'     => [
                'confirmApply' => __('Hiermee worden de goedgekeurde links echt in de content geplaatst. Doorgaan?', 'rankrepair'),
                'confirmUndo'  => __('Deze link uit de pagina halen?', 'rankrepair'),
            ],
        ]);
    }

    /* ------------------------------------------------------------ bewaking */

    private function guard() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
    }

    /* ---------------------------------------------------------------- scan */

    /** Batched graaf-scan. De client stuurt 'offset'; de server doet één batch. */
    public function ajax_scan() {
        $this->guard();
        set_time_limit(120);

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

        $all = IL_Graph_Scanner::all_post_ids();
        if ($offset === 0) {
            IL_Graph_Scanner::reset();
        }

        $slice = array_slice($all, $offset, self::SCAN_BATCH);
        IL_Graph_Scanner::scan_batch($slice);

        $next = $offset + count($slice);
        wp_send_json_success([
            'total'     => count($all),
            'processed' => $next,
            'done'      => $next >= count($all),
        ]);
    }

    public function ajax_stats() {
        $this->guard();

        $ids     = IL_Graph_Scanner::all_post_ids();
        $inbound = IL_Graph_Scanner::inbound_map();
        $rows    = [];
        $orphans = 0;
        $thin    = 0;
        $sum     = 0;

        $suggested = $this->suggestion_counts_by_target();

        foreach ($ids as $id) {
            $in   = isset($inbound[$id]) ? (int) $inbound[$id] : 0;
            $sum += $in;
            if ($in > 1) {
                continue;
            }
            $post = get_post($id);
            $rows[] = [
                'id'        => $id,
                'title'     => get_the_title($id),
                'type'      => $post ? $post->post_type : '',
                'inbound'   => $in,
                'outbound'  => IL_Graph_Scanner::outbound_count($id),
                'suggested' => isset($suggested[$id]) ? (int) $suggested[$id] : 0,
            ];
            if ($in === 0) { $orphans++; } else { $thin++; }
        }

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
            'avg_inbound' => $total > 0 ? round($sum / $total, 1) : 0,
            'rows'        => $rows,
            'counts'      => IL_Suggestions::count_by_status(),
        ]);
    }

    private function suggestion_counts_by_target() {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT target_id, COUNT(*) AS n FROM ' . IL_Suggestions::table() .
            " WHERE status IN ('pending','approved','applied') GROUP BY target_id",
            ARRAY_A
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['target_id']] = (int) $r['n'];
        }
        return $out;
    }

    /* --------------------------------------------------------- suggesties */

    public function ajax_suggest() {
        $this->guard();
        set_time_limit(180);

        $target_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        if ($target_id <= 0) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
        }

        $result = IL_Suggester::for_target($target_id, !empty($_POST['force']));

        wp_send_json_success([
            'target_id'   => $target_id,
            'target_title' => get_the_title($target_id),
            'has_ai'      => IL_Config::ai_available(),
            'suggestions' => $result['suggestions'],
            'rejected'    => $result['rejected'],
        ]);
    }

    /**
     * Genereert suggesties voor een reeks doelpagina's, één batch per aanroep.
     * Zonder AI is dit puur rekenwerk; met AI kost elke pagina een paar calls,
     * dus houden we de batch klein.
     */
    public function ajax_suggest_bulk() {
        $this->guard();
        set_time_limit(300);

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch  = IL_Config::ai_available() ? 2 : 6;

        $inbound = IL_Graph_Scanner::inbound_map();
        $targets = [];
        foreach (IL_Graph_Scanner::all_post_ids() as $id) {
            if ((isset($inbound[$id]) ? (int) $inbound[$id] : 0) <= 1) {
                $targets[] = $id;
            }
        }

        $slice = array_slice($targets, $offset, $batch);
        $found = 0;
        foreach ($slice as $target_id) {
            $result = IL_Suggester::for_target($target_id, false);
            // Alleen wat er in deze ronde bij kwam. Doelen die al suggesties
            // hadden leveren niets nieuws op en horen niet mee te tellen.
            $found += isset($result['created']) ? (int) $result['created'] : 0;
        }

        $next = $offset + count($slice);
        wp_send_json_success([
            'total'     => count($targets),
            'processed' => $next,
            'found'     => $found,
            'done'      => $next >= count($targets),
        ]);
    }

    public function ajax_list() {
        $this->guard();

        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : IL_Suggestions::STATUS_PENDING;
        $limit  = isset($_POST['limit']) ? min(200, max(1, (int) $_POST['limit'])) : 50;
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

        $rows = IL_Suggestions::query([
            'status' => $status === 'all' ? '' : $status,
            'limit'  => $limit,
            'offset' => $offset,
        ]);

        wp_send_json_success([
            'rows'   => IL_Suggester::decorate($rows),
            'counts' => IL_Suggestions::count_by_status(),
        ]);
    }

    /** Ankertekst bijwerken of status zetten (goedkeuren / afwijzen). */
    public function ajax_update() {
        $this->guard();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = IL_Suggestions::get($id);
        if (!$row) {
            wp_send_json_error(['message' => __('Suggestie niet gevonden.', 'rankrepair')]);
        }
        if ($row['status'] === IL_Suggestions::STATUS_APPLIED) {
            wp_send_json_error(['message' => __('Deze link staat al in de pagina; draai hem eerst terug.', 'rankrepair')]);
        }

        $fields = [];

        if (isset($_POST['anchor'])) {
            $anchor = IL_Text::normalize_ws(sanitize_text_field(wp_unslash($_POST['anchor'])));
            if ($anchor === '') {
                wp_send_json_error(['message' => __('Ankertekst mag niet leeg zijn.', 'rankrepair')]);
            }
            $fields['anchor'] = $anchor;
        }

        if (isset($_POST['status'])) {
            $status  = sanitize_text_field(wp_unslash($_POST['status']));
            $allowed = [IL_Suggestions::STATUS_PENDING, IL_Suggestions::STATUS_APPROVED, IL_Suggestions::STATUS_REJECTED];
            if (!in_array($status, $allowed, true)) {
                wp_send_json_error(['message' => __('Onbekende status.', 'rankrepair')]);
            }
            $fields['status'] = $status;
            $fields['reason'] = '';
        }

        if (empty($fields)) {
            wp_send_json_error(['message' => __('Niets om bij te werken.', 'rankrepair')]);
        }

        // Een gewijzigde ankertekst moet opnieuw langs de gates: iemand kan er
        // "klik hier" van maken, of een woord dat niet in de alinea staat.
        if (isset($fields['anchor'])) {
            $check = $this->dry_run(array_merge($row, $fields));
            if (!$check['ok']) {
                wp_send_json_error(['message' => $check['message'], 'gate' => $check['gate']]);
            }
        }

        IL_Suggestions::update($id, $fields);

        $rows = IL_Suggester::decorate([IL_Suggestions::get($id)]);
        wp_send_json_success(['row' => reset($rows), 'counts' => IL_Suggestions::count_by_status()]);
    }

    /** Toetst een suggestie zonder iets weg te schrijven. */
    private function dry_run(array $row) {
        $source = get_post((int) $row['source_id']);
        $target = get_post((int) $row['target_id']);
        if (!$source || !$target) {
            return ['ok' => false, 'message' => __('Bron of doel bestaat niet meer.', 'rankrepair'), 'gate' => ''];
        }

        $segment = null;
        foreach (IL_Content::segments($source) as $seg) {
            if ((string) $seg['ref'] === (string) $row['segment_ref']) {
                $segment = $seg;
                break;
            }
        }
        if (!$segment) {
            return ['ok' => false, 'message' => __('De alinea is niet meer gevonden.', 'rankrepair'), 'gate' => ''];
        }

        $cand = [
            'source_id'       => (int) $row['source_id'],
            'target_id'       => (int) $row['target_id'],
            'mode'            => (string) $row['mode'],
            'anchor'          => (string) $row['anchor'],
            'url'             => get_permalink($target->ID),
            'uid'             => IL_Inserter::new_uid(),
            'html'            => $segment['html'],
            'segment_ref'     => $segment['ref'],
            'segment_kind'    => $segment['kind'],
            'sentence_before' => (string) $row['sentence_before'],
            'sentence_after'  => (string) $row['sentence_after'],
        ];

        if ($cand['mode'] === 'wrap') {
            $ranges = IL_Text::protected_ranges($segment['html']);
            $found  = false;
            foreach (IL_Text::phrase_offsets($segment['html'], $cand['anchor']) as $hit) {
                if (IL_Text::in_protected_range($hit['offset'], strlen($hit['match']), $ranges)) {
                    continue;
                }
                $cand['offset'] = $hit['offset'];
                $cand['length'] = strlen($hit['match']);
                $cand['anchor'] = $hit['match'];
                $found = true;
                break;
            }
            if (!$found) {
                return [
                    'ok'      => false,
                    'message' => __('Die woorden staan niet in deze alinea; kies tekst die er letterlijk in voorkomt.', 'rankrepair'),
                    'gate'    => 'G4',
                ];
            }
        }

        $ctx = [
            'existing_targets'     => array_diff(IL_Graph_Scanner::targets_of((int) $row['source_id']), [(int) $row['target_id']]),
            'added_in_source'      => 0,
            'added_per_segment'    => [],
            'added_prefix_classes' => [],
            'density_headroom'     => IL_Profile::density_headroom((int) $row['source_id']),
            'max_links_per_source' => (int) IL_Config::get('max_links_per_source'),
            'max_same_anchor'      => (int) IL_Config::get('max_same_anchor'),
            'target_terms'         => IL_Text::remove_stopwords(IL_Text::tokenize(
                get_the_title($target->ID) . ' ' . IL_Index::focus_keyword($target->ID)
            )),
            'target_keyword'       => IL_Index::focus_keyword($target->ID),
            'intro_ref'            => null,
        ];

        $verdict = IL_Planner::evaluate($cand, $ctx);
        return [
            'ok'      => $verdict['ok'],
            'message' => $verdict['reason'],
            'gate'    => $verdict['gate'],
        ];
    }

    /* --------------------------------------------------------- toepassen */

    public function ajax_apply() {
        $this->guard();
        set_time_limit(120);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = IL_Applier::apply($id);

        $row  = IL_Suggestions::get($id);
        $rows = $row ? IL_Suggester::decorate([$row]) : [];

        wp_send_json_success([
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'gate'    => $result['gate'],
            // true = geen ruimte in deze ronde, staat nog goedgekeurd klaar.
            'retry'   => !empty($result['retry']),
            'row'     => $rows ? reset($rows) : null,
            'counts'  => IL_Suggestions::count_by_status(),
        ]);
    }

    public function ajax_undo() {
        $this->guard();
        set_time_limit(120);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $result = IL_Applier::undo($id);

        $row  = IL_Suggestions::get($id);
        $rows = $row ? IL_Suggester::decorate([$row]) : [];

        wp_send_json_success([
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'row'     => $rows ? reset($rows) : null,
            'counts'  => IL_Suggestions::count_by_status(),
        ]);
    }

    /* -------------------------------------------------------------- data */

    public function ajax_graph() {
        $this->guard();
        set_time_limit(120);

        $ids     = IL_Graph_Scanner::all_post_ids();
        $inbound = IL_Graph_Scanner::inbound_map();
        $ours    = IL_Suggestions::applied_edges();

        $nodes = [];
        foreach ($ids as $id) {
            $in   = isset($inbound[$id]) ? (int) $inbound[$id] : 0;
            $post = get_post($id);
            $nodes[] = [
                'id'      => $id,
                'label'   => get_the_title($id),
                'type'    => $post ? $post->post_type : '',
                'inbound' => $in,
                'state'   => $in === 0 ? 'orphan' : ($in === 1 ? 'thin' : ($in >= 8 ? 'hub' : 'ok')),
                'val'     => 1 + min(12, $in),
            ];
        }

        $links = [];
        foreach (IL_Graph_Scanner::edges() as $e) {
            $s = (int) $e['source_id'];
            $t = (int) $e['target_id'];
            $links[] = [
                'source' => $s,
                'target' => $t,
                'ours'   => isset($ours[$s . '-' . $t]),
            ];
        }

        wp_send_json_success(['nodes' => $nodes, 'links' => $links]);
    }

    public function ajax_profile() {
        $this->guard();
        set_time_limit(120);

        $metrics = IL_Profile::metrics();
        wp_send_json_success([
            'metrics'  => $metrics,
            'verdicts' => IL_Profile::verdicts($metrics),
        ]);
    }

    /* --------------------------------------------------------- instellingen */

    public function ajax_settings() {
        $this->guard();

        if (!empty($_POST['save'])) {
            foreach (array_keys(IL_Config::defaults()) as $key) {
                if (!isset($_POST[$key])) {
                    continue;
                }
                $raw = wp_unslash($_POST[$key]);
                $default = IL_Config::defaults()[$key];
                $value = is_float($default) ? (float) $raw : (int) $raw;
                IL_Config::set($key, $value);
            }
        }

        wp_send_json_success(['config' => IL_Config::all(), 'hasAi' => IL_Config::ai_available()]);
    }

    /* --------------------------------------------------------------- export */

    public function ajax_export() {
        $this->guard();
        set_time_limit(300);

        $rows = IL_Suggestions::query(['limit' => 5000]);
        if (empty($rows)) {
            wp_send_json_error(['message' => __('Nog geen suggesties. Genereer ze eerst.', 'rankrepair')]);
        }

        // Bewust NIET via decorate(): die parseert per rij de hele bronpagina om
        // een voorbeeldzin te maken, en die staat niet eens in de CSV. Bij vijfduizend
        // rijen zijn dat vijfduizend volledige content-parses in één request.
        $out = [['doel_id', 'doel_titel', 'bron_id', 'bron_titel', 'score', 'modus', 'ankertekst', 'alinea', 'status', 'reden']];
        foreach ($rows as $r) {
            $out[] = [
                $r['target_id'], get_the_title((int) $r['target_id']),
                $r['source_id'], get_the_title((int) $r['source_id']),
                round((float) $r['score'], 4), $r['mode'], $r['anchor'], $r['segment_ref'],
                $r['status'], $r['reason'],
            ];
        }

        $csv = '';
        foreach ($out as $r) {
            $escaped = array_map(function ($v) {
                $v = (string) $v;
                // Voorkomt dat Excel een cel als formule uitvoert.
                if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
                    $v = "'" . $v;
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, $r);
            $csv .= implode(';', $escaped) . "\r\n";
        }

        wp_send_json_success([
            'filename' => 'interne-links-' . wp_date('Ymd-His') . '.csv',
            'csv'      => $csv,
        ]);
    }

    public function get_stats() {
        $counts = IL_Suggestions::count_by_status();
        return [
            'label' => __('Interne Links', 'rankrepair'),
            'value' => (int) $counts[IL_Suggestions::STATUS_APPLIED],
        ];
    }

    /* ----------------------------------------------------------------- UI */

    public function render_page() {
        $this->render_header();
        $config = IL_Config::all();
        ?>
        <div class="rr-il-wrap">

            <nav class="rr-il-tabs" role="tablist">
                <button class="rr-il-tab is-active" data-tab="overzicht"><?php esc_html_e('Overzicht', 'rankrepair'); ?></button>
                <button class="rr-il-tab" data-tab="suggesties"><?php esc_html_e('Suggesties', 'rankrepair'); ?> <span class="rr-il-pill" id="rr-il-count-pending">0</span></button>
                <button class="rr-il-tab" data-tab="toepassen"><?php esc_html_e('Toepassen', 'rankrepair'); ?> <span class="rr-il-pill" id="rr-il-count-approved">0</span></button>
                <button class="rr-il-tab" data-tab="data"><?php esc_html_e('Data', 'rankrepair'); ?></button>
                <button class="rr-il-tab" data-tab="instellingen"><?php esc_html_e('Instellingen', 'rankrepair'); ?></button>
            </nav>

            <?php $this->render_panel_overview(); ?>
            <?php $this->render_panel_suggestions(); ?>
            <?php $this->render_panel_apply(); ?>
            <?php $this->render_panel_data(); ?>
            <?php $this->render_panel_settings($config); ?>

        </div>
        <?php
        $this->render_footer();
    }

    private function render_panel_overview() {
        ?>
        <section class="rr-il-panel is-active" data-panel="overzicht">
            <div class="rr-il-toolbar">
                <button id="rr-il-scan-btn" class="button button-primary"><?php esc_html_e('Scan interne links', 'rankrepair'); ?></button>
                <button id="rr-il-bulk-btn" class="button"><?php esc_html_e('Genereer suggesties', 'rankrepair'); ?></button>
                <button id="rr-il-export-btn" class="button"><?php esc_html_e('Exporteer CSV', 'rankrepair'); ?></button>
                <div id="rr-il-progress" class="rr-il-progress" style="display:none;">
                    <div class="rr-il-progress-bar"><span id="rr-il-progress-fill"></span></div>
                    <span id="rr-il-progress-txt">0 / 0</span>
                </div>
            </div>

            <div class="rr-il-stats">
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
                        <th><?php esc_html_e('Suggesties', 'rankrepair'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rr-il-tbody">
                    <tr><td colspan="6"><?php esc_html_e('Klik op "Scan interne links" om te beginnen.', 'rankrepair'); ?></td></tr>
                </tbody>
            </table>
        </section>
        <?php
    }

    private function render_panel_suggestions() {
        ?>
        <section class="rr-il-panel" data-panel="suggesties">
            <div class="rr-il-toolbar">
                <select id="rr-il-filter">
                    <option value="pending"><?php esc_html_e('Te beoordelen', 'rankrepair'); ?></option>
                    <option value="approved"><?php esc_html_e('Goedgekeurd', 'rankrepair'); ?></option>
                    <option value="applied"><?php esc_html_e('Geplaatst', 'rankrepair'); ?></option>
                    <option value="rejected"><?php esc_html_e('Afgewezen', 'rankrepair'); ?></option>
                    <option value="failed"><?php esc_html_e('Mislukt', 'rankrepair'); ?></option>
                    <option value="all"><?php esc_html_e('Alles', 'rankrepair'); ?></option>
                </select>
                <button id="rr-il-approve-all" class="button"><?php esc_html_e('Alles op deze pagina goedkeuren', 'rankrepair'); ?></button>
                <span class="rr-il-hint"><?php esc_html_e('Ankertekst is aanpasbaar: klik erop, typ, en druk op enter.', 'rankrepair'); ?></span>
            </div>
            <div id="rr-il-suggestions"><p class="rr-il-empty"><?php esc_html_e('Nog geen suggesties geladen.', 'rankrepair'); ?></p></div>
        </section>
        <?php
    }

    private function render_panel_apply() {
        ?>
        <section class="rr-il-panel" data-panel="toepassen">
            <div class="rr-il-notice">
                <strong><?php esc_html_e('Wat hier gebeurt:', 'rankrepair'); ?></strong>
                <?php esc_html_e('elke goedgekeurde suggestie gaat vlak voor het opslaan nog één keer door alle controles, wordt dan in de pagina gezet, en is daarna per stuk terug te draaien.', 'rankrepair'); ?>
                <?php esc_html_e('Van elke wijziging bewaren we bovendien een kopie van de pagina zoals hij was. Bij Gutenberg en de klassieke editor legt WordPress daarnaast een revisie vast; bij Elementor niet, omdat revisies de postmeta waar Elementor in werkt niet meenemen — daar is onze eigen kopie de terugweg.', 'rankrepair'); ?>
            </div>
            <div class="rr-il-toolbar">
                <button id="rr-il-apply-btn" class="button button-primary"><?php esc_html_e('Plaats goedgekeurde links', 'rankrepair'); ?></button>
                <div id="rr-il-apply-progress" class="rr-il-progress" style="display:none;">
                    <div class="rr-il-progress-bar"><span id="rr-il-apply-fill"></span></div>
                    <span id="rr-il-apply-txt">0 / 0</span>
                </div>
            </div>
            <div id="rr-il-apply-log" class="rr-il-log"></div>
        </section>
        <?php
    }

    private function render_panel_data() {
        ?>
        <section class="rr-il-panel" data-panel="data">
            <div class="rr-il-data">
                <div id="rr-il-graph" class="rr-il-graph"><p class="rr-il-empty"><?php esc_html_e('Graaf wordt opgebouwd…', 'rankrepair'); ?></p></div>
                <aside class="rr-il-sidebar">
                    <h3><?php esc_html_e('Linkprofiel', 'rankrepair'); ?></h3>
                    <div id="rr-il-metrics"></div>
                    <h3><?php esc_html_e('Legenda', 'rankrepair'); ?></h3>
                    <ul class="rr-il-legend">
                        <li><span class="rr-il-dot" style="background:#EF4444"></span><?php esc_html_e('orphan — 0 inkomend', 'rankrepair'); ?></li>
                        <li><span class="rr-il-dot" style="background:#F59E0B"></span><?php esc_html_e('thin — 1 inkomend', 'rankrepair'); ?></li>
                        <li><span class="rr-il-dot" style="background:#10B981"></span><?php esc_html_e('ok — 2 of meer', 'rankrepair'); ?></li>
                        <li><span class="rr-il-dot" style="background:#6366F1"></span><?php esc_html_e('hub — 8 of meer', 'rankrepair'); ?></li>
                        <li><span class="rr-il-dot" style="background:#A855F7"></span><?php esc_html_e('lijn — door RankRepair geplaatst', 'rankrepair'); ?></li>
                    </ul>
                </aside>
            </div>
        </section>
        <?php
    }

    private function render_panel_settings(array $config) {
        $fields = [
            'max_links_per_source'   => [__('Nieuwe links per bronpagina', 'rankrepair'), __('Hoeveel links we in één ronde aan dezelfde pagina toevoegen.', 'rankrepair')],
            'max_links_per_target'   => [__('Nieuwe links per doelpagina', 'rankrepair'), __('Hoeveel inkomende links één pagina er per ronde bij mag krijgen.', 'rankrepair')],
            'suggestions_per_target' => [__('Suggesties per doelpagina', 'rankrepair'), __('Hoeveel bronnen de planner per pagina zoekt.', 'rankrepair')],
            'max_same_anchor'        => [__('Zelfde ankertekst per doel', 'rankrepair'), __('Boven dit aantal wordt exact dezelfde tekst geweigerd.', 'rankrepair')],
            'density_per_100w'       => [__('Linkdichtheid per 100 woorden', 'rankrepair'), __('Bovengrens op het totaal aantal interne links in een pagina.', 'rankrepair')],
        ];
        ?>
        <section class="rr-il-panel" data-panel="instellingen">
            <form id="rr-il-settings-form" class="rr-il-settings">
                <?php foreach ($fields as $key => $meta): ?>
                    <label class="rr-il-field">
                        <span class="rr-il-field-lbl"><?php echo esc_html($meta[0]); ?></span>
                        <input type="number" step="<?php echo $key === 'density_per_100w' ? '0.1' : '1'; ?>" min="0"
                               name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($config[$key]); ?>">
                        <span class="rr-il-field-help"><?php echo esc_html($meta[1]); ?></span>
                    </label>
                <?php endforeach; ?>

                <fieldset class="rr-il-field">
                    <legend class="rr-il-field-lbl"><?php esc_html_e('Plaatsingsmodi', 'rankrepair'); ?></legend>
                    <label><input type="checkbox" name="mode_wrap" value="1" <?php checked($config['mode_wrap'], 1); ?>>
                        <?php esc_html_e('Bestaande woorden linken (veiligst, geen tekstwijziging)', 'rankrepair'); ?></label><br>
                    <label><input type="checkbox" name="mode_rewrite" value="1" <?php checked($config['mode_rewrite'], 1); ?>>
                        <?php esc_html_e('Zin minimaal herschrijven (vereist AI-key)', 'rankrepair'); ?></label><br>
                    <label><input type="checkbox" name="mode_clause" value="1" <?php checked($config['mode_clause'], 1); ?>>
                        <?php esc_html_e('Korte bijzin toevoegen (vereist AI-key)', 'rankrepair'); ?></label><br>
                    <label><input type="checkbox" name="use_ai" value="1" <?php checked($config['use_ai'], 1); ?>>
                        <?php esc_html_e('AI gebruiken waar dat mag', 'rankrepair'); ?></label>
                </fieldset>

                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Opslaan', 'rankrepair'); ?></button>
                    <span id="rr-il-settings-msg" class="rr-il-hint"></span>
                </p>
            </form>
        </section>
        <?php
    }
}

new RR_Addon_Internal_Links();
