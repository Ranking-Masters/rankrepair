<?php
/**
 * Yoast als basis: dubbele Organization/BreadcrumbList/WebSite weglaten.
 * Run: php tests/structured-data/test-sd-dedupe.php
 */
define('ABSPATH', true);
$GLOBALS['t'] = ['opts' => [], 'filters' => []];
function get_option($n, $d = false) { return $GLOBALS['t']['opts'][$n] ?? $d; }
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['t']['filters'][$tag][] = [$cb, $prio]; }

require __DIR__ . '/../../addons/structured-data/class-sd-dedupe.php';

$fail = 0;
function ok($cond, $msg) { global $fail; if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; } echo "ok  $msg\n"; }

$yoast = [
    ['@type' => 'CollectionPage', '@id' => 'https://x.test/c/'],
    ['@type' => 'BreadcrumbList', '@id' => 'https://x.test/c/#breadcrumb'],
    ['@type' => 'Organization', '@id' => 'https://x.test/#organization'],
];
$tpl = [
    ['@type' => 'Organization', '@id' => 'https://x.test/#organization'],
    ['@type' => 'CollectionPage', '@id' => 'https://x.test/c/#collection'],
    ['@type' => 'BreadcrumbList'],
    ['@type' => 'ProductGroup', '@id' => 'https://x.test/c/#productgroup'],
    ['@type' => 'WebSite'],
];

ok(RR_SD_Dedupe::enabled() === false, 'standaard uit');
RR_SD_Dedupe::register_hooks();
ok(isset($GLOBALS['t']['filters']['wpseo_schema_graph']), 'hangt aan wpseo_schema_graph');

$t = RR_SD_Dedupe::base_types_in($yoast);
sort($t);
ok($t === ['BreadcrumbList', 'Organization'], 'herkent basistypes in Yoast-graph (CollectionPage telt niet)');

// Observer geeft de graph ongewijzigd terug
$out = RR_SD_Dedupe::record_yoast_graph($yoast, null);
ok($out === $yoast, 'observer wijzigt de Yoast-graph niet');
$rec = RR_SD_Dedupe::yoast_types(); sort($rec);
ok($rec === ['BreadcrumbList', 'Organization'], 'types onthouden');

// Alleen de dubbele basistypes verdwijnen
$kept = RR_SD_Dedupe::drop_duplicates($tpl, RR_SD_Dedupe::yoast_types());
$ktypes = array_map(fn($n) => $n['@type'], $kept);
ok($ktypes === ['CollectionPage', 'ProductGroup', 'WebSite'], 'Organization + BreadcrumbList weg; CollectionPage/ProductGroup/WebSite blijven: ' . json_encode($ktypes));

// WebSite blijft staan zolang Yoast hem niet uitgeeft (niet-homepage), verdwijnt anders
$kept2 = RR_SD_Dedupe::drop_duplicates($tpl, ['WebSite']);
ok(count($kept2) === 4, 'WebSite weg als Yoast hem heeft');

// Zonder Yoast-types verandert er niets
ok(RR_SD_Dedupe::drop_duplicates($tpl, []) === $tpl, 'geen Yoast-types: alles blijft');

// @type als lijst, en rommel
$mixed = [['@type' => ['Organization', 'LocalBusiness']], 'rommel', ['geen' => 'type'], ['@type' => 5]];
$kept3 = RR_SD_Dedupe::drop_duplicates($mixed, ['Organization']);
ok(count($kept3) === 3 && !isset($kept3[0]['@type']), '@type-lijst herkend; rommel veroorzaakt geen fout');

// Geen graph / ongeldige invoer
RR_SD_Dedupe::reset();
RR_SD_Dedupe::record_yoast_graph('geen array', null);
ok(RR_SD_Dedupe::yoast_types() === [], 'ongeldige graph geeft lege lijst');

$GLOBALS['t']['opts']['rr_sd_skip_yoast_duplicates'] = '1';
ok(RR_SD_Dedupe::enabled() === true, 'aan te zetten');

exit($fail ? 1 : 0);
