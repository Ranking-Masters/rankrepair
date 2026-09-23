<?php
/**
 * Image Optimizer Add-on
 * Scan, compress, convert format, and resize images.
 * Compression engine ported from Smart Image Compressor.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-rr-display-scanner.php';

class RR_Addon_Image_Optimizer extends RR_Addon_Base {

    /** @var RR_Display_Scanner */
    private $display_scanner;

    /** @var array Plugin options (stored under jic_options for backwards compat) */
    private $options = [];

    protected function init() {
        $this->slug        = 'image-optimizer';
        $this->name        = __('Afbeeldingen', 'rankrepair');
        $this->description = __('Comprimeer afbeeldingen, converteer naar WebP en pas formaten automatisch aan.', 'rankrepair');
        $this->icon        = 'dashicons-format-image';

        $this->load_options();
        $this->display_scanner = new RR_Display_Scanner();
        $this->register_ajax_hooks();

        if ($this->options['auto_compress']) {
            add_filter('wp_handle_upload',                [$this, 'handle_upload_compression'], 10, 2);
            add_filter('wp_generate_attachment_metadata', [$this, 'compress_thumbnails_on_upload'], 10, 2);
        }

        add_filter('manage_media_columns',       [$this, 'add_media_column']);
        add_action('manage_media_custom_column', [$this, 'render_media_column'], 10, 2);
    }

    // =========================================================================
    // OPTIONS
    // =========================================================================

    private function load_options() {
        $defaults = [
            'max_file_size'       => 102400, // 100 KB
            'quality'             => 82,
            'max_width'           => 1920,
            'max_height'          => 1920,
            'auto_compress'       => 1,
            'compress_thumbnails' => 1,
            'backup_originals'    => 1,
            'convert_png_to_jpg'  => 0,
            'convert_to_webp'     => 1,
            'convert_gif_to_webp' => 0,
            'strip_metadata'      => 1,
        ];
        $this->options = wp_parse_args(get_option('jic_options', []), $defaults);
    }

    // =========================================================================
    // AJAX HOOKS
    // =========================================================================

    private function register_ajax_hooks() {
        add_action('wp_ajax_rr_img_scan',          [$this, 'ajax_scan']);
        add_action('wp_ajax_rr_img_compress',      [$this, 'ajax_compress']);
        add_action('wp_ajax_rr_img_stats',         [$this, 'ajax_stats']);
        add_action('wp_ajax_rr_img_restore',       [$this, 'ajax_restore']);
        add_action('wp_ajax_rr_img_cli_queue',     [$this, 'ajax_cli_queue']);
        add_action('wp_ajax_rr_img_clear_queue',   [$this, 'ajax_clear_queue']);
        add_action('wp_ajax_rr_img_save_settings', [$this, 'ajax_save_settings']);

        // Display-size scanner (oversized for displayed dimensions)
        add_action('wp_ajax_rr_img_ds_scan',    [$this, 'ajax_ds_scan']);
        add_action('wp_ajax_rr_img_ds_resize',  [$this, 'ajax_ds_resize']);

        // Uitsluitingen (bv. handtekening-afbeeldingen die nooit geoptimaliseerd mogen worden)
        add_action('wp_ajax_rr_img_exclude',       [$this, 'ajax_exclude']);
        add_action('wp_ajax_rr_img_unexclude',     [$this, 'ajax_unexclude']);
        add_action('wp_ajax_rr_img_excluded_list', [$this, 'ajax_excluded_list']);
    }

    // =========================================================================
    // DISPLAY-SIZE SCANNER AJAX
    // =========================================================================

    public function ajax_ds_scan() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $raw  = isset($_POST['urls']) ? wp_unslash($_POST['urls']) : '';
        $list = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
        if (empty($list)) {
            $list = [home_url('/')];
        }
        $list = array_slice(array_unique($list), 0, 20);

        $results = $this->display_scanner->scan_urls($list);

        // Add bytes-saving estimate per row (~70% of original on typical PNG/JPG resize)
        $total_potential = 0;
        foreach ($results as &$r) {
            $r['est_saving_bytes'] = (int) round($r['natural_bytes'] * 0.7);
            $r['est_saving_text']  = size_format($r['est_saving_bytes']);
            $total_potential      += $r['est_saving_bytes'];
            $r['pages_count']      = count($r['pages']);
        }
        unset($r);

        wp_send_json_success([
            'images'             => $results,
            'total'              => count($results),
            'urls_scanned'       => $list,
            'total_potential'    => $total_potential,
            'total_potential_text' => size_format($total_potential),
        ]);
    }

    public function ajax_ds_resize() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        $target_width  = isset($_POST['target_width'])  ? absint($_POST['target_width'])  : 0;
        if (!$attachment_id || !$target_width) {
            wp_send_json_error(__('Ongeldige parameters.', 'rankrepair'));
        }

        try {
            $result = $this->display_scanner->resize_attachment($attachment_id, $target_width);
        } catch (\Throwable $e) {
            error_log('[RR Display Scanner] ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    // =========================================================================
    // UITSLUITINGEN
    // =========================================================================

    /**
     * Sluit een afbeelding permanent uit van optimalisatie (bv. een handtekening-
     * afbeelding die nooit gecomprimeerd/geconverteerd mag worden). Wordt zowel
     * vanuit een rij in de hoofdtabel aangeroepen als vanuit de media-library-picker
     * in het uitsluitingen-overzicht.
     */
    public function ajax_exclude() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
            wp_send_json_error(__('Ongeldig attachment ID.', 'rankrepair'));
        }
        update_post_meta($attachment_id, '_jic_excluded', 1);
        wp_send_json_success();
    }

    /** Haalt de uitsluiting van een afbeelding weg — komt weer gewoon mee in de scan. */
    public function ajax_unexclude() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(__('Ongeldig attachment ID.', 'rankrepair'));
        }
        delete_post_meta($attachment_id, '_jic_excluded');
        wp_send_json_success();
    }

    /** Lijst van alle momenteel uitgesloten afbeeldingen, voor het uitsluitingen-overzicht. */
    public function ajax_excluded_list() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $query = new WP_Query([
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => '_jic_excluded', 'value' => '1'],
            ],
        ]);

        $images = [];
        foreach ($query->posts as $id) {
            $file_path = get_attached_file($id);
            $images[]  = [
                'id'        => $id,
                'title'     => get_the_title($id),
                'file_name' => $file_path ? basename($file_path) : '',
                'thumb_url' => wp_get_attachment_image_url($id, 'thumbnail') ?: '',
            ];
        }
        wp_send_json_success(['images' => $images, 'total' => count($images)]);
    }

    // =========================================================================
    // ENQUEUE ASSETS
    // =========================================================================

    public function enqueue_assets($hook) {
        if (strpos($hook, 'rankrepair-image-optimizer') === false) {
            return;
        }

        // Nodig voor de media-library-picker in het uitsluitingen-overzicht
        // (handmatig een afbeelding toevoegen die niet in de scanlijst staat).
        wp_enqueue_media();

        $css_ver = filemtime(RR_PLUGIN_DIR . 'addons/image-optimizer/image-optimizer.css') ?: RR_VERSION;
        $js_ver  = filemtime(RR_PLUGIN_DIR . 'addons/image-optimizer/image-optimizer.js')  ?: RR_VERSION;

        wp_enqueue_style(
            'rr-image-optimizer',
            RR_PLUGIN_URL . 'addons/image-optimizer/image-optimizer.css',
            ['rr-admin-style'],
            $css_ver
        );

        wp_enqueue_script(
            'rr-image-optimizer',
            RR_PLUGIN_URL . 'addons/image-optimizer/image-optimizer.js',
            ['jquery', 'rr-admin-script'],
            $js_ver,
            true
        );

        wp_localize_script('rr-image-optimizer', 'rrImg', [
            'convertToWebp'    => (int) $this->options['convert_to_webp'],
            'convertPngToJpg'  => (int) $this->options['convert_png_to_jpg'],
            'convertGifToWebp' => (int) $this->options['convert_gif_to_webp'],
            'quality'          => (int) $this->options['quality'],
            'maxWidth'         => (int) $this->options['max_width'],
            'backupOriginals'  => (int) $this->options['backup_originals'],
            'webpSupported'    => (int) $this->server_supports_webp(),
            'cliQueueCount'    => count(get_option('jic_cli_queue', [])),
            'strings'          => [
                'confirmBulk'      => __('Weet je zeker dat je alle afbeeldingen wilt optimaliseren?', 'rankrepair'),
                'confirmSelection' => __('Weet je zeker dat je de geselecteerde afbeeldingen wilt optimaliseren?', 'rankrepair'),
                'bulkComplete'     => __('Optimalisatie voltooid!', 'rankrepair'),
                'noImages'         => __('Geen afbeeldingen gevonden om te optimaliseren.', 'rankrepair'),
                'restored'         => __('Origineel hersteld!', 'rankrepair'),
            ],
        ]);
    }

    // =========================================================================
    // GET STATS (for dashboard)
    // =========================================================================

    public function get_stats() {
        global $wpdb;

        $total_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'
             AND post_status = 'inherit'"
        );

        $compressed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_jic_compressed' AND meta_value = '1'"
        );

        return [
            'total'  => $total_images,
            'issues' => max(0, $total_images - $compressed_count),
        ];
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    public function render_page() {
        global $wpdb;

        // Live stats
        $total_images = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'"
        );

        $compressed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_jic_compressed' AND meta_value = '1'"
        );

        $too_large = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_jic_compressed'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
             AND p.post_status = 'inherit' AND (pm.meta_value IS NULL OR pm.meta_value = '0')"
        ));

        $orig_sizes  = array_map('intval', (array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jic_original_size'"));
        $comp_sizes  = array_map('intval', (array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jic_compressed_size'"));
        $total_saved = max(0, array_sum($orig_sizes) - array_sum($comp_sizes));
        $saving_pct  = array_sum($orig_sizes) > 0
            ? round(($total_saved / array_sum($orig_sizes)) * 100)
            : 0;

        // Options
        $format_mode = $this->options['convert_to_webp']   ? 'webp'
                     : ($this->options['convert_png_to_jpg'] ? 'jpg' : 'original');
        $quality     = (int) $this->options['quality'];
        $max_width   = (int) $this->options['max_width'];
        $backup_on   = (bool) $this->options['backup_originals'];

        // Total estimated savings for unoptimized images (rough: 80% average)
        $est_total_savings_mb = $total_saved > 0
            ? round($total_saved / (1024 * 1024)) . ' MB'
            : '—';
        ?>
        <div class="wrap rr-wrap rr-img-wrap">

            <!-- Page Header -->
            <div class="rr-img-page-header">
                <div class="rr-img-page-header__left">
                    <img src="<?php echo esc_url(RR_PLUGIN_URL . 'assets/images/logoRankrepair.svg'); ?>" class="rr-logo-img" alt="RankRepair" height="32">
                    <div class="rr-img-title-group">
                        <div class="rr-img-title-row">
                            <span class="rr-img-title-badge"><?php _e('Afbeelding Optimizer', 'rankrepair'); ?></span>
                        </div>
                        <div class="rr-img-subtitle"><?php _e('Comprimeer, converteer naar WebP en resize te grote afbeeldingen', 'rankrepair'); ?></div>
                    </div>
                </div>
                <div class="rr-img-page-header__right">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=rankrepair-settings')); ?>" class="rr-img-btn rr-img-btn--outline">
                        <?php _e('Instellingen', 'rankrepair'); ?>
                    </a>
                    <button id="rr-img-optimize-all" class="rr-img-btn rr-img-btn--green">
                        ⚡ <?php _e('Optimaliseer alles', 'rankrepair'); ?> (<span id="rr-img-all-count"><?php echo esc_html($too_large); ?></span>)
                    </button>
                </div>
            </div>

            <!-- Stats Row -->
            <div class="rr-img-stats-row">
                <div class="rr-img-stat-card">
                    <div class="rr-img-stat-label"><?php _e('Totale mediagrootte', 'rankrepair'); ?></div>
                    <div class="rr-img-stat-number" id="rr-img-stat-total-size">—</div>
                    <div class="rr-img-stat-sub rr-img-stat-sub--green" id="rr-img-stat-total-sub">—</div>
                </div>
                <div class="rr-img-stat-card">
                    <div class="rr-img-stat-label"><?php _e('Afbeeldingen te groot', 'rankrepair'); ?></div>
                    <div class="rr-img-stat-number rr-img-stat-number--red" id="rr-img-stat-toolarge"><?php echo esc_html($too_large); ?></div>
                    <div class="rr-img-stat-sub"><?php printf(__('boven %s', 'rankrepair'), size_format($this->options['max_file_size'])); ?></div>
                </div>
                <div class="rr-img-stat-card">
                    <div class="rr-img-stat-label"><?php _e('Al geoptimaliseerd', 'rankrepair'); ?></div>
                    <div class="rr-img-stat-number rr-img-stat-number--green" id="rr-img-stat-compressed"><?php echo esc_html($compressed_count); ?></div>
                    <div class="rr-img-stat-sub rr-img-stat-sub--green"><?php echo esc_html($total_saved > 0 ? $est_total_savings_mb . ' ' . __('bespaard', 'rankrepair') : __('nog niets bespaard', 'rankrepair')); ?></div>
                </div>
                <div class="rr-img-stat-card">
                    <div class="rr-img-stat-label"><?php _e('Mogelijke besparing', 'rankrepair'); ?></div>
                    <div class="rr-img-stat-number rr-img-stat-number--darkgreen" id="rr-img-stat-saving-pct">—</div>
                    <div class="rr-img-stat-sub rr-img-stat-sub--green" id="rr-img-stat-saving-sub">—</div>
                </div>
            </div>

            <!-- Settings / Filter Bar -->
            <div class="rr-img-filter-bar">
                <span class="rr-img-filter-bar__title"><?php _e('Optimalisatie instellingen', 'rankrepair'); ?></span>
                <div class="rr-img-filter-bar__divider"></div>

                <!-- Format -->
                <div class="rr-img-filter-group">
                    <span class="rr-img-filter-label"><?php _e('Formaat', 'rankrepair'); ?></span>
                    <div class="rr-img-seg" id="rr-img-format-seg">
                        <button class="rr-img-seg__btn <?php echo $format_mode === 'webp'     ? 'rr-img-seg__btn--active' : ''; ?>" data-val="webp">WebP</button>
                        <button class="rr-img-seg__btn <?php echo $format_mode === 'jpg'      ? 'rr-img-seg__btn--active' : ''; ?>" data-val="jpg">JPEG</button>
                        <button class="rr-img-seg__btn <?php echo $format_mode === 'original' ? 'rr-img-seg__btn--active' : ''; ?>" data-val="original"><?php _e('Origineel', 'rankrepair'); ?></button>
                    </div>
                </div>
                <div class="rr-img-filter-bar__divider"></div>

                <!-- Quality slider -->
                <div class="rr-img-filter-group rr-img-filter-group--quality">
                    <span class="rr-img-filter-label"><?php _e('Kwaliteit', 'rankrepair'); ?></span>
                    <div class="rr-img-quality-wrap">
                        <span class="rr-img-quality-min">60</span>
                        <div class="rr-img-slider-wrap">
                            <input type="range" id="rr-img-quality-slider" min="60" max="100" step="1"
                                   value="<?php echo esc_attr($quality); ?>" class="rr-img-slider">
                            <div class="rr-img-slider-track">
                                <div class="rr-img-slider-fill" id="rr-img-slider-fill"
                                     style="width:<?php echo esc_attr(round(($quality - 60) / 40 * 100)); ?>%"></div>
                            </div>
                        </div>
                        <span class="rr-img-quality-val" id="rr-img-quality-val"><?php echo esc_html($quality); ?>%</span>
                    </div>
                </div>
                <div class="rr-img-filter-bar__divider"></div>

                <!-- Max width -->
                <div class="rr-img-filter-group">
                    <span class="rr-img-filter-label"><?php _e('Max breedte', 'rankrepair'); ?></span>
                    <div class="rr-img-seg" id="rr-img-width-seg">
                        <button class="rr-img-seg__btn <?php echo $max_width >= 1920 ? 'rr-img-seg__btn--active' : ''; ?>" data-val="1920">1920px</button>
                        <button class="rr-img-seg__btn <?php echo $max_width === 1440 ? 'rr-img-seg__btn--active' : ''; ?>" data-val="1440">1440px</button>
                        <button class="rr-img-seg__btn <?php echo $max_width <= 1200 ? 'rr-img-seg__btn--active' : ''; ?>" data-val="1200">1200px</button>
                    </div>
                </div>
                <div class="rr-img-filter-bar__divider"></div>

                <!-- Backup toggle -->
                <div class="rr-img-filter-group rr-img-filter-group--toggle">
                    <span class="rr-img-filter-label"><?php _e('Origineel bewaren', 'rankrepair'); ?></span>
                    <button id="rr-img-backup-toggle"
                            class="rr-img-toggle <?php echo $backup_on ? 'rr-img-toggle--on' : ''; ?>"
                            data-val="<?php echo $backup_on ? '1' : '0'; ?>" aria-pressed="<?php echo $backup_on ? 'true' : 'false'; ?>">
                        <span class="rr-img-toggle__thumb"></span>
                    </button>
                </div>
                <div class="rr-img-filter-bar__divider"></div>

                <!-- Uitsluitingen -->
                <div class="rr-img-filter-group">
                    <button id="rr-img-excluded-btn" class="rr-img-btn rr-img-btn--outline" type="button">
                        🚫 <?php _e('Uitgesloten', 'rankrepair'); ?> (<span id="rr-img-excluded-count">0</span>)
                    </button>
                </div>
            </div>

            <!-- Uitsluitingen-overlay -->
            <div id="rr-img-excl-overlay" class="rr-img-excl-overlay" style="display:none">
                <div class="rr-img-excl-modal">
                    <div class="rr-img-excl-modal__header">
                        <div class="rr-img-excl-modal__title"><?php _e('Uitgesloten afbeeldingen', 'rankrepair'); ?></div>
                        <div class="rr-img-excl-modal__sub"><?php _e('Deze afbeeldingen worden nooit meegenomen in de optimalisatie.', 'rankrepair'); ?></div>
                        <button id="rr-img-excl-close" class="rr-img-excl-modal__close" type="button" aria-label="<?php esc_attr_e('Sluiten', 'rankrepair'); ?>">&times;</button>
                    </div>
                    <div class="rr-img-excl-modal__toolbar">
                        <button id="rr-img-excl-add-btn" class="rr-img-btn rr-img-btn--green rr-img-btn--sm" type="button">
                            + <?php _e('Afbeelding toevoegen', 'rankrepair'); ?>
                        </button>
                    </div>
                    <div id="rr-img-excl-list" class="rr-img-excl-modal__list">
                        <div class="rr-img-excl-empty"><?php _e('Laden...', 'rankrepair'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="rr-img-table-card" id="rr-img-table-card">
                <div class="rr-img-table-card__header">
                    <div>
                        <div class="rr-img-table-card__title"><?php _e('Te optimaliseren afbeeldingen', 'rankrepair'); ?></div>
                        <div class="rr-img-table-card__sub"><?php _e('Gesorteerd op bestandsgrootte (groot → klein)', 'rankrepair'); ?></div>
                    </div>
                    <div class="rr-img-table-card__actions">
                        <button class="rr-img-btn rr-img-btn--outline rr-img-btn--sm" id="rr-img-export-btn">
                            <?php _e('Rapport exporteren', 'rankrepair'); ?>
                        </button>
                        <button class="rr-img-btn rr-img-btn--outline rr-img-btn--sm" id="rr-img-select-all-btn">
                            <?php _e('Selecteer alles', 'rankrepair'); ?>
                        </button>
                    </div>
                </div>

                <!-- Progress bar (hidden until bulk running) -->
                <div id="rr-img-progress-wrap" style="display:none">
                    <div class="rr-img-progress-bar-outer">
                        <div id="rr-img-progress-fill" class="rr-img-progress-bar-fill" style="width:0%">
                            <span class="rr-img-progress-bar-pct">0%</span>
                        </div>
                    </div>
                    <div class="rr-img-progress-meta">
                        <span id="rr-img-progress-count">0 / 0</span>
                        <span id="rr-img-progress-saved"></span>
                        <button id="rr-img-stop-btn" class="rr-img-btn rr-img-btn--outline rr-img-btn--sm">
                            <?php _e('Stop', 'rankrepair'); ?>
                        </button>
                    </div>
                </div>

                <div class="rr-img-table-scroll">
                    <table class="rr-img-table">
                        <thead>
                            <tr class="rr-img-table__hrow">
                                <th class="rr-img-th rr-img-th--check">
                                    <input type="checkbox" id="rr-img-check-all" class="rr-img-checkbox">
                                </th>
                                <th class="rr-img-th"><?php _e('Voorbeeld', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Bestandsnaam', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Huidig', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Na optimalisatie', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Besparing', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Afmetingen', 'rankrepair'); ?></th>
                                <th class="rr-img-th"><?php _e('Status', 'rankrepair'); ?></th>
                                <th class="rr-img-th"></th>
                                <th class="rr-img-th rr-img-th--excl"><?php _e('Uitsluiten', 'rankrepair'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="rr-img-tbody">
                            <tr>
                                <td colspan="10" class="rr-img-empty-state" id="rr-img-empty">
                                    <div class="rr-img-empty-spinner">
                                        <span class="rr-spin rr-spin--lg"></span>
                                        <span><?php _e('Afbeeldingen laden...', 'rankrepair'); ?></span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================ -->
            <!--  Display-Size Scanner                        -->
            <!-- ============================================ -->
            <div class="rr-img-table-card" id="rr-img-ds-card" style="margin-top: 24px;">
                <div class="rr-img-table-card__header">
                    <div>
                        <div class="rr-img-table-card__title"><?php _e('Te grote afmetingen voor weergave', 'rankrepair'); ?></div>
                        <div class="rr-img-table-card__sub">
                            <?php _e('Detecteert afbeeldingen die fysiek veel groter zijn dan ze gerenderd worden (Lighthouse: "properly sized images")', 'rankrepair'); ?>
                        </div>
                    </div>
                </div>

                <div style="padding: 12px 20px; display: flex; gap: 12px; align-items: flex-start; border-bottom: 1px solid #e5e7eb;">
                    <div style="flex: 1;">
                        <label style="display:block; font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                            <?php _e('URLs om te scannen (één per regel — max 20)', 'rankrepair'); ?>
                        </label>
                        <textarea id="rr-img-ds-urls" rows="3" style="width: 100%; font-family: monospace; font-size: 12px; padding: 6px;"
                                  placeholder="<?php echo esc_attr(home_url('/')); ?>"><?php echo esc_textarea(home_url('/')); ?></textarea>
                    </div>
                    <button id="rr-img-ds-scan-btn" class="rr-img-btn rr-img-btn--green" style="align-self: flex-end;">
                        🔍 <?php _e('Scan pagina(s)', 'rankrepair'); ?>
                    </button>
                </div>

                <div id="rr-img-ds-summary" style="display:none; padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 13px;">
                    <strong id="rr-img-ds-count">0</strong> <?php _e('te grote afbeeldingen gevonden', 'rankrepair'); ?>
                    · <strong><?php _e('Geschatte besparing', 'rankrepair'); ?>:</strong>
                    <span id="rr-img-ds-savings">—</span>
                </div>

                <div class="rr-img-table-scroll">
                    <table class="rr-img-table" id="rr-img-ds-table" style="display:none;">
                        <thead>
                            <tr>
                                <th style="width: 60px;"><?php _e('Voorbeeld', 'rankrepair'); ?></th>
                                <th><?php _e('Bestandsnaam', 'rankrepair'); ?></th>
                                <th><?php _e('Werkelijke afm.', 'rankrepair'); ?></th>
                                <th><?php _e('Weergegeven', 'rankrepair'); ?></th>
                                <th><?php _e('Verhouding', 'rankrepair'); ?></th>
                                <th><?php _e('Voorgesteld', 'rankrepair'); ?></th>
                                <th style="width: 140px;"><?php _e('Actie', 'rankrepair'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="rr-img-ds-tbody"></tbody>
                    </table>
                </div>

                <div id="rr-img-ds-empty" style="padding: 20px; text-align:center; color:#9ca3af;">
                    <?php _e('Klik op "Scan pagina(s)" om te beginnen.', 'rankrepair'); ?>
                </div>
            </div>

        </div><!-- /rr-img-wrap -->

        <!-- Sticky Footer Bar -->
        <div class="rr-img-footer-bar" id="rr-img-footer-bar">
            <div class="rr-img-footer-bar__left">
                <div class="rr-img-footer-bar__label"><?php _e('Totale besparing na optimalisatie', 'rankrepair'); ?></div>
                <div class="rr-img-footer-bar__value">
                    <span class="rr-img-footer-savings" id="rr-img-footer-savings"><?php echo esc_html($est_total_savings_mb); ?></span>
                    <span class="rr-img-footer-sub"><?php _e('bespaard', 'rankrepair'); ?> (<span id="rr-img-footer-pct"><?php echo esc_html($saving_pct); ?></span>% <?php _e('kleiner', 'rankrepair'); ?>)</span>
                </div>
            </div>
            <div class="rr-img-footer-bar__right">
                <span class="rr-img-footer-sel" id="rr-img-footer-sel" style="display:none"></span>
                <button class="rr-img-btn rr-img-btn--outline" id="rr-img-export-footer-btn">
                    <?php _e('Rapport exporteren', 'rankrepair'); ?>
                </button>
                <button class="rr-img-btn rr-img-btn--green" id="rr-img-optimize-sel-btn" disabled>
                    ⚡ <?php _e('Optimaliseer selectie', 'rankrepair'); ?> (<span id="rr-img-sel-count">0</span>)
                </button>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_scan() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        // Re-load options so we have current settings (might have changed via inline bar)
        $this->load_options();

        // Determine target format. In conversion modes we include attachments that
        // are not yet in the target format, even if previously compressed — otherwise
        // a PNG optimized in "Origineel" mode can never be converted to WebP later.
        $target_mime = null;
        if (!empty($this->options['convert_to_webp'])) {
            $target_mime = 'image/webp';
        } elseif (!empty($this->options['convert_png_to_jpg'])) {
            $target_mime = 'image/jpeg';
        }

        $all_image_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => $target_mime
                ? array_values(array_diff($all_image_mimes, [$target_mime]))
                : $all_image_mimes,
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        // Uitgesloten afbeeldingen (bv. handtekeningen) mogen nooit in de scanlijst
        // verschijnen, ongeacht modus — vandaar als aparte AND-groep toegevoegd.
        $not_excluded = [
            'relation' => 'OR',
            ['key' => '_jic_excluded', 'compare' => 'NOT EXISTS'],
            ['key' => '_jic_excluded', 'value' => '0'],
        ];

        if ($target_mime) {
            // Format-conversion mode: show every attachment whose current mime
            // isn't the target — _jic_compressed flag is ignored on purpose.
            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_jic_compressed', 'compare' => 'NOT EXISTS'],
                    ['key' => '_jic_compressed', 'value' => '0'],
                    ['key' => '_jic_compressed', 'value' => '1'],
                ],
                $not_excluded,
            ];
        } else {
            // Origineel mode: existing behaviour — only uncompressed images.
            $args['meta_query'] = [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_jic_compressed', 'compare' => 'NOT EXISTS'],
                    ['key' => '_jic_compressed', 'value' => '0'],
                ],
                $not_excluded,
            ];
        }

        $query       = new WP_Query($args);
        $images      = [];
        $upload_dir  = wp_upload_dir();
        $total_bytes = 0;

        foreach ($query->posts as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }

            $file_size = filesize($file_path);
            $total_bytes += $file_size;

            $mime_type = get_post_mime_type($attachment_id);
            $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

            // Dimensions from metadata
            $meta      = wp_get_attachment_metadata($attachment_id);
            $width     = isset($meta['width'])  ? (int) $meta['width']  : 0;
            $height    = isset($meta['height']) ? (int) $meta['height'] : 0;

            // New dimensions after potential resize
            $max_w     = $this->options['max_width'];
            $max_h     = $this->options['max_height'];
            $new_w     = $width;
            $new_h     = $height;
            $will_resize = false;
            if ($width > $max_w || $height > $max_h) {
                $will_resize = true;
                $ratio = min($max_w / max(1, $width), $max_h / max(1, $height));
                $new_w = (int) round($width * $ratio);
                $new_h = (int) round($height * $ratio);
            }

            // Estimated output size
            $est_ratio = 0.15; // default WebP ~15% of original
            if ($this->options['convert_to_webp']) {
                $est_ratio = ($mime_type === 'image/png') ? 0.12 : 0.15;
            } elseif ($this->options['convert_png_to_jpg'] && $mime_type === 'image/png') {
                $est_ratio = 0.18;
            } else {
                $quality   = $this->options['quality'];
                $est_ratio = max(0.10, ($quality / 100) * 0.25);
            }
            if ($will_resize) {
                $dim_ratio  = ($new_w * $new_h) / max(1, $width * $height);
                $est_ratio *= sqrt($dim_ratio);
            }

            $est_size    = (int) ($file_size * $est_ratio);
            $est_savings = $file_size > 0 ? round((1 - $est_size / $file_size) * 100) : 0;

            $images[] = [
                'id'                => $attachment_id,
                'title'             => get_the_title($attachment_id),
                'file_size'         => $file_size,
                'size_text'         => size_format($file_size),
                'file_name'         => basename($file_path),
                'thumb_url'         => $thumb_url ?: '',
                'mime_type'         => $mime_type,
                // In a format-conversion mode, every non-target-format image is
                // a candidate — even those already under max_file_size — because
                // PNG → WebP usually still saves significantly on disk.
                'needs_compression' => ($file_size > $this->options['max_file_size'])
                    || ($target_mime && $mime_type !== $target_mime),
                'width'             => $width,
                'height'            => $height,
                'new_width'         => $new_w,
                'new_height'        => $new_h,
                'will_resize'       => $will_resize,
                'est_size'          => $est_size,
                'est_size_text'     => size_format($est_size),
                'est_savings_pct'   => $est_savings,
            ];
        }

        usort($images, function ($a, $b) { return $b['file_size'] - $a['file_size']; });

        wp_send_json_success([
            'images'          => $images,
            'total'           => count($images),
            'total_bytes'     => $total_bytes,
            'total_size_text' => size_format($total_bytes),
            'max_size'        => $this->options['max_file_size'],
        ]);
    }

    public function ajax_compress() {
        ob_start();
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            ob_end_clean();
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            ob_end_clean();
            wp_send_json_error(__('Ongeldig attachment ID.', 'rankrepair'));
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            ob_end_clean();
            wp_send_json_error(__('Bestand niet gevonden.', 'rankrepair'));
        }

        $this->load_options();
        $original_mime  = get_post_mime_type($attachment_id);
        $is_gif_or_webp = in_array($original_mime, ['image/gif', 'image/webp'], true);

        $convert_to_jpg  = isset($_POST['convert_to_jpg'])  ? (bool) absint($_POST['convert_to_jpg'])  : null;
        $convert_to_webp = isset($_POST['convert_to_webp']) ? (bool) absint($_POST['convert_to_webp']) : null;

        try {
            $result = $this->compress_image($file_path, $attachment_id, $convert_to_jpg, $convert_to_webp);
        } catch (\Throwable $e) {
            ob_end_clean();
            error_log('[RR Image Optimizer] ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }

        if (is_wp_error($result)) {
            ob_end_clean();
            error_log('[RR Image Optimizer] ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        }

        if (!$is_gif_or_webp) {
            $this->compress_attachment_thumbnails($attachment_id);
            if ('compressed' === $result['status']) {
                $metadata = wp_generate_attachment_metadata($attachment_id, get_attached_file($attachment_id));
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        ob_end_clean();
        wp_send_json_success($result);
    }

    public function ajax_stats() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }

        global $wpdb;

        $total_images     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'");
        $compressed_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_jic_compressed' AND meta_value = '1'");
        $orig_sizes       = array_map('intval', (array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jic_original_size'"));
        $comp_sizes       = array_map('intval', (array) $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_jic_compressed_size'"));
        $total_original   = array_sum($orig_sizes);
        $total_compressed = array_sum($comp_sizes);
        $total_saved      = max(0, $total_original - $total_compressed);

        wp_send_json_success([
            'total_images'     => $total_images,
            'compressed_count' => $compressed_count,
            'uncompressed'     => max(0, $total_images - $compressed_count),
            'total_saved'      => $total_saved,
            'total_saved_text' => size_format($total_saved),
            'percentage'       => $total_original > 0 ? round(($total_saved / $total_original) * 100, 1) : 0,
        ]);
    }

    public function ajax_restore() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Geen toestemming.', 'rankrepair'));
        }
        $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$attachment_id) {
            wp_send_json_error(__('Ongeldig attachment ID.', 'rankrepair'));
        }
        $result = $this->restore_original($attachment_id);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success(['message' => __('Origineel succesvol hersteld.', 'rankrepair')]);
    }

    public function ajax_cli_queue() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Geen toestemming.'); }
        $id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$id) { wp_send_json_error('Ongeldig ID.'); }
        $queue = get_option('jic_cli_queue', []);
        if (!in_array($id, $queue, true)) { $queue[] = $id; update_option('jic_cli_queue', $queue, false); }
        wp_send_json_success(['count' => count($queue)]);
    }

    public function ajax_clear_queue() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Geen toestemming.'); }
        delete_option('jic_cli_queue');
        wp_send_json_success(['count' => 0]);
    }

    public function ajax_save_settings() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Geen toestemming.');
        }

        $opts = get_option('jic_options', []);

        if (isset($_POST['format'])) {
            $fmt = sanitize_key($_POST['format']);
            $opts['convert_to_webp']   = ($fmt === 'webp')     ? 1 : 0;
            $opts['convert_png_to_jpg']= ($fmt === 'jpg')      ? 1 : 0;
        }
        if (isset($_POST['quality'])) {
            $opts['quality'] = min(100, max(1, absint($_POST['quality'])));
        }
        if (isset($_POST['max_width'])) {
            $opts['max_width']  = max(800, absint($_POST['max_width']));
            $opts['max_height'] = max(800, absint($_POST['max_width']));
        }
        if (isset($_POST['backup_originals'])) {
            $opts['backup_originals'] = absint($_POST['backup_originals']) ? 1 : 0;
        }

        update_option('jic_options', $opts);
        $this->options = wp_parse_args($opts, $this->options);

        wp_send_json_success();
    }

    // =========================================================================
    // UPLOAD HOOKS
    // =========================================================================

    public function handle_upload_compression($upload, $context) {
        if ('upload' !== $context) return $upload;
        if (!in_array($upload['type'], ['image/jpeg', 'image/png', 'image/webp'], true)) return $upload;
        $result = $this->compress_image($upload['file'], 0);
        if (!is_wp_error($result) && isset($result['new_path'])) {
            $upload['file'] = $result['new_path'];
            $upload['type'] = $result['new_mime'];
            $upload['url']  = str_replace(basename($upload['url']), basename($result['new_path']), $upload['url']);
        }
        return $upload;
    }

    public function compress_thumbnails_on_upload($metadata, $attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if ($file_path && file_exists($file_path)) {
            $file_size = filesize($file_path);
            if ($file_size <= $this->options['max_file_size']) {
                update_post_meta($attachment_id, '_jic_compressed', 1);
                update_post_meta($attachment_id, '_jic_compressed_size', $file_size);
                update_post_meta($attachment_id, '_jic_compressed_date', current_time('mysql'));
            }
        }
        $this->compress_attachment_thumbnails($attachment_id);
        return $metadata;
    }

    // =========================================================================
    // MEDIA LIBRARY COLUMN
    // =========================================================================

    public function add_media_column($columns) {
        $columns['rr_img_status'] = __('Compressie', 'rankrepair');
        return $columns;
    }

    public function render_media_column($column_name, $post_id) {
        if ('rr_img_status' !== $column_name) return;
        $mime_type = get_post_mime_type($post_id);
        if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            echo '<span style="color:#9ca3af">—</span>';
            return;
        }
        $is_compressed = get_post_meta($post_id, '_jic_compressed', true);
        if ($is_compressed) {
            $orig = (int) get_post_meta($post_id, '_jic_original_size', true);
            $comp = (int) get_post_meta($post_id, '_jic_compressed_size', true);
            if ($orig && $comp) {
                $saved = round((1 - $comp / $orig) * 100, 1);
                printf('<span style="color:#10b981;font-weight:600">✓ -%s%%</span><br><small style="color:#6b7280">%s → %s</small>',
                    esc_html($saved), esc_html(size_format($orig)), esc_html(size_format($comp)));
            } else {
                echo '<span style="color:#10b981">✓ Gecomprimeerd</span>';
            }
        } else {
            $file_path = get_attached_file($post_id);
            $file_size = ($file_path && file_exists($file_path)) ? filesize($file_path) : 0;
            if ($file_size > $this->options['max_file_size']) {
                printf('<span style="color:#ef4444;font-weight:600">✗ %s</span>', esc_html(size_format($file_size)));
            } else {
                printf('<span style="color:#9ca3af">%s</span>', esc_html($file_size ? size_format($file_size) : '—'));
            }
        }
    }

    // =========================================================================
    // COMPRESSION ENGINE
    // =========================================================================

    private function is_animated_gif($file_path) {
        $fp = @fopen($file_path, 'rb');
        if (!$fp) return false;
        $count = 0;
        while (!feof($fp) && $count < 2) { $chunk = fread($fp, 1024 * 100); $count += substr_count($chunk, "\x00\x21\xF9\x04"); }
        fclose($fp);
        return $count > 1;
    }

    private function run_shell_command($cmd) {
        if (function_exists('exec')) { $lines = []; exec($cmd . ' 2>&1', $lines, $code); return ['output' => implode("\n", $lines), 'code' => $code]; }
        if (function_exists('proc_open')) { $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']]; $proc = proc_open($cmd, $desc, $pipes); if (is_resource($proc)) { $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); return ['output' => $out, 'code' => proc_close($proc)]; } }
        if (function_exists('popen')) { $handle = popen($cmd . ' 2>&1', 'r'); if (false !== $handle) { $out = ''; while (!feof($handle)) { $out .= fread($handle, 4096); } return ['output' => $out, 'code' => pclose($handle)]; } }
        if (function_exists('shell_exec')) { return ['output' => (string) shell_exec($cmd . ' 2>&1'), 'code' => 0]; }
        return false;
    }

    public function server_supports_webp() {
        if (extension_loaded('imagick')) { $formats = Imagick::queryFormats('WEBP'); if (!empty($formats)) return true; }
        return function_exists('imagewebp');
    }

    private function compress_animated_gif($file_path, $attachment_id = 0, $do_webp = false) {
        @set_time_limit(300);
        $original_size = filesize($file_path);
        $out_ext  = $do_webp ? 'webp' : 'gif';
        $temp_file = $file_path . '.rr_temp.' . $out_ext;
        $image_info = @getimagesize($file_path);
        $orig_w = $image_info ? $image_info[0] : 0;
        $orig_h = $image_info ? $image_info[1] : 0;
        $max_w  = $this->options['max_width'];
        $max_h  = $this->options['max_height'];
        $needs_resize = ($orig_w > 0 && ($orig_w > $max_w || $orig_h > $max_h));
        $resize_arg   = $needs_resize ? sprintf('-resize %dx%d\\> ', (int) $max_w, (int) $max_h) : '';

        $policy_dir = sys_get_temp_dir() . '/rr_magick_' . uniqid('', true);
        @mkdir($policy_dir, 0755, true);
        file_put_contents($policy_dir . '/policy.xml', '<?xml version="1.0" encoding="UTF-8"?><policymap><policy domain="resource" name="list-length" value="65536"/><policy domain="resource" name="disk" value="10GiB"/><policy domain="resource" name="memory" value="512MiB"/><policy domain="resource" name="time" value="300"/></policymap>');

        $which_result = $this->run_shell_command('which magick');
        if (false !== $which_result) {
            $magick = (0 === (int) $which_result['code']) ? trim($which_result['output']) : '/usr/bin/magick';
            if (empty($magick)) $magick = '/usr/bin/magick';
            $optimize_arg = $do_webp ? '' : '-layers optimize ';
            $cmd = sprintf('MAGICK_CONFIGURE_PATH=%s %s %s -coalesce %s%s%s 2>&1', escapeshellarg($policy_dir), escapeshellarg($magick), escapeshellarg($file_path), $resize_arg, $optimize_arg, escapeshellarg($temp_file));
            $cli_result = $this->run_shell_command($cmd);
            @unlink($policy_dir . '/policy.xml'); @rmdir($policy_dir);
            if (false !== $cli_result && 0 === (int) $cli_result['code'] && file_exists($temp_file)) {
                return $this->finalize_gif_result($file_path, $temp_file, $attachment_id, $do_webp, $original_size);
            }
        } else { @unlink($policy_dir . '/policy.xml'); @rmdir($policy_dir); }

        if (!extension_loaded('imagick')) { return new WP_Error('no_method', __('GIF compressie vereist CLI magick of de PHP Imagick-extensie.', 'rankrepair')); }
        try {
            $imagick = new Imagick(); $list_type = defined('Imagick::RESOURCETYPE_LIST') ? Imagick::RESOURCETYPE_LIST : 5;
            @$imagick->setResourceLimit($list_type, 65536); $imagick->readImage($file_path); $imagick = $imagick->coalesceImages();
            if ($needs_resize) { foreach ($imagick as $frame) { $frame->thumbnailImage((int) $max_w, (int) $max_h, true); } $imagick->resetIterator(); }
            if (!$do_webp) { $optimized = $imagick->optimizeLayers(); if ($optimized instanceof Imagick) { $imagick->clear(); $imagick = $optimized; } }
            $imagick->setFormat($do_webp ? 'WEBP' : 'GIF'); $imagick->writeImages($temp_file, true); $imagick->clear();
        } catch (ImagickException $e) { if (isset($imagick)) $imagick->clear(); @unlink($temp_file); return new WP_Error('imagick_error', $e->getMessage()); }
        if (!file_exists($temp_file)) { return new WP_Error('no_output', __('Geen uitvoerbestand aangemaakt.', 'rankrepair')); }
        return $this->finalize_gif_result($file_path, $temp_file, $attachment_id, $do_webp, $original_size);
    }

    private function finalize_gif_result($file_path, $temp_file, $attachment_id, $do_webp, $original_size) {
        $new_size = filesize($temp_file);
        if (!$do_webp && $new_size >= $original_size) { @unlink($temp_file); return ['status' => 'skipped', 'original_size' => $original_size, 'new_size' => $original_size, 'saved' => 0, 'message' => __('GIF kon niet verder gecomprimeerd worden.', 'rankrepair')]; }
        $target_path = $do_webp ? preg_replace('/\.gif$/i', '.webp', $file_path) : $file_path;
        copy($temp_file, $target_path); @unlink($temp_file);
        if ($do_webp && $target_path !== $file_path) { @unlink($file_path); if ($attachment_id > 0) { $this->update_attachment_path($attachment_id, $target_path, 'image/webp'); } }
        if ($attachment_id > 0) { update_post_meta($attachment_id, '_jic_compressed', 1); update_post_meta($attachment_id, '_jic_original_size', $original_size); update_post_meta($attachment_id, '_jic_compressed_size', $new_size); update_post_meta($attachment_id, '_jic_compressed_date', current_time('mysql')); }
        return ['status' => 'compressed', 'original_size' => $original_size, 'new_size' => $new_size, 'saved' => $original_size - $new_size, 'percentage' => round((1 - $new_size / $original_size) * 100, 1), 'message' => sprintf(__('Gecomprimeerd: %s → %s (-%s%%)', 'rankrepair'), size_format($original_size), size_format($new_size), round((1 - $new_size / $original_size) * 100, 1))];
    }

    public function compress_image($file_path, $attachment_id = 0, $convert_to_jpg = null, $convert_to_webp = null) {
        // Harde blokkade, ongeacht via welke weg deze functie wordt aangeroepen
        // (handmatig, bulk, of de auto-compress-upload-hook): een uitgesloten
        // afbeelding (bv. een handtekening) mag nooit geoptimaliseerd worden.
        if ($attachment_id > 0 && get_post_meta($attachment_id, '_jic_excluded', true)) {
            return new WP_Error('excluded', __('Deze afbeelding is uitgesloten van optimalisatie.', 'rankrepair'));
        }
        if (!file_exists($file_path)) { return new WP_Error('file_not_found', __('Bestand niet gevonden.', 'rankrepair')); }
        $original_size = filesize($file_path);
        $max_size = $this->options['max_file_size'];

        // Skip the "already small enough" shortcut when the caller explicitly
        // requested a format conversion (e.g. PNG → WebP). Otherwise files under
        // max_file_size never get converted even when the user picks WebP.
        $current_mime = function_exists('mime_content_type') ? @mime_content_type($file_path) : null;
        $wants_format_change =
            ($convert_to_webp && $current_mime && $current_mime !== 'image/webp' && $current_mime !== 'image/gif') ||
            ($convert_to_jpg  && $current_mime === 'image/png');

        if ($original_size <= $max_size && !$wants_format_change) {
            if ($attachment_id > 0) {
                update_post_meta($attachment_id, '_jic_compressed', 1);
                update_post_meta($attachment_id, '_jic_compressed_size', $original_size);
                if (!get_post_meta($attachment_id, '_jic_original_size', true)) {
                    update_post_meta($attachment_id, '_jic_original_size', $original_size);
                }
                update_post_meta($attachment_id, '_jic_compressed_date', current_time('mysql'));
            }
            return ['status' => 'skipped', 'original_size' => $original_size, 'new_size' => $original_size, 'saved' => 0, 'message' => __('Bestand is al kleiner dan het maximum.', 'rankrepair')];
        }
        $image_info = getimagesize($file_path);
        if (false === $image_info) { return new WP_Error('invalid_image', __('Ongeldig afbeeldingsbestand.', 'rankrepair')); }
        $mime_type = $image_info['mime'];
        if (!in_array($mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) { return new WP_Error('unsupported_type', sprintf(__('Type %s wordt niet ondersteund.', 'rankrepair'), $mime_type)); }
        if ('image/gif' === $mime_type) { if ($this->options['backup_originals'] && $attachment_id > 0) { $this->create_backup($file_path, $attachment_id); } $gif_webp = null !== $convert_to_webp ? (bool) $convert_to_webp : (bool) $this->options['convert_gif_to_webp']; return $this->compress_animated_gif($file_path, $attachment_id, $gif_webp); }
        if ($this->options['backup_originals'] && $attachment_id > 0) { $this->create_backup($file_path, $attachment_id); }
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) return $editor;
        $current_size = $editor->get_size(); $width = $current_size['width']; $height = $current_size['height'];
        $max_w = $this->options['max_width']; $max_h = $this->options['max_height'];
        if ($width > $max_w || $height > $max_h) { $editor->resize($max_w, $max_h, false); }
        $do_webp  = null !== $convert_to_webp ? (bool) $convert_to_webp : (bool) $this->options['convert_to_webp'];
        $do_jpg   = null !== $convert_to_jpg  ? (bool) $convert_to_jpg  : (bool) $this->options['convert_png_to_jpg'];
        $save_mime = $mime_type;
        if ($do_webp && $this->server_supports_webp() && 'image/webp' !== $mime_type && 'image/gif' !== $mime_type) { $save_mime = 'image/webp'; }
        elseif ('image/png' === $mime_type && $do_jpg) { $save_mime = 'image/jpeg'; }
        $quality = $this->options['quality']; $min_quality = 20; $temp_file = $file_path . '.rr_temp'; $success = false;
        // max_size <= 0 means "no size target" — accept first compression result
        // as long as it's smaller than the original (which it virtually always is).
        $has_size_target = $max_size > 0;
        $skip_loop = ('image/gif' === $mime_type && 'image/gif' === $save_mime);
        while (!$skip_loop && $quality >= $min_quality) {
            $editor->set_quality($quality); $saved = $editor->save($temp_file, $save_mime);
            if (is_wp_error($saved)) break;
            $new_size = filesize($saved['path']);
            if (!$has_size_target ? $new_size < $original_size : $new_size <= $max_size) {
                $target_path = $file_path;
                if ($save_mime !== $mime_type) { $ext_map = ['image/jpeg' => 'jpg', 'image/webp' => 'webp']; $new_ext = $ext_map[$save_mime] ?? ''; $target_path = $new_ext ? preg_replace('/\.(png|jpg|jpeg|gif|webp)$/i', '.' . $new_ext, $file_path) : $file_path; }
                if (copy($saved['path'], $target_path)) { $success = true; @unlink($saved['path']); if ($target_path !== $file_path && $attachment_id > 0) { @unlink($file_path); $this->update_attachment_path($attachment_id, $target_path, $save_mime); } $final_path = $target_path; $final_size = $new_size; }
                break;
            }
            @unlink($saved['path']); $quality = $quality > 70 ? $quality - 5 : $quality - 10;
        }
        if (!$success) {
            foreach ([0.75, 0.6, 0.5, 0.4, 0.3, 0.2, 0.1] as $scale) {
                $editor = wp_get_image_editor($file_path); if (is_wp_error($editor)) continue;
                $new_w = (int) ($width * $scale); $new_h = (int) ($height * $scale);
                if ($new_w < 400) continue;
                $editor->resize($new_w, $new_h, false); $editor->set_quality(max($min_quality, $this->options['quality'] - 10));
                $saved = $editor->save($temp_file, $save_mime);
                if (!is_wp_error($saved) && (!$has_size_target ? filesize($saved['path']) < $original_size : filesize($saved['path']) <= $max_size)) {
                    $target_path = $file_path; if ($save_mime !== $mime_type) { $target_path = preg_replace('/\.png$/i', '.jpg', $file_path); }
                    if (copy($saved['path'], $target_path)) { $success = true; $final_path = $target_path; $final_size = filesize($saved['path']); @unlink($saved['path']); if ($target_path !== $file_path && $attachment_id > 0) { @unlink($file_path); $this->update_attachment_path($attachment_id, $target_path, $save_mime); } break; }
                }
                if (isset($saved) && !is_wp_error($saved)) @unlink($saved['path']);
            }
        }
        @unlink($temp_file);
        if ($success) {
            if ($attachment_id > 0) { update_post_meta($attachment_id, '_jic_compressed', 1); update_post_meta($attachment_id, '_jic_original_size', $original_size); update_post_meta($attachment_id, '_jic_compressed_size', $final_size); update_post_meta($attachment_id, '_jic_compressed_date', current_time('mysql')); }
            return ['status' => 'compressed', 'original_size' => $original_size, 'new_size' => $final_size, 'saved' => $original_size - $final_size, 'percentage' => round((1 - $final_size / $original_size) * 100, 1), 'message' => sprintf(__('Gecomprimeerd: %s → %s (-%s%%)', 'rankrepair'), size_format($original_size), size_format($final_size), round((1 - $final_size / $original_size) * 100, 1))];
        }
        return new WP_Error('compression_failed', __('Kon de afbeelding niet voldoende comprimeren.', 'rankrepair'));
    }

    public function compress_attachment_thumbnails($attachment_id) {
        if (!$this->options['compress_thumbnails']) return;
        $metadata = wp_get_attachment_metadata($attachment_id); $upload_dir = wp_upload_dir(); $base_dir = trailingslashit($upload_dir['basedir']);
        if (empty($metadata['sizes'])) return;
        $file_dir = trailingslashit(dirname($metadata['file']));
        foreach ($metadata['sizes'] as $size_data) { $thumb_path = $base_dir . $file_dir . $size_data['file']; if (file_exists($thumb_path) && filesize($thumb_path) > $this->options['max_file_size']) { $editor = wp_get_image_editor($thumb_path); if (!is_wp_error($editor)) { $editor->set_quality($this->options['quality']); $editor->save($thumb_path); } } }
    }

    private function create_backup($file_path, $attachment_id) {
        $backup_dir = dirname($file_path) . '/jic-backups/';
        if (!file_exists($backup_dir)) { wp_mkdir_p($backup_dir); file_put_contents($backup_dir . '.htaccess', 'Deny from all'); }
        $backup_path = $backup_dir . basename($file_path);
        if (!file_exists($backup_path)) { copy($file_path, $backup_path); update_post_meta($attachment_id, '_jic_backup_path', $backup_path); }
    }

    public function restore_original($attachment_id) {
        $backup_path = get_post_meta($attachment_id, '_jic_backup_path', true);
        if (empty($backup_path) || !file_exists($backup_path)) { return new WP_Error('no_backup', __('Geen backup gevonden.', 'rankrepair')); }
        $file_path = get_attached_file($attachment_id);
        if (copy($backup_path, $file_path)) {
            delete_post_meta($attachment_id, '_jic_compressed'); delete_post_meta($attachment_id, '_jic_original_size'); delete_post_meta($attachment_id, '_jic_compressed_size'); delete_post_meta($attachment_id, '_jic_compressed_date');
            if (function_exists('wp_create_image_subsizes')) { $metadata = wp_create_image_subsizes($file_path, $attachment_id); wp_update_attachment_metadata($attachment_id, $metadata); }
            return true;
        }
        return new WP_Error('restore_failed', __('Kon het origineel niet herstellen.', 'rankrepair'));
    }

    private function update_attachment_path($attachment_id, $new_path, $new_mime) {
        $upload_dir = wp_upload_dir(); $relative_path = str_replace(trailingslashit($upload_dir['basedir']), '', $new_path);
        // Oude URL bepalen VÓÓR we het record bijwerken, zodat we hardcoded
        // verwijzingen in content kunnen omschrijven naar de nieuwe (webp) URL.
        $old_rel = get_post_meta($attachment_id, '_wp_attached_file', true);
        $old_url = $old_rel ? trailingslashit($upload_dir['baseurl']) . $old_rel : '';
        $new_url = trailingslashit($upload_dir['baseurl']) . $relative_path;
        update_attached_file($attachment_id, $relative_path); wp_update_post(['ID' => $attachment_id, 'post_mime_type' => $new_mime]);
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts, ['guid' => $new_url], ['ID' => $attachment_id]);
        // Het originele bestand is na conversie verwijderd; verwijzingen in
        // post-content blijven anders naar de oude (.png/.jpg) URL wijzen → 404.
        if ($old_url && $old_url !== $new_url) {
            $this->rewrite_content_image_urls($old_url, $new_url);
        }
    }

    /**
     * Werk hardcoded verwijzingen naar de oude afbeeldings-URL bij naar de nieuwe URL
     * in post-content. post_content is geen geserialiseerde data, dus een directe
     * REPLACE is veilig. (Postmeta wordt overgeslagen: dat is vaak geserialiseerd en
     * afbeeldingen daar worden doorgaans via attachment-ID gerenderd, niet via URL.)
     */
    private function rewrite_content_image_urls($old_url, $new_url) {
        global $wpdb;
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
            $old_url, $new_url, '%' . $wpdb->esc_like($old_url) . '%'
        ));
        return (int) $affected;
    }
}

new RR_Addon_Image_Optimizer();
