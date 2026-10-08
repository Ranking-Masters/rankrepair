<?php
/**
 * Pure unit tests for RR_Security_IP.
 * Run: /opt/homebrew/bin/php tests/security/test-rr-security.php
 */
define('ABSPATH', true);
require __DIR__ . '/../../addons/security/class-rr-security-ip.php';

$fail = 0;
function ok($cond, $msg) {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); $fail++; return; }
    echo "ok: $msg\n";
}

// --- normalize_list ---
$list = RR_Security_IP::normalize_list("203.0.113.5\n# comment\n\n10.0.0.0/8  # intern\n");
ok($list === ['203.0.113.5', '10.0.0.0/8'], 'normalize_list: trimt, negeert comments + inline #');

// --- exacte IP ---
ok(RR_Security_IP::ip_in_list('203.0.113.5', ['203.0.113.5']) === true, 'exacte IPv4 match');
ok(RR_Security_IP::ip_in_list('203.0.113.6', ['203.0.113.5']) === false, 'andere IPv4 geen match');

// --- CIDR IPv4 ---
ok(RR_Security_IP::cidr_match('203.0.113.42', '203.0.113.0/24') === true, 'IPv4 /24 binnen bereik');
ok(RR_Security_IP::cidr_match('203.0.114.42', '203.0.113.0/24') === false, 'IPv4 /24 buiten bereik');
ok(RR_Security_IP::cidr_match('10.1.2.3', '10.0.0.0/8') === true, 'IPv4 /8 binnen bereik');
ok(RR_Security_IP::cidr_match('11.1.2.3', '10.0.0.0/8') === false, 'IPv4 /8 buiten bereik');
ok(RR_Security_IP::cidr_match('192.168.1.100', '192.168.1.128/25') === false, 'IPv4 /25 onderste helft buiten bereik');
ok(RR_Security_IP::cidr_match('192.168.1.200', '192.168.1.128/25') === true, 'IPv4 /25 bovenste helft binnen bereik');
ok(RR_Security_IP::cidr_match('8.8.8.8', '0.0.0.0/0') === true, '/0 matcht alles');

// --- CIDR IPv6 ---
ok(RR_Security_IP::cidr_match('2001:db8::1', '2001:db8::/32') === true, 'IPv6 /32 binnen bereik');
ok(RR_Security_IP::cidr_match('2001:dead::1', '2001:db8::/32') === false, 'IPv6 /32 buiten bereik');

// --- familie-mismatch mag niet matchen ---
ok(RR_Security_IP::cidr_match('203.0.113.5', '2001:db8::/32') === false, 'IPv4 vs IPv6-reeks geen match');

// --- lijst met mix ---
$entries = ['203.0.113.0/24', '198.51.100.7', '2001:db8::/32'];
ok(RR_Security_IP::ip_in_list('203.0.113.99', $entries) === true, 'lijst: CIDR-match');
ok(RR_Security_IP::ip_in_list('198.51.100.7', $entries) === true, 'lijst: exacte match');
ok(RR_Security_IP::ip_in_list('8.8.8.8', $entries) === false, 'lijst: geen match');
ok(RR_Security_IP::ip_in_list('niet-een-ip', $entries) === false, 'ongeldig IP matcht nooit');

// --- client_ip ---
ok(RR_Security_IP::client_ip(['REMOTE_ADDR' => '203.0.113.5']) === '203.0.113.5', 'client_ip: REMOTE_ADDR standaard');
ok(RR_Security_IP::client_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], 'HTTP_CF_CONNECTING_IP') === '203.0.113.9', 'client_ip: vertrouwde header wint');
ok(RR_Security_IP::client_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 70.1.2.3'], 'HTTP_X_FORWARDED_FOR') === '203.0.113.9', 'client_ip: eerste IP uit XFF-lijst');
ok(RR_Security_IP::client_ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'rubbish'], 'HTTP_X_FORWARDED_FOR') === '10.0.0.1', 'client_ip: onzin-header valt terug op REMOTE_ADDR');
ok(RR_Security_IP::client_ip(['REMOTE_ADDR' => '10.0.0.1'], 'HTTP_CF_CONNECTING_IP') === '10.0.0.1', 'client_ip: vertrouwde header afwezig -> REMOTE_ADDR');
ok(RR_Security_IP::client_ip([]) === '', 'client_ip: niks -> leeg');

// --- is_blocked: whitelist wint ---
ok(RR_Security_IP::is_blocked('203.0.113.5', ['203.0.113.0/24'], []) === true, 'blocklist-match -> geblokkeerd');
ok(RR_Security_IP::is_blocked('203.0.113.5', ['203.0.113.0/24'], ['203.0.113.5']) === false, 'whitelist wint van blocklist');
ok(RR_Security_IP::is_blocked('203.0.113.5', ['203.0.113.0/24'], ['203.0.113.0/24']) === false, 'whitelist-CIDR wint van blocklist-CIDR');
ok(RR_Security_IP::is_blocked('8.8.8.8', ['203.0.113.0/24'], []) === false, 'niet op blocklist -> niet geblokkeerd');
ok(RR_Security_IP::is_blocked('', ['0.0.0.0/0'], []) === false, 'leeg IP nooit geblokkeerd (geen lockout bij twijfel)');

if ($fail === 0) { echo "ALL PASS\n"; } else { fwrite(STDERR, "$fail FAILED\n"); exit(1); }
