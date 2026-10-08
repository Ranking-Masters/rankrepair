<?php
/**
 * Security Add-on — IP-blocklist/whitelist, 403-blokkade met log, aangepaste
 * login-URL en lockout-beveiligingen. Puur lokaal (geen Level4).
 *
 * Noodknop (SSH): zet `define('RR_SECURITY_DISABLE', true);` in wp-config.php —
 * dat schakelt álle blokkades én de custom login-URL direct uit.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-rr-security-ip.php';

class RR_Addon_Security extends RR_Addon_Base {

    const OPT_ENABLED = 'rr_security_enabled';
    const OPT_BLOCK   = 'rr_security_blocklist';
    const OPT_WHITE   = 'rr_security_whitelist';
    const OPT_HEADER  = 'rr_security_trusted_header';
    const OPT_LOGIN   = 'rr_security_login_slug';
    const OPT_LOG     = 'rr_security_log';
    const MAX_LOG     = 100;

    /** Toegestane vertrouwde proxy-headers (key => label). */
    public static function trusted_headers(): array {
        return [
            ''                          => __('Geen (alleen REMOTE_ADDR)', 'rankrepair'),
            'HTTP_CF_CONNECTING_IP'      => 'CF-Connecting-IP (Cloudflare)',
            'HTTP_X_FORWARDED_FOR'       => 'X-Forwarded-For',
            'HTTP_X_REAL_IP'             => 'X-Real-IP',
            'HTTP_TRUE_CLIENT_IP'        => 'True-Client-IP',
        ];
    }

    protected function init() {
        $this->slug        = 'security';
        $this->name        = __('Beveiliging', 'rankrepair');
        $this->description = __('IP-blocklist/whitelist, aangepaste login-URL en toegangslog.', 'rankrepair');
        $this->icon        = 'dashicons-shield';

        // Blokkade op 'init' (pluggable functies + ingelogde gebruiker zijn dan bekend,
        // zodat we beheerders nooit buitensluiten) maar vóór enige front-end output.
        add_action('init', [$this, 'maybe_block'], 0);

        // Custom login-URL moet vroeg, vóór wp-login.php laadt.
        add_action('plugins_loaded', [$this, 'login_guard'], 1);
        add_filter('site_url', [$this, 'filter_login_url'], 10, 2);
        add_filter('wp_redirect', [$this, 'filter_login_redirect'], 10, 1);

        // Settings opslaan.
        add_action('admin_post_rr_security_save', [$this, 'handle_save']);
    }

    // =====================================================================
    // Kill-switch / helpers
    // =====================================================================

    private function disabled(): bool {
        if (defined('RR_SECURITY_DISABLE') && RR_SECURITY_DISABLE) {
            return true;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return true; // CLI nooit blokkeren/ompunten
        }
        return get_option(self::OPT_ENABLED, '0') !== '1';
    }

    public function current_ip(): string {
        return RR_Security_IP::client_ip($_SERVER, (string) get_option(self::OPT_HEADER, ''));
    }

    private function blocklist(): array {
        return RR_Security_IP::normalize_list(get_option(self::OPT_BLOCK, ''));
    }

    private function whitelist(): array {
        return RR_Security_IP::normalize_list(get_option(self::OPT_WHITE, ''));
    }

    // =====================================================================
    // Blokkade
    // =====================================================================

    public function maybe_block() {
        if ($this->disabled()) {
            return;
        }
        // Ingelogde beheerders worden NOOIT geblokkeerd (lockout-beveiliging).
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }
        $ip = $this->current_ip();
        if ($ip === '') {
            return; // geen betrouwbaar IP -> niet blokkeren (geen lockout bij twijfel)
        }
        if (RR_Security_IP::is_blocked($ip, $this->blocklist(), $this->whitelist())) {
            $this->log_block($ip);
            status_header(403);
            nocache_headers();
            wp_die(
                esc_html__('Toegang geweigerd.', 'rankrepair'),
                esc_html__('403 Verboden', 'rankrepair'),
                ['response' => 403]
            );
        }
    }

    private function log_block(string $ip) {
        $log = get_option(self::OPT_LOG, []);
        if (!is_array($log)) { $log = []; }
        // Dedup-ruis beperken: niet vaker dan eens per 5 min per IP loggen.
        $now = time();
        foreach ($log as $entry) {
            if (($entry['ip'] ?? '') === $ip && ($now - (int) ($entry['t'] ?? 0)) < 300) {
                return;
            }
        }
        array_unshift($log, [
            'ip'  => $ip,
            't'   => $now,
            'uri' => isset($_SERVER['REQUEST_URI']) ? substr(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])), 0, 200) : '',
        ]);
        $log = array_slice($log, 0, self::MAX_LOG);
        update_option(self::OPT_LOG, $log, false);
    }

    // =====================================================================
    // Aangepaste login-URL (verberg wp-login.php)
    // =====================================================================

    private function login_slug(): string {
        $slug = trim((string) get_option(self::OPT_LOGIN, ''), '/ ');
        return preg_match('/^[a-z0-9-]{3,64}$/', $slug) ? $slug : '';
    }

    private function request_path(): string {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        // Sub-directory-installaties: haal het WP-pad eraf.
        $home = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');
        $path = trim($path, '/');
        if ($home !== '' && strpos($path, $home) === 0) {
            $path = trim(substr($path, strlen($home)), '/');
        }
        return $path;
    }

    public function login_guard() {
        if ($this->disabled()) { return; }
        $slug = $this->login_slug();
        if ($slug === '') { return; } // feature uit
        if (defined('DOING_CRON') && DOING_CRON) { return; }
        // admin-ajax en admin-post moeten gewoon blijven werken.
        $path = $this->request_path();
        if ($path === 'wp-admin/admin-ajax.php' || $path === 'wp-admin/admin-post.php') { return; }

        $is_login = ($path === 'wp-login.php');
        $is_slug  = ($path === $slug);

        if ($is_slug) {
            // Serveer het echte login-formulier op de geheime URL.
            if (!defined('RR_SECURITY_LOGIN_OK')) { define('RR_SECURITY_LOGIN_OK', true); }
            global $pagenow;
            $pagenow = 'wp-login.php';
            require_once ABSPATH . 'wp-login.php';
            exit;
        }

        if ($is_login && !defined('RR_SECURITY_LOGIN_OK')) {
            // Directe toegang tot wp-login.php zonder de geheime URL -> 404.
            $this->not_found();
        }
    }

    /** Herschrijft door WordPress gegenereerde login-URL's naar de geheime slug. */
    public function filter_login_url($url, $scheme = null) {
        if ($this->disabled()) { return $url; }
        $slug = $this->login_slug();
        if ($slug === '') { return $url; }
        if (is_string($url) && strpos($url, 'wp-login.php') !== false) {
            $url = str_replace('wp-login.php', $slug, $url);
        }
        return $url;
    }

    public function filter_login_redirect($location) {
        return $this->filter_login_url($location);
    }

    private function not_found() {
        status_header(404);
        nocache_headers();
        // Zo veel mogelijk als een echte 404 ogen: thema-404 als het kan, anders kaal.
        if (function_exists('wp_die')) {
            wp_die(
                esc_html__('Pagina niet gevonden.', 'rankrepair'),
                esc_html__('404', 'rankrepair'),
                ['response' => 404]
            );
        }
        exit;
    }

    // =====================================================================
    // Admin-pagina
    // =====================================================================

    public function handle_save() {
        if (!current_user_can('manage_options')) { wp_die('forbidden'); }
        check_admin_referer('rr_security_save');

        update_option(self::OPT_ENABLED, isset($_POST['enabled']) ? '1' : '0', false);

        $header = isset($_POST['trusted_header']) ? sanitize_text_field(wp_unslash($_POST['trusted_header'])) : '';
        if (!array_key_exists($header, self::trusted_headers())) { $header = ''; }
        update_option(self::OPT_HEADER, $header, false);

        update_option(self::OPT_BLOCK, sanitize_textarea_field(wp_unslash($_POST['blocklist'] ?? '')), false);
        update_option(self::OPT_WHITE, sanitize_textarea_field(wp_unslash($_POST['whitelist'] ?? '')), false);

        $slug = isset($_POST['login_slug']) ? sanitize_title(wp_unslash($_POST['login_slug'])) : '';
        update_option(self::OPT_LOGIN, $slug, false);

        wp_safe_redirect(add_query_arg('rr_saved', '1', admin_url('admin.php?page=rankrepair-security')));
        exit;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) { return; }

        $ip        = $this->current_ip();
        $blocklist = $this->blocklist();
        $whitelist = $this->whitelist();
        $self_risk = $ip !== '' && RR_Security_IP::is_blocked($ip, $blocklist, $whitelist);
        $header    = (string) get_option(self::OPT_HEADER, '');
        $enabled   = get_option(self::OPT_ENABLED, '0') === '1';
        $slug      = (string) get_option(self::OPT_LOGIN, '');
        $log       = (array) get_option(self::OPT_LOG, []);
        $killed    = defined('RR_SECURITY_DISABLE') && RR_SECURITY_DISABLE;

        $this->render_header();
        echo '<div class="wrap rr-sec-wrap">';

        if (isset($_GET['rr_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Beveiligingsinstellingen opgeslagen.', 'rankrepair') . '</p></div>';
        }
        if ($killed) {
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Noodknop actief:', 'rankrepair') . '</strong> ' . esc_html__('RR_SECURITY_DISABLE staat in wp-config.php — alle blokkades en de custom login-URL zijn uitgeschakeld.', 'rankrepair') . '</p></div>';
        }

        echo '<p>' . esc_html__('Jouw huidige IP:', 'rankrepair') . ' <code>' . esc_html($ip ?: '—') . '</code></p>';
        if ($self_risk) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Let op:', 'rankrepair') . '</strong> ' . esc_html__('jouw eigen IP zou met deze lijsten geblokkeerd worden. Zet het op de whitelist voordat je opslaat — ingelogde beheerders worden weliswaar nooit geblokkeerd, maar wees voorzichtig.', 'rankrepair') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('rr_security_save');
        echo '<input type="hidden" name="action" value="rr_security_save">';

        echo '<table class="form-table"><tbody>';

        echo '<tr><th>' . esc_html__('Ingeschakeld', 'rankrepair') . '</th><td><label><input type="checkbox" name="enabled" value="1" ' . checked($enabled, true, false) . '> ' . esc_html__('Blokkades en login-URL actief', 'rankrepair') . '</label></td></tr>';

        echo '<tr><th>' . esc_html__('Vertrouwde proxy-header', 'rankrepair') . '</th><td><select name="trusted_header">';
        foreach (self::trusted_headers() as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($header, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Kies dit alleen als de site achter een proxy/CDN draait (bv. Cloudflare), anders blokkeer je het proxy-IP.', 'rankrepair') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Blocklist', 'rankrepair') . '</th><td><textarea name="blocklist" rows="6" class="large-text code" placeholder="203.0.113.5&#10;203.0.113.0/24">' . esc_textarea(get_option(self::OPT_BLOCK, '')) . '</textarea><p class="description">' . esc_html__('Eén IP of reeks (CIDR) per regel. # voor commentaar.', 'rankrepair') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Whitelist', 'rankrepair') . '</th><td><textarea name="whitelist" rows="6" class="large-text code" placeholder="' . esc_attr($ip) . '">' . esc_textarea(get_option(self::OPT_WHITE, '')) . '</textarea><p class="description">' . esc_html__('Whitelist wint altijd van blocklist. Zet hier je eigen IP/kantoor-IP.', 'rankrepair') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Login-URL', 'rankrepair') . '</th><td>' . esc_html(trailingslashit(home_url())) . '<input type="text" name="login_slug" value="' . esc_attr($slug) . '" class="regular-text" placeholder="geheim-inloggen"><p class="description">' . esc_html__('Leeg = standaard /wp-login.php. Ingevuld: inloggen kan alleen via deze URL; /wp-login.php en /wp-admin geven 404 voor niet-ingelogde bezoekers.', 'rankrepair') . '</p></td></tr>';

        echo '</tbody></table>';
        echo '<p><button class="button button-primary">' . esc_html__('Opslaan', 'rankrepair') . '</button></p>';
        echo '</form>';

        // Noodknop-uitleg.
        echo '<h2>' . esc_html__('Noodknop (als je jezelf buitensluit)', 'rankrepair') . '</h2>';
        echo '<p>' . esc_html__('Zet via SSH deze regel in wp-config.php en alles staat direct uit:', 'rankrepair') . '</p>';
        echo '<pre style="background:#f3f4f6;padding:10px;border-radius:6px;max-width:600px">define(\'RR_SECURITY_DISABLE\', true);</pre>';

        // Block-log.
        echo '<h2>' . esc_html__('Geblokkeerde IP\'s (recent)', 'rankrepair') . '</h2>';
        if (empty($log)) {
            echo '<p>' . esc_html__('Nog niks geblokkeerd.', 'rankrepair') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>IP</th><th>' . esc_html__('Wanneer', 'rankrepair') . '</th><th>' . esc_html__('Pad', 'rankrepair') . '</th></tr></thead><tbody>';
            foreach (array_slice($log, 0, 50) as $e) {
                echo '<tr><td><code>' . esc_html($e['ip'] ?? '') . '</code></td><td>' . esc_html(isset($e['t']) ? human_time_diff((int) $e['t']) . ' ' . __('geleden', 'rankrepair') : '') . '</td><td>' . esc_html($e['uri'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
        $this->render_footer();
    }
}

new RR_Addon_Security();
