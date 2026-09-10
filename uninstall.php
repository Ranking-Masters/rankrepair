<?php
/**
 * RankRepair - Uninstall
 * Clean up all plugin data when uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Informeer Level 4 dat deze site verdwijnt (best effort, niet blokkerend)
$site_id = get_option( 'rr_agent_site_id' );
$api_key = get_option( 'rr_agent_api_key' );
if ( $site_id && $api_key ) {
    $level4 = defined( 'RR_LEVEL4_URL' ) && RR_LEVEL4_URL
        ? rtrim( RR_LEVEL4_URL, '/' )
        : ( get_option( 'rr_level4_url' ) ? rtrim( get_option( 'rr_level4_url' ), '/' ) : 'https://level4.rankingmasters.nl' );

    $body = wp_json_encode( [ 'reason' => 'uninstall', 'at' => time() ] );
    $sig  = hash_hmac( 'sha256', $body, $api_key );
    wp_remote_post( $level4 . '/api/wp-agent/unregister', [
        'timeout' => 5,
        'blocking' => false,
        'headers' => [
            'Content-Type'   => 'application/json',
            'X-RR-Site-Id'   => $site_id,
            'X-RR-Signature' => $sig,
        ],
        'body' => $body,
    ] );
}

// Stop cron
$next = wp_next_scheduled( 'rr_agent_heartbeat_event' );
if ( $next ) {
    wp_unschedule_event( $next, 'rr_agent_heartbeat_event' );
}

// Delete options
$options = [
    'rr_pagespeed_api_key',
    'rr_seranking_api_key',
    'rr_db_version',
    'rr_agent_site_id',
    'rr_agent_api_key',
    'rr_agent_api_key_previous',
    'rr_agent_api_key_rotated_at',
    'rr_agent_registered',
    'rr_agent_last_heartbeat_at',
    'rr_agent_last_response',
    'rr_agent_linked_client',
    'rr_level4_url',
    'rr_malware_scan_result',
    'rr_malware_scan_progress',
];

foreach ($options as $option) {
    delete_option($option);
}

// Geplande malware-scan opruimen.
wp_clear_scheduled_hook('rr_malware_scan_event');

// Drop custom tables
$tables = [
    $wpdb->prefix . 'rr_pagespeed_results',
    $wpdb->prefix . 'rr_meta_data',
    $wpdb->prefix . 'rr_internal_links',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

// Clear transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rr_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rr_%'");
