<?php
/**
 * Dubbele basisschema's voorkomen als Yoast ze al uitgeeft.
 *
 * Yoast geeft op elke pagina Organization, BreadcrumbList (en op de homepage WebSite) uit.
 * RankRepair draait ná Yoast en bouwde die types, of de versie uit een template, nog een keer
 * erbij. Met de instelling `rr_sd_skip_yoast_duplicates` is Yoast de basis en vult RankRepair
 * alleen aan wat Yoast niet heeft (ProductGroup/ItemList, FAQPage, eigen types enzovoort).
 *
 * De logica staat los van WordPress zodat ze zonder site te testen is.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_SD_Dedupe {

    /** Types waarvan een tweede exemplaar naast dat van Yoast altijd een dubbele is. */
    const BASE_TYPES = ['Organization', 'BreadcrumbList', 'WebSite'];

    /** @var string[] Types die Yoast op deze pagina heeft uitgegeven. */
    private static $yoast_types = [];

    /** Is de instelling aan? */
    public static function enabled(): bool {
        return get_option('rr_sd_skip_yoast_duplicates', '0') === '1';
    }

    /** Optienaam van de schakelaar die de losse BreadcrumbList van WooCommerce uitzet. */
    const OPTION_WC_BREADCRUMB = 'rr_sd_disable_wc_breadcrumb';

    /**
     * Staat de schakelaar aan, en is WooCommerce actief? Standaard uit, zodat er bij bestaande
     * klanten niets verandert na een update.
     */
    public static function wc_breadcrumb_disabled(): bool {
        return get_option(self::OPTION_WC_BREADCRUMB, '0') === '1' && class_exists('WooCommerce');
    }

    /**
     * Filter-callback voor `woocommerce_structured_data_breadcrumblist`.
     * Een leeg array heeft geen @type, dus WC_Structured_Data::set_data() slaat het over en
     * WooCommerce geeft geen BreadcrumbList uit. De zichtbare broodkruimel blijft ongemoeid:
     * die komt uit woocommerce_breadcrumb(), niet uit dit filter.
     *
     * @param mixed $markup
     * @return mixed
     */
    public static function filter_wc_breadcrumb($markup) {
        return self::wc_breadcrumb_disabled() ? [] : $markup;
    }

    public static function register_hooks(): void {
        add_filter('woocommerce_structured_data_breadcrumblist', [__CLASS__, 'filter_wc_breadcrumb'], 10, 1);
        // Alleen meekijken: de graph gaat ongewijzigd door. Laat prioriteit hoog zodat we de
        // eindstand zien, ook na aanpassingen van andere plugins.
        add_filter('wpseo_schema_graph', [__CLASS__, 'record_yoast_graph'], 99, 2);
    }

    /** Filter-callback: onthoud welke basistypes Yoast uitgeeft. */
    public static function record_yoast_graph($graph, $context = null) {
        self::$yoast_types = self::base_types_in(is_array($graph) ? $graph : []);
        return $graph;
    }

    /** @return string[] */
    public static function yoast_types(): array {
        return self::$yoast_types;
    }

    /** Voor tests. */
    public static function reset(): void {
        self::$yoast_types = [];
    }

    /**
     * Welke basistypes staan er in een lijst nodes.
     *
     * @param array $nodes
     * @return string[]
     */
    public static function base_types_in(array $nodes): array {
        $found = [];
        foreach ($nodes as $node) {
            foreach (self::types_of($node) as $type) {
                if (in_array($type, self::BASE_TYPES, true)) {
                    $found[$type] = $type;
                }
            }
        }
        return array_values($found);
    }

    /**
     * Haal nodes weg waarvan het type al door Yoast wordt uitgegeven.
     *
     * @param array    $nodes       Nodes uit een template of eigen JSON.
     * @param string[] $yoast_types Types die Yoast uitgeeft.
     * @return array
     */
    public static function drop_duplicates(array $nodes, array $yoast_types): array {
        if (!$yoast_types) {
            return $nodes;
        }
        $out = [];
        foreach ($nodes as $node) {
            $types = self::types_of($node);
            $dup   = false;
            foreach ($types as $type) {
                if (in_array($type, self::BASE_TYPES, true) && in_array($type, $yoast_types, true)) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function types_of($node): array {
        if (!is_array($node) || !isset($node['@type'])) {
            return [];
        }
        $type = $node['@type'];
        return array_values(array_filter(is_array($type) ? $type : [$type], 'is_string'));
    }
}
