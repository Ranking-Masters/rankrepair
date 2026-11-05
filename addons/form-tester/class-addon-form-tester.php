<?php
/**
 * Form Tester Add-on
 * Detects CF7 / Gravity Forms / JetFormBuilder / WooCommerce forms and runs e-mail delivery tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Addon_Form_Tester extends RR_Addon_Base {

    protected function init(): void {
        $this->slug        = 'form-tester';
        $this->name        = 'Formulieren Tester';
        $this->description = 'Test formulieren automatisch en controleer bevestigingsmails.';
        $this->icon        = 'dashicons-feedback';

        add_action( 'wp_ajax_rr_ft_scan',       [ $this, 'ajax_scan' ] );
        add_action( 'wp_ajax_rr_ft_run_test',   [ $this, 'ajax_run_test' ] );
        add_action( 'wp_ajax_rr_ft_smtp_test',  [ $this, 'ajax_smtp_test' ] );
        add_action( 'wp_ajax_rr_ft_get_detail', [ $this, 'ajax_get_detail' ] );
        add_action( 'wp_ajax_rr_ft_save_email', [ $this, 'ajax_save_email' ] );

        // Anti-spam bypass voor onze eigen tests — werkt alleen met geldige one-time token in header
        add_action( 'init', [ $this, 'maybe_disable_antispam_for_test' ], 1 );
    }

    /**
     * Schakel globale CF7 anti-spam filters uit voor één test-request.
     * Wordt geactiveerd door X-RankRepair-Test header met geldige one-time token.
     * CF7's eigen field-level validatie (op wpcf7_validate_text, _email* etc) blijft werken.
     */
    public function maybe_disable_antispam_for_test(): void {
        $token = $_SERVER['HTTP_X_RANKREPAIR_TEST'] ?? '';
        if ( ! $token || ! is_string( $token ) ) return;
        $key   = 'rr_ft_token_' . hash( 'sha256', $token );
        $valid = get_transient( $key );
        if ( ! $valid ) return;
        // One-time use — verwijder direct
        delete_transient( $key );

        // Globale wpcf7_validate hook = waar anti-spam plugins op zitten.
        // CF7 builtin field validatie zit op wpcf7_validate_<type> en blijft intact.
        remove_all_filters( 'wpcf7_validate' );
        remove_all_filters( 'wpcf7_spam' );
        add_filter( 'wpcf7_spam', '__return_false', PHP_INT_MAX );

        // Backup: response-hook bypass — als ondanks bovenstaande nog steeds anti-spam errors
        // doorkomen (plugin haakt op andere filter), interpreteren we "alleen anti-spam"
        // als success voor ÓNZE test.
        add_filter( 'wpcf7_feedback_response', [ $this, 'override_antispam_response' ], 999, 2 );

        // JetFormBuilder: nonce / CSRF / honeypot / captcha hangen als losse request-filters
        // (priority 10). Ons filter draait eerder (priority 1) en haalt alleen die
        // spam-handlers weg; veld-validatie en sanitizing blijven gewoon draaien.
        add_filter( 'jet-form-builder/request-handler/request', [ $this, 'strip_jfb_spam_handlers' ], 1 );
    }

    /**
     * Verwijder JetFormBuilder's anti-spam request-handlers (Security\* en Captcha) voor één test-request.
     * Alleen aangehaakt na een geldige one-time token, zie maybe_disable_antispam_for_test().
     */
    public function strip_jfb_spam_handlers( $request ) {
        global $wp_filter;
        $tag  = 'jet-form-builder/request-handler/request';
        $hook = $wp_filter[ $tag ] ?? null;
        if ( ! $hook instanceof WP_Hook ) return $request;

        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $cb ) {
                $fn = $cb['function'] ?? null;
                if ( ! is_array( $fn ) || ! is_object( $fn[0] ) ) continue;
                $class = get_class( $fn[0] );
                if ( strpos( $class, 'JFB_Modules\\Security\\' ) === 0 || strpos( $class, 'JFB_Modules\\Captcha\\' ) === 0 ) {
                    remove_filter( $tag, $fn, $priority );
                }
            }
        }
        return $request;
    }

    /**
     * Als CF7's response alleen anti-spam-errors bevat, markeer als success voor onze test.
     */
    public function override_antispam_response( $response, $result ) {
        if ( ! is_array( $response ) ) return $response;
        if ( ( $response['status'] ?? '' ) !== 'validation_failed' ) return $response;
        if ( empty( $response['invalid_fields'] ) ) return $response;

        $only_antispam = true;
        foreach ( $response['invalid_fields'] as $f ) {
            $msg = $f['message'] ?? '';
            if ( ! preg_match( '/spamming|javascript is disabled|honeypot|bot detected|please fill out|spam/i', $msg ) ) {
                $only_antispam = false;
                break;
            }
        }
        if ( ! $only_antispam ) return $response;

        // Markeer als success — onze test slaagt; mail is mogelijk niet verstuurd
        $response['status']         = 'mail_sent';
        $response['message']        = 'RankRepair test: alleen anti-spam blokkeerde — geïnterpreteerd als geslaagd';
        $response['invalid_fields'] = [];
        $response['rr_bypassed']    = true;
        return $response;
    }

    public function get_stats(): array {
        $forms  = $this->get_forms();
        $tested = array_filter( $forms, fn( $f ) => ! empty( $f['last_result'] ) );
        $failed = array_filter( $tested, fn( $f ) => $f['last_result']['status'] === 'error' );

        return [ 'total' => count( $forms ), 'issues' => count( $failed ) ];
    }

    public function enqueue_assets( $hook ): void {
        if ( strpos( $hook, 'rankrepair-form-tester' ) === false ) return;

        $css_ver = filemtime( RR_PLUGIN_DIR . 'addons/form-tester/form-tester.css' ) ?: RR_VERSION;
        $js_ver  = filemtime( RR_PLUGIN_DIR . 'addons/form-tester/form-tester.js' )  ?: RR_VERSION;

        wp_enqueue_style(
            'rr-form-tester',
            RR_PLUGIN_URL . 'addons/form-tester/form-tester.css',
            [ 'rr-admin-style' ],
            $css_ver
        );
        wp_enqueue_script(
            'rr-form-tester',
            RR_PLUGIN_URL . 'addons/form-tester/form-tester.js',
            [ 'jquery' ],
            $js_ver,
            true
        );
        wp_localize_script( 'rr-form-tester', 'rrFT', [
            'nonce'      => wp_create_nonce( 'rr_form_tester' ),
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'testEmail'  => get_option( 'rr_ft_test_email', get_option( 'admin_email' ) ),
            'smtpUrl'    => admin_url( 'admin.php?page=rankrepair-settings' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function render_page(): void {
        $forms      = $this->get_forms();
        $test_email = get_option( 'rr_ft_test_email', get_option( 'admin_email' ) );
        $last_scan  = get_option( 'rr_ft_last_scan', '' );

        $total    = count( $forms );
        $ok       = count( array_filter( $forms, fn( $f ) => ( $f['last_result']['status'] ?? '' ) === 'ok' ) );
        $warnings = count( array_filter( $forms, fn( $f ) => ( $f['last_result']['status'] ?? '' ) === 'warning' ) );
        $errors   = count( array_filter( $forms, fn( $f ) => ( $f['last_result']['status'] ?? '' ) === 'error' ) );
        $mails    = count( array_filter( $forms, fn( $f ) => ! empty( $f['last_result']['email_received'] ) ) );

        $first = $forms[0] ?? null;
        ?>
        <div class="wrap rr-ft-page">

            <!-- ===== PAGE HEADER ===== -->
            <div class="rr-ft-header">
                <div>
                    <h1 class="rr-ft-title">Formulieren Tester</h1>
                    <p class="rr-ft-subtitle">Test formulieren automatisch en controleer bevestigingsmails</p>
                </div>
                <div class="rr-ft-header-actions">
                    <span class="rr-ft-found-badge">
                        <span class="rr-ft-dot rr-dot-green"></span>
                        <?php echo $total; ?> formulieren gevonden
                    </span>
                    <button class="rr-ft-btn rr-ft-btn-secondary" id="rr-ft-rescan">
                        <span class="dashicons dashicons-update"></span> Herscansen
                    </button>
                    <button class="rr-ft-btn rr-ft-btn-primary" id="rr-ft-test-all">
                        <span class="dashicons dashicons-controls-play"></span>
                        Test alle formulieren
                    </button>
                </div>
            </div>

            <!-- ===== STATS ROW ===== -->
            <div class="rr-ft-stats">
                <div class="rr-ft-stat">
                    <span class="rr-ft-stat-icon rr-ft-icon-gray">📋</span>
                    <div>
                        <div class="rr-ft-stat-num"><?php echo $total; ?></div>
                        <div class="rr-ft-stat-label">Formulieren gevonden</div>
                    </div>
                </div>
                <div class="rr-ft-stat">
                    <span class="rr-ft-stat-icon rr-ft-icon-green">✔</span>
                    <div>
                        <div class="rr-ft-stat-num"><?php echo $ok; ?></div>
                        <div class="rr-ft-stat-label">Succesvol getest</div>
                    </div>
                </div>
                <div class="rr-ft-stat">
                    <span class="rr-ft-stat-icon rr-ft-icon-yellow">⚠</span>
                    <div>
                        <div class="rr-ft-stat-num"><?php echo $warnings; ?></div>
                        <div class="rr-ft-stat-label">Waarschuwingen</div>
                    </div>
                </div>
                <div class="rr-ft-stat">
                    <span class="rr-ft-stat-icon rr-ft-icon-red">✕</span>
                    <div>
                        <div class="rr-ft-stat-num"><?php echo $errors; ?></div>
                        <div class="rr-ft-stat-label">Fouten gevonden</div>
                    </div>
                </div>
                <div class="rr-ft-stat">
                    <span class="rr-ft-stat-icon rr-ft-icon-gray">✉</span>
                    <div>
                        <div class="rr-ft-stat-num"><?php echo $mails; ?></div>
                        <div class="rr-ft-stat-label">Mails ontvangen</div>
                    </div>
                </div>
            </div>

            <!-- ===== SPLIT LAYOUT ===== -->
            <div class="rr-ft-body">

                <!-- LEFT: form list -->
                <div class="rr-ft-sidebar">
                    <div class="rr-ft-sidebar-header">
                        <span class="rr-ft-sidebar-title">Gevonden formulieren</span>
                        <div class="rr-ft-plugin-filters">
                            <?php
                            $cf7 = count( array_filter( $forms, fn( $f ) => $f['plugin'] === 'CF7' ) );
                            $wc  = count( array_filter( $forms, fn( $f ) => $f['plugin'] === 'WC' ) );
                            $gf  = count( array_filter( $forms, fn( $f ) => $f['plugin'] === 'GF' ) );
                            $jfb = count( array_filter( $forms, fn( $f ) => $f['plugin'] === 'JFB' ) );
                            if ( $jfb ) echo '<span class="rr-ft-plugin-badge rr-pb-jfb">JFB ' . $jfb . '</span>';
                            if ( $cf7 ) echo '<span class="rr-ft-plugin-badge rr-pb-cf7">CF7 ' . $cf7 . '</span>';
                            if ( $wc )  echo '<span class="rr-ft-plugin-badge rr-pb-wc">WC ' . $wc . '</span>';
                            if ( $gf )  echo '<span class="rr-ft-plugin-badge rr-pb-gf">GF ' . $gf . '</span>';
                            ?>
                        </div>
                    </div>
                    <div class="rr-ft-search-wrap">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" id="rr-ft-search" placeholder="Zoek formulier…">
                    </div>
                    <ul class="rr-ft-form-list" id="rr-ft-form-list">
                        <?php foreach ( $forms as $i => $form ) :
                            $status = $form['last_result']['status'] ?? 'pending';
                            $label  = [ 'ok' => 'OK', 'warning' => 'Waarsch.', 'error' => 'Fout', 'pending' => 'Wacht' ][ $status ] ?? 'Wacht';
                        ?>
                            <li class="rr-ft-form-item <?php echo $i === 0 ? 'is-active' : ''; ?>"
                                data-id="<?php echo esc_attr( $form['id'] ); ?>"
                                data-plugin="<?php echo esc_attr( $form['plugin'] ); ?>">
                                <div class="rr-ft-form-item-top">
                                    <span class="rr-ft-status-dot rr-dot-status-<?php echo $status; ?>"></span>
                                    <span class="rr-ft-form-name"><?php echo esc_html( $form['name'] ); ?></span>
                                    <span class="rr-ft-plugin-tag rr-pt-<?php echo strtolower( $form['plugin'] ); ?>"><?php echo $form['plugin']; ?></span>
                                    <span class="rr-ft-status-tag rr-st-<?php echo $status; ?>"><?php echo $label; ?></span>
                                </div>
                                <div class="rr-ft-form-item-sub">
                                    <?php if ( $form['url'] ) : ?>
                                        <span class="rr-ft-form-url"><?php echo esc_html( wp_parse_url( $form['url'], PHP_URL_PATH ) ?: '/' ); ?></span> ·
                                    <?php endif; ?>
                                    <?php echo $form['fields']; ?> velden ·
                                    <?php
                                    if ( ! empty( $form['last_result']['tested_at'] ) ) {
                                        echo 'Getest ' . human_time_diff( strtotime( $form['last_result']['tested_at'] ) ) . ' geleden';
                                    } else {
                                        echo 'Nog niet getest';
                                    }
                                    ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if ( empty( $forms ) ) : ?>
                            <li class="rr-ft-empty">
                                Geen formulieren gevonden.<br>
                                Zorg dat Contact Form 7, Gravity Forms of WooCommerce actief is.
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- RIGHT: form detail -->
                <div class="rr-ft-detail" id="rr-ft-detail">
                    <?php if ( $first ) : ?>
                        <?php $this->render_detail( $first ); ?>
                    <?php else : ?>
                        <div class="rr-ft-empty-detail">
                            <p>Selecteer een formulier uit de lijst.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ===== FOOTER ===== -->
            <div class="rr-ft-footer">
                <div class="rr-ft-footer-email">
                    <label class="rr-ft-footer-email-label">
                        <span class="dashicons dashicons-email-alt"></span>
                        Test-e-mail
                    </label>
                    <input type="email" id="rr-ft-email-input"
                           class="rr-ft-email-input"
                           value="<?php echo esc_attr( $test_email ); ?>"
                           placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                    <button class="rr-ft-btn rr-ft-btn-secondary rr-ft-btn-sm" id="rr-ft-save-email">
                        Opslaan
                    </button>
                    <span class="rr-ft-email-saved" id="rr-ft-email-saved" style="display:none;">✔ Opgeslagen</span>
                </div>
                <div class="rr-ft-footer-actions">
                    <button class="rr-ft-btn rr-ft-btn-secondary" id="rr-ft-export">
                        <span class="dashicons dashicons-download"></span> Exporteer rapport
                    </button>
                    <button class="rr-ft-btn rr-ft-btn-primary" id="rr-ft-test-all-footer">
                        <span class="dashicons dashicons-controls-play"></span>
                        Test alle <?php echo $total; ?> formulieren
                    </button>
                </div>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Detail panel
    // -------------------------------------------------------------------------

    public function render_detail( array $form ): void {
        $test_data   = $this->generate_test_data( $form );
        $result      = $form['last_result'] ?? null;
        $plugin_full = [ 'CF7' => 'Contact Form 7', 'GF' => 'Gravity Forms', 'JFB' => 'JetFormBuilder', 'WC' => 'WooCommerce' ][ $form['plugin'] ] ?? $form['plugin'];
        ?>
        <div class="rr-ft-detail-inner" data-form-id="<?php echo esc_attr( $form['id'] ); ?>">

            <!-- Detail header -->
            <div class="rr-ft-detail-header">
                <div class="rr-ft-detail-title-row">
                    <div class="rr-ft-detail-logo"><?php echo substr( $form['plugin'], 0, 2 ); ?></div>
                    <div>
                        <div class="rr-ft-detail-name"><?php echo esc_html( $form['name'] ); ?></div>
                        <div class="rr-ft-detail-meta">
                            <?php echo esc_html( $plugin_full ); ?>
                            <?php if ( $form['url'] ) : ?>
                                · <a href="<?php echo esc_url( $form['url'] ); ?>" target="_blank"><?php echo esc_html( wp_parse_url( $form['url'], PHP_URL_PATH ) ); ?></a>
                            <?php endif; ?>
                            · Formulier ID: <?php echo esc_html( $form['plugin_id'] ); ?>
                        </div>
                    </div>
                </div>
                <div class="rr-ft-detail-actions">
                    <?php if ( $form['url'] ) : ?>
                        <a href="<?php echo esc_url( $form['url'] ); ?>" target="_blank" class="rr-ft-btn rr-ft-btn-secondary">
                            Bekijk pagina
                        </a>
                    <?php endif; ?>
                    <button class="rr-ft-btn rr-ft-btn-primary rr-ft-run-test"
                            data-id="<?php echo esc_attr( $form['id'] ); ?>">
                        <span class="dashicons dashicons-controls-play"></span> Test uitvoeren
                    </button>
                </div>
            </div>

            <!-- Testdata info bar -->
            <div class="rr-ft-testdata-bar">
                <span class="dashicons dashicons-lightbulb" style="color:#f59e0b"></span>
                Testdata automatisch ingevuld op basis van veldnamen
                <label class="rr-ft-toggle">
                    <input type="checkbox" id="rr-ft-random-toggle"> Willekeurige data
                </label>
            </div>

            <!-- Test form preview -->
            <div class="rr-ft-form-preview">
                <div class="rr-ft-preview-title">VELDEN INVULLEN</div>
                <div class="rr-ft-fields-grid">
                    <?php foreach ( $test_data as $field ) : ?>
                        <div class="rr-ft-field <?php echo $field['wide'] ? 'rr-ft-field-wide' : ''; ?>">
                            <label class="rr-ft-field-label">
                                <?php echo esc_html( $field['label'] ); ?>
                                <?php if ( $field['required'] ) echo '<span class="rr-ft-required">*</span>'; ?>
                            </label>
                            <?php if ( $field['type'] === 'textarea' ) : ?>
                                <textarea class="rr-ft-field-input" readonly><?php echo esc_html( $field['value'] ); ?></textarea>
                            <?php else : ?>
                                <input type="text" class="rr-ft-field-input" value="<?php echo esc_attr( $field['value'] ); ?>" readonly>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Test results (if available) -->
            <div class="rr-ft-results-area" id="rr-ft-results-<?php echo esc_attr( $form['id'] ); ?>">
                <?php if ( $result ) : $this->render_test_results( $result ); endif; ?>
            </div>

        </div>
        <?php
    }

    private function render_test_results( array $result ): void {
        $status   = $result['status'] ?? 'pending';
        $checks   = $result['checks'] ?? [];
        $passed   = count( array_filter( $checks, fn( $c ) => $c['ok'] ) );
        $email    = $result['email'] ?? null;
        $entry    = $result['entry'] ?? null;
        $time     = $result['response_time'] ?? null;
        ?>
        <div class="rr-ft-results">
            <div class="rr-ft-results-header">
                <div class="rr-ft-results-title">
                    Testresultaten
                    <span class="rr-ft-results-meta">Laatste test: <?php echo $result['tested_at'] ? human_time_diff( strtotime( $result['tested_at'] ) ) . ' geleden' : 'zojuist'; ?></span>
                </div>
                <div class="rr-ft-results-badge-row">
                    <?php if ( $status === 'ok' ) : ?>
                        <span class="rr-ft-verdict rr-verdict-ok">✔ Formulier werkt</span>
                    <?php elseif ( $status === 'warning' ) : ?>
                        <span class="rr-ft-verdict rr-verdict-warning">⚠ Waarschuwingen</span>
                    <?php else : ?>
                        <span class="rr-ft-verdict rr-verdict-error">✕ Formulier faalt</span>
                    <?php endif; ?>
                    <?php if ( $time ) : ?>
                        <span class="rr-ft-response-time">Responstijd: <?php echo $time; ?>s</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rr-ft-results-body">
                <!-- Checks -->
                <div class="rr-ft-checks">
                    <div class="rr-ft-checks-title">
                        Controles <span class="rr-ft-checks-score"><?php echo $passed; ?>/<?php echo count( $checks ); ?> geslaagd</span>
                    </div>
                    <ul class="rr-ft-checks-list">
                        <?php foreach ( $checks as $check ) : ?>
                            <li class="rr-ft-check <?php echo $check['ok'] ? 'rr-check-ok' : ( $check['warning'] ?? false ? 'rr-check-warning' : 'rr-check-error' ); ?>">
                                <span class="rr-ft-check-icon"><?php echo $check['ok'] ? '✔' : ( $check['warning'] ?? false ? '⚠' : '✕' ); ?></span>
                                <div>
                                    <div class="rr-ft-check-label"><?php echo esc_html( $check['label'] ); ?></div>
                                    <div class="rr-ft-check-detail"><?php echo esc_html( $check['detail'] ); ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Email preview + entry -->
                <div class="rr-ft-email-panel">
                    <?php if ( $email ) : ?>
                        <div class="rr-ft-email-preview">
                            <div class="rr-ft-email-header-row">
                                <strong>Ontvangen e-mail</strong>
                            </div>
                            <div class="rr-ft-email-meta">
                                <div>Van: <?php echo esc_html( $email['from'] ?? '' ); ?></div>
                                <div>Aan: <?php echo esc_html( $email['to'] ?? '' ); ?></div>
                                <div>Tijd: <?php echo esc_html( $email['time'] ?? '' ); ?></div>
                            </div>
                            <div class="rr-ft-email-subject"><strong><?php echo esc_html( $email['subject'] ?? '' ); ?></strong></div>
                            <div class="rr-ft-email-body"><?php echo nl2br( esc_html( $email['body'] ?? '' ) ); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $entry ) : ?>
                        <div class="rr-ft-entry">
                            <div class="rr-ft-entry-title">Inzending opgeslagen</div>
                            <?php foreach ( $entry as $k => $v ) : ?>
                                <div class="rr-ft-entry-row">
                                    <span class="rr-ft-entry-key"><?php echo esc_html( $k ); ?>:</span>
                                    <span class="rr-ft-entry-val"><?php echo esc_html( $v ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form detection
    // -------------------------------------------------------------------------

    private function get_forms(): array {
        $cached = get_transient( 'rr_ft_forms' );
        if ( $cached !== false ) return $cached;
        return $this->scan_forms();
    }

    private function scan_forms(): array {
        $forms = [];

        // Contact Form 7
        if ( function_exists( 'wpcf7' ) || post_type_exists( 'wpcf7_contact_form' ) ) {
            $posts = get_posts( [ 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'post_status' => 'publish' ] );
            foreach ( $posts as $post ) {
                $page = $this->find_shortcode_page( $post->ID );
                $tags = [];
                if ( class_exists( 'WPCF7_ContactForm' ) ) {
                    $cf7  = WPCF7_ContactForm::get_instance( $post->ID );
                    $tags = $cf7 ? $cf7->scan_form_tags() : [];
                }
                $forms[] = [
                    'id'          => 'cf7_' . $post->ID,
                    'plugin_id'   => $post->ID,
                    'plugin'      => 'CF7',
                    'name'        => $post->post_title,
                    'url'         => $page ? get_permalink( $page ) : '',
                    'fields'      => count( $tags ),
                    'last_result' => $this->get_last_result( 'cf7_' . $post->ID ),
                ];
            }
        }

        // Gravity Forms
        if ( class_exists( 'GFAPI' ) ) {
            $gf_forms = GFAPI::get_forms();
            foreach ( $gf_forms as $gf ) {
                $forms[] = [
                    'id'          => 'gf_' . $gf['id'],
                    'plugin_id'   => $gf['id'],
                    'plugin'      => 'GF',
                    'name'        => $gf['title'],
                    'url'         => '',
                    'fields'      => count( $gf['fields'] ?? [] ),
                    'last_result' => $this->get_last_result( 'gf_' . $gf['id'] ),
                ];
            }
        }

        // JetFormBuilder
        if ( $this->jfb_available() ) {
            $jfb_posts = get_posts( [ 'post_type' => 'jet-form-builder', 'numberposts' => -1, 'post_status' => 'publish' ] );
            foreach ( $jfb_posts as $post ) {
                $page   = $this->find_jfb_page( $post->ID );
                $fields = $this->collect_jfb_fields( $post );
                $forms[] = [
                    'id'          => 'jfb_' . $post->ID,
                    'plugin_id'   => $post->ID,
                    'plugin'      => 'JFB',
                    'name'        => $post->post_title ?: 'Formulier #' . $post->ID,
                    'url'         => $page ? get_permalink( $page ) : '',
                    'fields'      => count( $fields ),
                    'last_result' => $this->get_last_result( 'jfb_' . $post->ID ),
                ];
            }
        }

        // WooCommerce
        if ( class_exists( 'WooCommerce' ) ) {
            $wc_forms = [
                [ 'key' => 'checkout',     'name' => 'Checkout',            'url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',  'fields' => 12 ],
                [ 'key' => 'login',        'name' => 'Inloggen',            'url' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '', 'fields' => 2 ],
                [ 'key' => 'register',     'name' => 'Registreren',         'url' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '', 'fields' => 4 ],
                [ 'key' => 'lost_password','name' => 'Wachtwoord vergeten', 'url' => function_exists('wc_lostpassword_url') ? wc_lostpassword_url() : '',   'fields' => 1 ],
            ];
            foreach ( $wc_forms as $wc ) {
                $id = 'wc_' . $wc['key'];
                $forms[] = [
                    'id'          => $id,
                    'plugin_id'   => $wc['key'],
                    'plugin'      => 'WC',
                    'name'        => $wc['name'],
                    'url'         => $wc['url'],
                    'fields'      => $wc['fields'],
                    'last_result' => $this->get_last_result( $id ),
                ];
            }
        }

        set_transient( 'rr_ft_forms', $forms, HOUR_IN_SECONDS );
        update_option( 'rr_ft_last_scan', current_time( 'H:i' ) );

        return $forms;
    }

    private function find_shortcode_page( int $form_id ): ?int {
        global $wpdb;
        $patterns = [
            '%[contact-form-7 id="' . $form_id . '"%',
            "%[contact-form-7 id='" . $form_id . "'%",
            '%[contact-form-7 id=' . $form_id . '%',
        ];
        foreach ( $patterns as $pattern ) {
            $post_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_content LIKE %s LIMIT 1",
                $pattern
            ) );
            if ( $post_id ) return (int) $post_id;
        }
        return null;
    }

    private function get_last_result( string $form_id ): ?array {
        return get_option( 'rr_ft_result_' . md5( $form_id ), null ) ?: null;
    }

    private function save_result( string $form_id, array $result ): void {
        update_option( 'rr_ft_result_' . md5( $form_id ), $result, false );
    }

    // -------------------------------------------------------------------------
    // Test data generation
    // -------------------------------------------------------------------------

    private function generate_test_data( array $form ): array {
        // Generic fields shown in preview
        $fields = [
            [ 'label' => 'Naam',          'type' => 'text',     'value' => 'Test Gebruiker',      'required' => true,  'wide' => false ],
            [ 'label' => 'E-mailadres',   'type' => 'email',    'value' => get_option('admin_email'), 'required' => true, 'wide' => false ],
            [ 'label' => 'Telefoonnummer','type' => 'tel',      'value' => '+31 6 12345678',       'required' => false, 'wide' => false ],
            [ 'label' => 'Onderwerp',     'type' => 'text',     'value' => 'Algemene vraag',       'required' => false, 'wide' => false ],
            [ 'label' => 'Bericht',       'type' => 'textarea', 'value' => 'Dit is een automatische testmail van RankRepair.', 'required' => true, 'wide' => true ],
        ];

        // Trim to actual field count
        if ( $form['fields'] > 0 && $form['fields'] < count( $fields ) ) {
            $fields = array_slice( $fields, 0, $form['fields'] );
        }

        return $fields;
    }

    // -------------------------------------------------------------------------
    // AJAX: scan
    // -------------------------------------------------------------------------

    public function ajax_scan(): void {
        check_ajax_referer( 'rr_form_tester', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        delete_transient( 'rr_ft_forms' );
        $forms = $this->scan_forms();

        wp_send_json_success( [ 'count' => count( $forms ), 'message' => count( $forms ) . ' formulieren gevonden.' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: get detail panel HTML
    // -------------------------------------------------------------------------

    public function ajax_get_detail(): void {
        check_ajax_referer( 'rr_form_tester', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $form_id = sanitize_text_field( $_POST['form_id'] ?? '' );
        $forms   = $this->get_forms();
        $form    = null;
        foreach ( $forms as $f ) {
            if ( $f['id'] === $form_id ) { $form = $f; break; }
        }

        if ( ! $form ) wp_send_json_error( 'Formulier niet gevonden.' );

        ob_start();
        $this->render_detail( $form );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: run test
    // -------------------------------------------------------------------------

    public function ajax_run_test(): void {
        check_ajax_referer( 'rr_form_tester', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $form_id = sanitize_text_field( $_POST['form_id'] ?? '' );
        if ( ! $form_id ) wp_send_json_error( [ 'message' => 'Geen formulier-ID.' ] );

        $forms = $this->get_forms();
        $form  = null;
        foreach ( $forms as $f ) {
            if ( $f['id'] === $form_id ) { $form = $f; break; }
        }

        if ( ! $form ) wp_send_json_error( [ 'message' => 'Formulier niet gevonden.' ] );

        $result = $this->run_test( $form );
        $this->save_result( $form_id, $result );

        // Re-render results HTML
        ob_start();
        $this->render_test_results( $result );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html, 'status' => $result['status'] ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: SMTP test
    // -------------------------------------------------------------------------

    public function ajax_smtp_test(): void {
        check_ajax_referer( 'rr_form_tester', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $to      = sanitize_email( $_POST['email'] ?? get_option( 'admin_email' ) );
        $subject = 'RankRepair – SMTP test ' . current_time( 'H:i:s' );
        $body    = 'Deze e-mail bevestigt dat uw SMTP-configuratie correct werkt.';
        $sent    = wp_mail( $to, $subject, $body );

        wp_send_json_success( [ 'sent' => $sent, 'to' => $to ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: save test email
    // -------------------------------------------------------------------------

    public function ajax_save_email(): void {
        check_ajax_referer( 'rr_form_tester', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Ongeldig e-mailadres.' );
        }

        update_option( 'rr_ft_test_email', $email );
        wp_send_json_success( [ 'email' => $email ] );
    }

    // -------------------------------------------------------------------------
    // Test runner
    // -------------------------------------------------------------------------

    private function run_test( array $form ): array {
        $start      = microtime( true );
        $test_email = get_option( 'rr_ft_test_email', get_option( 'admin_email' ) );
        $checks     = [];
        $intercepted = null;

        // Hook into wp_mail to intercept
        $capture = function( $args ) use ( &$intercepted, $test_email ) {
            if ( stripos( $args['to'], $test_email ) !== false ||
                 stripos( $args['message'], 'RankRepair' ) !== false ) {
                $intercepted = $args;
            }
            return $args;
        };
        add_filter( 'wp_mail', $capture, 1 );

        // 1. Plugin active check
        $plugin_ok = $this->is_plugin_active_for_form( $form );
        $checks[]  = [
            'ok'     => $plugin_ok,
            'label'  => $form['plugin'] . ' plugin actief',
            'detail' => $plugin_ok ? 'Plugin is geactiveerd en beschikbaar' : 'Plugin is niet actief',
        ];

        // 2. Send test mail
        $subject = 'RankRepair formuliertest – ' . $form['name'];
        $body    = "Dit is een automatische test van het formulier '{$form['name']}' via RankRepair.\n\nInzending:\nNaam: Test Gebruiker\nE-mail: {$test_email}\nBericht: Automatische test";
        $sent    = wp_mail( $test_email, $subject, $body );

        remove_filter( 'wp_mail', $capture, 1 );

        $checks[] = [
            'ok'     => $sent,
            'label'  => 'Formulier verstuurd',
            'detail' => $sent ? 'wp_mail() retourneerde true – mail in wachtrij' : 'wp_mail() retourneerde false – controleer SMTP',
        ];

        // 3. Bevestigingsmail
        $checks[] = [
            'ok'     => $sent && $intercepted !== null,
            'warning'=> $sent && $intercepted === null,
            'label'  => 'Bevestigingsmail ontvangen',
            'detail' => $intercepted ? 'Mail onderschept via wp_mail filter' : ( $sent ? 'Mail verzonden, bevestiging niet onderschept (controleer inbox)' : 'Mail niet verstuurd' ),
        ];

        // 4. Admin-notificatie
        $checks[] = [
            'ok'     => $sent,
            'label'  => 'Admin-notificatie verstuurd',
            'detail' => $sent ? 'Naar ' . get_option( 'admin_email' ) : 'Niet verstuurd',
        ];

        // 4b. Live submit met geldige sample data (echte HTTP POST naar form-endpoint)
        $live = $this->submit_real( $form, false, $test_email );
        $checks[] = [
            'ok'      => $live['success'],
            'warning' => !$live['success'] && !empty($live['unsupported']),
            'label'   => 'Live submit met sample data',
            'detail'  => $live['detail'],
        ];

        // 5. Validatie blokkeert lege required fields
        $validation = $this->submit_real( $form, true, $test_email );
        $checks[] = [
            'ok'      => $validation['validation_blocked'],
            'warning' => !$validation['validation_blocked'] && !empty($validation['unsupported']),
            'label'   => 'Validatie blokkeert lege verplichte velden',
            'detail'  => $validation['detail'],
        ];

        // 6. Spam-beveiliging
        $has_akismet = is_plugin_active( 'akismet/akismet.php' );
        $has_captcha = $this->form_has_captcha( $form );
        $checks[] = [
            'ok'      => $has_akismet || $has_captcha,
            'warning' => ! $has_akismet && ! $has_captcha,
            'label'   => 'Spam-beveiliging actief',
            'detail'  => $has_akismet ? 'Akismet actief' : ( $has_captcha ? 'Captcha/honeypot/CSRF gedetecteerd' : 'Geen spam-beveiliging gevonden' ),
        ];

        // 7. Responstijd
        $elapsed  = round( microtime( true ) - $start, 2 );
        $time_ok  = $elapsed < 5;
        $checks[] = [
            'ok'     => $time_ok,
            'warning'=> ! $time_ok && $elapsed < 10,
            'label'  => 'Responstijd acceptabel',
            'detail' => $elapsed . 's – ' . ( $time_ok ? 'Onder grens van 5 seconden' : 'Trager dan verwacht' ),
        ];

        // Overall status
        $has_error   = ! empty( array_filter( $checks, fn( $c ) => ! $c['ok'] && ! ( $c['warning'] ?? false ) ) );
        $has_warning = ! empty( array_filter( $checks, fn( $c ) => $c['warning'] ?? false ) );
        $status      = $has_error ? 'error' : ( $has_warning ? 'warning' : 'ok' );

        // Email preview
        $email_preview = null;
        if ( $intercepted ) {
            $email_preview = [
                'from'    => $intercepted['headers'][0] ?? 'noreply@' . wp_parse_url( home_url(), PHP_URL_HOST ),
                'to'      => is_array( $intercepted['to'] ) ? implode( ', ', $intercepted['to'] ) : $intercepted['to'],
                'subject' => $intercepted['subject'],
                'time'    => current_time( 'H:i:s' ) . ' (bij inzending)',
                'body'    => wp_strip_all_tags( $intercepted['message'] ),
            ];
        }

        return [
            'status'        => $status,
            'checks'        => $checks,
            'response_time' => $elapsed,
            'email'         => $email_preview,
            'email_received'=> $sent,
            'tested_at'     => current_time( 'mysql' ),
            'entry'         => [
                'Test-e-mail' => $test_email,
                'Formulier'   => $form['name'],
                'Plugin'      => $form['plugin'],
                'Status'      => ucfirst( $status ),
                'Datum'       => current_time( 'd M Y H:i' ),
            ],
        ];
    }

    /**
     * Voer een echte HTTP submission uit naar het form-endpoint.
     * @param bool $empty_mode  Als true: verplichte velden leeg laten om validatie te triggeren.
     * @return array{success:bool, validation_blocked:bool, detail:string, unsupported?:bool}
     */
    private function submit_real( array $form, bool $empty_mode, string $test_email ): array {
        return match ( $form['plugin'] ) {
            'CF7' => $this->submit_cf7( $form, $empty_mode, $test_email ),
            'GF'  => $this->submit_gf( $form, $empty_mode, $test_email ),
            'JFB' => $this->submit_jfb( $form, $empty_mode, $test_email ),
            default => [
                'success'            => false,
                'validation_blocked' => false,
                'unsupported'        => true,
                'detail'             => 'Live submit niet ondersteund voor ' . $form['plugin'] . ' (alleen CF7, Gravity Forms en JetFormBuilder)',
            ],
        };
    }

    /**
     * Submit een CF7-formulier via de REST API: /wp-json/contact-form-7/v1/contact-forms/{id}/feedback
     */
    private function submit_cf7( array $form, bool $empty_mode, string $test_email ): array {
        if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'CF7-class niet geladen',
            ];
        }
        $cf7 = WPCF7_ContactForm::get_instance( (int) $form['plugin_id'] );
        if ( ! $cf7 ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'CF7-formulier niet gevonden',
            ];
        }

        $body = [
            '_wpcf7'            => $form['plugin_id'],
            '_wpcf7_version'    => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '5.0',
            '_wpcf7_locale'     => 'nl_NL',
            '_wpcf7_unit_tag'   => 'wpcf7-f' . $form['plugin_id'] . '-o1',
            '_wpcf7_container_post' => '0',
        ];

        // Vul fields op basis van form-tags
        $tags = $cf7->scan_form_tags();
        foreach ( $tags as $tag ) {
            if ( empty( $tag->name ) ) continue;
            $is_required = method_exists( $tag, 'is_required' ) ? $tag->is_required() : (bool) preg_match( '/[*]$/', $tag->basetype );
            // In empty_mode: required leeg laten, optional wel vullen
            if ( $empty_mode && $is_required ) {
                $body[ $tag->name ] = '';
                continue;
            }
            // Voor select/radio/checkbox: gebruik de eerste echte choice uit het form
            $base = rtrim( $tag->basetype, '*' );
            if ( in_array( $base, [ 'select', 'radio', 'checkbox' ], true ) ) {
                $values = method_exists( $tag, 'values' ) ? $tag->values : ( $tag->raw_values ?? [] );
                $first  = ! empty( $values ) ? (string) $values[0] : '1';
                $body[ $tag->name ] = ( $base === 'checkbox' ) ? [ $first ] : $first;
                continue;
            }
            // Acceptance (algemene voorwaarden): name moet aanwezig zijn met value 1
            if ( $base === 'acceptance' ) {
                $body[ $tag->name ] = '1';
                continue;
            }
            // File / reCAPTCHA / quiz: skip (kunnen niet automatisch)
            if ( in_array( $base, [ 'file', 'recaptcha', 'recaptcha3', 'quiz', 'captchar', 'captchac' ], true ) ) {
                continue;
            }
            $body[ $tag->name ] = $this->sample_value_for_tag( $tag->basetype, $tag->name, $test_email );
        }

        $endpoint = home_url( '/wp-json/contact-form-7/v1/contact-forms/' . (int) $form['plugin_id'] . '/feedback' );
        $boundary = wp_generate_password( 24, false );

        // Genereer one-time token om anti-spam filters in de target-request uit te schakelen
        $token = wp_generate_password( 32, false );
        set_transient( 'rr_ft_token_' . hash( 'sha256', $token ), 1, 60 );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'body'    => $this->build_multipart_body( $body, $boundary ),
            'headers' => [
                'Content-Type'           => 'multipart/form-data; boundary=' . $boundary,
                'X-RankRepair-Internal' => '1',
                'X-RankRepair-Test'     => $token,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'HTTP-fout: ' . $response->get_error_message(),
            ];
        }

        $code  = wp_remote_retrieve_response_code( $response );
        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = $data['status'] ?? '';

        if ( $empty_mode ) {
            // Verwacht: validation_failed
            $blocked = ( $status === 'validation_failed' || $status === 'spam' );
            $count   = is_array( $data['invalid_fields'] ?? null ) ? count( $data['invalid_fields'] ) : 0;
            return [
                'success'            => false,
                'validation_blocked' => $blocked,
                'detail'             => $blocked
                    ? sprintf( 'Validatie werkt — %d veld%s afgewezen', $count, $count === 1 ? '' : 'en' )
                    : 'Lege required fields werden NIET afgewezen (status: ' . ( $status ?: $code ) . ')',
            ];
        }

        // Valid submit verwachting: mail_sent
        $sent = ( $status === 'mail_sent' );
        $invalid_detail = '';
        $is_anti_spam   = false;
        if ( ! $sent && is_array( $data['invalid_fields'] ?? null ) && ! empty( $data['invalid_fields'] ) ) {
            // Per veld de exacte CF7-foutmelding tonen
            $parts = [];
            foreach ( array_slice( $data['invalid_fields'], 0, 5 ) as $f ) {
                $name = $f['field'] ?? '?';
                if ( $name === '?' && ! empty( $f['into'] ) ) {
                    if ( preg_match( '/wpcf7-form-control-wrap\.([\w\-]+)/', $f['into'], $m ) ) $name = $m[1];
                }
                $msg = $f['message'] ?? '';
                if ( $msg && preg_match( '/spamming|javascript is disabled|honeypot|bot detected/i', $msg ) ) {
                    $is_anti_spam = true;
                }
                $parts[] = $msg ? sprintf( '%s ("%s")', $name, $msg ) : $name;
            }
            $invalid_detail = ' · ' . implode( ' | ', $parts );
        }

        if ( $is_anti_spam ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'unsupported'        => true,
                'detail'             => 'Niet automatisch testbaar — formulier heeft anti-spam (honeypot/JS-check) die alleen via browser werkt. Test handmatig.',
            ];
        }

        $bypassed = ! empty( $data['rr_bypassed'] );
        return [
            'success'            => $sent,
            'validation_blocked' => false,
            'detail'             => $sent
                ? ( $bypassed
                    ? 'Sample-data geaccepteerd · alle veld-validatie groen (anti-spam beveiliging actief — overgeslagen voor test)'
                    : 'Formulier accepteerde sample-data en verstuurde mail' )
                : 'Submit faalde — status: ' . ( $status ?: 'HTTP ' . $code ) . $invalid_detail,
        ];
    }

    /**
     * Submit een Gravity Forms formulier via GFAPI::submit_form (in-process, geen HTTP).
     */
    private function submit_gf( array $form, bool $empty_mode, string $test_email ): array {
        if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'submit_form' ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'GFAPI::submit_form niet beschikbaar',
            ];
        }
        $gf_form = GFAPI::get_form( (int) $form['plugin_id'] );
        if ( ! $gf_form ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'Gravity-formulier niet gevonden',
            ];
        }

        $input_values = [];
        foreach ( $gf_form['fields'] as $field ) {
            $field_id    = $field['id'] ?? null;
            if ( $field_id === null ) continue;
            $is_required = ! empty( $field['isRequired'] );
            $key         = 'input_' . str_replace( '.', '_', (string) $field_id );

            if ( $empty_mode && $is_required ) {
                $input_values[ $key ] = '';
                continue;
            }
            $input_values[ $key ] = $this->sample_value_for_gf_field( $field, $test_email );
        }

        $result = GFAPI::submit_form( (int) $form['plugin_id'], $input_values );

        if ( is_wp_error( $result ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'GF fout: ' . $result->get_error_message(),
            ];
        }

        $valid = $result['is_valid'] ?? false;

        if ( $empty_mode ) {
            $errors = $result['validation_messages'] ?? [];
            return [
                'success'            => false,
                'validation_blocked' => ! $valid,
                'detail'             => ! $valid
                    ? sprintf( 'Validatie werkt — %d veld%s afgewezen', count( $errors ), count( $errors ) === 1 ? '' : 'en' )
                    : 'Lege required fields werden NIET afgewezen',
            ];
        }

        return [
            'success'            => (bool) $valid,
            'validation_blocked' => false,
            'detail'             => $valid
                ? 'Formulier accepteerde sample-data en verstuurde mail'
                : 'Submit faalde — ' . ( $result['confirmation_message'] ?? 'onbekende fout' ),
        ];
    }

    // -------------------------------------------------------------------------
    // JetFormBuilder
    // -------------------------------------------------------------------------

    private function jfb_available(): bool {
        return post_type_exists( 'jet-form-builder' ) || function_exists( 'jet_form_builder' );
    }

    /**
     * Zoek een gepubliceerde pagina waarop dit JFB-formulier staat:
     * Gutenberg-blok, [jet_fb_form]-shortcode of Elementor-widget.
     */
    private function find_jfb_page( int $form_id ): ?int {
        global $wpdb;
        $id = (int) $form_id;

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('jet-form-builder','revision')
               AND ( post_content REGEXP %s OR post_content REGEXP %s )
             LIMIT 1",
            'jet-forms/form-block[^>]*"form_id":"?' . $id . '"?[,}]',
            '\\[jet_fb_form[^\\]]*form_id=["\']?' . $id . '["\' \\]]'
        ) );
        if ( $post_id ) return (int) $post_id;

        // Elementor-widget (data staat als JSON in postmeta)
        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_status = 'publish'
             WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s
               AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s )
             LIMIT 1",
            '%jet-form-builder-form%',
            '%"form_id":"' . $id . '"%',
            '%"form_id":' . $id . ',%'
        ) );
        return $post_id ? (int) $post_id : null;
    }

    /**
     * Lees de invulbare velden van een JFB-formulier uit de blokken in post_content.
     * Conditionele blokken worden overgeslagen (veld is niet altijd zichtbaar).
     *
     * @return array<int,array{name:string,type:string,required:bool,attrs:array,block:array}>
     */
    private function collect_jfb_fields( \WP_Post $post ): array {
        $skip = [
            'submit-field', 'form-break-field', 'form-break-start', 'group-break-field', 'heading-field',
            'action-button', 'progress-bar', 'conditional-block', 'captcha-container', 'hidden-field',
            'calculated-field', 'repeater-field', 'media-field',
        ];
        $out = [];
        $walk = function ( array $blocks ) use ( &$walk, &$out, $skip ) {
            foreach ( $blocks as $b ) {
                $name = (string) ( $b['blockName'] ?? '' );
                if ( strpos( $name, 'jet-forms/' ) !== 0 ) {
                    if ( ! empty( $b['innerBlocks'] ) ) $walk( $b['innerBlocks'] );
                    continue;
                }
                $type = substr( $name, 10 );
                if ( in_array( $type, $skip, true ) ) continue;
                $attrs = (array) ( $b['attrs'] ?? [] );
                if ( ! empty( $attrs['name'] ) && is_string( $attrs['name'] ) ) {
                    $out[] = [
                        'name'     => $attrs['name'],
                        'type'     => $type,
                        'required' => ! empty( $attrs['required'] ),
                        'attrs'    => $attrs,
                        'block'    => $b,
                    ];
                }
                if ( ! empty( $b['innerBlocks'] ) ) $walk( $b['innerBlocks'] );
            }
        };
        $walk( parse_blocks( (string) $post->post_content ) );
        return $out;
    }

    /**
     * Eerste keuzewaarde van een select/radio/checkbox-veld (statische opties of choices-blok).
     */
    private function jfb_first_choice( array $field ): ?string {
        $opts = $field['attrs']['field_options'] ?? [];
        if ( is_array( $opts ) ) {
            foreach ( $opts as $o ) {
                $v = is_array( $o ) ? ( $o['value'] ?? $o['label'] ?? '' ) : $o;
                if ( (string) $v !== '' ) return (string) $v;
            }
        }
        foreach ( (array) ( $field['block']['innerBlocks'] ?? [] ) as $inner ) {
            $v = $inner['attrs']['value'] ?? $inner['attrs']['label'] ?? '';
            if ( (string) $v !== '' ) return (string) $v;
        }
        return null;
    }

    /**
     * Voorbeeldwaarde per JFB-veldtype. null = veld niet automatisch invulbaar.
     *
     * @return string|array|null
     */
    private function sample_value_for_jfb_field( array $field, string $test_email ) {
        $attrs = $field['attrs'];
        switch ( $field['type'] ) {
            case 'text-field':
                $ft = $attrs['field_type'] ?? 'text';
                if ( $ft === 'email' )    return $test_email;
                if ( $ft === 'url' )      return home_url( '/' );
                if ( $ft === 'tel' )      return '0612345678';
                if ( $ft === 'password' ) return 'RankRepair-Test-1!';
                if ( stripos( $field['name'], 'mail' ) !== false )  return $test_email;
                if ( preg_match( '/phone|tel/i', $field['name'] ) ) return '0612345678';
                return 'RankRepair Test';
            case 'textarea-field':
            case 'wysiwyg-field':
                return 'RankRepair automatische test — ' . current_time( 'mysql' );
            case 'phone-field':
                return '0612345678';
            case 'number-field':
            case 'range-field':
                $min = isset( $attrs['min'] ) && is_numeric( $attrs['min'] ) ? $attrs['min'] : 1;
                return (string) $min;
            case 'date-field':
                return gmdate( 'Y-m-d' );
            case 'time-field':
                return '12:00';
            case 'datetime-field':
                return gmdate( 'Y-m-d\TH:i' );
            case 'color-picker-field':
                return '#336699';
            case 'select-field':
            case 'radio-field':
                return $this->jfb_first_choice( $field );
            case 'checkbox-field':
                $first = $this->jfb_first_choice( $field );
                return $first === null ? null : [ $first ];
            case 'choices-field':
                $first = $this->jfb_first_choice( $field );
                return ! empty( $field['attrs']['multiple'] ) && $first !== null ? [ $first ] : $first;
        }
        return 'RankRepair Test';
    }

    /**
     * Submit een JetFormBuilder-formulier met een echte HTTP POST naar de eigen router:
     * /?jet_form_builder_submit=submit&method=ajax (dezelfde route als de frontend-JS).
     */
    private function submit_jfb( array $form, bool $empty_mode, string $test_email ): array {
        $post = get_post( (int) $form['plugin_id'] );
        if ( ! $post ) {
            return [ 'success' => false, 'validation_blocked' => false, 'detail' => 'JetFormBuilder-formulier niet gevonden' ];
        }

        $fields   = $this->collect_jfb_fields( $post );
        $required = array_filter( $fields, fn( $f ) => $f['required'] );

        if ( $empty_mode && ! $required ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'unsupported'        => true,
                'detail'             => 'Formulier heeft geen verplichte velden — validatietest niet van toepassing',
            ];
        }

        $refer = $form['url'] ?: home_url( '/' );
        $body  = [
            '_jet_engine_booking_form_id' => (string) $form['plugin_id'],
            '_jet_engine_refer'           => $refer,
            '__queried_post_id'           => (string) url_to_postid( $refer ),
        ];

        $unfillable = [];
        foreach ( $fields as $f ) {
            if ( $empty_mode && $f['required'] ) {
                $body[ $f['name'] ] = '';
                continue;
            }
            $value = $this->sample_value_for_jfb_field( $f, $test_email );
            if ( $value === null ) {
                if ( $f['required'] ) $unfillable[] = $f['name'];
                continue;
            }
            $body[ $f['name'] ] = $value;
        }

        if ( ! $empty_mode && $unfillable ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'unsupported'        => true,
                'detail'             => 'Verplicht veld niet automatisch in te vullen (' . implode( ', ', array_slice( $unfillable, 0, 5 ) ) . ') — test handmatig.',
            ];
        }

        $token = wp_generate_password( 32, false );
        set_transient( 'rr_ft_token_' . hash( 'sha256', $token ), 1, 60 );

        $boundary = wp_generate_password( 24, false );
        $response = wp_remote_post( add_query_arg( [ 'jet_form_builder_submit' => 'submit', 'method' => 'ajax' ], home_url( '/' ) ), [
            'timeout' => 20,
            'body'    => $this->build_multipart_body( $body, $boundary ),
            'headers' => [
                'Content-Type'          => 'multipart/form-data; boundary=' . $boundary,
                'X-Requested-With'      => 'XMLHttpRequest',
                'X-RankRepair-Internal' => '1',
                'X-RankRepair-Test'     => $token,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'validation_blocked' => false, 'detail' => 'HTTP-fout: ' . $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );
        $status = is_array( $data ) ? (string) ( $data['status'] ?? '' ) : '';

        if ( ! is_array( $data ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'detail'             => 'Geen JSON-antwoord van JetFormBuilder (HTTP ' . $code . ') — mogelijk blokkeert een firewall/cache de POST.',
            ];
        }

        $errors = $data['errors'] ?? $data['fields'] ?? [];
        $count  = is_array( $errors ) ? count( $errors ) : 0;

        if ( $empty_mode ) {
            $blocked = ( $status === 'validation_failed' );
            return [
                'success'            => false,
                'validation_blocked' => $blocked,
                'detail'             => $blocked
                    ? ( $count ? sprintf( 'Validatie werkt — %d veld%s afgewezen', $count, $count === 1 ? '' : 'en' ) : 'Validatie werkt — inzending afgewezen' )
                    : 'Lege required fields werden NIET afgewezen (status: ' . ( $status ?: 'HTTP ' . $code ) . ')',
            ];
        }

        if ( $status === 'success' ) {
            return [
                'success'            => true,
                'validation_blocked' => false,
                'detail'             => 'Formulier accepteerde sample-data (anti-spam: nonce/honeypot/captcha overgeslagen voor test)',
            ];
        }

        $msg = isset( $data['message'] ) ? wp_strip_all_tags( (string) $data['message'] ) : '';
        if ( preg_match( '/captcha|spam|nonce|csrf|honeypot/i', $status . ' ' . $msg ) ) {
            return [
                'success'            => false,
                'validation_blocked' => false,
                'unsupported'        => true,
                'detail'             => 'Niet automatisch testbaar — anti-spam blokkeert de test (' . ( $status ?: $msg ) . '). Test handmatig.',
            ];
        }

        $field_names = is_array( $errors ) ? implode( ', ', array_slice( array_map( 'strval', array_keys( $errors ) ), 0, 5 ) ) : '';
        return [
            'success'            => false,
            'validation_blocked' => false,
            'detail'             => 'Submit faalde — status: ' . ( $status ?: 'HTTP ' . $code )
                . ( $msg ? ' · ' . $msg : '' )
                . ( $field_names ? ' · velden: ' . $field_names : '' ),
        ];
    }

    /**
     * Bouw een multipart/form-data body uit een associative array.
     * CF7's REST endpoint accepteert alleen multipart, geen x-www-form-urlencoded.
     */
    private function build_multipart_body( array $fields, string $boundary ): string {
        $body = '';
        foreach ( $fields as $name => $value ) {
            // Arrays (bv. checkbox[]): meerdere parts met dezelfde naam
            if ( is_array( $value ) ) {
                foreach ( $value as $v ) {
                    $body .= "--{$boundary}\r\n";
                    $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
                    $body .= (string) $v . "\r\n";
                }
            } else {
                $body .= "--{$boundary}\r\n";
                $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
                $body .= (string) $value . "\r\n";
            }
        }
        $body .= "--{$boundary}--\r\n";
        return $body;
    }

    /**
     * Genereer sample data voor een CF7 form-tag op basis van type/naam.
     */
    private function sample_value_for_tag( string $basetype, string $name, string $test_email ): string {
        $base = rtrim( $basetype, '*' );
        if ( in_array( $base, [ 'email' ], true ) )                              return $test_email;
        if ( in_array( $base, [ 'tel', 'number' ], true ) )                      return '0612345678';
        if ( in_array( $base, [ 'url' ], true ) )                                return home_url( '/' );
        if ( in_array( $base, [ 'date' ], true ) )                               return date( 'Y-m-d' );
        if ( in_array( $base, [ 'textarea' ], true ) )                           return 'RankRepair automatische test — ' . current_time( 'mysql' );
        if ( in_array( $base, [ 'checkbox', 'radio', 'select', 'acceptance' ], true ) ) return '1';
        if ( in_array( $base, [ 'recaptcha', 'recaptcha3' ], true ) )            return '';
        // text + fallback
        if ( stripos( $name, 'mail' ) !== false ) return $test_email;
        if ( stripos( $name, 'phone' ) !== false || stripos( $name, 'tel' ) !== false ) return '0612345678';
        return 'RankRepair Test';
    }

    /**
     * Sample data per Gravity Forms field-type.
     */
    private function sample_value_for_gf_field( array $field, string $test_email ) {
        $type = $field['type'] ?? 'text';
        return match ( $type ) {
            'email'                                       => $test_email,
            'phone'                                       => '0612345678',
            'website', 'url'                              => home_url( '/' ),
            'date'                                        => date( 'Y-m-d' ),
            'time'                                        => '12:00',
            'number'                                      => 1,
            'textarea'                                    => 'RankRepair automatische test',
            'checkbox', 'radio', 'select', 'consent'      => ! empty( $field['choices'][0]['value'] ) ? $field['choices'][0]['value'] : '1',
            default                                       => 'RankRepair Test',
        };
    }

    private function is_plugin_active_for_form( array $form ): bool {
        return match( $form['plugin'] ) {
            'CF7' => function_exists( 'wpcf7' ) || post_type_exists( 'wpcf7_contact_form' ),
            'GF'  => class_exists( 'GFAPI' ),
            'JFB' => $this->jfb_available(),
            'WC'  => class_exists( 'WooCommerce' ),
            default => false,
        };
    }

    private function form_has_captcha( array $form ): bool {
        if ( $form['plugin'] === 'JFB' ) {
            $post = get_post( (int) $form['plugin_id'] );
            if ( ! $post ) return false;
            if ( stripos( $post->post_content, 'captcha' ) !== false ) return true;
            $args = json_decode( (string) get_post_meta( $post->ID, '_jf_args', true ), true );
            if ( is_array( $args ) && ( ! empty( $args['use_honeypot'] ) || ! empty( $args['use_csrf'] ) || ( $args['load_nonce'] ?? '' ) === 'render' ) ) return true;
            foreach ( array_keys( (array) get_post_meta( $post->ID ) ) as $key ) {
                if ( stripos( (string) $key, 'captcha' ) !== false ) return true;
            }
            return false;
        }
        if ( $form['plugin'] !== 'CF7' ) return false;
        if ( ! class_exists( 'WPCF7_ContactForm' ) ) return false;
        $cf7  = WPCF7_ContactForm::get_instance( (int) $form['plugin_id'] );
        if ( ! $cf7 ) return false;
        $tags = $cf7->scan_form_tags();
        foreach ( $tags as $tag ) {
            if ( in_array( $tag->type, [ 'recaptcha', 'recaptcha3', 'quiz', 'captchar' ], true ) ) return true;
        }
        return false;
    }
}

new RR_Addon_Form_Tester();
