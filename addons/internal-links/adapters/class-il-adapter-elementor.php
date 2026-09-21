<?php
/**
 * Elementor-adapter: leest de widgetboom uit _elementor_data.
 *
 * Elementor bewaart de hele pagina als JSON in postmeta. Elk element heeft een
 * eigen id, en dat gebruiken we als adres — die id verandert niet als je elders
 * op de pagina iets toevoegt, in tegenstelling tot een positie-index.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Adapter_Elementor extends IL_Adapter_Base {

    /**
     * Widgets met lopende tekst, en in welk instellingsveld die tekst staat.
     * Alleen velden waarin een <a> geen layout stukmaakt.
     */
    private static $text_widgets = [
        'text-editor' => ['field' => 'editor', 'kind' => 'paragraph'],
        'heading'     => ['field' => 'title',  'kind' => 'heading'],
    ];

    public function slug() {
        return 'elementor';
    }

    public function label() {
        return 'Elementor';
    }

    public function priority() {
        return 90; // wint van Gutenberg: post_content is bij Elementor niet de bron
    }

    public function detect(WP_Post $post) {
        if (get_post_meta($post->ID, '_elementor_edit_mode', true) !== 'builder') {
            return false;
        }
        return $this->data($post) !== null;
    }

    public function read(WP_Post $post) {
        $data = $this->data($post);
        if ($data === null) {
            return [];
        }
        return self::collect($data);
    }

    /** Pure boomwandeling: Elementor-elementen in, segmenten uit. */
    public static function collect(array $elements) {
        $out = [];
        self::walk($elements, $out);
        return $out;
    }

    private static function walk(array $elements, array &$out) {
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }

            $type = isset($el['widgetType']) ? (string) $el['widgetType'] : '';
            if (isset(self::$text_widgets[$type], $el['id'])) {
                $spec  = self::$text_widgets[$type];
                $value = isset($el['settings'][$spec['field']]) ? $el['settings'][$spec['field']] : '';
                if (is_string($value) && trim($value) !== '') {
                    $out[] = [
                        'ref'  => 'e:' . $el['id'] . '.' . $spec['field'],
                        'html' => $value,
                        'kind' => $spec['kind'],
                    ];
                }
            }

            if (!empty($el['elements']) && is_array($el['elements'])) {
                self::walk($el['elements'], $out);
            }
        }
    }

    public function apply(WP_Post $post, array $changes) {
        $data = $this->data($post);
        if ($data === null) {
            return new WP_Error('il_elementor_data', __('Elementor-data kon niet worden gelezen.', 'rankrepair'));
        }

        $applied = 0;
        foreach ($changes as $ref => $html) {
            if (strpos($ref, 'e:') !== 0) {
                continue;
            }
            $rest = substr($ref, 2);
            $dot  = strrpos($rest, '.');
            if ($dot === false) {
                continue;
            }
            $id    = substr($rest, 0, $dot);
            $field = substr($rest, $dot + 1);

            if (self::set_by_id($data, $id, $field, (string) $html)) {
                $applied++;
            }
        }

        if ($applied === 0) {
            return new WP_Error('il_ref_missing', __('Het bedoelde Elementor-element is niet meer gevonden.', 'rankrepair'));
        }

        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return new WP_Error('il_elementor_encode', __('Elementor-data kon niet worden opgeslagen.', 'rankrepair'));
        }

        if (function_exists('wp_save_post_revision') && wp_revisions_enabled($post)) {
            wp_save_post_revision($post->ID);
        }

        update_post_meta($post->ID, '_elementor_data', wp_slash($json));
        $this->bust_cache($post->ID);

        return true;
    }

    public static function set_by_id(array &$elements, $id, $field, $html) {
        foreach ($elements as &$el) {
            if (!is_array($el)) {
                continue;
            }
            if (isset($el['id']) && (string) $el['id'] === (string) $id) {
                if (!isset($el['settings']) || !is_array($el['settings'])) {
                    $el['settings'] = [];
                }
                $el['settings'][$field] = $html;
                return true;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                if (self::set_by_id($el['elements'], $id, $field, $html)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array|null */
    private function data(WP_Post $post) {
        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($raw)) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $data = json_decode((string) $raw, true);
        return (is_array($data) && !empty($data)) ? $data : null;
    }

    /**
     * Elementor bewaart per post gegenereerde CSS en (vanaf 3.22) gerenderde HTML.
     * Zonder deze opruiming blijft de oude pagina zichtbaar.
     */
    private function bust_cache($post_id) {
        delete_post_meta($post_id, '_elementor_css');
        delete_post_meta($post_id, '_elementor_element_cache');
        delete_post_meta($post_id, '_elementor_inline_svg');
        clean_post_cache($post_id);

        if (did_action('elementor/loaded') && class_exists('\Elementor\Plugin')) {
            $plugin = \Elementor\Plugin::$instance;
            if (isset($plugin->files_manager)) {
                $plugin->files_manager->clear_cache();
            }
        }
    }

    public function snapshot(WP_Post $post) {
        return [
            '_elementor_data' => (string) get_post_meta($post->ID, '_elementor_data', true),
        ];
    }

    public function restore(WP_Post $post, array $snapshot) {
        if (!isset($snapshot['_elementor_data']) || $snapshot['_elementor_data'] === '') {
            return new WP_Error('il_no_snapshot', __('Geen snapshot beschikbaar.', 'rankrepair'));
        }
        update_post_meta($post->ID, '_elementor_data', wp_slash((string) $snapshot['_elementor_data']));
        $this->bust_cache($post->ID);
        return true;
    }
}
