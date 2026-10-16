<?php
/**
 * RankRepair Agent — verbinding met Level 4
 *
 * Verantwoordelijkheden:
 *  - genereren en bewaren van site_id + api_key
 *  - registreren bij Level 4 na plugin-activatie
 *  - 6-uurlijkse heartbeat (versies + plugin/theme/core inventory) via WP-cron
 *  - WP REST endpoints voor inkomende commando's (sync, run-update, visual-check)
 *  - admin-tab "Level 4 koppeling" onder RankRepair
 *
 * Level 4 URL via `define('RR_LEVEL4_URL', '...');` in wp-config.php.
 * Default: productie URL (kan in instellingen overschreven).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Agent {

    const OPT_SITE_ID          = 'rr_agent_site_id';
    const OPT_API_KEY          = 'rr_agent_api_key';
    const OPT_API_KEY_PREVIOUS = 'rr_agent_api_key_previous';
    const OPT_API_KEY_ROTATED  = 'rr_agent_api_key_rotated_at';
    const OPT_REGISTERED       = 'rr_agent_registered';
    const OPT_LAST_HEARTBEAT   = 'rr_agent_last_heartbeat_at';
    const OPT_LAST_RESPONSE    = 'rr_agent_last_response';
    const OPT_LINKED_CLIENT    = 'rr_agent_linked_client';
    const CRON_HOOK            = 'rr_agent_heartbeat_event';
    const CRON_POLL_HOOK       = 'rr_agent_poll_event';

    public function init(): void {
        // Cron
        add_action( self::CRON_HOOK, [ $this, 'do_heartbeat' ] );
        add_action( self::CRON_POLL_HOOK, [ $this, 'do_poll_jobs' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

        // Admin
        add_action( 'admin_menu', [ $this, 'register_admin_page' ], 20 );
        add_action( 'admin_post_rr_agent_resend_heartbeat', [ $this, 'handle_resend_heartbeat' ] );
        add_action( 'admin_post_rr_agent_register_now', [ $this, 'handle_register_now' ] );
        add_action( 'admin_post_rr_malware_scan_now', [ $this, 'handle_malware_scan_now' ] );

        // REST endpoints (binnenkomend vanuit Level 4)
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Self-bootstrap voor sites die via plugin-update binnenkomen
        // (waar register_activation_hook niet wordt getriggerd).
        add_action( 'init', [ $this, 'maybe_bootstrap' ], 20 );
        add_action( 'rr_agent_bootstrap_event', [ $this, 'do_bootstrap' ] );
    }

    /**
     * Wordt bij elke WP-load aangeroepen. Idempotent en goedkoop: alleen
     * triggeren als er nog geen credentials of cron staan.
     */
    public function maybe_bootstrap(): void {
        $needs_credentials = ! get_option( self::OPT_SITE_ID ) || ! get_option( self::OPT_API_KEY );
        $needs_cron        = ! wp_next_scheduled( self::CRON_HOOK );
        $needs_poll_cron   = ! wp_next_scheduled( self::CRON_POLL_HOOK );

        if ( ! $needs_credentials && ! $needs_cron && ! $needs_poll_cron ) {
            return;
        }

        if ( $needs_credentials ) {
            if ( ! get_option( self::OPT_SITE_ID ) ) {
                update_option( self::OPT_SITE_ID, wp_generate_uuid4(), false );
            }
            if ( ! get_option( self::OPT_API_KEY ) ) {
                update_option( self::OPT_API_KEY, self::generate_api_key(), false );
            }
        }

        if ( $needs_cron ) {
            wp_schedule_event( time() + 60, 'rr_six_hours', self::CRON_HOOK );
        }
        if ( $needs_poll_cron ) {
            wp_schedule_event( time() + 30, 'rr_one_minute', self::CRON_POLL_HOOK );
        }

        // Schedule one-off register-call over 30 sec (non-blocking)
        if ( ! wp_next_scheduled( 'rr_agent_bootstrap_event' ) && ! get_option( self::OPT_REGISTERED ) ) {
            wp_schedule_single_event( time() + 30, 'rr_agent_bootstrap_event' );
        }
    }

    public function do_bootstrap(): void {
        if ( get_option( self::OPT_REGISTERED ) ) {
            return;
        }
        $this->register_with_level4();
    }

    public static function get_level4_url(): string {
        if ( defined( 'RR_LEVEL4_URL' ) && RR_LEVEL4_URL ) {
            return rtrim( RR_LEVEL4_URL, '/' );
        }
        $opt = get_option( 'rr_level4_url', '' );
        if ( $opt ) {
            return rtrim( $opt, '/' );
        }
        return 'https://level4.rankingmasters.nl';
    }

    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['rr_six_hours'] ) ) {
            $schedules['rr_six_hours'] = [
                'interval' => 6 * HOUR_IN_SECONDS,
                'display'  => __( 'Every 6 hours (RankRepair)', 'rankrepair' ),
            ];
        }
        if ( ! isset( $schedules['rr_one_minute'] ) ) {
            $schedules['rr_one_minute'] = [
                'interval' => 60,
                'display'  => __( 'Every minute (RankRepair poll)', 'rankrepair' ),
            ];
        }
        return $schedules;
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    public static function on_activate(): void {
        // Genereer credentials als nog niet aanwezig
        if ( ! get_option( self::OPT_SITE_ID ) ) {
            update_option( self::OPT_SITE_ID, wp_generate_uuid4(), false );
        }
        if ( ! get_option( self::OPT_API_KEY ) ) {
            update_option( self::OPT_API_KEY, self::generate_api_key(), false );
        }

        // Plan cron als die nog niet bestaat
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'rr_six_hours', self::CRON_HOOK );
        }
        if ( ! wp_next_scheduled( self::CRON_POLL_HOOK ) ) {
            wp_schedule_event( time() + 30, 'rr_one_minute', self::CRON_POLL_HOOK );
        }

        // Probeer direct te registreren bij Level 4 (best effort, niet blokkerend)
        ( new self() )->register_with_level4();
    }

    public static function on_deactivate(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
        $ts_poll = wp_next_scheduled( self::CRON_POLL_HOOK );
        if ( $ts_poll ) {
            wp_unschedule_event( $ts_poll, self::CRON_POLL_HOOK );
        }
    }

    private static function generate_api_key(): string {
        $bytes = function_exists( 'random_bytes' ) ? random_bytes( 32 ) : wp_generate_password( 64, true, true );
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    // ── Register flow ───────────────────────────────────────────────────────

    public function register_with_level4(): array {
        $site_id = (string) get_option( self::OPT_SITE_ID );
        $api_key = (string) get_option( self::OPT_API_KEY );
        if ( ! $site_id || ! $api_key ) {
            return [ 'ok' => false, 'error' => 'no_credentials' ];
        }

        $url = self::get_level4_url() . '/api/wp-agent/register';
        $body = [
            'site_id'    => $site_id,
            'api_key'    => $api_key,
            'site_url'   => home_url(),
            'site_name'  => get_bloginfo( 'name' ),
            'rr_version' => defined( 'RR_VERSION' ) ? RR_VERSION : null,
            'wp_version' => get_bloginfo( 'version' ),
            'php_version'=> PHP_VERSION,
        ];

        $response = wp_remote_post( $url, [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $resp = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code >= 200 && $code < 300 ) {
            update_option( self::OPT_REGISTERED, true, false );
            update_option( self::OPT_LAST_RESPONSE, [ 'kind' => 'register', 'code' => $code, 'body' => $resp, 'at' => time() ], false );
            // Eerste heartbeat direct na register
            $this->do_heartbeat();
            return [ 'ok' => true, 'response' => $resp ];
        }

        update_option( self::OPT_LAST_RESPONSE, [ 'kind' => 'register', 'code' => $code, 'body' => $resp, 'at' => time() ], false );
        return [ 'ok' => false, 'code' => $code, 'response' => $resp ];
    }

    // ── Heartbeat ───────────────────────────────────────────────────────────

    public function do_heartbeat(): array {
        $site_id = (string) get_option( self::OPT_SITE_ID );
        $api_key = (string) get_option( self::OPT_API_KEY );
        if ( ! $site_id || ! $api_key ) {
            return [ 'ok' => false, 'error' => 'no_credentials' ];
        }

        $payload = $this->build_heartbeat_payload();
        $body    = wp_json_encode( $payload );
        $sig     = hash_hmac( 'sha256', $body, $api_key );

        $response = wp_remote_post( self::get_level4_url() . '/api/wp-agent/heartbeat', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'    => 'application/json',
                'X-RR-Site-Id'    => $site_id,
                'X-RR-Signature'  => $sig,
            ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            update_option( self::OPT_LAST_RESPONSE, [ 'kind' => 'heartbeat', 'error' => $response->get_error_message(), 'at' => time() ], false );
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $resp = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 404 && isset( $resp['error'] ) && $resp['error'] === 'unknown_site' ) {
            // Self-reregister
            $new_id  = wp_generate_uuid4();
            $new_key = self::generate_api_key();
            update_option( self::OPT_SITE_ID, $new_id, false );
            update_option( self::OPT_API_KEY, $new_key, false );
            update_option( self::OPT_API_KEY_PREVIOUS, null, false );
            update_option( self::OPT_API_KEY_ROTATED, null, false );
            update_option( self::OPT_REGISTERED, false, false );
            $result = $this->register_with_level4();
            update_option( self::OPT_LAST_RESPONSE, [ 'kind' => 'self_reregister', 'result' => $result, 'at' => time() ], false );
            return [ 'ok' => $result['ok'] ?? false, 'note' => 'self_reregister' ];
        }

        update_option( self::OPT_LAST_RESPONSE, [ 'kind' => 'heartbeat', 'code' => $code, 'body' => $resp, 'at' => time() ], false );
        if ( $code >= 200 && $code < 300 ) {
            update_option( self::OPT_LAST_HEARTBEAT, time(), false );
            // Level 4 geeft in de respons terug aan welke klant de site gekoppeld is
            // (of null als nog niet gekoppeld). Zo blijft de admin-status accuraat.
            if ( is_array( $resp ) && array_key_exists( 'linked_client', $resp ) ) {
                $linked = $resp['linked_client'];
                update_option( self::OPT_LINKED_CLIENT, is_string( $linked ) ? $linked : '', false );
            }
            return [ 'ok' => true, 'response' => $resp ];
        }
        return [ 'ok' => false, 'code' => $code, 'response' => $resp ];
    }

    public function build_heartbeat_payload(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        // Force update transient refresh als die oud is
        wp_clean_plugins_cache( true );
        wp_clean_themes_cache( true );
        wp_update_plugins();
        wp_update_themes();
        wp_version_check();

        $plugins         = get_plugins();
        $active_plugins  = array_flip( (array) get_option( 'active_plugins', [] ) );
        $plugin_updates  = get_site_transient( 'update_plugins' );
        $plugin_resp     = isset( $plugin_updates->response ) ? $plugin_updates->response : [];

        $plugin_list = [];
        foreach ( $plugins as $slug => $info ) {
            $new_version = isset( $plugin_resp[ $slug ] ) ? ( $plugin_resp[ $slug ]->new_version ?? null ) : null;
            $plugin_list[] = [
                'slug'             => $slug,
                'name'             => isset( $info['Name'] ) ? wp_strip_all_tags( $info['Name'] ) : $slug,
                'version'          => $info['Version'] ?? '',
                'new_version'      => $new_version,
                'update_available' => (bool) $new_version,
                'active'           => isset( $active_plugins[ $slug ] ),
            ];
        }

        $themes          = wp_get_themes();
        $active_theme    = wp_get_theme();
        $theme_updates   = get_site_transient( 'update_themes' );
        $theme_resp      = isset( $theme_updates->response ) ? $theme_updates->response : [];

        $theme_list = [];
        foreach ( $themes as $stylesheet => $theme ) {
            $new_version = isset( $theme_resp[ $stylesheet ] ) ? ( $theme_resp[ $stylesheet ]['new_version'] ?? null ) : null;
            $theme_list[] = [
                'slug'             => $stylesheet,
                'name'             => $theme->get( 'Name' ),
                'version'          => $theme->get( 'Version' ),
                'new_version'      => $new_version,
                'update_available' => (bool) $new_version,
                'active'           => $stylesheet === $active_theme->get_stylesheet(),
            ];
        }

        $core_updates = get_site_transient( 'update_core' );
        $core_new     = null;
        if ( isset( $core_updates->updates ) && is_array( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $u ) {
                if ( $u->response === 'upgrade' ) {
                    $core_new = $u->current;
                    break;
                }
            }
        }
        $core = [
            'version'          => get_bloginfo( 'version' ),
            'new_version'      => $core_new,
            'update_available' => (bool) $core_new,
        ];

        // Security-blok. Defensief: mag de heartbeat nooit breken.
        // Voorkeur: de EIGEN RankRepair-scanner. Wordfence alleen als die echt actief is
        // én een voltooide scan heeft (niet louter omdat het plugin-bestand aanwezig is —
        // dat gaf een misleidende scanner:"wordfence" / last_scan_status:"failed").
        $security = [ 'scanner' => 'rankrepair', 'last_scan_at' => null, 'last_scan_status' => 'never', 'verdict' => 'unknown', 'counts' => [ 'critical' => 0, 'warning' => 0 ], 'issues' => [] ];
        try {
            $wf  = class_exists( 'RR_Wordfence' ) ? RR_Wordfence::get_security_block() : null;
            $own = class_exists( 'RR_Malware_Scan' ) ? RR_Malware_Scan::get_security_block() : null;
            if ( class_exists( 'RR_Malware_Scan' ) ) {
                $security = RR_Malware_Scan::choose_security_source( $wf, $own );
            } elseif ( is_array( $wf ) ) {
                $security = $wf;
            }
        } catch ( \Throwable $e ) {
            $security = [ 'scanner' => 'rankrepair', 'last_scan_at' => null, 'last_scan_status' => 'never', 'verdict' => 'unknown', 'counts' => [ 'critical' => 0, 'warning' => 0 ], 'issues' => [] ];
        }

        return [
            'site_url'    => home_url(),
            'site_name'   => get_bloginfo( 'name' ),
            'rr_version'  => defined( 'RR_VERSION' ) ? RR_VERSION : '',
            'wp_version'  => get_bloginfo( 'version' ),
            'php_version' => PHP_VERSION,
            'core'        => $core,
            'plugins'     => $plugin_list,
            'themes'      => $theme_list,
            'security'    => $security,
        ];
    }

    // ── REST endpoints (sync, run-update, visual-check) ─────────────────────

    public function register_rest_routes(): void {
        register_rest_route( 'rankrepair/v1', '/sync', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_sync' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( 'rankrepair/v1', '/run-update', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_run_update' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( 'rankrepair/v1', '/visual-check', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_visual_check' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( 'rankrepair/v1', '/rotate-key', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_rotate_key' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );
    }

    public function rest_rotate_key( WP_REST_Request $req ): WP_REST_Response {
        $params = $req->get_json_params();
        $new    = isset( $params['new_api_key'] ) ? (string) $params['new_api_key'] : '';
        if ( strlen( $new ) < 32 ) {
            return new WP_REST_Response( [ 'ok' => false, 'error' => 'invalid_new_key' ], 400 );
        }
        $current = (string) get_option( self::OPT_API_KEY );
        update_option( self::OPT_API_KEY_PREVIOUS, $current, false );
        update_option( self::OPT_API_KEY, $new, false );
        update_option( self::OPT_API_KEY_ROTATED, time(), false );
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function rest_permission( WP_REST_Request $req ): bool {
        $auth = (string) $req->get_header( 'authorization' );
        if ( strpos( $auth, 'Bearer ' ) !== 0 ) {
            return false;
        }
        $token = substr( $auth, 7 );
        $current  = (string) get_option( self::OPT_API_KEY );
        $previous = (string) get_option( self::OPT_API_KEY_PREVIOUS );
        $rotated  = (int) get_option( self::OPT_API_KEY_ROTATED, 0 );

        if ( $current && hash_equals( $current, $token ) ) {
            return true;
        }
        if ( $previous && $rotated && ( time() - $rotated ) < DAY_IN_SECONDS && hash_equals( $previous, $token ) ) {
            return true;
        }
        return false;
    }

    public function rest_sync(): WP_REST_Response {
        $this->do_heartbeat();
        return new WP_REST_Response( $this->build_heartbeat_payload(), 200 );
    }

    public function rest_run_update( WP_REST_Request $req ): WP_REST_Response {
        $params = $req->get_json_params();
        $kind   = isset( $params['kind'] ) ? (string) $params['kind'] : '';
        $slugs  = isset( $params['slugs'] ) && is_array( $params['slugs'] ) ? array_values( array_filter( array_map( 'strval', $params['slugs'] ) ) ) : [];
        $job_id = isset( $params['job_id'] ) ? (string) $params['job_id'] : '';

        if ( ! in_array( $kind, [ 'plugin', 'theme', 'core' ], true ) ) {
            return new WP_REST_Response( [ 'error' => 'invalid_kind' ], 400 );
        }
        if ( $kind !== 'core' && empty( $slugs ) ) {
            return new WP_REST_Response( [ 'error' => 'no_slugs' ], 400 );
        }

        $started = microtime( true );
        $results = $this->perform_kind_updates( $kind, $slugs );
        return new WP_REST_Response( [
            'job_id'            => $job_id,
            'results'           => $results,
            'total_duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
        ], 200 );
    }

    /**
     * Voert plugin/theme/core updates uit en retourneert per slug een result-array.
     * Gedeelde logica tussen REST (push) en cron-poll (pull) flow.
     */
    private function perform_kind_updates( string $kind, array $slugs ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        $results = [];

        if ( $kind === 'plugin' ) {
            $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
            foreach ( $slugs as $slug ) {
                $before        = $this->plugin_version( $slug );
                $was_active    = is_plugin_active( $slug );
                $was_net_active = is_plugin_active_for_network( $slug );
                $t0  = microtime( true );
                $err = null;
                try {
                    $res = $upgrader->upgrade( $slug );
                } catch ( \Throwable $t ) {
                    $res = new WP_Error( 'upgrade_exception', $t->getMessage() );
                    $err = $t->getMessage();
                }
                $duration = (int) round( ( microtime( true ) - $t0 ) * 1000 );
                // Re-fetch actual version on disk — bron van waarheid, ongeacht
                // wat de upgrader teruggaf. WSOD's tijdens hooks geven valse fouten.
                wp_clean_plugins_cache( true );
                $after = $this->plugin_version( $slug );
                $changed = $before !== null && $after !== null && $before !== $after;

                // Reactivate als WP de plugin onderweg gedeactiveerd heeft
                $reactivated = false;
                $reactivate_error = null;
                if ( ( $was_active || $was_net_active ) && ! is_plugin_active( $slug ) ) {
                    $act = activate_plugin( $slug, '', $was_net_active, /* silent */ true );
                    if ( is_wp_error( $act ) ) {
                        $reactivate_error = $act->get_error_message();
                    } else {
                        $reactivated = is_plugin_active( $slug );
                    }
                }

                $results[] = [
                    'slug'             => $slug,
                    'ok'               => ( $res === true || $changed || ( $res === false && $before === $after ) )
                                          && ( ! ( $was_active || $was_net_active ) || is_plugin_active( $slug ) ),
                    'from'             => $before,
                    'to'               => $after,
                    'note'             => ( $res === false && $before === $after ) ? 'already_up_to_date' : null,
                    'was_active'       => $was_active,
                    'still_active'     => is_plugin_active( $slug ),
                    'reactivated'      => $reactivated,
                    'reactivate_error' => $reactivate_error,
                    'error'            => is_wp_error( $res ) && ! $changed ? $res->get_error_message() : $err,
                    'duration_ms'      => $duration,
                ];
            }
        } elseif ( $kind === 'theme' ) {
            $upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
            foreach ( $slugs as $slug ) {
                $before = $this->theme_version( $slug );
                $t0     = microtime( true );
                $err    = null;
                try {
                    $res = $upgrader->upgrade( $slug );
                } catch ( \Throwable $t ) {
                    $res = new WP_Error( 'upgrade_exception', $t->getMessage() );
                    $err = $t->getMessage();
                }
                $duration = (int) round( ( microtime( true ) - $t0 ) * 1000 );
                wp_clean_themes_cache( true );
                $after = $this->theme_version( $slug );
                $changed = $before !== null && $after !== null && $before !== $after;
                $results[] = [
                    'slug'        => $slug,
                    'ok'          => $res === true || $changed || ( $res === false && $before === $after ),
                    'from'        => $before,
                    'to'          => $after,
                    'note'        => ( $res === false && $before === $after ) ? 'already_up_to_date' : null,
                    'error'       => is_wp_error( $res ) && ! $changed ? $res->get_error_message() : $err,
                    'duration_ms' => $duration,
                ];
            }
        } else {
            // core
            require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/update.php';
            wp_version_check();
            $core_updates = get_core_updates();
            if ( empty( $core_updates ) || ! is_array( $core_updates ) ) {
                $results[] = [ 'slug' => 'core', 'ok' => false, 'error' => 'no_update_available' ];
            } else {
                $update   = $core_updates[0];
                $before   = get_bloginfo( 'version' );
                $t0       = microtime( true );
                $upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
                $res      = $upgrader->upgrade( $update );
                $duration = (int) round( ( microtime( true ) - $t0 ) * 1000 );
                $after    = get_bloginfo( 'version' );
                $results[] = [
                    'slug'        => 'core',
                    'ok'          => ! is_wp_error( $res ) && $res !== false,
                    'from'        => $before,
                    'to'          => $after,
                    'error'       => is_wp_error( $res ) ? $res->get_error_message() : ( $res === false ? 'failed' : null ),
                    'duration_ms' => $duration,
                ];
            }
        }

        return $results;
    }

    // ── Pull-flow: poll Level 4 voor pending update-jobs ────────────────────

    public function do_poll_jobs(): void {
        $site_id = (string) get_option( self::OPT_SITE_ID );
        $api_key = (string) get_option( self::OPT_API_KEY );
        if ( ! $site_id || ! $api_key ) {
            return;
        }

        $payload = [ 'site_url' => home_url(), 'rr_version' => defined( 'RR_VERSION' ) ? RR_VERSION : '' ];
        $body    = wp_json_encode( $payload );
        $sig     = hash_hmac( 'sha256', $body, $api_key );

        $response = wp_remote_post( self::get_level4_url() . '/api/wp-agent/poll-jobs', [
            'timeout' => 15,
            'headers' => [
                'Content-Type'   => 'application/json',
                'X-RR-Site-Id'   => $site_id,
                'X-RR-Signature' => $sig,
            ],
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return;
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return;
        }
        $resp = json_decode( wp_remote_retrieve_body( $response ), true );
        $jobs = ( is_array( $resp ) && isset( $resp['jobs'] ) && is_array( $resp['jobs'] ) ) ? $resp['jobs'] : [];
        if ( empty( $jobs ) ) {
            return;
        }

        foreach ( $jobs as $job ) {
            $job_id = isset( $job['id'] ) ? (string) $job['id'] : '';
            $kind   = isset( $job['kind'] ) ? (string) $job['kind'] : '';
            $slug   = isset( $job['slug'] ) ? (string) $job['slug'] : '';
            if ( ! $job_id || ! in_array( $kind, [ 'plugin', 'theme', 'core' ], true ) ) {
                continue;
            }

            $started = microtime( true );
            $results = $this->perform_kind_updates( $kind, $kind === 'core' ? [] : [ $slug ] );
            $r = isset( $results[0] ) ? $results[0] : null;
            $total_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

            $this->report_job_result( $job_id, $r, $total_ms );
        }
    }

    private function report_job_result( string $job_id, ?array $r, int $total_ms ): void {
        $site_id = (string) get_option( self::OPT_SITE_ID );
        $api_key = (string) get_option( self::OPT_API_KEY );

        $payload = [
            'job_id'      => $job_id,
            'ok'          => $r && ! empty( $r['ok'] ),
            'from'        => $r['from'] ?? null,
            'to'          => $r['to'] ?? null,
            'note'        => $r['note'] ?? null,
            'error'       => $r['error'] ?? null,
            'duration_ms' => $r['duration_ms'] ?? $total_ms,
        ];
        $body = wp_json_encode( $payload );
        $sig  = hash_hmac( 'sha256', $body, $api_key );

        wp_remote_post( self::get_level4_url() . '/api/wp-agent/job-result', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'   => 'application/json',
                'X-RR-Site-Id'   => $site_id,
                'X-RR-Signature' => $sig,
            ],
            'body'    => $body,
        ] );
    }

    public function rest_visual_check(): WP_REST_Response {
        $url      = home_url( '/' );
        $t0       = microtime( true );
        $response = wp_remote_get( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
        $duration = (int) round( ( microtime( true ) - $t0 ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return new WP_REST_Response( [
                'ok' => false,
                'error' => $response->get_error_message(),
                'duration_ms' => $duration,
            ], 200 );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $has_body_tag = stripos( $body, '<body' ) !== false;

        return new WP_REST_Response( [
            'ok' => $code === 200 && $has_body_tag,
            'http_status' => $code,
            'has_body_tag' => $has_body_tag,
            'duration_ms' => $duration,
        ], 200 );
    }

    private function plugin_version( string $slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        return isset( $all[ $slug ]['Version'] ) ? (string) $all[ $slug ]['Version'] : null;
    }

    private function theme_version( string $slug ): ?string {
        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            return null;
        }
        return (string) $theme->get( 'Version' );
    }

    // ── Admin page ──────────────────────────────────────────────────────────

    public function register_admin_page(): void {
        add_submenu_page(
            'rankrepair',
            __( 'Level 4 koppeling', 'rankrepair' ),
            __( 'Level 4 koppeling', 'rankrepair' ),
            'manage_options',
            'rankrepair-level4',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $site_id      = (string) get_option( self::OPT_SITE_ID );
        $api_key      = (string) get_option( self::OPT_API_KEY );
        $registered   = (bool) get_option( self::OPT_REGISTERED );
        $linked_client = (string) get_option( self::OPT_LINKED_CLIENT );
        $last_hb      = (int) get_option( self::OPT_LAST_HEARTBEAT, 0 );
        $last_resp    = (array) get_option( self::OPT_LAST_RESPONSE, [] );
        $masked_key   = $api_key ? substr( $api_key, 0, 6 ) . '…' . substr( $api_key, -4 ) : '—';

        echo '<div class="wrap"><h1>' . esc_html__( 'Level 4 koppeling', 'rankrepair' ) . '</h1>';

        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Status', 'rankrepair' ) . '</th><td>';
        if ( ! $registered ) {
            echo '<span style="color:#b32d2e">●</span> ' . esc_html__( 'Niet geregistreerd', 'rankrepair' );
        } elseif ( $linked_client ) {
            echo '<span style="color:#1a7f37">●</span> ' . esc_html( sprintf( __( 'Gekoppeld aan: %s', 'rankrepair' ), $linked_client ) );
        } else {
            echo '<span style="color:#bf8700">●</span> ' . esc_html__( 'Wachtend op koppeling in Level 4 (zie inbox)', 'rankrepair' );
        }
        echo '</td></tr>';

        echo '<tr><th>' . esc_html__( 'Site ID', 'rankrepair' ) . '</th><td><code>' . esc_html( $site_id ?: '—' ) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__( 'API key', 'rankrepair' ) . '</th><td><code>' . esc_html( $masked_key ) . '</code></td></tr>';
        echo '<tr><th>' . esc_html__( 'Laatste heartbeat', 'rankrepair' ) . '</th><td>' . esc_html( $last_hb ? human_time_diff( $last_hb ) . ' ' . __( 'geleden', 'rankrepair' ) : __( 'nooit', 'rankrepair' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Level 4 URL', 'rankrepair' ) . '</th><td><code>' . esc_html( self::get_level4_url() ) . '</code>';
        if ( ! defined( 'RR_LEVEL4_URL' ) ) {
            echo ' <em>(' . esc_html__( 'aanpasbaar via wp-config: define(\'RR_LEVEL4_URL\', \'…\')', 'rankrepair' ) . ')</em>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
        wp_nonce_field( 'rr_agent_resend_heartbeat' );
        echo '<input type="hidden" name="action" value="rr_agent_resend_heartbeat">';
        echo '<button class="button button-primary">' . esc_html__( 'Stuur nu een heartbeat', 'rankrepair' ) . '</button>';
        echo '</form>';

        if ( ! $registered ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
            wp_nonce_field( 'rr_agent_register_now' );
            echo '<input type="hidden" name="action" value="rr_agent_register_now">';
            echo '<button class="button">' . esc_html__( 'Opnieuw registreren', 'rankrepair' ) . '</button>';
            echo '</form>';
        }
        echo '</p>';

        // ── Beveiliging: interne malware-scan ────────────────────────────────
        if ( class_exists( 'RR_Malware_Scan' ) ) {
            echo '<h2>' . esc_html__( 'Beveiliging — interne scan', 'rankrepair' ) . '</h2>';

            echo '<p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
            wp_nonce_field( 'rr_malware_scan_now' );
            echo '<input type="hidden" name="action" value="rr_malware_scan_now">';
            echo '<button class="button button-primary">' . esc_html__( 'Scan nu', 'rankrepair' ) . '</button>';
            echo '</form> <span style="color:#666">' . esc_html__( '(kan tot ~1 min duren; draait anders elk uur gechunkt via cron)', 'rankrepair' ) . '</span>';
            echo '</p>';

            try {
                $block = RR_Malware_Scan::get_security_block();
            } catch ( \Throwable $e ) {
                $block = [ 'verdict' => 'unknown', 'last_scan_status' => 'never', 'counts' => [ 'critical' => 0, 'warning' => 0 ], 'issues' => [] ];
            }
            $verdict = isset( $block['verdict'] ) ? (string) $block['verdict'] : 'unknown';
            $colors  = [ 'clean' => '#1a7f37', 'issues' => '#bf8700', 'critical' => '#b32d2e', 'unknown' => '#666' ];
            $dot     = isset( $colors[ $verdict ] ) ? $colors[ $verdict ] : '#666';

            echo '<table class="form-table"><tbody>';
            echo '<tr><th>' . esc_html__( 'Verdict', 'rankrepair' ) . '</th><td><span style="color:' . esc_attr( $dot ) . '">●</span> <strong>' . esc_html( strtoupper( $verdict ) ) . '</strong></td></tr>';
            echo '<tr><th>' . esc_html__( 'Scanstatus', 'rankrepair' ) . '</th><td>' . esc_html( (string) ( $block['last_scan_status'] ?? 'never' ) ) . '</td></tr>';
            $c = isset( $block['counts'] ) ? $block['counts'] : [ 'critical' => 0, 'warning' => 0 ];
            echo '<tr><th>' . esc_html__( 'Bevindingen', 'rankrepair' ) . '</th><td>' . esc_html( sprintf( __( '%d kritiek · %d waarschuwing', 'rankrepair' ), (int) ( $c['critical'] ?? 0 ), (int) ( $c['warning'] ?? 0 ) ) ) . '</td></tr>';
            echo '</tbody></table>';

            if ( ! empty( $block['issues'] ) && is_array( $block['issues'] ) ) {
                echo '<table class="widefat striped" style="max-width:900px;margin-top:8px"><thead><tr>';
                echo '<th>' . esc_html__( 'Ernst', 'rankrepair' ) . '</th><th>' . esc_html__( 'Type', 'rankrepair' ) . '</th><th>' . esc_html__( 'Pad', 'rankrepair' ) . '</th><th>' . esc_html__( 'Detail', 'rankrepair' ) . '</th>';
                echo '</tr></thead><tbody>';
                foreach ( array_slice( $block['issues'], 0, 50 ) as $iss ) {
                    $sev = ( isset( $iss['severity'] ) && $iss['severity'] === 'critical' ) ? 'critical' : 'warning';
                    $sevColor = $sev === 'critical' ? '#b32d2e' : '#bf8700';
                    echo '<tr>';
                    echo '<td><span style="color:' . esc_attr( $sevColor ) . ';font-weight:600">' . esc_html( $sev ) . '</span></td>';
                    echo '<td><code>' . esc_html( (string) ( $iss['type'] ?? '' ) ) . '</code></td>';
                    echo '<td><code>' . esc_html( (string) ( $iss['path'] ?? '' ) ) . '</code></td>';
                    echo '<td>' . esc_html( (string) ( $iss['detail'] ?? '' ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }

        if ( ! empty( $last_resp ) ) {
            echo '<h2>' . esc_html__( 'Laatste respons', 'rankrepair' ) . '</h2>';
            echo '<pre style="background:#f3f4f6;padding:12px;border-radius:6px;max-width:800px;overflow:auto">';
            echo esc_html( wp_json_encode( $last_resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            echo '</pre>';
        }

        echo '</div>';
    }

    public function handle_resend_heartbeat(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'rr_agent_resend_heartbeat' );
        $this->do_heartbeat();
        wp_safe_redirect( admin_url( 'admin.php?page=rankrepair-level4&rr=hb' ) );
        exit;
    }

    public function handle_register_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'rr_agent_register_now' );
        $this->register_with_level4();
        wp_safe_redirect( admin_url( 'admin.php?page=rankrepair-level4&rr=reg' ) );
        exit;
    }

    public function handle_malware_scan_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'rr_malware_scan_now' );
        if ( class_exists( 'RR_Malware_Scan' ) ) {
            RR_Malware_Scan::run_full( 55 );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=rankrepair-level4&rr=scan' ) );
        exit;
    }
}
