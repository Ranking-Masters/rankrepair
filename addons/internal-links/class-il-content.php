<?php
/**
 * Content-laag: één manier om elke editor uit te lezen en terug te schrijven.
 *
 * Planner, gates en inserter kennen alleen segmenten. Ze weten niet of een post
 * in Gutenberg, de klassieke editor of Elementor is gemaakt. Een nieuwe editor
 * ondersteunen is daarom één adapter toevoegen, niet de plaatsingslogica aanpassen.
 *
 * Een segment ziet er zo uit:
 *   [
 *     'ref'   => 'b:2.1',       adapter-specifiek adres
 *     'html'  => '<p>…</p>',    rijke tekst
 *     'text'  => '…',           platte tekst
 *     'kind'  => 'paragraph',   paragraph | heading | list | other
 *     'index' => 3,             documentvolgorde
 *   ]
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/adapters/class-il-adapter-base.php';
require_once __DIR__ . '/adapters/class-il-adapter-elementor.php';
require_once __DIR__ . '/adapters/class-il-adapter-gutenberg.php';
require_once __DIR__ . '/adapters/class-il-adapter-classic.php';

class IL_Content {

    /** @var IL_Adapter_Base[]|null */
    private static $adapters = null;

    /** Segmenten per post, binnen één request. Parsen is het duurste dat we doen. */
    private static $segment_cache = [];

    /**
     * Geregistreerde adapters, hoogste prioriteit eerst.
     * Uitbreiden kan via de filter — bijvoorbeeld voor WPBakery, Divi of een ACF-veld.
     */
    public static function adapters() {
        if (self::$adapters !== null) {
            return self::$adapters;
        }

        $classes = apply_filters('rr_il_content_adapters', [
            'IL_Adapter_Elementor',
            'IL_Adapter_Gutenberg',
            'IL_Adapter_Classic',
        ]);

        $instances = [];
        foreach ((array) $classes as $class) {
            if (is_string($class) && class_exists($class)) {
                $instances[] = new $class();
            } elseif ($class instanceof IL_Adapter_Base) {
                $instances[] = $class;
            }
        }

        usort($instances, function ($a, $b) {
            return $b->priority() <=> $a->priority();
        });

        self::$adapters = $instances;
        return self::$adapters;
    }

    /** Alleen voor tests: registry opnieuw laten opbouwen. */
    public static function flush_adapters() {
        self::$adapters = null;
    }

    /**
     * De adapter die deze post aankan. Classic is de vangnet-adapter en
     * herkent altijd, dus dit geeft nooit null terug.
     *
     * @return IL_Adapter_Base|null
     */
    public static function adapter_for($post) {
        $post = get_post($post);
        if (!$post) {
            return null;
        }
        foreach (self::adapters() as $adapter) {
            if ($adapter->detect($post)) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * Segmenten van een post, in documentvolgorde.
     *
     * @return array segmenten, elk met de velden uit de kop van dit bestand
     */
    public static function segments($post) {
        $post = get_post($post);
        if (!$post) {
            return [];
        }
        if (isset(self::$segment_cache[$post->ID])) {
            return self::$segment_cache[$post->ID];
        }

        $adapter = self::adapter_for($post);
        if (!$adapter) {
            return [];
        }

        $segments = $adapter->read($post);
        $index    = 0;
        $out      = [];

        foreach ($segments as $seg) {
            $html = isset($seg['html']) ? (string) $seg['html'] : '';
            $text = IL_Text::plain_text($html);
            if ($text === '') {
                continue;
            }
            $seg['text']    = $text;
            $seg['index']   = $index++;
            $seg['kind']    = isset($seg['kind']) ? $seg['kind'] : 'paragraph';
            $seg['adapter'] = $adapter->slug();
            $out[] = $seg;
        }

        self::$segment_cache[$post->ID] = $out;
        return $out;
    }

    /** Na een schrijfactie is de cache niet meer waar. */
    public static function flush_segments($post_id = null) {
        if ($post_id === null) {
            self::$segment_cache = [];
        } else {
            unset(self::$segment_cache[(int) $post_id]);
        }
    }

    /**
     * Alleen de segmenten waar een link in mag landen: lopende tekst, geen kop.
     * De eerste tekstalinea valt af — gate G6 blokkeert intro-links sowieso, maar
     * we bieden hem ook niet aan de planner aan.
     */
    public static function linkable_segments($post) {
        $all  = self::segments($post);
        $body = [];
        foreach ($all as $seg) {
            if ($seg['kind'] === 'heading') {
                continue;
            }
            if (IL_Text::word_count($seg['text']) < 12) {
                continue; // te kort voor een natuurlijke link
            }
            $body[] = $seg;
        }
        if (count($body) <= 1) {
            return []; // alleen een intro: niets om veilig in te linken
        }
        array_shift($body);
        return $body;
    }

    /**
     * Schrijf gewijzigde segmenten terug.
     *
     * @param array $changes ref => nieuwe html
     * @return true|WP_Error
     */
    public static function apply($post, array $changes) {
        $post    = get_post($post);
        $adapter = self::adapter_for($post);
        if (!$adapter) {
            return new WP_Error('il_no_adapter', __('Geen content-adapter voor deze pagina.', 'rankrepair'));
        }
        if (empty($changes)) {
            return true;
        }
        self::flush_segments($post->ID);
        return $adapter->apply($post, $changes);
    }

    /** Opslag-snapshot voor terugdraaien. */
    public static function snapshot($post) {
        $post    = get_post($post);
        $adapter = self::adapter_for($post);
        return $adapter ? $adapter->snapshot($post) : [];
    }

    /** @return true|WP_Error */
    public static function restore($post, array $snapshot) {
        $post    = get_post($post);
        $adapter = self::adapter_for($post);
        if (!$adapter) {
            return new WP_Error('il_no_adapter', __('Geen content-adapter voor deze pagina.', 'rankrepair'));
        }
        self::flush_segments($post->ID);
        return $adapter->restore($post, $snapshot);
    }

    /** Naam van de editor, voor weergave in de UI. */
    public static function editor_label($post) {
        $adapter = self::adapter_for($post);
        return $adapter ? $adapter->label() : __('onbekend', 'rankrepair');
    }

    /**
     * Alles waar een interne link in kán zitten, als één brok HTML.
     *
     * Let op het verschil met segments(): dát zijn de plekken waar wíj een link
     * mogen plaatsen — lopende tekst. Voor het tellen van BESTAANDE links moet je
     * juist alles hebben: knoppen, tabellen, afbeeldingen, citaten, blokken van
     * andere plugins. Een pagina die vanuit een knop gelinkt wordt is geen orphan,
     * en een bron die al via een knop naar het doel linkt mag er geen tweede
     * link bij krijgen.
     */
    public static function link_html($post) {
        $post = get_post($post);
        if (!$post) {
            return '';
        }

        $html = function_exists('do_blocks') ? do_blocks($post->post_content) : $post->post_content;

        $raw = get_post_meta($post->ID, '_elementor_data', true);
        if (!empty($raw)) {
            $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (is_array($data)) {
                $html .= ' ' . self::elementor_link_html($data);
            }
        }

        return $html;
    }

    /**
     * Loopt de Elementor-boom af en maakt er iets van dat DOMDocument kan lezen.
     *
     * De JSON zelf aan de parser voeren werkt niet: daar staan de aanhalingstekens
     * en schuine strepen in geëscapete vorm. Tekstvelden gaan er daarom ontdaan
     * doorheen, en losse URL-instellingen (knoppen, iconen) maken we tot een
     * kaal <a>-element zodat ze meetellen als link.
     */
    private static function elementor_link_html(array $node) {
        $out = '';

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if ($key === 'link' && !empty($value['url']) && is_string($value['url'])) {
                    $out .= '<a href="' . esc_url($value['url']) . '"></a> ';
                    continue;
                }
                $out .= self::elementor_link_html($value);
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (strpos($value, '<a ') !== false || strpos($value, '<A ') !== false) {
                $out .= $value . ' ';
            } elseif ($key === 'url' && preg_match('#^(https?:)?/#i', $value)) {
                $out .= '<a href="' . esc_url($value) . '"></a> ';
            }
        }

        return $out;
    }
}
