<?php
/**
 * Gutenberg-adapter: leest de blokkenboom uit post_content.
 *
 * Het adres van een segment is het pad door de boom: 'b:0.2.1' is het tweede
 * kindblok van het derde kindblok van het eerste blok. Dat pad is stabiel binnen
 * één lees-/schrijfronde, wat genoeg is — we lezen en schrijven in dezelfde request.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Adapter_Gutenberg extends IL_Adapter_Base {

    /** Blokken waarvan we de innerHTML als lopende tekst behandelen. */
    private static $text_blocks = [
        'core/paragraph'  => 'paragraph',
        'core/heading'    => 'heading',
        'core/list-item'  => 'list',
        'core/verse'      => 'paragraph',
        'core/freeform'   => 'paragraph',
        'core/preformatted' => 'other',
    ];

    public function slug() {
        return 'gutenberg';
    }

    public function label() {
        return 'Gutenberg';
    }

    public function priority() {
        return 60;
    }

    public function detect(WP_Post $post) {
        return function_exists('has_blocks') && has_blocks($post->post_content);
    }

    public function read(WP_Post $post) {
        if (!function_exists('parse_blocks')) {
            return [];
        }
        return self::collect(parse_blocks($post->post_content));
    }

    /** Pure boomwandeling: blokken in, segmenten uit. */
    public static function collect(array $blocks, $prefix = '') {
        $out = [];
        self::walk($blocks, $prefix, $out);
        return $out;
    }

    private static function walk(array $blocks, $prefix, array &$out) {
        foreach ($blocks as $i => $block) {
            $path = ($prefix === '') ? (string) $i : $prefix . '.' . $i;
            $name = isset($block['blockName']) ? (string) $block['blockName'] : '';

            if (isset(self::$text_blocks[$name])) {
                $html = isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
                if (trim($html) !== '') {
                    $out[] = [
                        'ref'  => 'b:' . $path,
                        'html' => $html,
                        'kind' => self::$text_blocks[$name],
                    ];
                }
            }

            if (!empty($block['innerBlocks'])) {
                self::walk($block['innerBlocks'], $path, $out);
            }
        }
    }

    public function apply(WP_Post $post, array $changes) {
        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return new WP_Error('il_no_blocks', __('Blok-functies niet beschikbaar.', 'rankrepair'));
        }

        $blocks  = parse_blocks($post->post_content);
        $applied = 0;

        foreach ($changes as $ref => $html) {
            if (strpos($ref, 'b:') !== 0) {
                continue;
            }
            $path = explode('.', substr($ref, 2));
            if (self::set_at_path($blocks, $path, (string) $html)) {
                $applied++;
            }
        }

        if ($applied === 0) {
            return new WP_Error('il_ref_missing', __('Het bedoelde blok is niet meer gevonden.', 'rankrepair'));
        }

        return $this->update_post_content($post, serialize_blocks($blocks));
    }

    /**
     * Zet nieuwe HTML op een blokpad. Zowel innerHTML als innerContent moet mee:
     * serialize_blocks() bouwt de output uit innerContent, andere code leest innerHTML.
     */
    public static function set_at_path(array &$blocks, array $path, $html) {
        $index = (int) array_shift($path);
        if (!isset($blocks[$index])) {
            return false;
        }
        if (!empty($path)) {
            if (empty($blocks[$index]['innerBlocks'])) {
                return false;
            }
            return self::set_at_path($blocks[$index]['innerBlocks'], $path, $html);
        }

        $old = isset($blocks[$index]['innerHTML']) ? (string) $blocks[$index]['innerHTML'] : '';
        $blocks[$index]['innerHTML'] = $html;

        if (isset($blocks[$index]['innerContent']) && is_array($blocks[$index]['innerContent'])) {
            $replaced = false;
            foreach ($blocks[$index]['innerContent'] as $k => $chunk) {
                if (is_string($chunk) && $chunk === $old) {
                    $blocks[$index]['innerContent'][$k] = $html;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                // Blok zonder innerBlocks heeft precies één tekstdeel.
                foreach ($blocks[$index]['innerContent'] as $k => $chunk) {
                    if (is_string($chunk) && trim($chunk) !== '') {
                        $blocks[$index]['innerContent'][$k] = $html;
                        $replaced = true;
                        break;
                    }
                }
            }
            if (!$replaced) {
                return false;
            }
        } else {
            $blocks[$index]['innerContent'] = [$html];
        }

        return true;
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
