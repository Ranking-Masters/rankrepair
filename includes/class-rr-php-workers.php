<?php
/**
 * RankRepair — PHP-workers meten en naar Level 4 sturen.
 *
 * Werking (elke host, geen serverrechten nodig):
 *  1. Aan het eind van elk PHP-verzoek (shutdown) schrijven we één regel weg:
 *     "start eind pad". Start = $_SERVER['REQUEST_TIME_FLOAT'], het moment dat een
 *     worker het verzoek oppakte. Kost één append met LOCK_EX, geen databasequery.
 *  2. Elke 5 minuten (WP-cron) rekenen we uit hoeveel verzoeken er per minuut
 *     maximaal tegelijk liepen (= bezette workers), met een vertraging van 5 min
 *     zodat lange verzoeken eerst klaar zijn. Alleen minuten met verkeer.
 *  3. Die minuten gaan HMAC-ondertekend naar Level 4 (/api/wp-agent/workers).
 *     Mislukt dat, dan bewaren we ze (max 1 dag) en sturen ze de volgende keer mee.
 *
 * Maximum aantal workers: waar leesbaar uit de PHP-FPM-poolconfig
 * (pm.max_children, bv. Kinsta). Anders null; dan vult men het in Level 4 in.
 *
 * Ziet niet: verzoeken die nooit WordPress laden (losse PHP-scripts), wachttijd in
 * de FPM-rij, en verzoeken die hard worden afgebroken (geen shutdown).
 *
 * Uitzetten: define('RR_PHP_WORKERS_DISABLE', true) in wp-config.php, of de
 * schakelaar op de pagina "Level 4 koppeling".
 *
 * Contract met Level 4: docs/php-workers-monitor.md in de Level4-repo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Php_Workers {

    const OPT_ENABLED     = 'rr_php_workers_enabled';      // '1' (standaard) of '0'
    const OPT_DIR_TOKEN   = 'rr_php_workers_dir_token';    // willekeurig deel van de mapnaam
    const OPT_DIR         = 'rr_php_workers_dir';          // volledig pad (autoload, scheelt werk per verzoek)
    const OPT_CURSOR      = 'rr_php_workers_cursor';       // eerste minuut (unix/60) die nog niet verwerkt is
    const OPT_PENDING     = 'rr_php_workers_pending';      // nog niet afgeleverde buckets
    const OPT_DETECTED    = 'rr_php_workers_detected';     // ['max' => int|null, 'at' => int, 'how' => string]
    const OPT_LAST_REPORT = 'rr_php_workers_last_report';  // laatste verzendpoging
    const CRON_HOOK       = 'rr_php_workers_report_event';
    const CRON_SCHEDULE   = 'rr_five_minutes';

    const FILE_SPAN       = 300;   // één logbestand per 5 minuten (op eindtijd)
    const LAG             = 300;   // minuten pas verwerken als ze 5 min voorbij zijn
    const MAX_BUCKETS     = 1440;  // max 1 dag achterstand bewaren/versturen
    const MAX_PATH_LEN    = 200;
    const MAX_LINES       = 200000; // veiligheidsgrens per verwerking
    const FIRST_RUN_MIN   = 30;    // eerste keer: hooguit 30 min terugkijken

    /** @var string|null|false  map voor de logbestanden (false = niet bruikbaar) */
    private static $dir = null;

    // ── Opstarten ────────────────────────────────────────────────────────────

    public static function enabled(): bool {
        if ( defined( 'RR_PHP_WORKERS_DISABLE' ) && RR_PHP_WORKERS_DISABLE ) {
            return false;
        }
        return get_option( self::OPT_ENABLED, '1' ) !== '0';
    }

    /** Wordt aangeroepen vanuit RankRepair::load_dependencies(). */
    public static function boot(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'report' ] );
        add_action( 'admin_post_rr_php_workers_toggle', [ __CLASS__, 'handle_toggle' ] );
        add_action( 'admin_post_rr_php_workers_report_now', [ __CLASS__, 'handle_report_now' ] );

        if ( ! self::enabled() ) {
            return;
        }
        add_action( 'init', [ __CLASS__, 'schedule' ] );

        // WP-CLI gebruikt geen webworker; niet meetellen.
        if ( PHP_SAPI === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        register_shutdown_function( [ __CLASS__, 'record' ] );
    }

    public static function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Elke 5 minuten (RankRepair PHP-workers)', 'rankrepair' ),
            ];
        }
        return $schedules;
    }

    public static function schedule(): void {
        // Opties die elk verzoek gelezen worden moeten autoload zijn, anders kost
        // get_option() per verzoek een databasequery.
        if ( get_option( self::OPT_ENABLED, null ) === null ) {
            add_option( self::OPT_ENABLED, '1', '', 'yes' );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 120, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::CRON_HOOK );
        while ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
            $ts = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    // ── 1. Vastleggen (elk verzoek) ──────────────────────────────────────────

    public static function record(): void {
        try {
            $start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
            $end   = microtime( true );
            if ( $start <= 0 || $end < $start || $end - $start > DAY_IN_SECONDS ) {
                return;
            }
            $dir = self::dir();
            if ( ! $dir ) {
                return;
            }
            $uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
            $line = sprintf( "%.3f %.3f %s\n", $start, $end, self::clean_path( $uri ) );
            @file_put_contents( $dir . '/w-' . (int) floor( $end / self::FILE_SPAN ) . '.log', $line, FILE_APPEND | LOCK_EX );
        } catch ( \Throwable $e ) {
            // Meten mag nooit een verzoek breken.
        }
    }

    /** Map in uploads met een onvoorspelbare naam + afscherming voor Apache. */
    private static function dir() {
        if ( self::$dir !== null ) {
            return self::$dir;
        }
        self::$dir = false;
        // Snelle weg (bijna elk verzoek): pad uit een autoload-optie, één stat.
        $known = get_option( self::OPT_DIR, '' );
        if ( is_string( $known ) && $known !== '' && is_dir( $known ) ) {
            return self::$dir = $known;
        }
        $token = (string) get_option( self::OPT_DIR_TOKEN, '' );
        if ( ! preg_match( '/^[a-z0-9]{12,32}$/', $token ) ) {
            $token = strtolower( wp_generate_password( 16, false, false ) );
            update_option( self::OPT_DIR_TOKEN, $token, false );
        }
        $up = wp_upload_dir( null, false );
        if ( empty( $up['basedir'] ) || ! empty( $up['error'] ) ) {
            return false;
        }
        $dir = rtrim( $up['basedir'], '/\\' ) . '/rankrepair-workers-' . $token;
        if ( ! is_dir( $dir ) ) {
            if ( ! @wp_mkdir_p( $dir ) ) {
                return false;
            }
            @file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
            @file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
        }
        if ( ! is_writable( $dir ) ) {
            return false;
        }
        update_option( self::OPT_DIR, $dir, true );
        return self::$dir = $dir;
    }

    /** Pad zonder querystring/fragment, zonder stuurtekens, max 200 tekens. */
    public static function clean_path( string $uri ): string {
        $cut = strcspn( $uri, '?#' );
        $p   = substr( $uri, 0, $cut );
        $p   = preg_replace( '/[\x00-\x20\x7f]+/', '', $p );
        if ( $p === '' || $p[0] !== '/' ) {
            $p = '/' . ltrim( (string) $p, '/' );
        }
        if ( strlen( $p ) > self::MAX_PATH_LEN ) {
            $p = substr( $p, 0, self::MAX_PATH_LEN );
        }
        return $p;
    }

    // ── 2. Uitrekenen (puur, testbaar) ───────────────────────────────────────

    /**
     * Leest regels "start eind pad" en houdt alleen intervallen die [from, until)
     * raken (in seconden). Kapotte regels worden overgeslagen.
     *
     * @return array<int, array{0: float, 1: float, 2: string}>
     */
    public static function parse_lines( string $text, float $from, float $until ): array {
        $out = [];
        foreach ( explode( "\n", $text ) as $line ) {
            if ( $line === '' ) {
                continue;
            }
            $parts = explode( ' ', $line, 3 );
            if ( count( $parts ) < 3 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
                continue;
            }
            $s = (float) $parts[0];
            $e = (float) $parts[1];
            if ( $e < $s || $e <= $from || $s >= $until ) {
                continue;
            }
            $out[] = [ $s, $e, $parts[2] ];
        }
        return $out;
    }

    /**
     * Rekent per minuut uit hoeveel verzoeken er tegelijk liepen.
     *
     * @param array $intervals [[start, eind, pad], ...] in seconden (float)
     * @param int   $from_min  eerste minuut (unix/60), inclusief
     * @param int   $until_min laatste minuut (unix/60), exclusief
     * @return array lijst buckets (alleen minuten met verkeer), oplopend
     */
    public static function compute_buckets( array $intervals, int $from_min, int $until_min ): array {
        $from  = $from_min * 60;
        $until = $until_min * 60;
        if ( $until <= $from ) {
            return [];
        }

        $events = [];
        $mins   = [];
        $touch  = static function ( int $m ) use ( &$mins ) {
            if ( ! isset( $mins[ $m ] ) ) {
                $mins[ $m ] = [ 'peak' => 0, 'area' => 0.0, 'requests' => 0, 'paths' => [] ];
            }
        };

        foreach ( $intervals as $iv ) {
            $s = (float) $iv[0];
            $e = (float) $iv[1];
            if ( $e - $s < 0.001 ) {
                $e = $s + 0.001; // supersnel verzoek telt wel mee
            }
            $cs = max( $s, $from );
            $ce = min( $e, $until );
            if ( $ce <= $cs ) {
                continue;
            }
            $events[] = [ $cs, 1 ];
            $events[] = [ $ce, -1 ];

            // Aantal verzoeken: op de minuut waarin het verzoek eindigde.
            if ( $e >= $from && $e < $until ) {
                $m = (int) floor( $e / 60 );
                $touch( $m );
                $mins[ $m ]['requests']++;
            }
            // Paden: elke minuut waarin het verzoek liep.
            $path = (string) $iv[2];
            $last = (int) floor( ( $ce - 0.0001 ) / 60 );
            for ( $m = (int) floor( $cs / 60 ); $m <= $last; $m++ ) {
                $touch( $m );
                $mins[ $m ]['paths'][ $path ] = ( $mins[ $m ]['paths'][ $path ] ?? 0 ) + 1;
            }
        }

        // Eindes vóór begins op hetzelfde moment: aansluitende verzoeken tellen niet dubbel.
        usort( $events, static function ( $a, $b ) {
            if ( $a[0] == $b[0] ) {
                return $a[1] <=> $b[1];
            }
            return $a[0] < $b[0] ? -1 : 1;
        } );

        $count = 0;
        $prev  = null;
        foreach ( $events as $ev ) {
            $t = $ev[0];
            if ( $prev !== null && $t > $prev && $count > 0 ) {
                // Segment [prev, t) met $count tegelijk, eventueel over minuutgrenzen.
                $a = $prev;
                while ( $a < $t ) {
                    $m  = (int) floor( $a / 60 );
                    $b  = min( $t, ( $m + 1 ) * 60 );
                    $touch( $m );
                    if ( $count > $mins[ $m ]['peak'] ) {
                        $mins[ $m ]['peak'] = $count;
                    }
                    $mins[ $m ]['area'] += $count * ( $b - $a );
                    $a = $b;
                }
            }
            $count += $ev[1];
            $prev   = $t;
        }

        ksort( $mins );
        $buckets = [];
        foreach ( $mins as $m => $d ) {
            if ( $d['peak'] <= 0 ) {
                continue;
            }
            arsort( $d['paths'] );
            $top = [];
            foreach ( array_slice( $d['paths'], 0, 5, true ) as $p => $c ) {
                $top[] = [ 'path' => (string) $p, 'count' => (int) $c ];
            }
            $buckets[] = [
                'start'     => gmdate( 'Y-m-d\TH:i:s\Z', $m * 60 ),
                'seconds'   => 60,
                'peak'      => (int) $d['peak'],
                'avg'       => round( $d['area'] / 60, 2 ),
                'requests'  => (int) $d['requests'],
                'top_paths' => $top,
            ];
        }
        return $buckets;
    }

    /** Voegt buckets samen op 'start' (nieuwste wint), houdt de laatste MAX_BUCKETS. */
    public static function merge_pending( array $pending, array $fresh ): array {
        $by = [];
        foreach ( array_merge( $pending, $fresh ) as $b ) {
            if ( is_array( $b ) && isset( $b['start'] ) ) {
                $by[ $b['start'] ] = $b;
            }
        }
        ksort( $by );
        return array_values( array_slice( $by, -self::MAX_BUCKETS, null, true ) );
    }

    /**
     * Zoekt pm.max_children in PHP-FPM-poolconfig. Voorkeur: de pool met de naam
     * van de systeemgebruiker; anders de enige pool die het getal noemt.
     */
    public static function parse_fpm_max( array $config_texts, string $user ): ?int {
        $pools = [];
        foreach ( $config_texts as $text ) {
            $pool = null;
            foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
                $line = trim( $line );
                if ( $line === '' || $line[0] === ';' || $line[0] === '#' ) {
                    continue;
                }
                if ( preg_match( '/^\[([^\]]+)\]$/', $line, $m ) ) {
                    $pool = $m[1];
                    continue;
                }
                if ( $pool !== null && preg_match( '/^pm\.max_children\s*=\s*(\d+)/', $line, $m ) ) {
                    $n = (int) $m[1];
                    if ( $n >= 1 && $n <= 500 ) {
                        $pools[ $pool ] = $n;
                    }
                }
            }
        }
        if ( $user !== '' && isset( $pools[ $user ] ) ) {
            return $pools[ $user ];
        }
        unset( $pools['www'] ); // standaardpool van Debian, meestal niet de site
        return count( $pools ) === 1 ? (int) reset( $pools ) : null;
    }

    // ── 3. Versturen (WP-cron, elke 5 min) ───────────────────────────────────

    public static function detected_max(): ?int {
        $cached = get_option( self::OPT_DETECTED, [] );
        if ( is_array( $cached ) && isset( $cached['at'] ) && time() - (int) $cached['at'] < DAY_IN_SECONDS ) {
            return isset( $cached['max'] ) ? ( $cached['max'] === null ? null : (int) $cached['max'] ) : null;
        }
        $max = null;
        $how = 'niet gevonden';
        try {
            $ver   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $files = @glob( '/etc/php/' . $ver . '/fpm/pool.d/*.conf' ) ?: [];
            $texts = [];
            foreach ( array_slice( $files, 0, 20 ) as $f ) {
                if ( @is_readable( $f ) && @filesize( $f ) < 256 * 1024 ) {
                    $texts[] = (string) @file_get_contents( $f );
                }
            }
            $user = '';
            if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
                $pw   = @posix_getpwuid( posix_geteuid() );
                $user = is_array( $pw ) && isset( $pw['name'] ) ? (string) $pw['name'] : '';
            }
            $max = self::parse_fpm_max( $texts, $user );
            if ( $max !== null ) {
                $how = 'php-fpm pool (' . $ver . ')';
            }
        } catch ( \Throwable $e ) {
            $max = null;
        }
        update_option( self::OPT_DETECTED, [ 'max' => $max, 'at' => time(), 'how' => $how ], false );
        return $max;
    }

    /** Verwerkt afgeronde minuten en stuurt ze naar Level 4. */
    public static function report(): array {
        if ( ! self::enabled() ) {
            return [ 'ok' => false, 'error' => 'disabled' ];
        }
        $dir = self::dir();
        if ( ! $dir ) {
            return self::remember( [ 'ok' => false, 'error' => 'map niet schrijfbaar' ] );
        }

        // Niet twee keer tegelijk (WP-cron kan dubbel starten).
        $lock = @fopen( $dir . '/report.lock', 'c' );
        if ( ! $lock || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
            return [ 'ok' => false, 'error' => 'bezig' ];
        }

        try {
            $now    = time();
            $until  = (int) floor( ( $now - self::LAG ) / 60 );
            $cursor = (int) get_option( self::OPT_CURSOR, 0 );
            if ( $cursor <= 0 || $cursor < $until - self::MAX_BUCKETS ) {
                $cursor = max( $cursor, $until - ( $cursor <= 0 ? self::FIRST_RUN_MIN : self::MAX_BUCKETS ) );
            }

            $fresh = [];
            if ( $cursor < $until ) {
                $text  = '';
                $lines = 0;
                $first = (int) floor( $cursor * 60 / self::FILE_SPAN );
                $last  = (int) floor( $now / self::FILE_SPAN );
                for ( $k = $first; $k <= $last && $lines < self::MAX_LINES; $k++ ) {
                    $f = $dir . '/w-' . $k . '.log';
                    if ( is_file( $f ) ) {
                        $chunk  = (string) @file_get_contents( $f );
                        $lines += substr_count( $chunk, "\n" );
                        $text  .= $chunk;
                    }
                }
                $intervals = self::parse_lines( $text, $cursor * 60, $until * 60 );
                $fresh     = self::compute_buckets( $intervals, $cursor, $until );
                update_option( self::OPT_CURSOR, $until, false );
            }

            $pending = get_option( self::OPT_PENDING, [] );
            $pending = self::merge_pending( is_array( $pending ) ? $pending : [], $fresh );
            update_option( self::OPT_PENDING, $pending, false );

            self::cleanup( $dir, $until );

            $result = self::send( $pending );
            if ( ! empty( $result['ok'] ) ) {
                update_option( self::OPT_PENDING, [], false );
            }
            return self::remember( $result + [ 'buckets' => count( $pending ) ] );
        } finally {
            @flock( $lock, LOCK_UN );
            @fclose( $lock );
        }
    }

    /** Logbestanden ouder dan een uur vóór de cursor weg (en alles > 1 dag). */
    private static function cleanup( string $dir, int $until_min ): void {
        $keep_from = (int) floor( ( $until_min * 60 - HOUR_IN_SECONDS ) / self::FILE_SPAN );
        foreach ( (array) @glob( $dir . '/w-*.log' ) as $f ) {
            if ( preg_match( '/w-(\d+)\.log$/', (string) $f, $m ) && (int) $m[1] < $keep_from ) {
                @unlink( $f );
            }
        }
    }

    private static function send( array $buckets ): array {
        $site_id = (string) get_option( RR_Agent::OPT_SITE_ID );
        $api_key = (string) get_option( RR_Agent::OPT_API_KEY );
        if ( ! $site_id || ! $api_key ) {
            return [ 'ok' => false, 'error' => 'no_credentials' ];
        }
        $body = wp_json_encode( [
            'site_url'     => home_url(),
            'source'       => 'counter',
            'detected_max' => self::detected_max(),
            'buckets'      => array_values( $buckets ),
        ] );
        $response = wp_remote_post( RR_Agent::get_level4_url() . '/api/wp-agent/workers', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'   => 'application/json',
                'X-RR-Site-Id'   => $site_id,
                'X-RR-Signature' => hash_hmac( 'sha256', $body, $api_key ),
            ],
            'body'    => $body,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $resp = json_decode( wp_remote_retrieve_body( $response ), true );
        // 400 = Level 4 keurt de inhoud af: niet eindeloos opnieuw proberen.
        $ok = ( $code >= 200 && $code < 300 ) || $code === 400;
        return [ 'ok' => $ok, 'code' => $code, 'response' => $resp ];
    }

    private static function remember( array $result ): array {
        update_option( self::OPT_LAST_REPORT, $result + [ 'at' => time() ], false );
        return $result;
    }

    // ── Beheer (op de pagina "Level 4 koppeling") ────────────────────────────

    public static function render_admin_section(): void {
        $on       = self::enabled();
        $forced   = defined( 'RR_PHP_WORKERS_DISABLE' ) && RR_PHP_WORKERS_DISABLE;
        $last     = (array) get_option( self::OPT_LAST_REPORT, [] );
        $detected = (array) get_option( self::OPT_DETECTED, [] );
        $pending  = get_option( self::OPT_PENDING, [] );

        echo '<h2>' . esc_html__( 'PHP-workers', 'rankrepair' ) . '</h2>';
        echo '<p style="max-width:720px;color:#555">' . esc_html__( 'Meet hoeveel PHP-verzoeken er tegelijk lopen en stuurt dat elke 5 minuten naar Level 4. Per verzoek wordt één regel weggeschreven (tijd en pad, zonder querystring of IP-adres).', 'rankrepair' ) . '</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Status', 'rankrepair' ) . '</th><td>';
        if ( $forced ) {
            echo '<span style="color:#666">●</span> ' . esc_html__( 'Uit via wp-config (RR_PHP_WORKERS_DISABLE)', 'rankrepair' );
        } else {
            echo $on
                ? '<span style="color:#1a7f37">●</span> ' . esc_html__( 'Aan', 'rankrepair' )
                : '<span style="color:#666">●</span> ' . esc_html__( 'Uit', 'rankrepair' );
        }
        echo '</td></tr>';
        $max = array_key_exists( 'max', $detected ) ? $detected['max'] : null;
        echo '<tr><th>' . esc_html__( 'Maximum gevonden', 'rankrepair' ) . '</th><td>' . esc_html( $max !== null ? sprintf( __( '%d workers (%s)', 'rankrepair' ), (int) $max, (string) ( $detected['how'] ?? '' ) ) : __( 'niet uit te lezen op deze server; vul het in Level 4 in', 'rankrepair' ) ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Laatste verzending', 'rankrepair' ) . '</th><td>';
        if ( ! empty( $last['at'] ) ) {
            $txt = human_time_diff( (int) $last['at'] ) . ' ' . __( 'geleden', 'rankrepair' ) . ' — ';
            $txt .= ! empty( $last['ok'] ) ? __( 'gelukt', 'rankrepair' ) : sprintf( __( 'mislukt (%s)', 'rankrepair' ), (string) ( $last['error'] ?? ( 'HTTP ' . ( $last['code'] ?? '?' ) ) ) );
            echo esc_html( $txt );
        } else {
            echo esc_html__( 'nog niet', 'rankrepair' );
        }
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Wacht op verzending', 'rankrepair' ) . '</th><td>' . esc_html( sprintf( __( '%d minuten', 'rankrepair' ), is_array( $pending ) ? count( $pending ) : 0 ) ) . '</td></tr>';
        echo '</tbody></table>';

        echo '<p>';
        if ( ! $forced ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
            wp_nonce_field( 'rr_php_workers_toggle' );
            echo '<input type="hidden" name="action" value="rr_php_workers_toggle">';
            echo '<input type="hidden" name="enable" value="' . ( $on ? '0' : '1' ) . '">';
            echo '<button class="button">' . esc_html( $on ? __( 'Meten uitzetten', 'rankrepair' ) : __( 'Meten aanzetten', 'rankrepair' ) ) . '</button>';
            echo '</form>';
        }
        if ( $on ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
            wp_nonce_field( 'rr_php_workers_report_now' );
            echo '<input type="hidden" name="action" value="rr_php_workers_report_now">';
            echo '<button class="button">' . esc_html__( 'Nu versturen', 'rankrepair' ) . '</button>';
            echo '</form>';
        }
        echo '</p>';
    }

    public static function handle_toggle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'rr_php_workers_toggle' );
        $enable = isset( $_POST['enable'] ) && $_POST['enable'] === '1';
        update_option( self::OPT_ENABLED, $enable ? '1' : '0', false );
        if ( $enable ) {
            self::schedule();
        } else {
            self::unschedule();
        }
        wp_safe_redirect( admin_url( 'admin.php?page=rankrepair-level4&rr=workers' ) );
        exit;
    }

    public static function handle_report_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden' );
        }
        check_admin_referer( 'rr_php_workers_report_now' );
        delete_option( self::OPT_DETECTED ); // opnieuw zoeken
        self::report();
        wp_safe_redirect( admin_url( 'admin.php?page=rankrepair-level4&rr=workers' ) );
        exit;
    }

    /** Bij verwijderen van de plugin: logmap + opties weg. */
    public static function uninstall(): void {
        self::unschedule();
        $up    = wp_upload_dir( null, false );
        $token = (string) get_option( self::OPT_DIR_TOKEN, '' );
        if ( ! empty( $up['basedir'] ) && preg_match( '/^[a-z0-9]{12,32}$/', $token ) ) {
            $dir = rtrim( $up['basedir'], '/\\' ) . '/rankrepair-workers-' . $token;
            // Geen GLOB_BRACE: bestaat niet op elk systeem.
            $files = array_merge( (array) @glob( $dir . '/*' ), [ $dir . '/.htaccess' ] );
            foreach ( $files as $f ) {
                if ( is_string( $f ) && is_file( $f ) ) {
                    @unlink( $f );
                }
            }
            @rmdir( $dir );
        }
        foreach ( [ self::OPT_ENABLED, self::OPT_DIR, self::OPT_DIR_TOKEN, self::OPT_CURSOR, self::OPT_PENDING, self::OPT_DETECTED, self::OPT_LAST_REPORT ] as $o ) {
            delete_option( $o );
        }
    }
}
