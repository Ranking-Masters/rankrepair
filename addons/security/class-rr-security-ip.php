<?php
/**
 * RR_Security_IP — pure IP-logica voor de Security-addon (geen WordPress nodig,
 * los van de addon-class zodat het standalone te unit-testen is).
 *
 * Kan losse IP's én CIDR-reeksen matchen (203.0.113.0/24), IPv4 + IPv6, en bepaalt
 * het bezoekers-IP met een optioneel-vertrouwde proxy-header.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Security_IP {

    /** Splitst een textarea in losse, opgeschoonde regels (zonder comments/leeg). */
    public static function normalize_list($text): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            // Inline-commentaar (" # ...") eraf, en hele commentaarregels overslaan.
            if ($line === '' || $line[0] === '#') { continue; }
            $hash = strpos($line, '#');
            if ($hash !== false) { $line = trim(substr($line, 0, $hash)); }
            if ($line !== '') { $out[] = $line; }
        }
        return $out;
    }

    /** True als $ip exact of via CIDR in één van de entries valt. */
    public static function ip_in_list(string $ip, array $entries): bool {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') { continue; }
            if (strpos($entry, '/') !== false) {
                if (self::cidr_match($ip, $entry)) { return true; }
            } elseif (self::same_ip($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /** Exacte IP-vergelijking, genormaliseerd (zodat 127.0.0.1 == 127.000.000.001 e.d. niet uitmaakt). */
    private static function same_ip(string $a, string $b): bool {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);
        if ($pa === false || $pb === false) { return false; }
        return $pa === $pb;
    }

    /** Matcht een IP tegen een CIDR-reeks (IPv4 én IPv6). */
    public static function cidr_match(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) {
            return self::same_ip($ip, $cidr);
        }
        list($subnet, $bits) = explode('/', $cidr, 2);
        $subnet = trim($subnet);
        if (!is_numeric($bits)) { return false; }
        $bits = (int) $bits;

        $ip_bin     = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false) { return false; }
        // IP en subnet moeten dezelfde familie zijn (beide IPv4 of beide IPv6).
        if (strlen($ip_bin) !== strlen($subnet_bin)) { return false; }

        $max = strlen($ip_bin) * 8; // 32 voor IPv4, 128 voor IPv6
        if ($bits < 0 || $bits > $max) { return false; }
        if ($bits === 0) { return true; }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;

        // Volledige bytes vergelijken.
        if ($bytes > 0 && strncmp($ip_bin, $subnet_bin, $bytes) !== 0) {
            return false;
        }
        // Resterende bits binnen de volgende byte.
        if ($rem > 0) {
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            if ((($ip_bin[$bytes] ^ $subnet_bin[$bytes]) & $mask) !== "\0") {
                return false;
            }
        }
        return true;
    }

    /**
     * Bepaalt het bezoekers-IP. Standaard REMOTE_ADDR; als een vertrouwde proxy-
     * header is ingesteld én aanwezig, het eerste GELDIGE IP daaruit (comma-lijst).
     * Niet-vertrouwde headers worden nooit gebruikt (die zijn te spoofen).
     *
     * @param array  $server         Meestal $_SERVER.
     * @param string $trusted_header bv. 'HTTP_CF_CONNECTING_IP' of 'HTTP_X_FORWARDED_FOR', of '' voor geen.
     */
    public static function client_ip(array $server, string $trusted_header = ''): string {
        if ($trusted_header !== '' && !empty($server[$trusted_header])) {
            foreach (explode(',', (string) $server[$trusted_header]) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        $remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : '';
        return ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) ? $remote : '';
    }

    /**
     * Beslist of een IP geblokkeerd moet worden. WHITELIST WINT ALTIJD.
     * Lege/ongeldige IP's worden niet geblokkeerd (geen harde lockout bij twijfel).
     */
    public static function is_blocked(string $ip, array $blocklist, array $whitelist): bool {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (self::ip_in_list($ip, $whitelist)) {
            return false; // whitelist wint
        }
        return self::ip_in_list($ip, $blocklist);
    }
}
