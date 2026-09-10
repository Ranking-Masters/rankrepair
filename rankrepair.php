<?php
/**
 * Plugin Name: RankRepair
 * Plugin URI: https://example.com/rankrepair
 * Description: Los veelvoorkomende SEO- en performance-problemen op met één klik. Dashboard met PageSpeed integratie en modulaire add-ons.
 * Version: 1.8.0
 * Author: Danique
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rankrepair
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RR_VERSION', '1.8.0');
define('RR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RR_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('RR_PLUGIN_FILE', __FILE__);

/**
 * Main RankRepair Class
 */
final class RankRepair {

    private static $instance = null;
    private $addons = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->register_addons();
        $this->init_hooks();
        $this->maybe_create_tables();
    }

    private function load_dependencies() {
        require_once RR_PLUGIN_DIR . 'includes/class-rr-settings.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-pagespeed.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-dashboard.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-addon-base.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-ajax-handler.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-updater.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-wordfence.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-malware-scan.php';
        require_once RR_PLUGIN_DIR . 'includes/class-rr-agent.php';

        // Eigen (gratis) malware-scan: cron-hook + zelfhelende planning.
        add_action( RR_Malware_Scan::CRON_HOOK, [ 'RR_Malware_Scan', 'run_and_store' ] );
        add_action( 'init', [ 'RR_Malware_Scan', 'schedule' ] );

        $updater = new RR_Updater( RR_PLUGIN_FILE, RR_VERSION );
        $updater->init();

        $agent = new RR_Agent();
        $agent->init();
    }

    /**
     * Register add-ons
     * Modulaire structuur: voeg hier nieuwe add-ons toe
     */
    private function register_addons() {
        $addon_files = [
            'meta-manager'      => RR_PLUGIN_DIR . 'addons/meta-manager/class-addon-meta-manager.php',
            'structured-data'   => RR_PLUGIN_DIR . 'addons/structured-data/class-addon-structured-data.php',
            'redirects-checker' => RR_PLUGIN_DIR . 'addons/redirects-checker/class-addon-redirects-checker.php',
            'image-optimizer'   => RR_PLUGIN_DIR . 'addons/image-optimizer/class-addon-image-optimizer.php',
            'form-tester'       => RR_PLUGIN_DIR . 'addons/form-tester/class-addon-form-tester.php',
            'internal-links'    => RR_PLUGIN_DIR . 'addons/internal-links/class-addon-internal-links.php',
        ];

        foreach ($addon_files as $slug => $file) {
            if (!file_exists($file)) continue;
            // Skip als addon expliciet is uitgeschakeld (default = aan)
            if (get_option('rr_addon_' . $slug . '_enabled', '1') !== '1') continue;
            require_once $file;
        }

        $this->addons = apply_filters('rr_registered_addons', $this->addons);
    }

    /**
     * Lijst van beschikbare addons (ongeacht enabled-status) — voor dashboard.
     */
    public function get_available_addons() {
        return [
            'meta-manager'      => ['name' => __('AI Meta Rewriter', 'rankrepair'),      'icon' => '🤖', 'bg' => '#EDE9FE'],
            'structured-data'   => ['name' => __('Structured Data', 'rankrepair'),       'icon' => '🔗', 'bg' => '#EDE9FE'],
            'redirects-checker' => ['name' => __('Redirects Checker', 'rankrepair'),     'icon' => '↪️', 'bg' => '#DBEAFE'],
            'image-optimizer'   => ['name' => __('Image Optimizer', 'rankrepair'),       'icon' => '🖼️', 'bg' => '#D1FAE5'],
            'form-tester'       => ['name' => __('Formulieren Tester', 'rankrepair'),    'icon' => '📋', 'bg' => '#FEF3C7'],
            'internal-links'    => ['name' => __('Interne Links', 'rankrepair'),        'icon' => '🔗', 'bg' => '#E0F2FE'],
        ];
    }

    public function register_addon($slug, $addon_instance) {
        $this->addons[$slug] = $addon_instance;
    }

    public function get_addons() {
        return $this->addons;
    }

    public function get_addon($slug) {
        return isset($this->addons[$slug]) ? $this->addons[$slug] : null;
    }

    private function init_hooks() {
        register_deactivation_hook(RR_PLUGIN_FILE, [$this, 'deactivate']);


        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('init', [$this, 'load_textdomain']);

        // Paginatie suffix voor meta titels
        add_filter('wpseo_title',              [$this, 'apply_pagination_suffix'], 20);
        add_filter('pre_get_document_title',   [$this, 'apply_pagination_suffix'], 20);

        // Paginatie suffix voor meta omschrijving
        add_filter('wpseo_metadesc',           [$this, 'apply_pagination_suffix'], 20);

    }

    public function maybe_create_tables() {
        global $wpdb;
        $table          = $wpdb->prefix . 'rr_meta_data';
        $exists         = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table) . "'") === $table;
        $db_version_ok  = get_option('rr_db_version') === RR_VERSION;

        if ( ! $exists || ! $db_version_ok ) {
            $this->create_tables();
            $created = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table) . "'") === $table;
            if ( $created ) {
                update_option('rr_db_version', RR_VERSION);
            } else {
                $last_error = $wpdb->last_error;
                add_action('admin_notices', function () use ($last_error) {
                    echo '<div class="notice notice-error"><p><strong>RankRepair:</strong> Database tabellen konden niet worden aangemaakt.' . ( $last_error ? ' MySQL-fout: <code>' . esc_html($last_error) . '</code>' : '' ) . '<br>Controleer of de databasegebruiker de <code>CREATE TABLE</code> rechten heeft.</p></div>';
                });
            }
        } else {
            $this->run_migrations();
        }
    }

    private function run_migrations() {
        global $wpdb;
        $table_meta = $wpdb->prefix . 'rr_meta_data';

        // Migratie: voeg current_h1 toe als die nog niet bestaat
        $col = $wpdb->get_results("SHOW COLUMNS FROM $table_meta LIKE 'current_h1'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE $table_meta ADD COLUMN current_h1 varchar(500) DEFAULT NULL AFTER url");
        }

        // Migratie: entity_type + entity_subtype voor taxonomy support
        $col = $wpdb->get_results("SHOW COLUMNS FROM $table_meta LIKE 'entity_type'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE $table_meta ADD COLUMN entity_type varchar(20) NOT NULL DEFAULT 'post' AFTER post_id");
            $wpdb->query("ALTER TABLE $table_meta ADD COLUMN entity_subtype varchar(50) DEFAULT NULL AFTER entity_type");
            $wpdb->query("ALTER TABLE $table_meta ADD INDEX entity_idx (entity_type, post_id)");
        }
    }

    public function activate() {
        $this->create_tables();

        $defaults = [
            'rr_pagespeed_api_key' => '',
            'rr_seranking_api_key' => '',
            'rr_gemini_api_key'    => '',
            'rr_gemini_prompt'     => '',
            'rr_ai_provider'       => 'google',
            'rr_ai_model'          => '',
        ];

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }

        // Level 4 agent: genereer credentials, schedule cron en registreer.
        require_once RR_PLUGIN_DIR . 'includes/class-rr-agent.php';
        RR_Agent::on_activate();

        flush_rewrite_rules();
    }

    public function deactivate() {
        require_once RR_PLUGIN_DIR . 'includes/class-rr-agent.php';
        RR_Agent::on_deactivate();
        require_once RR_PLUGIN_DIR . 'includes/class-rr-malware-scan.php';
        RR_Malware_Scan::unschedule();
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // PageSpeed results cache
        // Opmerking: dbDelta vereist twee spaties voor PRIMARY KEY en elke kolom op eigen regel.
        $table_pagespeed = $wpdb->prefix . 'rr_pagespeed_results';
        $sql_pagespeed = "CREATE TABLE $table_pagespeed (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  url varchar(500) NOT NULL,
  strategy varchar(10) NOT NULL DEFAULT 'mobile',
  score_performance decimal(5,2) DEFAULT NULL,
  score_accessibility decimal(5,2) DEFAULT NULL,
  score_best_practices decimal(5,2) DEFAULT NULL,
  score_seo decimal(5,2) DEFAULT NULL,
  audits longtext DEFAULT NULL,
  opportunities longtext DEFAULT NULL,
  diagnostics longtext DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY url_strategy (url(191), strategy)
) $charset_collate;";

        // Meta titels en beschrijvingen
        $table_meta = $wpdb->prefix . 'rr_meta_data';

        // Interne links (graaf-tabel voor de Internal Links add-on)
        $table_links = $wpdb->prefix . 'rr_internal_links';

        $sql_meta = "CREATE TABLE $table_meta (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  post_id bigint(20) DEFAULT NULL,
  entity_type varchar(20) NOT NULL DEFAULT 'post',
  entity_subtype varchar(50) DEFAULT NULL,
  url varchar(500) NOT NULL,
  current_h1 varchar(500) DEFAULT NULL,
  current_title varchar(500) DEFAULT NULL,
  current_description text DEFAULT NULL,
  new_title varchar(500) DEFAULT NULL,
  new_description text DEFAULT NULL,
  title_length int(11) DEFAULT NULL,
  description_length int(11) DEFAULT NULL,
  is_duplicate_title tinyint(1) DEFAULT 0,
  is_duplicate_description tinyint(1) DEFAULT 0,
  status varchar(20) DEFAULT 'pending',
  imported_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY url (url(191)),
  KEY status (status),
  KEY post_id (post_id),
  KEY entity_idx (entity_type, post_id)
) $charset_collate;";

        $sql_links = "CREATE TABLE $table_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  source_type varchar(20) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL,
  target_type varchar(20) NOT NULL DEFAULT '',
  anchor text NULL,
  scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY target_id (target_id),
  KEY source_id (source_id)
) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_pagespeed);
        dbDelta($sql_meta);
        dbDelta($sql_links);

        // Fallback: als dbDelta de tabellen niet heeft aangemaakt (bijv. door een parseerfout),
        // probeer dan een directe CREATE TABLE IF NOT EXISTS query.
        if ( $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table_pagespeed) . "'") !== $table_pagespeed ) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $table_pagespeed (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  url varchar(500) NOT NULL,
  strategy varchar(10) NOT NULL DEFAULT 'mobile',
  score_performance decimal(5,2) DEFAULT NULL,
  score_accessibility decimal(5,2) DEFAULT NULL,
  score_best_practices decimal(5,2) DEFAULT NULL,
  score_seo decimal(5,2) DEFAULT NULL,
  audits longtext DEFAULT NULL,
  opportunities longtext DEFAULT NULL,
  diagnostics longtext DEFAULT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY url_strategy (url(191), strategy)
) $charset_collate;");
        }

        if ( $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table_meta) . "'") !== $table_meta ) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $table_meta (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  post_id bigint(20) DEFAULT NULL,
  url varchar(500) NOT NULL,
  current_h1 varchar(500) DEFAULT NULL,
  current_title varchar(500) DEFAULT NULL,
  current_description text DEFAULT NULL,
  new_title varchar(500) DEFAULT NULL,
  new_description text DEFAULT NULL,
  title_length int(11) DEFAULT NULL,
  description_length int(11) DEFAULT NULL,
  is_duplicate_title tinyint(1) DEFAULT 0,
  is_duplicate_description tinyint(1) DEFAULT 0,
  status varchar(20) DEFAULT 'pending',
  imported_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY url (url(191)),
  KEY status (status),
  KEY post_id (post_id)
) $charset_collate;");
        }

        if ( $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->esc_like($table_links) . "'") !== $table_links ) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS $table_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  source_type varchar(20) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL,
  target_type varchar(20) NOT NULL DEFAULT '',
  anchor text NULL,
  scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY target_id (target_id),
  KEY source_id (source_id)
) $charset_collate;");
        }
    }

    public function register_admin_menu() {
        // Hoofdmenu
        add_menu_page(
            __('RankRepair', 'rankrepair'),
            __('RankRepair', 'rankrepair'),
            'manage_options',
            'rankrepair',
            [$this, 'render_dashboard'],
            'dashicons-performance',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'rankrepair',
            __('Dashboard', 'rankrepair'),
            __('Dashboard', 'rankrepair'),
            'manage_options',
            'rankrepair',
            [$this, 'render_dashboard']
        );

        // Instellingen submenu
        add_submenu_page(
            'rankrepair',
            __('Instellingen', 'rankrepair'),
            __('Instellingen', 'rankrepair'),
            'manage_options',
            'rankrepair-settings',
            [$this, 'render_settings']
        );

        // Add-on submenus
        foreach ($this->addons as $slug => $addon) {
            add_submenu_page(
                'rankrepair',
                $addon->get_name(),
                $addon->get_name(),
                'manage_options',
                'rankrepair-' . $slug,
                [$addon, 'render_page']
            );
        }
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rankrepair') === false) {
            return;
        }

        $admin_css_ver = filemtime(RR_PLUGIN_DIR . 'admin/css/admin-style.css') ?: RR_VERSION;
        $admin_js_ver  = filemtime(RR_PLUGIN_DIR . 'admin/js/admin-script.js')  ?: RR_VERSION;

        wp_enqueue_style(
            'rr-admin-style',
            RR_PLUGIN_URL . 'admin/css/admin-style.css',
            [],
            $admin_css_ver
        );

        wp_enqueue_script(
            'rr-admin-script',
            RR_PLUGIN_URL . 'admin/js/admin-script.js',
            ['jquery'],
            $admin_js_ver,
            true
        );

        wp_localize_script('rr-admin-script', 'rrAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('rr_admin_nonce'),
            'pluginUrl' => RR_PLUGIN_URL,
            'strings'   => [
                'loading'   => __('Laden...', 'rankrepair'),
                'error'     => __('Er is een fout opgetreden', 'rankrepair'),
                'success'   => __('Succesvol opgeslagen', 'rankrepair'),
                'confirm'   => __('Weet je het zeker?', 'rankrepair'),
                'analyzing' => __('Analyseren...', 'rankrepair'),
                'noResults' => __('Geen resultaten gevonden', 'rankrepair'),
            ],
        ]);

        if (false !== strpos($hook, 'rankrepair-settings')) {
            wp_localize_script('rr-admin-script', 'rrSettings', [
                'defaultPrompt'      => rr_get_default_prompt_template(),
                'confirmResetPrompt' => __('Prompt terugzetten naar de standaard? Je aanpassingen gaan verloren.', 'rankrepair'),
            ]);
        }

        // Add-on assets
        foreach ($this->addons as $slug => $addon) {
            $addon->enqueue_assets($hook);
        }
    }

    public function render_dashboard() {
        $dashboard = new RR_Dashboard();
        $dashboard->render();
    }

    public function render_settings() {
        $settings = new RR_Settings();
        $settings->render();
    }

    public function load_textdomain() {
        load_plugin_textdomain('rankrepair', false, dirname(RR_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Voeg paginatie-suffix toe aan de meta titel voor pagina 2+
     * Werkt met Yoast (wpseo_title) en vanilla WP (pre_get_document_title).
     * Maakt elke /page/N/ titel uniek zodat SE Ranking ze niet als duplicaat ziet.
     */
    public function apply_pagination_suffix( $title ) {
        if ( is_admin() ) return $title;
        if ( empty( $title ) ) return $title;

        $paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
        if ( $paged <= 1 ) return $title;

        $sep   = get_option( 'rr_pagination_sep',   '-' );
        $label = get_option( 'rr_pagination_label', 'Pagina' );

        // Als Yoast %%page%% al heeft toegevoegd, niet dubbel doen
        $suffix = $sep . ' ' . $label . ' ' . $paged;
        if ( strpos( $title, $suffix ) !== false ) return $title;
        // Ook voorkomen dat we toevoegen als 'Pagina N' al elders staat
        if ( preg_match( '/' . preg_quote( $label, '/' ) . '\s+' . $paged . '\b/i', $title ) ) return $title;

        return $title . ' ' . $suffix;
    }
}

function rankrepair() {
    return RankRepair::get_instance();
}

/**
 * Geeft het standaard AI-prompt template terug.
 * Bevat {{placeholders}} voor dynamische pagina-data.
 * Gebruiker kan dit in Instellingen volledig aanpassen.
 */
function rr_get_default_prompt_template() {
    return "Je bent een ervaren SEO-specialist. Schrijf een meta titel en meta beschrijving voor de onderstaande webpagina. Volg deze regels strikt:\n\nREGELS META TITEL:\n- Maximale breedte: 580px (Google knipt af bij ~580px). Houd de titel op MAXIMAAL 50 tekens om zeker onder deze pixellimiet te blijven — brede letters zoals H, W, M kosten meer pixels dan smalle letters.\n- Zoekintentiegericht: schrijf zoals de gebruiker zoekt en wil zien in de SERP.\n- Hoofdzoekwoord zo vroeg mogelijk in de titel.\n- GEEN bedrijfsnaam in de titel — Google verwijdert deze toch uit de SERP.\n- Gebruik leestekens (vraagteken, uitroepteken, streepje) om de titel dynamisch te maken.\n\nREGELS META BESCHRIJVING:\n- Maximale breedte: 920px (Google knipt af bij ~920px). Houd de beschrijving op MAXIMAAL 145 tekens om zeker onder deze pixellimiet te blijven.\n- Hoofdzoekwoord DIRECT aan het begin (front-loaded).\n- Sluit aan op de H1 en de highlights van de pagina.\n- Noem een concreet voordeel of USP.\n- Eindig met een call-to-action.\n\nTOON & STIJL:\nSchrijf in een vriendelijke en enthousiaste toon. Spreek de bezoeker direct aan met 'jij/je'.\n\nVOORBEELD:\nTitel:        Geld lenen? Vergelijk nu! Goedkoop én verantwoord lenen\nBeschrijving: Verantwoord geld lenen? Het meest complete aanbod leningen vergelijk je 100% onafhankelijk op Geld.nl. Vergelijk, kies en bespaar gemiddeld \u20ac 580!\n\nOUTPUT FORMAT (geef UITSLUITEND dit terug, niets anders):\nTITEL: [hier de titel]\nBESCHRIJVING: [hier de beschrijving]\n\nPAGINA-GEGEVENS:\nURL: {{url}}\n{{h1_line}}{{current_title_line}}{{current_desc_line}}{{content_line}}";
}

/**
 * Encrypt an API key for safe storage in the WordPress options table.
 * Falls back to plain-text if OpenSSL is unavailable.
 */
function rr_encrypt_key($value) {
    if (empty($value)) return '';
    if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
        return $value;
    }
    $key       = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
    $iv        = random_bytes(16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return $value;
    return 'rr_enc:' . base64_encode($iv . $encrypted);
}

/**
 * Decrypt a stored API key. Returns the original value unchanged if it was
 * never encrypted (backwards-compatible with plain-text stored keys).
 */
function rr_decrypt_key($stored) {
    if (empty($stored)) return '';
    if (strpos($stored, 'rr_enc:') !== 0) return $stored; // plain-text, legacy
    if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 7));
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $key = hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return ($dec === false) ? '' : $dec;
}

/**
 * Algemene AI-tekstcompletion. Hergebruikt de provider-opties van de meta-manager
 * (rr_ai_provider / rr_ai_model / rr_gemini_api_key). Retourneert platte tekst of WP_Error.
 */
function rr_ai_complete($prompt) {
    $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
    if (empty($api_key)) {
        return new WP_Error('no_key', __('Geen AI API key ingesteld.', 'rankrepair'));
    }
    $provider = get_option('rr_ai_provider', 'google');
    $model    = trim(get_option('rr_ai_model', ''));

    if ($provider === 'openrouter') {
        if (empty($model)) { $model = 'google/gemini-2.0-flash-001'; }
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.5,
                'max_tokens'  => 300,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('ai_api', 'OpenRouter: ' . ($body['error']['message'] ?? "HTTP $code"));
        }
        $text = $body['choices'][0]['message']['content'] ?? '';
    } else {
        if (empty($model)) { $model = 'gemini-1.5-flash'; }
        $endpoint = add_query_arg('key', $api_key, 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent');
        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 300],
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('ai_api', 'Gemini API: ' . ($body['error']['message'] ?? "HTTP $code"));
        }
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    if (empty($text)) {
        return new WP_Error('ai_empty', __('AI gaf geen resultaat terug.', 'rankrepair'));
    }
    return trim($text);
}

// Activatie hook op file-niveau zodat hij ook vuurt bij eerste installatie,
// voordat plugins_loaded heeft gefired voor deze plugin.
register_activation_hook(RR_PLUGIN_FILE, function () {
    RankRepair::get_instance()->activate();
} );

add_action('plugins_loaded', 'rankrepair');
