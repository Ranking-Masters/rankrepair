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

    public static function register_hooks(): void {
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
