<?php
/**
 * Basisklasse voor content-adapters.
 *
 * Een adapter vertaalt één editor naar segmenten en weer terug. Meer niet:
 * hij kent geen links, geen gates en geen AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class IL_Adapter_Base {

    /** Korte machinenaam, komt in het segment-adres terecht. */
    abstract public function slug();

    /** Leesbare naam voor de UI. */
    abstract public function label();

    /** Kan deze adapter deze post aan? */
    abstract public function detect(WP_Post $post);

    /**
     * Lees de post uit als segmenten.
     * Elk segment heeft minimaal 'ref', 'html' en 'kind'.
     *
     * @return array
     */
    abstract public function read(WP_Post $post);

    /**
     * Schrijf gewijzigde segmenten terug.
     *
     * @param array $changes ref => nieuwe html
     * @return true|WP_Error
     */
    abstract public function apply(WP_Post $post, array $changes);

    /** Opslag-snapshot waarmee restore() de post exact kan herstellen. */
    abstract public function snapshot(WP_Post $post);

    /** @return true|WP_Error */
    abstract public function restore(WP_Post $post, array $snapshot);

    /** Hoger = eerder gekozen bij detect(). */
    public function priority() {
        return 50;
    }

    /* ------------------------------------------------------------- helpers */

    /** Soort segment afleiden uit de buitenste tag. */
    protected function kind_from_html($html) {
        $html = ltrim((string) $html);
        if (preg_match('/^<\s*(h[1-6])\b/i', $html)) {
            return 'heading';
        }
        if (preg_match('/^<\s*(ul|ol|li)\b/i', $html)) {
            return 'list';
        }
        if (preg_match('/^<\s*(p|div|span)\b/i', $html) || !preg_match('/^</', $html)) {
            return 'paragraph';
        }
        return 'other';
    }

    /**
     * Post-content bijwerken zonder dat WordPress de HTML verbouwt.
     *
     * wp_update_post() draait kses over de content zodra de huidige gebruiker
     * geen unfiltered_html heeft. Dat zou onze data-attributen slopen, dus
     * schrijven we via $wpdb en verversen daarna de caches.
     *
     * @return true|WP_Error
     */
    protected function update_post_content(WP_Post $post, $content) {
        global $wpdb;

        // Eerst een revisie van de HUIDIGE inhoud, dan pas overschrijven —
        // andersom leg je de nieuwe versie vast en is het origineel weg.
        if (function_exists('wp_save_post_revision') && wp_revisions_enabled($post)) {
            wp_save_post_revision($post->ID);
        }

        $ok = $wpdb->update(
            $wpdb->posts,
            ['post_content' => $content, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)],
            ['ID' => $post->ID],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($ok === false) {
            return new WP_Error('il_db_write', __('Content kon niet worden opgeslagen.', 'rankrepair') . ' ' . $wpdb->last_error);
        }

        clean_post_cache($post->ID);

        return true;
    }
}
