# Interne Links — detectie & suggesties (fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Een nieuwe RankRepair-addon die blogs/pagina's met 0 of 1 inkomende interne link opspoort en per stuk relevante bron-posts + AI-ankerteksten voorstelt, read-only ter controle.

**Architecture:** Nieuwe addon `addons/internal-links/` in het bestaande `RR_Addon_Base`-stramien. Pure logica (tekst-tokenisatie, TF-IDF/cosine-matching, link-extractie, editor-detectie) zit in WordPress-onafhankelijke classes die met losse PHP-assert-scripts getest worden; de WordPress-gebonden delen (graaf-scan via `$wpdb`, AJAX, admin-UI, AI-call) worden handmatig in wp-admin geverifieerd.

**Tech Stack:** PHP (WordPress plugin API, `$wpdb`, `WP_Query`, `DOMDocument`), vanilla JS + jQuery (admin), MySQL. AI via bestaande OpenRouter/Google-koppeling.

## Global Constraints

- Alle nieuwe PHP-bestanden beginnen met `if (!defined('ABSPATH')) { exit; }`.
- Alle AJAX-handlers: `check_ajax_referer('rr_admin_nonce', 'nonce')` + `current_user_can('manage_options')`, conform bestaande addons.
- Addon-slug: `internal-links`; class: `RR_Addon_Internal_Links extends RR_Addon_Base`.
- Vertaaldomein voor alle strings: `'rankrepair'`.
- Graaf-tabel: `{$wpdb->prefix}rr_internal_links`.
- Fase 1 is **read-only op content**: geen enkele schrijfactie naar `post_content` of postmeta van posts.
- Meegenomen post-types standaard `['post','page']`, filterbaar via `rr_internal_links_post_types`. Alleen `post_status = publish`. Matching strikt **binnen hetzelfde type**.
- AI-optiesleutels (hergebruik): `rr_gemini_api_key` (versleuteld, via `rr_decrypt_key()`), `rr_ai_provider` (`google`|`openrouter`), `rr_ai_model`.
- De pure-unit-tests vereisen een PHP CLI (`php` ≥ 7.4). Niet geïnstalleerd? `brew install php`. De WordPress-gebonden taken worden in een draaiende WordPress-omgeving geverifieerd.

---

## File Structure

```
addons/internal-links/
  class-addon-internal-links.php   # addon-entry: slug/name, AJAX-hooks, render_page, get_stats, enqueue, tabel-check
  class-il-text.php                # PURE: tokenize, stopwoorden, interne-href-extractie, editor-detectie
  class-il-matcher.php             # PURE: TF-IDF + cosine + keyword-boost + top-N
  class-il-graph-scanner.php       # WP: bouwt link-graaf in tabel, telt inkomend/uitgaand
  class-il-suggester.php           # WP+AI: kandidaten (matcher) -> AI-ankertekst -> cache
  internal-links.js                # scan-flow (batched), tabel, suggesties-modal, export
  internal-links.css               # styling
tests/internal-links/
  test-il-text.php                 # losse PHP-assert-tests (pure)
  test-il-matcher.php              # losse PHP-assert-tests (pure)
rankrepair.php                     # MODIFY: addon registreren + tabel toevoegen + rr_ai_complete()
```

---

## Task 1: Addon-scaffold, registratie, DB-tabel en lege admin-pagina

**Files:**
- Create: `addons/internal-links/class-addon-internal-links.php`
- Modify: `rankrepair.php` (addon-registratie in `register_addons()`; tabel in `create_tables()`)

**Interfaces:**
- Produces: class `RR_Addon_Internal_Links` (slug `internal-links`), tabel `{$wpdb->prefix}rr_internal_links` met kolommen `id, source_id, source_type, target_id, target_type, anchor, scanned_at`.

- [ ] **Step 1: Maak de addon-class**

Create `addons/internal-links/class-addon-internal-links.php`:

```php
<?php
/**
 * Interne Links Add-on
 * Detecteert blogs/pagina's met te weinig inkomende interne links en stelt er links voor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RR_Addon_Internal_Links extends RR_Addon_Base {

    protected function init() {
        $this->slug        = 'internal-links';
        $this->name        = __('Interne Links', 'rankrepair');
        $this->description = __('Vind pagina\'s met te weinig inkomende interne links en krijg linksuggesties.', 'rankrepair');
        $this->icon        = 'dashicons-admin-links';
    }

    public function get_stats() {
        return ['label' => __('Interne Links', 'rankrepair'), 'value' => ''];
    }

    public function render_page() {
        $this->render_header();
        echo '<div class="rr-il-wrap"><p>' . esc_html__('Interne Links add-on — scanner volgt.', 'rankrepair') . '</p></div>';
        $this->render_footer();
    }
}

new RR_Addon_Internal_Links();
```

- [ ] **Step 2: Registreer de addon**

In `rankrepair.php`, in de array binnen `register_addons()` (nu regels ~71-75), voeg toe:

```php
'internal-links'    => RR_PLUGIN_DIR . 'addons/internal-links/class-addon-internal-links.php',
```

- [ ] **Step 3: Voeg de tabel toe aan `create_tables()`**

In `rankrepair.php`, in `create_tables()` na `$sql_meta` (rond regel 227-253), voeg een derde `CREATE TABLE` toe en een `dbDelta`-aanroep. Direct na de regel `$table_meta = ...` bovenin de functie, voeg toe:

```php
$table_links = $wpdb->prefix . 'rr_internal_links';
```

Voeg vlak vóór `dbDelta($sql_pagespeed);` de tabeldefinitie toe (let op: twee spaties voor kolommen, zoals dbDelta vereist):

```php
$sql_links = "CREATE TABLE $table_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  source_type varchar(20) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL,
  target_type varchar(20) NOT NULL DEFAULT '',
  anchor text NULL,
  scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY target_id (target_id),
  KEY source_id (source_id)
) $charset_collate;";
```

Voeg na `dbDelta($sql_meta);` toe:

```php
dbDelta($sql_links);
```

En in het fallback-blok (`CREATE TABLE IF NOT EXISTS`, rond regel 259) een analoge fallback voor `$table_links`:

```php
$wpdb->query("CREATE TABLE IF NOT EXISTS $table_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_id bigint(20) unsigned NOT NULL,
  source_type varchar(20) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL,
  target_type varchar(20) NOT NULL DEFAULT '',
  anchor text NULL,
  scanned_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (id),
  KEY target_id (target_id),
  KEY source_id (source_id)
) $charset_collate;");
```

- [ ] **Step 4: Verifieer in WordPress (handmatig)**

1. Deactiveer + heractiveer de plugin (triggert `create_tables()`).
2. Controleer in het admin-menu dat **Interne Links** verschijnt onder RankRepair en de pagina laadt met de placeholder-tekst.
3. Controleer in de database dat `wp_rr_internal_links` bestaat:
   `SHOW TABLES LIKE '%rr_internal_links';` → 1 rij.

Verwacht: menu-item zichtbaar, pagina laadt, tabel bestaat.

- [ ] **Step 5: Commit**

```bash
git add addons/internal-links/class-addon-internal-links.php rankrepair.php
git commit -m "feat(internal-links): addon-scaffold, registratie en DB-tabel"
```

---

## Task 2: Pure tekst-helpers (`IL_Text`) met unit-tests

**Files:**
- Create: `addons/internal-links/class-il-text.php`
- Test: `tests/internal-links/test-il-text.php`

**Interfaces:**
- Produces:
  - `IL_Text::tokenize(string $text): array` — lowercase, unicode-woorden, lengte ≥ 3.
  - `IL_Text::remove_stopwords(array $tokens): array` — verwijdert NL-stopwoorden.
  - `IL_Text::extract_internal_hrefs(string $html, string $home_host): array` — lijst van `['href'=>string,'anchor'=>string]` voor interne links (relatief of zelfde host).
  - `IL_Text::detect_editor(string $post_content, bool $has_elementor): string` — `'elementor'|'gutenberg'|'classic'`.

- [ ] **Step 1: Schrijf de falende test**

Create `tests/internal-links/test-il-text.php`:

```php
<?php
define('ABSPATH', true);
require __DIR__ . '/../../addons/internal-links/class-il-text.php';

function ok($cond, $msg) {
    if (!$cond) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); }
    echo "ok: $msg\n";
}

// tokenize
$t = IL_Text::tokenize('Hét beste SEO-advies, in 2024!');
ok(in_array('beste', $t) && in_array('seo', $t) && in_array('advies', $t), 'tokenize splitst en lowercased');
ok(!in_array('in', $t), 'tokenize verwijdert woorden < 3 tekens');

// remove_stopwords
$s = IL_Text::remove_stopwords(['de','beste','een','linkbuilding']);
ok($s === array_values(['beste','linkbuilding']), 'remove_stopwords verwijdert NL-stopwoorden');

// extract_internal_hrefs
$html = '<p>Zie <a href="/over-ons">ons team</a> en <a href="https://example.com/blog/x">deze blog</a> '
      . 'en <a href="https://google.com">extern</a>.</p>';
$links = IL_Text::extract_internal_hrefs($html, 'example.com');
$hrefs = array_map(function ($l) { return $l['href']; }, $links);
ok(in_array('/over-ons', $hrefs), 'relatieve link is intern');
ok(in_array('https://example.com/blog/x', $hrefs), 'zelfde-host link is intern');
ok(!in_array('https://google.com', $hrefs), 'externe link wordt uitgesloten');
ok($links[0]['anchor'] === 'ons team', 'anchor-tekst wordt meegenomen');

// detect_editor
ok(IL_Text::detect_editor('<!-- wp:paragraph --><p>x</p>', false) === 'gutenberg', 'gutenberg gedetecteerd');
ok(IL_Text::detect_editor('<p>gewoon html</p>', false) === 'classic', 'classic gedetecteerd');
ok(IL_Text::detect_editor('<p>x</p>', true) === 'elementor', 'elementor gedetecteerd');

echo "ALL PASS\n";
```

- [ ] **Step 2: Draai de test — verwacht falen**

Run: `php tests/internal-links/test-il-text.php`
Expected: FAIL — `require`-fout, bestand `class-il-text.php` bestaat nog niet.

- [ ] **Step 3: Implementeer `IL_Text`**

Create `addons/internal-links/class-il-text.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Text {

    /** NL-stopwoorden (compacte, praktische set). */
    private static $stopwords = [
        'de','het','een','en','van','te','dat','die','in','op','voor','met','als','zijn','er','maar',
        'om','door','over','ze','uit','aan','bij','nog','kan','naar','wordt','wat','worden','deze',
        'dit','is','was','ook','tot','je','jij','wij','we','ik','hij','zij','u','uw','ons','onze',
        'niet','geen','wel','meer','veel','heel','zeer','dan','of','omdat','want','dus','al','hier',
    ];

    public static function tokenize($text) {
        $text  = mb_strtolower((string) $text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out   = [];
        foreach ($parts as $p) {
            if (mb_strlen($p, 'UTF-8') >= 3) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function remove_stopwords(array $tokens) {
        $stop = array_flip(self::$stopwords);
        return array_values(array_filter($tokens, function ($t) use ($stop) {
            return !isset($stop[$t]);
        }));
    }

    public static function extract_internal_hrefs($html, $home_host) {
        $html = (string) $html;
        if (trim($html) === '') {
            return [];
        }
        $home_host = strtolower(preg_replace('/^www\./i', '', (string) $home_host));

        $prev = libxml_use_internal_errors(true);
        $dom  = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '' || $href[0] === '#') {
                continue;
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            $host = preg_replace('/^www\./i', '', $host);

            $is_internal = ($host === '' || $host === $home_host);
            if (!$is_internal) {
                continue;
            }
            $out[] = [
                'href'   => $href,
                'anchor' => trim($a->textContent),
            ];
        }
        return $out;
    }

    public static function detect_editor($post_content, $has_elementor) {
        if ($has_elementor) {
            return 'elementor';
        }
        if (strpos((string) $post_content, '<!-- wp:') !== false) {
            return 'gutenberg';
        }
        return 'classic';
    }
}
```

- [ ] **Step 4: Draai de test — verwacht slagen**

Run: `php tests/internal-links/test-il-text.php`
Expected: reeks `ok:`-regels, eindigend op `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add addons/internal-links/class-il-text.php tests/internal-links/test-il-text.php
git commit -m "feat(internal-links): pure tekst-helpers (tokenize, links, editor-detectie) + tests"
```

---

## Task 3: Pure matcher (`IL_Matcher`) met unit-tests

**Files:**
- Create: `addons/internal-links/class-il-matcher.php`
- Test: `tests/internal-links/test-il-matcher.php`

**Interfaces:**
- Consumes: token-arrays (uit `IL_Text::tokenize` + `remove_stopwords`).
- Produces:
  - `IL_Matcher::score_candidates(array $target, array $candidates, int $top_n): array`
    - `$target = ['tokens' => string[], 'keyword' => string]`
    - `$candidates = [ post_id => ['tokens' => string[], 'keyword' => string] ]`
    - Retour: aflopende lijst `[ ['id' => int, 'score' => float], ... ]`, max `$top_n`, alleen `score > 0`.

- [ ] **Step 1: Schrijf de falende test**

Create `tests/internal-links/test-il-matcher.php`:

```php
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
```

- [ ] **Step 2: Draai de test — verwacht falen**

Run: `php tests/internal-links/test-il-matcher.php`
Expected: FAIL — `class-il-matcher.php` bestaat nog niet.

- [ ] **Step 3: Implementeer `IL_Matcher`**

Create `addons/internal-links/class-il-matcher.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Matcher {

    /** Boost-factor wanneer target- en kandidaat-keyword overlappen. */
    const KEYWORD_BOOST = 1.5;

    public static function score_candidates(array $target, array $candidates, $top_n = 3) {
        $top_n = max(1, (int) $top_n);

        $target_tokens = isset($target['tokens']) ? (array) $target['tokens'] : [];
        if (empty($target_tokens) || empty($candidates)) {
            return [];
        }

        // Document-frequency over target + alle kandidaten (voor IDF).
        $docs = [];
        $docs['__target__'] = $target_tokens;
        foreach ($candidates as $id => $c) {
            $docs[$id] = isset($c['tokens']) ? (array) $c['tokens'] : [];
        }

        $doc_count = count($docs);
        $df = [];
        foreach ($docs as $tokens) {
            foreach (array_unique($tokens) as $term) {
                $df[$term] = isset($df[$term]) ? $df[$term] + 1 : 1;
            }
        }
        $idf = [];
        foreach ($df as $term => $n) {
            // Gladde IDF, altijd > 0.
            $idf[$term] = log(($doc_count + 1) / ($n + 1)) + 1;
        }

        $target_vec = self::tfidf_vector($target_tokens, $idf);

        $target_kw = self::norm_kw($target['keyword'] ?? '');

        $scored = [];
        foreach ($candidates as $id => $c) {
            $vec  = self::tfidf_vector((array) ($c['tokens'] ?? []), $idf);
            $sim  = self::cosine($target_vec, $vec);
            if ($sim <= 0) {
                continue;
            }
            $cand_kw = self::norm_kw($c['keyword'] ?? '');
            if ($target_kw !== '' && $cand_kw !== '' && $target_kw === $cand_kw) {
                $sim *= self::KEYWORD_BOOST;
            }
            $scored[] = ['id' => (int) $id, 'score' => round($sim, 6)];
        }

        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['score'] <=> $a['score'];
        });

        return array_slice($scored, 0, $top_n);
    }

    private static function tfidf_vector(array $tokens, array $idf) {
        if (empty($tokens)) {
            return [];
        }
        $tf = [];
        foreach ($tokens as $t) {
            $tf[$t] = isset($tf[$t]) ? $tf[$t] + 1 : 1;
        }
        $len = count($tokens);
        $vec = [];
        foreach ($tf as $term => $count) {
            $w = ($count / $len) * (isset($idf[$term]) ? $idf[$term] : 0);
            if ($w != 0.0) {
                $vec[$term] = $w;
            }
        }
        return $vec;
    }

    private static function cosine(array $a, array $b) {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $dot = 0.0;
        foreach ($a as $term => $wa) {
            if (isset($b[$term])) {
                $dot += $wa * $b[$term];
            }
        }
        if ($dot == 0.0) {
            return 0.0;
        }
        $na = 0.0; foreach ($a as $wa) { $na += $wa * $wa; }
        $nb = 0.0; foreach ($b as $wb) { $nb += $wb * $wb; }
        $denom = sqrt($na) * sqrt($nb);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    private static function norm_kw($kw) {
        return trim(mb_strtolower((string) $kw, 'UTF-8'));
    }
}
```

- [ ] **Step 4: Draai de test — verwacht slagen**

Run: `php tests/internal-links/test-il-matcher.php`
Expected: `ok:`-regels eindigend op `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add addons/internal-links/class-il-matcher.php tests/internal-links/test-il-matcher.php
git commit -m "feat(internal-links): pure matcher (TF-IDF + cosine + keyword-boost) + tests"
```

---

## Task 4: Graaf-scanner en batched scan-AJAX

**Files:**
- Create: `addons/internal-links/class-il-graph-scanner.php`
- Modify: `addons/internal-links/class-addon-internal-links.php` (require + AJAX-hook `rr_il_scan`)

**Interfaces:**
- Consumes: `IL_Text::extract_internal_hrefs`.
- Produces:
  - `IL_Graph_Scanner::post_types(): array` — de meegenomen types.
  - `IL_Graph_Scanner::all_post_ids(): int[]` — alle `publish`-post-ids van die types, oplopend.
  - `IL_Graph_Scanner::scan_batch(int[] $ids): int` — bouwt graafrijen voor die posts (vervangt bestaande rijen per source), retourneert aantal verwerkte posts.
  - `IL_Graph_Scanner::reset(): void` — leegt de tabel.
  - `IL_Graph_Scanner::inbound_count(int $post_id): int` en `outbound_count(int $post_id): int`.

- [ ] **Step 1: Implementeer de scanner**

Create `addons/internal-links/class-il-graph-scanner.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Graph_Scanner {

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rr_internal_links';
    }

    public static function post_types() {
        $types = apply_filters('rr_internal_links_post_types', ['post', 'page']);
        $types = array_values(array_filter(array_map('sanitize_key', (array) $types)));
        return empty($types) ? ['post'] : $types;
    }

    public static function all_post_ids() {
        $q = new WP_Query([
            'post_type'      => self::post_types(),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        return array_map('intval', $q->posts);
    }

    public static function reset() {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::table());
    }

    /** Bouwt graafrijen voor de opgegeven bron-posts. */
    public static function scan_batch(array $ids) {
        global $wpdb;
        $table = self::table();
        $home_host = (string) parse_url(home_url(), PHP_URL_HOST);
        $now = current_time('mysql');
        $processed = 0;

        foreach ($ids as $source_id) {
            $source_id = (int) $source_id;
            $post = get_post($source_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            // Verwijder eerdere rijen van deze bron (idempotente herscan).
            $wpdb->delete($table, ['source_id' => $source_id], ['%d']);

            $source_type = $post->post_type;
            $content = $post->post_content;
            $rendered = function_exists('do_blocks') ? do_blocks($content) : $content;

            // Elementor-content zit in postmeta; voeg toe voor href-extractie.
            $elementor = get_post_meta($source_id, '_elementor_data', true);
            if (!empty($elementor)) {
                $rendered .= ' ' . wp_json_encode($elementor);
            }

            $links = IL_Text::extract_internal_hrefs($rendered, $home_host);
            $seen_targets = [];

            foreach ($links as $link) {
                $target_id = (int) url_to_postid($link['href']);
                if ($target_id <= 0 || $target_id === $source_id) {
                    continue;
                }
                $target = get_post($target_id);
                if (!$target || $target->post_status !== 'publish') {
                    continue;
                }
                // Alleen binnen dezelfde type-silo relevant, maar sla alle interne links op;
                // de silo-filtering gebeurt bij het tellen/suggereren.
                $key = $target_id;
                if (isset($seen_targets[$key])) {
                    continue; // dedup per (source,target)
                }
                $seen_targets[$key] = true;

                $wpdb->insert($table, [
                    'source_id'   => $source_id,
                    'source_type' => $source_type,
                    'target_id'   => $target_id,
                    'target_type' => $target->post_type,
                    'anchor'      => $link['anchor'],
                    'scanned_at'  => $now,
                ], ['%d', '%s', '%d', '%s', '%s', '%s']);
            }
            $processed++;
        }
        return $processed;
    }

    public static function inbound_count($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT source_id) FROM $table WHERE target_id = %d",
            (int) $post_id
        ));
    }

    public static function outbound_count($post_id) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT target_id) FROM $table WHERE source_id = %d",
            (int) $post_id
        ));
    }

    /** Bron-post-ids die al naar het doel linken (voor uitsluiting bij matching). */
    public static function sources_linking_to($target_id) {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT source_id FROM $table WHERE target_id = %d",
            (int) $target_id
        ));
        return array_map('intval', $rows);
    }
}
```

- [ ] **Step 2: Koppel scanner + AJAX in de addon**

In `addons/internal-links/class-addon-internal-links.php`, boven de class-definitie, voeg requires toe:

```php
require_once __DIR__ . '/class-il-text.php';
require_once __DIR__ . '/class-il-matcher.php';
require_once __DIR__ . '/class-il-graph-scanner.php';
```

Vervang de `init()`-body zodat AJAX-hooks geregistreerd worden:

```php
    protected function init() {
        $this->slug        = 'internal-links';
        $this->name        = __('Interne Links', 'rankrepair');
        $this->description = __('Vind pagina\'s met te weinig inkomende interne links en krijg linksuggesties.', 'rankrepair');
        $this->icon        = 'dashicons-admin-links';

        add_action('wp_ajax_rr_il_scan', [$this, 'ajax_scan']);
    }
```

Voeg de scan-handler toe als methode van de class:

```php
    /** Batched graaf-scan. Client stuurt 'offset'; server verwerkt 1 batch. */
    public function ajax_scan() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(120);

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch  = 25;

        $all = IL_Graph_Scanner::all_post_ids();
        if ($offset === 0) {
            IL_Graph_Scanner::reset();
        }

        $slice = array_slice($all, $offset, $batch);
        $processed = IL_Graph_Scanner::scan_batch($slice);

        $next = $offset + count($slice);
        wp_send_json_success([
            'total'     => count($all),
            'processed' => $next,
            'done'      => $next >= count($all),
        ]);
    }
```

- [ ] **Step 3: Verifieer in WordPress (handmatig)**

1. Zorg voor minimaal 3 gepubliceerde blogs waarvan er onderling wat links bestaan.
2. Open de browserconsole op de Interne Links-pagina en draai handmatig één scan-batch:
   ```js
   jQuery.post(rrAdmin.ajaxUrl, {action:'rr_il_scan', nonce:rrAdmin.nonce, offset:0}, function(r){ console.log(r); });
   ```
   Verwacht: `success:true`, `total` = aantal posts, `processed` > 0.
3. Controleer de tabel: `SELECT source_id, target_id, anchor FROM wp_rr_internal_links LIMIT 20;` → rijen die de bestaande interne links weerspiegelen.
4. Controleer een teller in de console:
   ```js
   // vervang 123 door een post-id met bekende inkomende links
   ```
   via SQL: `SELECT COUNT(DISTINCT source_id) FROM wp_rr_internal_links WHERE target_id = 123;`

Verwacht: graafrijen kloppen met de werkelijke links; herhaalde scan met `offset:0` geeft geen dubbele rijen.

- [ ] **Step 4: Commit**

```bash
git add addons/internal-links/class-il-graph-scanner.php addons/internal-links/class-addon-internal-links.php
git commit -m "feat(internal-links): graaf-scanner + batched scan-AJAX"
```

---

## Task 5: Stats, probleemtabel en scan-UI (JS/CSS)

**Files:**
- Modify: `addons/internal-links/class-addon-internal-links.php` (`enqueue_assets`, `render_page`, AJAX `rr_il_stats`)
- Create: `addons/internal-links/internal-links.js`
- Create: `addons/internal-links/internal-links.css`

**Interfaces:**
- Consumes: `IL_Graph_Scanner::all_post_ids`, `inbound_count`, `outbound_count`.
- Produces: AJAX `rr_il_stats` → `{ orphans:int, thin:int, total:int, avg_inbound:float, rows: [ {id,title,type,inbound,outbound} ] }` (rows = alleen orphans+thin, orphans eerst).

- [ ] **Step 1: Voeg de stats-handler + enqueue + render toe**

In `class-addon-internal-links.php`, registreer in `init()` extra hook:

```php
        add_action('wp_ajax_rr_il_stats', [$this, 'ajax_stats']);
```

Voeg de enqueue-methode toe (laadt alleen op de eigen pagina; `rrAdmin` met `ajaxUrl`+`nonce` wordt al globaal door de plugin gelokaliseerd — controleer dat door in de console `rrAdmin` te loggen):

```php
    public function enqueue_assets($hook) {
        if (strpos((string) $hook, 'internal-links') === false) {
            return;
        }
        wp_enqueue_style('rr-internal-links', RR_PLUGIN_URL . 'addons/internal-links/internal-links.css', [], RR_VERSION);
        wp_enqueue_script('rr-internal-links', RR_PLUGIN_URL . 'addons/internal-links/internal-links.js', ['jquery'], RR_VERSION, true);
    }
```

Voeg de stats-handler toe:

```php
    public function ajax_stats() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }

        $ids   = IL_Graph_Scanner::all_post_ids();
        $rows  = [];
        $sum_in = 0;
        $orphans = 0;
        $thin = 0;

        foreach ($ids as $id) {
            $in  = IL_Graph_Scanner::inbound_count($id);
            $sum_in += $in;
            if ($in <= 1) {
                $post = get_post($id);
                $rows[] = [
                    'id'       => $id,
                    'title'    => get_the_title($id),
                    'type'     => $post ? $post->post_type : '',
                    'inbound'  => $in,
                    'outbound' => IL_Graph_Scanner::outbound_count($id),
                ];
                if ($in === 0) { $orphans++; } else { $thin++; }
            }
        }

        // Orphans eerst (inbound oplopend), dan op titel.
        usort($rows, function ($a, $b) {
            if ($a['inbound'] === $b['inbound']) {
                return strcasecmp($a['title'], $b['title']);
            }
            return $a['inbound'] <=> $b['inbound'];
        });

        $total = count($ids);
        wp_send_json_success([
            'orphans'     => $orphans,
            'thin'        => $thin,
            'total'       => $total,
            'avg_inbound' => $total > 0 ? round($sum_in / $total, 1) : 0,
            'rows'        => $rows,
        ]);
    }
```

Vervang `render_page()` door de volledige UI-shell:

```php
    public function render_page() {
        $this->render_header();
        ?>
        <div class="rr-il-wrap">
            <div class="rr-il-toolbar">
                <button id="rr-il-scan-btn" class="button button-primary"><?php esc_html_e('Scan interne links', 'rankrepair'); ?></button>
                <div id="rr-il-progress" class="rr-il-progress" style="display:none;">
                    <div class="rr-il-progress-bar"><span id="rr-il-progress-fill"></span></div>
                    <span id="rr-il-progress-txt">0 / 0</span>
                </div>
            </div>

            <div class="rr-il-stats" id="rr-il-stats">
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-orphans">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Orphans (0 inkomend)', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-thin">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Thin (1 inkomend)', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-avg">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Gem. inkomend', 'rankrepair'); ?></span></div>
                <div class="rr-il-stat"><span class="rr-il-stat-val" id="rr-il-stat-total">–</span><span class="rr-il-stat-lbl"><?php esc_html_e('Totaal gescand', 'rankrepair'); ?></span></div>
            </div>

            <table class="rr-il-table widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Titel', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Type', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Inkomend', 'rankrepair'); ?></th>
                        <th><?php esc_html_e('Uitgaand', 'rankrepair'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="rr-il-tbody">
                    <tr><td colspan="5"><?php esc_html_e('Klik op "Scan interne links" om te beginnen.', 'rankrepair'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
        $this->render_footer();
    }
```

- [ ] **Step 2: Schrijf de scan-UI JS**

Create `addons/internal-links/internal-links.js`:

```js
(function ($) {
    'use strict';

    var RRIL = {
        total: 0,

        init: function () {
            $('#rr-il-scan-btn').on('click', function () { RRIL.startScan(); });
        },

        startScan: function () {
            $('#rr-il-scan-btn').prop('disabled', true);
            $('#rr-il-progress').show();
            RRIL.scanBatch(0);
        },

        scanBatch: function (offset) {
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_scan', nonce: rrAdmin.nonce, offset: offset })
                .done(function (r) {
                    if (!r || !r.success) {
                        RRIL.scanError(r && r.data ? r.data.message : 'Scanfout');
                        return;
                    }
                    RRIL.total = r.data.total;
                    var pct = r.data.total > 0 ? Math.round(r.data.processed / r.data.total * 100) : 100;
                    $('#rr-il-progress-fill').css('width', pct + '%');
                    $('#rr-il-progress-txt').text(r.data.processed + ' / ' + r.data.total);

                    if (r.data.done) {
                        RRIL.loadStats();
                    } else {
                        RRIL.scanBatch(r.data.processed);
                    }
                })
                .fail(function () { RRIL.scanError('Verbindingsfout tijdens scan.'); });
        },

        scanError: function (msg) {
            $('#rr-il-scan-btn').prop('disabled', false);
            $('#rr-il-tbody').html('<tr><td colspan="5" style="color:#b32d2e">' + RRIL.esc(msg) + '</td></tr>');
        },

        loadStats: function () {
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_stats', nonce: rrAdmin.nonce })
                .done(function (r) {
                    $('#rr-il-scan-btn').prop('disabled', false);
                    setTimeout(function () { $('#rr-il-progress').fadeOut(300); }, 800);
                    if (!r || !r.success) { return; }
                    var d = r.data;
                    $('#rr-il-stat-orphans').text(d.orphans);
                    $('#rr-il-stat-thin').text(d.thin);
                    $('#rr-il-stat-avg').text(d.avg_inbound);
                    $('#rr-il-stat-total').text(d.total);
                    RRIL.renderRows(d.rows);
                });
        },

        renderRows: function (rows) {
            var $tb = $('#rr-il-tbody').empty();
            if (!rows.length) {
                $tb.html('<tr><td colspan="5">' + RRIL.esc('Geen orphan- of thin-pagina\'s gevonden. 🎉') + '</td></tr>');
                return;
            }
            rows.forEach(function (row) {
                var badge = row.inbound === 0
                    ? '<span class="rr-il-badge rr-il-badge--orphan">orphan</span>'
                    : '<span class="rr-il-badge rr-il-badge--thin">thin</span>';
                $tb.append(
                    '<tr data-id="' + row.id + '">' +
                        '<td>' + badge + ' ' + RRIL.esc(row.title) + '</td>' +
                        '<td>' + RRIL.esc(row.type) + '</td>' +
                        '<td>' + row.inbound + '</td>' +
                        '<td>' + row.outbound + '</td>' +
                        '<td><button class="button rr-il-suggest-btn" data-id="' + row.id + '">' + RRIL.esc('Suggesties') + '</button></td>' +
                    '</tr>'
                );
            });
        },

        esc: function (s) {
            return $('<div>').text(s == null ? '' : String(s)).html();
        }
    };

    $(function () { RRIL.init(); });
    window.RRIL = RRIL;
})(jQuery);
```

- [ ] **Step 3: Schrijf de CSS**

Create `addons/internal-links/internal-links.css`:

```css
.rr-il-wrap { max-width: 1100px; }
.rr-il-toolbar { display: flex; align-items: center; gap: 16px; margin: 16px 0; }
.rr-il-progress { display: flex; align-items: center; gap: 8px; }
.rr-il-progress-bar { width: 220px; height: 8px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
.rr-il-progress-bar span { display: block; height: 100%; width: 0; background: #6366f1; transition: width .2s; }
.rr-il-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
.rr-il-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; text-align: center; }
.rr-il-stat-val { display: block; font-size: 24px; font-weight: 700; color: #111827; }
.rr-il-stat-lbl { display: block; font-size: 12px; color: #6b7280; margin-top: 4px; }
.rr-il-table { margin-top: 12px; background: #fff; }
.rr-il-badge { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; color: #fff; }
.rr-il-badge--orphan { background: #b32d2e; }
.rr-il-badge--thin { background: #d97706; }
```

- [ ] **Step 4: Verifieer in WordPress (handmatig)**

1. Open de Interne Links-pagina, klik **Scan interne links**.
2. Verwacht: progressbar loopt tot 100%, stat-cards vullen (orphans/thin/gem./totaal), tabel toont orphans (rode badge) bovenaan, dan thin (oranje).
3. Vergelijk met de database dat de aantallen kloppen (`SELECT target_id, COUNT(DISTINCT source_id) ...`).

Verwacht: UI toont correcte detectie; herscan geeft consistente cijfers.

- [ ] **Step 5: Commit**

```bash
git add addons/internal-links/class-addon-internal-links.php addons/internal-links/internal-links.js addons/internal-links/internal-links.css
git commit -m "feat(internal-links): stats, probleemtabel en scan-UI"
```

---

## Task 6: Suggester (matcher + AI) met herbruikbare AI-helper

**Files:**
- Modify: `rankrepair.php` (nieuwe functie `rr_ai_complete()` naast `rr_decrypt_key`)
- Create: `addons/internal-links/class-il-suggester.php`
- Modify: `addons/internal-links/class-addon-internal-links.php` (require + AJAX `rr_il_suggest`)

**Interfaces:**
- Consumes: `IL_Matcher::score_candidates`, `IL_Graph_Scanner::post_types`/`sources_linking_to`, `IL_Text::tokenize`/`remove_stopwords`/`detect_editor`.
- Produces:
  - `rr_ai_complete(string $prompt): string|WP_Error` — algemene AI-tekstcompletion via bestaande provider-opties.
  - `IL_Suggester::for_target(int $target_id): array` — cachet en retourneert lijst suggestie-objecten:
    `{ target_id, target_title, source_id, source_title, source_type, score, anchor_text, context_sentence, placement_hint, editor }`.

- [ ] **Step 1: Voeg de herbruikbare AI-helper toe**

In `rankrepair.php`, direct ná de functie `rr_decrypt_key()` (rond regel 468+), voeg toe:

```php
/**
 * Algemene AI-tekstcompletion. Hergebruikt de provider-opties van de meta-manager
 * (rr_ai_provider / rr_ai_model / rr_gemini_api_key). Retourneert platte tekst of WP_Error.
 */
function rr_ai_complete($prompt) {
    $api_key = rr_decrypt_key(get_option('rr_gemini_api_key', ''));
    if (empty($api_key)) {
        return new WP_Error('no_key', __('Geen AI API key ingesteld.', 'rankrepair'));
    }
    $provider = get_option('rr_ai_provider', 'google');
    $model    = trim(get_option('rr_ai_model', ''));

    if ($provider === 'openrouter') {
        if (empty($model)) { $model = 'google/gemini-2.0-flash-001'; }
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'temperature' => 0.5,
                'max_tokens'  => 300,
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('ai_api', 'OpenRouter: ' . ($body['error']['message'] ?? "HTTP $code"));
        }
        $text = $body['choices'][0]['message']['content'] ?? '';
    } else {
        if (empty($model)) { $model = 'gemini-1.5-flash'; }
        $endpoint = add_query_arg('key', $api_key, 'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent');
        $response = wp_remote_post($endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 300],
            ]),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) { return $response; }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            return new WP_Error('ai_api', 'Gemini API: ' . ($body['error']['message'] ?? "HTTP $code"));
        }
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    if (empty($text)) {
        return new WP_Error('ai_empty', __('AI gaf geen resultaat terug.', 'rankrepair'));
    }
    return trim($text);
}
```

- [ ] **Step 2: Implementeer de suggester**

Create `addons/internal-links/class-il-suggester.php`:

```php
<?php
if (!defined('ABSPATH')) {
    exit;
}

class IL_Suggester {

    const CACHE_PREFIX = 'rr_il_suggestions_';
    const CACHE_TTL    = 43200; // 12 uur

    public static function for_target($target_id, $force = false) {
        $target_id = (int) $target_id;
        $cache_key = self::CACHE_PREFIX . $target_id;

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $target = get_post($target_id);
        if (!$target || $target->post_status !== 'publish') {
            return [];
        }

        $max = (int) apply_filters('rr_internal_links_max_suggestions', 3);
        $candidates = self::gather_candidates($target);

        $target_bundle = self::bundle($target);
        $scored = IL_Matcher::score_candidates($target_bundle, $candidates['tokens'], $max);

        $out = [];
        foreach ($scored as $hit) {
            $source = get_post($hit['id']);
            if (!$source) { continue; }
            $ai = self::ai_anchor($source, $target);
            $out[] = [
                'target_id'        => $target_id,
                'target_title'     => get_the_title($target_id),
                'source_id'        => $hit['id'],
                'source_title'     => get_the_title($hit['id']),
                'source_type'      => $source->post_type,
                'score'            => $hit['score'],
                'anchor_text'      => $ai['anchor_text'],
                'context_sentence' => $ai['context_sentence'],
                'placement_hint'   => $ai['placement_hint'],
                'editor'           => $ai['editor'],
            ];
        }

        set_transient($cache_key, $out, self::CACHE_TTL);
        return $out;
    }

    /** Kandidaat-bronnen: zelfde type, publish, niet zichzelf, linken nog niet naar target. */
    private static function gather_candidates(WP_Post $target) {
        $already = IL_Graph_Scanner::sources_linking_to($target->ID);
        $exclude = array_merge([$target->ID], $already);

        $q = new WP_Query([
            'post_type'      => $target->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post__not_in'   => $exclude,
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $tokens = [];
        foreach ($q->posts as $p) {
            $b = self::bundle($p);
            $tokens[$p->ID] = ['tokens' => $b['tokens'], 'keyword' => $b['keyword']];
        }
        return ['tokens' => $tokens];
    }

    private static function bundle(WP_Post $p) {
        $raw   = $p->post_title . ' ' . wp_strip_all_tags(function_exists('do_blocks') ? do_blocks($p->post_content) : $p->post_content);
        $tok   = IL_Text::remove_stopwords(IL_Text::tokenize($raw));
        $kw    = (string) get_post_meta($p->ID, '_yoast_wpseo_focuskw', true);
        return ['tokens' => $tok, 'keyword' => $kw];
    }

    /** Vraag de AI om ankertekst + context-zin; val terug op heuristiek zonder key/bij fout. */
    private static function ai_anchor(WP_Post $source, WP_Post $target) {
        $has_elementor = (bool) get_post_meta($source->ID, '_elementor_data', true);
        $editor = IL_Text::detect_editor($source->post_content, $has_elementor);
        $placement = in_array($editor, ['gutenberg', 'classic'], true) ? 'inline' : 'block';

        $source_text = wp_strip_all_tags(function_exists('do_blocks') ? do_blocks($source->post_content) : $source->post_content);
        $source_text = mb_substr($source_text, 0, 1500, 'UTF-8');
        $target_title = get_the_title($target->ID);
        $target_kw = (string) get_post_meta($target->ID, '_yoast_wpseo_focuskw', true);

        $prompt = "Je helpt bij interne SEO-links op een Nederlandse website.\n"
            . "DOELPAGINA titel: \"{$target_title}\"\n"
            . ($target_kw !== '' ? "DOELPAGINA focus-keyword: \"{$target_kw}\"\n" : '')
            . "BRONTEKST (fragment):\n\"\"\"\n{$source_text}\n\"\"\"\n\n"
            . "Kies uit de BRONTEKST één bestaande zin waarin een link naar de DOELPAGINA natuurlijk past, "
            . "en stel een korte, natuurlijke ankertekst voor (2-5 woorden) die in die zin voorkomt of past.\n"
            . "Antwoord EXACT in dit formaat, zonder extra uitleg:\n"
            . "ANKER: <ankertekst>\n"
            . "ZIN: <de gekozen zin uit de brontekst>";

        $fallback = [
            'anchor_text'      => $target_kw !== '' ? $target_kw : $target_title,
            'context_sentence' => '',
            'placement_hint'   => $placement,
            'editor'           => $editor,
        ];

        if (!function_exists('rr_ai_complete')) {
            return $fallback;
        }
        $resp = rr_ai_complete($prompt);
        if (is_wp_error($resp)) {
            return $fallback;
        }

        $anchor = '';
        $sentence = '';
        if (preg_match('/ANKER:\s*(.+)/i', $resp, $m)) { $anchor = trim($m[1]); }
        if (preg_match('/ZIN:\s*(.+)/is', $resp, $m)) { $sentence = trim($m[1]); }

        return [
            'anchor_text'      => $anchor !== '' ? $anchor : $fallback['anchor_text'],
            'context_sentence' => $sentence,
            'placement_hint'   => $placement,
            'editor'           => $editor,
        ];
    }
}
```

- [ ] **Step 3: Koppel de suggester + AJAX in de addon**

In `class-addon-internal-links.php`, voeg require toe bij de andere requires:

```php
require_once __DIR__ . '/class-il-suggester.php';
```

Registreer in `init()`:

```php
        add_action('wp_ajax_rr_il_suggest', [$this, 'ajax_suggest']);
```

Voeg de handler toe:

```php
    public function ajax_suggest() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(120);

        $target_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        if ($target_id <= 0) {
            wp_send_json_error(['message' => __('Ongeldig ID.', 'rankrepair')]);
        }
        $force = !empty($_POST['force']);
        $suggestions = IL_Suggester::for_target($target_id, $force);

        wp_send_json_success([
            'target_id'   => $target_id,
            'has_ai'      => !empty(get_option('rr_gemini_api_key', '')),
            'suggestions' => $suggestions,
        ]);
    }
```

- [ ] **Step 4: Verifieer in WordPress (handmatig)**

1. Zorg dat er een AI-key is ingesteld (Instellingen) én draai eerst een scan (Task 5).
2. Kies in de console een orphan-`target_id` en vraag suggesties:
   ```js
   jQuery.post(rrAdmin.ajaxUrl, {action:'rr_il_suggest', nonce:rrAdmin.nonce, target_id: 123}, function(r){ console.log(r.data.suggestions); });
   ```
   Verwacht: tot 3 suggesties met `source_title`, `score` (aflopend), `anchor_text`, en meestal een `context_sentence`; `placement_hint` = `inline` voor Gutenberg/Classic, `block` voor Elementor.
3. Test de fallback: verwijder tijdelijk de AI-key en herhaal met `force:1` → nog steeds suggesties, met heuristische `anchor_text` en lege `context_sentence`. Zet de key terug.

Verwacht: relevante bronnen, correcte sortering, werkende AI-fallback.

- [ ] **Step 5: Commit**

```bash
git add rankrepair.php addons/internal-links/class-il-suggester.php addons/internal-links/class-addon-internal-links.php
git commit -m "feat(internal-links): suggester met AI-ankertekst + herbruikbare rr_ai_complete()"
```

---

## Task 7: Suggesties-modal en CSV-export

**Files:**
- Modify: `addons/internal-links/class-addon-internal-links.php` (AJAX `rr_il_export`)
- Modify: `addons/internal-links/internal-links.js` (modal + export-knop)
- Modify: `addons/internal-links/internal-links.css` (modal-styling)

**Interfaces:**
- Consumes: `IL_Suggester::for_target`, AJAX `rr_il_suggest`.
- Produces: AJAX `rr_il_export` → CSV-download van alle suggesties voor de huidige orphan/thin-lijst.

- [ ] **Step 1: Voeg de export-handler toe**

In `class-addon-internal-links.php`, registreer in `init()`:

```php
        add_action('wp_ajax_rr_il_export', [$this, 'ajax_export']);
```

Voeg de handler toe (bouwt suggesties voor alle orphans+thin en stuurt CSV):

```php
    public function ajax_export() {
        check_ajax_referer('rr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Geen toestemming.', 'rankrepair')]);
        }
        set_time_limit(300);

        $ids = IL_Graph_Scanner::all_post_ids();
        $rows = [['doel_id', 'doel_titel', 'bron_id', 'bron_titel', 'type', 'score', 'ankertekst', 'context_zin', 'plaatsing']];

        foreach ($ids as $id) {
            if (IL_Graph_Scanner::inbound_count($id) > 1) {
                continue;
            }
            foreach (IL_Suggester::for_target($id) as $s) {
                $rows[] = [
                    $s['target_id'], $s['target_title'], $s['source_id'], $s['source_title'],
                    $s['source_type'], $s['score'], $s['anchor_text'], $s['context_sentence'], $s['placement_hint'],
                ];
            }
        }

        $csv = '';
        foreach ($rows as $r) {
            $escaped = array_map(function ($v) {
                return '"' . str_replace('"', '""', (string) $v) . '"';
            }, $r);
            $csv .= implode(';', $escaped) . "\r\n";
        }

        wp_send_json_success([
            'filename' => 'interne-links-suggesties-' . date('Ymd-His') . '.csv',
            'csv'      => $csv,
        ]);
    }
```

- [ ] **Step 2: Voeg modal + export toe aan de JS**

In `addons/internal-links/internal-links.js`, breid `RRIL.init` uit met event-handlers en voeg methodes toe. Vervang de `init`-functie door:

```js
        init: function () {
            $('#rr-il-scan-btn').on('click', function () { RRIL.startScan(); });
            $('#rr-il-export-btn').on('click', function () { RRIL.exportCsv(); });
            $(document).on('click', '.rr-il-suggest-btn', function () {
                RRIL.openSuggestions(parseInt($(this).data('id'), 10));
            });
            $(document).on('click', '#rr-il-modal-close, #rr-il-modal-overlay', function () { RRIL.closeModal(); });
        },
```

Voeg deze methodes toe aan het `RRIL`-object (na `renderRows`):

```js
        openSuggestions: function (id) {
            RRIL.showModal('<div class="rr-il-modal-loading">' + RRIL.esc('Suggesties laden…') + '</div>');
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_suggest', nonce: rrAdmin.nonce, target_id: id })
                .done(function (r) {
                    if (!r || !r.success) { RRIL.showModal('<p style="color:#b32d2e">' + RRIL.esc('Fout bij laden.') + '</p>'); return; }
                    RRIL.renderSuggestions(r.data);
                })
                .fail(function () { RRIL.showModal('<p style="color:#b32d2e">' + RRIL.esc('Verbindingsfout.') + '</p>'); });
        },

        renderSuggestions: function (data) {
            var html = '';
            if (!data.has_ai) {
                html += '<div class="rr-il-note">' + RRIL.esc('Geen AI-key ingesteld — ankerteksten zijn heuristisch.') + '</div>';
            }
            if (!data.suggestions.length) {
                html += '<p>' + RRIL.esc('Geen relevante bronnen gevonden.') + '</p>';
            } else {
                html += '<table class="rr-il-modal-table"><thead><tr>' +
                        '<th>' + RRIL.esc('Bron') + '</th><th>' + RRIL.esc('Score') + '</th>' +
                        '<th>' + RRIL.esc('Ankertekst') + '</th><th>' + RRIL.esc('Context') + '</th>' +
                        '<th>' + RRIL.esc('Plaatsing') + '</th></tr></thead><tbody>';
                data.suggestions.forEach(function (s) {
                    html += '<tr>' +
                        '<td>' + RRIL.esc(s.source_title) + ' <span class="rr-il-muted">#' + s.source_id + '</span></td>' +
                        '<td>' + s.score + '</td>' +
                        '<td><strong>' + RRIL.esc(s.anchor_text) + '</strong></td>' +
                        '<td class="rr-il-context">' + RRIL.esc(s.context_sentence || '—') + '</td>' +
                        '<td>' + RRIL.esc(s.placement_hint) + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table>';
            }
            RRIL.showModal(html);
        },

        showModal: function (inner) {
            RRIL.closeModal();
            var $overlay = $('<div id="rr-il-modal-overlay"></div>');
            var $modal = $('<div id="rr-il-modal"><button id="rr-il-modal-close" aria-label="Sluiten">&times;</button><div id="rr-il-modal-body"></div></div>');
            $modal.find('#rr-il-modal-body').html(inner);
            $('body').append($overlay).append($modal);
        },

        closeModal: function () {
            $('#rr-il-modal-overlay, #rr-il-modal').remove();
        },

        exportCsv: function () {
            var $btn = $('#rr-il-export-btn').prop('disabled', true).text('Exporteren…');
            $.post(rrAdmin.ajaxUrl, { action: 'rr_il_export', nonce: rrAdmin.nonce })
                .done(function (r) {
                    if (r && r.success) {
                        var blob = new Blob([r.data.csv], { type: 'text/csv;charset=utf-8;' });
                        var url = URL.createObjectURL(blob);
                        var a = document.createElement('a');
                        a.href = url; a.download = r.data.filename;
                        document.body.appendChild(a); a.click(); document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }
                })
                .always(function () { $btn.prop('disabled', false).text('Exporteer CSV'); });
        },
```

Voeg de export-knop toe aan de toolbar in `render_page()` (in `class-addon-internal-links.php`), direct na de scan-knop:

```php
                <button id="rr-il-export-btn" class="button"><?php esc_html_e('Exporteer CSV', 'rankrepair'); ?></button>
```

- [ ] **Step 3: Voeg modal-CSS toe**

Append aan `addons/internal-links/internal-links.css`:

```css
#rr-il-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 100000; }
#rr-il-modal { position: fixed; top: 8%; left: 50%; transform: translateX(-50%); width: min(900px, 92vw); max-height: 80vh; overflow: auto; background: #fff; border-radius: 10px; z-index: 100001; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
#rr-il-modal-close { position: absolute; top: 10px; right: 14px; border: 0; background: none; font-size: 24px; cursor: pointer; line-height: 1; }
.rr-il-modal-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.rr-il-modal-table th, .rr-il-modal-table td { text-align: left; padding: 8px; border-bottom: 1px solid #eee; vertical-align: top; font-size: 13px; }
.rr-il-context { color: #374151; font-style: italic; }
.rr-il-muted { color: #9ca3af; font-size: 11px; }
.rr-il-note { background: #fef3c7; border: 1px solid #fde68a; padding: 8px 12px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; }
.rr-il-modal-loading { padding: 40px; text-align: center; color: #6b7280; }
```

- [ ] **Step 4: Verifieer in WordPress (handmatig)**

1. Scan, klik bij een orphan op **Suggesties** → modal opent met bron, score, ankertekst, context-zin en plaatsing; sluiten werkt (kruis + overlay).
2. Klik **Exporteer CSV** → een `.csv` downloadt; open in spreadsheet en controleer kolommen/rijen (puntkomma-gescheiden, quotes correct).
3. Zonder AI-key: modal toont de gele notitie en heuristische ankerteksten.

Verwacht: modal en export werken, data klopt met de suggesties.

- [ ] **Step 5: Commit**

```bash
git add addons/internal-links/class-addon-internal-links.php addons/internal-links/internal-links.js addons/internal-links/internal-links.css
git commit -m "feat(internal-links): suggesties-modal en CSV-export"
```

---

## Self-Review (uitgevoerd)

**Spec-dekking:**
- Detectie orphan/thin op inkomende links → Task 4 (graaf + tellers) + Task 5 (classificatie/stats). ✓
- Relevante bronnen op keyword+inhoud → Task 3 (matcher) + Task 6 (kandidaten binnen type-silo, Yoast-keyword-boost). ✓
- Suggesties + ankerteksten tonen → Task 6 (AI) + Task 7 (modal). ✓
- Handmatig controleren (read-only) → geen content-writes; modal + CSV-export in Task 7. ✓
- Matching binnen type-silo → `gather_candidates` filtert op `$target->post_type`. ✓
- Batched scan → Task 4 `ajax_scan` met offset/batch=25. ✓
- Editor-detectie + placement_hint (informatief) → Task 2 `detect_editor` + Task 6. ✓
- AI-fallback zonder key → Task 6 `ai_anchor` fallback. ✓

**Placeholder-scan:** geen TBD/TODO; alle code-steps bevatten volledige code. ✓

**Type-consistentie:** `score_candidates($target, $candidates, $top_n)` met `['id','score']`-output wordt in `IL_Suggester::for_target` zo geconsumeerd; `for_target` levert de velden die `ajax_suggest`/modal/`ajax_export` gebruiken; `inbound_count`/`outbound_count`/`sources_linking_to`/`all_post_ids`/`post_types` consistent aangeroepen. ✓

**Buiten scope (bewust):** content-insertie, ankertekst/bron bewerken vóór plaatsing, Elementor-inline, cron — alle fase 2.
