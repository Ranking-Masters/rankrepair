<?php
/**
 * Pure unit tests voor RR_Php_Workers (rekenkern, geen WordPress nodig).
 * Run: php tests/php-workers/test-rr-php-workers.php
 */
define('ABSPATH', true);
require __DIR__ . '/../../includes/class-rr-php-workers.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

// Vaste minuut: 2026-11-20 10:00:00 UTC
$T = 1795168800;
$M = intdiv($T, 60);

// ---------------------------------------------------------------------------
// clean_path
// ---------------------------------------------------------------------------
ok(RR_Php_Workers::clean_path('/bedankt/?email=a@b.nl') === '/bedankt/', 'querystring weg');
ok(RR_Php_Workers::clean_path('/a#frag') === '/a', 'fragment weg');
ok(RR_Php_Workers::clean_path("/a b\n\tc") === '/abc', 'spaties/stuurtekens weg (regelformaat blijft heel)');
ok(RR_Php_Workers::clean_path('') === '/', 'leeg -> /');
ok(RR_Php_Workers::clean_path('index.php') === '/index.php', 'zonder slash -> met slash');
ok(strlen(RR_Php_Workers::clean_path('/' . str_repeat('x', 500))) === 200, 'max 200 tekens');

// ---------------------------------------------------------------------------
// parse_lines
// ---------------------------------------------------------------------------
$text = sprintf("%.3f %.3f /a\nkapot\n%.3f %.3f /b\n1 0 /omgekeerd\n%.3f %.3f /buiten\n", $T + 1, $T + 2, $T + 5, $T + 70, $T - 100, $T - 50);
$iv = RR_Php_Workers::parse_lines($text, $T, $T + 60);
ok(count($iv) === 2, 'parse_lines: 2 geldige intervallen binnen bereik');
ok($iv[1][2] === '/b', 'parse_lines: pad bewaard');
$iv2 = RR_Php_Workers::parse_lines(sprintf("%.3f %.3f /met spatie in pad\n", $T + 1, $T + 2), $T, $T + 60);
ok(count($iv2) === 1 && $iv2[0][2] === '/met spatie in pad', 'parse_lines: rest van de regel is het pad');

// ---------------------------------------------------------------------------
// compute_buckets
// ---------------------------------------------------------------------------
// Na elkaar (geen overlap) -> piek 1
$b = RR_Php_Workers::compute_buckets([[$T + 1, $T + 2, '/a'], [$T + 3, $T + 4, '/b']], $M, $M + 1);
ok(count($b) === 1 && $b[0]['peak'] === 1, 'na elkaar: piek 1');
ok($b[0]['requests'] === 2, 'na elkaar: 2 verzoeken');
ok($b[0]['start'] === '2026-11-20T10:00:00Z', 'start als ISO UTC');
ok(abs($b[0]['avg'] - round(2 / 60, 2)) < 0.001, 'gemiddelde = bezette tijd / 60');

// Aansluitend (eind == begin) telt niet als 2 tegelijk
$b = RR_Php_Workers::compute_buckets([[$T + 1, $T + 2, '/a'], [$T + 2, $T + 3, '/b']], $M, $M + 1);
ok($b[0]['peak'] === 1, 'aansluitend: geen dubbele telling');

// Drie overlappend -> piek 3
$b = RR_Php_Workers::compute_buckets([
    [$T + 1, $T + 10, '/seo/'], [$T + 2, $T + 9, '/seo/'], [$T + 3, $T + 8, '/'], [$T + 20, $T + 21, '/x'],
], $M, $M + 1);
ok($b[0]['peak'] === 3, 'overlap: piek 3');
ok($b[0]['top_paths'][0] === ['path' => '/seo/', 'count' => 2], 'drukste pad bovenaan');

// Verzoek over de minuutgrens: telt in beide minuten, aantal op eindminuut
$b = RR_Php_Workers::compute_buckets([[$T + 50, $T + 70, '/lang']], $M, $M + 2);
ok(count($b) === 2, 'over minuutgrens: 2 buckets');
ok($b[0]['requests'] === 0 && $b[1]['requests'] === 1, 'verzoek geteld in de minuut waarin het eindigde');
ok($b[0]['peak'] === 1 && $b[1]['peak'] === 1, 'piek in beide minuten');
ok(abs($b[0]['avg'] - round(10 / 60, 2)) < 0.001 && abs($b[1]['avg'] - round(10 / 60, 2)) < 0.001, 'bezette tijd verdeeld over de minuten');

// Buiten bereik wordt afgeknipt
$b = RR_Php_Workers::compute_buckets([[$T - 30, $T + 5, '/a']], $M, $M + 1);
ok(count($b) === 1 && $b[0]['requests'] === 1, 'begon vóór bereik: telt alleen binnen bereik');
$b = RR_Php_Workers::compute_buckets([[$T + 50, $T + 70, '/a']], $M, $M + 1);
ok(count($b) === 1 && $b[0]['requests'] === 0, 'eindigt na bereik: wel bezet, verzoek telt later');

// Lege minuten worden niet verstuurd
$b = RR_Php_Workers::compute_buckets([[$T + 1, $T + 2, '/a'], [$T + 181, $T + 182, '/b']], $M, $M + 5);
ok(count($b) === 2, 'stille minuten weggelaten');

// Supersnel verzoek (start == eind) telt mee
$b = RR_Php_Workers::compute_buckets([[$T + 1, $T + 1, '/snel']], $M, $M + 1);
ok(count($b) === 1 && $b[0]['peak'] === 1, 'start == eind telt als 1');

// Crawlerpiek zoals 19-11: 30 verzoeken van 6 s die elkaar overlappen
$iv = [];
for ($i = 0; $i < 30; $i++) { $iv[] = [$T + $i * 0.2, $T + $i * 0.2 + 6, '/pagina-' . $i]; }
$b = RR_Php_Workers::compute_buckets($iv, $M, $M + 1);
ok($b[0]['peak'] === 30, 'crawlerpiek: 30 tegelijk');
ok(count($b[0]['top_paths']) === 5, 'max 5 paden');

// Ongeldig bereik
ok(RR_Php_Workers::compute_buckets([[$T + 1, $T + 2, '/a']], $M + 1, $M) === [], 'until <= from -> leeg');

// ---------------------------------------------------------------------------
// merge_pending
// ---------------------------------------------------------------------------
$a = [['start' => '2026-11-20T10:00:00Z', 'peak' => 1], ['start' => '2026-11-20T10:01:00Z', 'peak' => 1]];
$n = [['start' => '2026-11-20T10:01:00Z', 'peak' => 4], ['start' => '2026-11-20T10:02:00Z', 'peak' => 2]];
$m = RR_Php_Workers::merge_pending($a, $n);
ok(count($m) === 3 && $m[1]['peak'] === 4, 'merge: nieuwste wint, op volgorde');
$many = [];
for ($i = 0; $i < 1500; $i++) { $many[] = ['start' => gmdate('Y-m-d\TH:i:s\Z', $T + $i * 60), 'peak' => 1]; }
$m = RR_Php_Workers::merge_pending([], $many);
ok(count($m) === 1440 && $m[0]['start'] === gmdate('Y-m-d\TH:i:s\Z', $T + 60 * 60), 'merge: max 1440, oudste vallen af');
ok(count(RR_Php_Workers::merge_pending(['rommel', null], [])) === 0, 'merge: rommel genegeerd');

// ---------------------------------------------------------------------------
// parse_fpm_max
// ---------------------------------------------------------------------------
$kinsta = "[rankingmastersnl]\nuser = rankingmastersnl\npm = ondemand\npm.max_children = 6\n";
ok(RR_Php_Workers::parse_fpm_max([$kinsta], 'rankingmastersnl') === 6, 'Kinsta-pool: 6');
ok(RR_Php_Workers::parse_fpm_max([$kinsta], '') === 6, 'enige pool zonder gebruikersnaam: 6');
$www = "[www]\npm.max_children = 5\n";
ok(RR_Php_Workers::parse_fpm_max([$kinsta, $www], 'iemand-anders') === 6, 'www-pool genegeerd');
$two = "[siteA]\npm.max_children = 4\n[siteB]\npm.max_children = 10\n";
ok(RR_Php_Workers::parse_fpm_max([$two], 'siteB') === 10, 'meerdere pools: die van de gebruiker');
ok(RR_Php_Workers::parse_fpm_max([$two], 'onbekend') === null, 'meerdere pools, geen match: null (niet gokken)');
ok(RR_Php_Workers::parse_fpm_max(["[x]\n;pm.max_children = 9\n"], 'x') === null, 'uitgecommentarieerd telt niet');
ok(RR_Php_Workers::parse_fpm_max(["[x]\npm.max_children = 0\n"], 'x') === null, '0 is ongeldig');
ok(RR_Php_Workers::parse_fpm_max([], 'x') === null, 'geen config: null');

if ($fail) { fwrite(STDERR, "$fail FAILED\n"); exit(1); }
echo "ALL PASS\n";
