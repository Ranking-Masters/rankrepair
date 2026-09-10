<?php
define('ABSPATH', true);
require __DIR__ . '/../../addons/internal-links/class-il-matcher.php';

function ok($cond, $msg) {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

$target = ['tokens' => ['linkbuilding','backlinks','autoriteit','google'], 'keyword' => 'linkbuilding'];

$candidates = [
    10 => ['tokens' => ['linkbuilding','backlinks','strategie','autoriteit'], 'keyword' => 'linkbuilding'], // zeer relevant
    11 => ['tokens' => ['recept','taart','oven','suiker'],                    'keyword' => 'taart'],        // niet relevant
    12 => ['tokens' => ['google','ranking','zoekmachine'],                    'keyword' => ''],             // deels relevant
];

$res = IL_Matcher::score_candidates($target, $candidates, 3);

ok(count($res) >= 2, 'geeft relevante kandidaten terug');
ok($res[0]['id'] === 10, 'meest relevante kandidaat staat bovenaan');
$ids = array_map(function ($r) { return $r['id']; }, $res);
ok(!in_array(11, $ids), 'volledig irrelevante kandidaat (score 0) valt af');
for ($i = 1; $i < count($res); $i++) {
    ok($res[$i-1]['score'] >= $res[$i]['score'], 'aflopend gesorteerd op score');
}

// top_n begrenst
$res2 = IL_Matcher::score_candidates($target, $candidates, 1);
ok(count($res2) === 1, 'top_n begrenst het aantal');

echo "ALL PASS\n";
