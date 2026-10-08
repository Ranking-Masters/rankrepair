# Security Add-on — IP-blocklist/whitelist, login-URL, toegangslog

**Datum:** 2026-10-08
**Add-on:** `addons/security/` (nieuw)
**Doel:** Centrale, Nederlandstalige beveiligingslaag in RankRepair: IP-blocklist/whitelist
(losse IP's + CIDR), 403 voor geblokkeerde bezoekers met log, aangepaste login-URL, en
stevige lockout-beveiliging. Puur lokaal — geen Level4.

## Beslissingen (brainstorm)
1. **IP-detectie:** standaard `REMOTE_ADDR`, met per site instelbare vertrouwde proxy-header
   (CF-Connecting-IP / X-Forwarded-For / X-Real-IP / True-Client-IP). Niet-vertrouwde headers
   worden nooit gebruikt (spoofbaar).
2. **Noodknop:** `define('RR_SECURITY_DISABLE', true);` in wp-config.php zet álles uit.
3. **Login-URL:** meteen meegenomen in v1.

## Bestanden
- `addons/security/class-rr-security-ip.php` — PURE logica (geen WP): `normalize_list`,
  `ip_in_list`, `cidr_match` (IPv4+IPv6), `client_ip`, `is_blocked` (whitelist wint). Unit-getest.
- `addons/security/class-addon-security.php` — addon (extend `RR_Addon_Base`): hooks, 403-blokkade,
  login-guard, admin-pagina, opslaan, log.
- Registratie: 2 regels in `rankrepair.php` (`register_addons` + `get_available_addons`).
- `tests/security/test-rr-security.php` — pure unit-tests.

## Gedrag
- **Blokkade** op `init` (prio 0): kill-switch/uit → niks; ingelogde beheerder → nooit blokkeren;
  whitelist wint van blocklist; match → 403 + logregel (IP/tijd/pad, gecapt op 100, max 1×/5min per IP).
- **Login-URL:** leeg = standaard. Ingevuld (slug `[a-z0-9-]{3,64}`): login-formulier alleen op de
  geheime slug; directe `wp-login.php`/`wp-admin` → 404 voor niet-ingelogde bezoekers. `site_url`/
  `wp_redirect` worden herschreven naar de slug. admin-ajax/admin-post/cron blijven werken.
- **Lockout-beveiliging:** beheerders nooit geblokkeerd; settings toont jouw IP + waarschuwing als
  dat IP geblokkeerd zou worden; noodknop-constante.

## Opslag (opties, autoload off)
`rr_security_enabled`, `rr_security_blocklist`, `rr_security_whitelist`, `rr_security_trusted_header`,
`rr_security_login_slug`, `rr_security_log`. Opgeruimd in `uninstall.php`.

## Ontwikkelomgeving-modus (toegevoegd 2026-10-08)
Aparte schakelaar `rr_security_dev_mode` die de hele site (voor- én achterkant) afschermt
voor de buitenwereld — bedoeld voor staging/dev. Onafhankelijk van de "Ingeschakeld"-toggle;
respecteert de noodknop en de CLI-uitzondering.
- **Toegestaan:** ingelogde gebruikers (elke rol), bezoekers vanaf een **whitelist-IP** (ook
  uitgelogd), en het inlog-mechanisme (geheime slug / `wp-login.php` / `admin-ajax` /
  `admin-post` / cron) — zodat je jezelf nooit buitensluit.
- **Afgeschermd:** al het andere → **HTTP 503** (Service Unavailable) + `Retry-After` +
  `X-Robots-Tag: noindex, nofollow` + een eenvoudige 🚧-pagina. SEO-veilig: dev-URLs worden
  niet geïndexeerd/gedeïndexeerd.
- Hook: `maybe_dev_gate()` op `init` prio 6, ná `login_guard`.

## Buiten v1 (uitbreidbaar)
Automatisch blokkeren bij formulier-misbruik, rate-limiting, geoblocking, dedicated log-tabel
(i.p.v. optie) voor hoog-volume.
