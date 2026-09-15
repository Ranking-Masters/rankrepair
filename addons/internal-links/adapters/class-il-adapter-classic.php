<?php
/**
 * Klassieke editor — en tegelijk de vangnet-adapter.
 *
 * post_content is hier vrije HTML waarin lege regels de alinea's scheiden
 * (wat wpautop op de frontend omzet naar <p>). We knippen op die lege regels
 * en bewaren de scheidingstekens letterlijk, zodat samenvoegen zonder
 * wijzigingen byte-voor-byte hetzelfde oplevert.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Adapter_Classic extends IL_Adapter_Base {

    public function slug() {
        return 'classic';
    }

    public function label() {
        return __('Klassiek', 'rankrepair');
    }

    public function priority() {
        return 10; // laagste: pakt alles op wat de andere adapters laten liggen
    }

    public function detect(WP_Post $post) {
        return true;
    }

    public function read(WP_Post $post) {
        $parts = self::split($post->post_content);
        $out   = [];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // oneven index = de scheiding zelf
            }
            if (trim($part) === '') {
                continue;
            }
            $out[] = [
                'ref'  => 'c:' . $i,
                'html' => $part,
                'kind' => $this->kind_from_html($part),
            ];
        }
        return $out;
    }

    public function apply(WP_Post $post, array $changes) {
        $parts   = self::split($post->post_content);
        $applied = 0;

        foreach ($changes as $ref => $html) {
            if (strpos($ref, 'c:') !== 0) {
                continue;
            }
            $i = (int) substr($ref, 2);
            if ($i % 2 === 1 || !isset($parts[$i])) {
                continue;
            }
            $parts[$i] = (string) $html;
            $applied++;
        }

        if ($applied === 0) {
            return new WP_Error('il_ref_missing', __('De bedoelde alinea is niet meer gevonden.', 'rankrepair'));
        }

        return $this->update_post_content($post, implode('', $parts));
    }

    /**
     * Splitst op lege regels, met de scheidingen als aparte elementen op de
     * oneven indexen. implode('') geeft daarmee altijd het origineel terug.
     */
    public static function split($content) {
        $parts = preg_split('/(\R[ \t]*\R)/u', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        return $parts === false ? [(string) $content] : $parts;
    }

    public function snapshot(WP_Post $post) {
        return ['post_content' => $post->post_content];
    }

    public function restore(WP_Post $post, array $snapshot) {
        if (!isset($snapshot['post_content'])) {
            return new WP_Error('il_no_snapshot', __('Geen snapshot beschikbaar.', 'rankrepair'));
        }
        return $this->update_post_content($post, (string) $snapshot['post_content']);
    }
}
