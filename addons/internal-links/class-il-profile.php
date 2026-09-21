<?php
/**
 * Het interne linkprofiel: meten en begrenzen.
 *
 * Fase 1 stuurde op één getal — inkomende links per pagina. Daarmee kun je 555
 * orphans oplossen en eindigen met een profiel dat er machinaal uitziet: alles
 * hetzelfde anker, alles naar dezelfde hub, drie links in één alinea.
 *
 * Deze klasse meet de vier dingen die een profiel natuurlijk of onnatuurlijk
 * maken, en levert tegelijk de tellingen waar de gates op toetsen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Profile {

    private static $anchor_cache       = [];
    private static $anchor_owner_cache = [];
    private static $wordcount_cache    = [];

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rr_internal_links';
    }

    /* ---------------------------------------------------------- metingen */

    /**
     * Profielmetingen over de hele site. Bedoeld voor het Data-scherm én als
     * onderbouwing bij de klant: dit is wat er veranderd is, niet alleen
     * "minder orphans".
     */
    public static function metrics() {
        global $wpdb;
        $table = self::table();

        $ids = IL_Graph_Scanner::all_post_ids();
        $total = count($ids);
        if ($total === 0) {
            return self::empty_metrics();
        }

        $inbound = array_fill_keys($ids, 0);
        $rows = $wpdb->get_results("SELECT target_id, COUNT(DISTINCT source_id) AS n FROM $table GROUP BY target_id", ARRAY_A);
        foreach ($rows as $r) {
            $tid = (int) $r['target_id'];
            if (isset($inbound[$tid])) {
                $inbound[$tid] = (int) $r['n'];
            }
        }

        $values = array_values($inbound);
        sort($values);
        $sum = array_sum($values);

        // Topzwaarte: welk deel van alle inkomende links landt op de bovenste 10%
        // van de pagina's? Boven ~50% is het profiel hub-en-spaak in plaats van web.
        $top_n     = max(1, (int) ceil($total * 0.10));
        $top_slice = array_slice($values, -$top_n);
        $top_share = $sum > 0 ? array_sum($top_slice) / $sum : 0.0;

        $median = $total % 2
            ? $values[(int) floor($total / 2)]
            : ($values[$total / 2 - 1] + $values[$total / 2]) / 2;

        // Ankerdiversiteit: unieke ankers gedeeld door het aantal links. 1.0 is
        // alles uniek, richting 0 is steeds dezelfde tekst.
        $anchor_rows = $wpdb->get_results(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT LOWER(TRIM(anchor))) AS uniq FROM $table WHERE anchor <> ''",
            ARRAY_A
        );
        $anchor_total = isset($anchor_rows[0]['total']) ? (int) $anchor_rows[0]['total'] : 0;
        $anchor_uniq  = isset($anchor_rows[0]['uniq']) ? (int) $anchor_rows[0]['uniq'] : 0;
        $anchor_div   = $anchor_total > 0 ? $anchor_uniq / $anchor_total : 0.0;

        // Wederkerigheid: A linkt naar B én B naar A. Een beetje is normaal,
        // veel betekent dat er geen richting in het profiel zit.
        $recip = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table a
             INNER JOIN $table b ON a.source_id = b.target_id AND a.target_id = b.source_id"
        );
        $edges = (int) $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(source_id,'-',target_id)) FROM $table");
        $recip_share = $edges > 0 ? min(1.0, $recip / $edges) : 0.0;

        $orphans = 0;
        $thin    = 0;
        foreach ($values as $v) {
            if ($v === 0) { $orphans++; } elseif ($v === 1) { $thin++; }
        }

        return [
            'total'            => $total,
            'orphans'          => $orphans,
            'thin'             => $thin,
            'links'            => $edges,
            'avg_inbound'      => round($sum / $total, 1),
            'median_inbound'   => round($median, 1),
            'top_share'        => round($top_share, 3),
            'anchor_diversity' => round($anchor_div, 3),
            'reciprocity'      => round($recip_share, 3),
            'placed_by_rr'     => IL_Suggestions::count_applied(),
        ];
    }

    private static function empty_metrics() {
        return [
            'total' => 0, 'orphans' => 0, 'thin' => 0, 'links' => 0,
            'avg_inbound' => 0, 'median_inbound' => 0, 'top_share' => 0,
            'anchor_diversity' => 0, 'reciprocity' => 0, 'placed_by_rr' => 0,
        ];
    }

    /**
     * Beoordeling per meting, zodat de UI kan laten zien wat er nog scheef staat
     * zonder dat iemand de getallen hoeft te interpreteren.
     */
    public static function verdicts(array $m) {
        $out = [];

        $out['orphans'] = $m['orphans'] === 0
            ? ['ok', __('Geen weespagina\'s.', 'rankrepair')]
            : ['warn', sprintf(__('%d pagina\'s zonder enige inkomende link.', 'rankrepair'), $m['orphans'])];

        $out['top_share'] = $m['top_share'] <= 0.5
            ? ['ok', __('Links zijn redelijk verdeeld over de site.', 'rankrepair')]
            : ['warn', sprintf(__('%d%% van alle links gaat naar de bovenste 10%% pagina\'s.', 'rankrepair'), round($m['top_share'] * 100))];

        $out['anchor_diversity'] = $m['anchor_diversity'] >= 0.5
            ? ['ok', __('Voldoende variatie in ankerteksten.', 'rankrepair')]
            : ['warn', sprintf(__('Ankerteksten herhalen zich (%d%% uniek).', 'rankrepair'), round($m['anchor_diversity'] * 100))];

        $out['reciprocity'] = $m['reciprocity'] <= 0.3
            ? ['ok', __('Weinig wederzijdse links.', 'rankrepair')]
            : ['warn', sprintf(__('%d%% van de links is wederzijds.', 'rankrepair'), round($m['reciprocity'] * 100))];

        return $out;
    }

    /* ------------------------------------------------------------- caps */

    /**
     * Hoe vaak wijst deze genormaliseerde ankertekst al naar dit doel?
     * Voedt gate G11: exact dezelfde tekst te vaak herhalen is de klassieke
     * over-optimalisatie.
     */
    public static function anchor_usage($target_id, $anchor) {
        global $wpdb;
        $target_id = (int) $target_id;
        $needle    = IL_Text::normalize_anchor($anchor);
        if ($needle === '') {
            return 0;
        }

        if (!isset(self::$anchor_cache[$target_id])) {
            $map = [];

            // Bestaande links uit de graaf …
            $rows = $wpdb->get_col($wpdb->prepare(
                'SELECT anchor FROM ' . self::table() . ' WHERE target_id = %d',
                $target_id
            ));

            // … én wat er in deze ronde al klaarstaat. Zonder dat tweede deel
            // bijt de cap pas tijdens het plaatsen, en heeft iemand intussen zes
            // keer hetzelfde voorstel zitten beoordelen.
            $rows = array_merge($rows, $wpdb->get_col($wpdb->prepare(
                'SELECT anchor FROM ' . IL_Suggestions::table() . '
                 WHERE target_id = %d AND status IN (%s, %s)',
                $target_id, IL_Suggestions::STATUS_PENDING, IL_Suggestions::STATUS_APPROVED
            )));

            foreach ($rows as $a) {
                $k = IL_Text::normalize_anchor($a);
                if ($k === '') { continue; }
                $map[$k] = isset($map[$k]) ? $map[$k] + 1 : 1;
            }
            self::$anchor_cache[$target_id] = $map;
        }

        $map = self::$anchor_cache[$target_id];
        return isset($map[$needle]) ? $map[$needle] : 0;
    }

    /**
     * Wijst deze ankertekst elders al naar een ándere pagina?
     *
     * Eén ankertekst hoort bij één bestemming. Staat "Google Ads" in het ene
     * artikel naar pagina A en in het volgende naar pagina B, dan weet een lezer
     * niet waar hij op klikt en weet een zoekmachine niet welke pagina het
     * onderwerp draagt. Dit is de belangrijkste knop voor een profiel dat als
     * redactie leest in plaats van als generator.
     *
     * @return int het andere doel, of 0
     */
    public static function anchor_claimed_by($target_id, $anchor) {
        global $wpdb;
        $needle = IL_Text::normalize_anchor($anchor);
        if ($needle === '') {
            return 0;
        }

        if (!isset(self::$anchor_owner_cache[$needle])) {
            $owner = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT target_id FROM ' . self::table() . '
                 WHERE LOWER(TRIM(anchor)) = %s LIMIT 1',
                $needle
            ));
            if ($owner === 0) {
                $owner = (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT target_id FROM ' . IL_Suggestions::table() . '
                     WHERE LOWER(TRIM(anchor)) = %s AND status IN (%s, %s, %s) LIMIT 1',
                    $needle,
                    IL_Suggestions::STATUS_PENDING,
                    IL_Suggestions::STATUS_APPROVED,
                    IL_Suggestions::STATUS_APPLIED
                ));
            }
            self::$anchor_owner_cache[$needle] = $owner;
        }

        $owner = self::$anchor_owner_cache[$needle];
        return ($owner && $owner !== (int) $target_id) ? $owner : 0;
    }

    /**
     * Aantal woorden in de lopende tekst van een post.
     *
     * Uit de index, niet uit de pagina zelf. Parsen is het duurste dat deze
     * add-on doet: het overzichtsscherm vraagt dit voor elke rij op, en over
     * 625 pagina's liep het geheugen daarmee 84 MB op — genoeg om op een host
     * met een krappe limiet een wit scherm op te leveren. De index heeft het
     * getal al, want de scan berekent het toch.
     */
    public static function word_count($post_id) {
        $post_id = (int) $post_id;
        if (isset(self::$wordcount_cache[$post_id])) {
            return self::$wordcount_cache[$post_id];
        }

        $entry = IL_Index::get($post_id);
        if ($entry !== null && isset($entry['r'])) {
            self::$wordcount_cache[$post_id] = (int) $entry['r'];
            return self::$wordcount_cache[$post_id];
        }

        // Geen (of een oude) index-rij: dan tellen we hem alsnog. Gebeurt
        // eenmalig, tot de eerstvolgende scan de index heeft bijgewerkt.
        $words = 0;
        foreach (IL_Content::segments($post_id) as $seg) {
            $words += IL_Text::word_count($seg['text']);
        }
        self::$wordcount_cache[$post_id] = $words;
        return $words;
    }

    /**
     * Hoeveel interne links mogen er nog bij op deze bronpagina voordat de
     * dichtheid boven de grens komt?
     */
    public static function density_headroom($source_id) {
        $words = self::word_count($source_id);
        if ($words <= 0) {
            return 0;
        }
        // Afronden, niet naar beneden afkappen: bij floor() krijgt een artikel van
        // 90 woorden nul links, terwijl één link daar prima natuurlijk is. Echt
        // korte teksten (onder ~50 woorden) komen met round() nog steeds op nul uit.
        $max     = (int) round(($words / 100) * IL_Config::get('density_per_100w'));
        $current = IL_Graph_Scanner::outbound_count($source_id);
        return max(0, $max - $current);
    }

    public static function flush() {
        self::$anchor_cache       = [];
        self::$anchor_owner_cache = [];
        self::$wordcount_cache    = [];
    }
}
