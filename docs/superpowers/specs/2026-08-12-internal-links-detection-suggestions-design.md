# Interne Links — detectie & suggesties (fase 1: augustus)

**Datum:** 2026-08-12
**Add-on:** `addons/internal-links/` (nieuw)
**Doel:** Blogs (en andere post-types) opsporen die **geen of maar één inkomende interne link** hebben, per blog **relevante andere blogs** vinden op basis van keywords + inhoud, en **suggesties voor interne links + ankerteksten** tonen die je **handmatig kunt controleren**. Deze fase is **read-only op de content**: er wordt niets in posts geschreven. Het daadwerkelijk plaatsen van goedgekeurde links is fase 2 (september) en valt buiten deze spec.

## Achtergrond / huidige staat

RankRepair heeft een addon-architectuur (`RR_Addon_Base`) met 5 bestaande addons die worden geregistreerd in `rankrepair.php` → `register_addons()`. Elke addon extend de base (`init`, `render_page`, `get_stats`, `enqueue_assets`) en self-registreert via `register()`.

Herbruikbare bouwstenen die deze feature benut:
- **AI-call:** `RR_Ajax_Handler::gemini_generate()` in `includes/class-rr-ajax-handler.php` — praat met OpenRouter én Google AI Studio, met instelbare API-key (`rr_gemini_api_key`) en prompt. Wordt hergebruikt voor de ankertekst-suggesties.
- **Content-scan over post-types:** meta-manager `load_live_items()` toont het patroon van een `$wpdb`-scan over publieke post-types, plus `do_blocks()`/Elementor-parsing van `post_content`.
- **Review-modal:** meta-manager `rrShowBulkReview()` — AI-suggesties per item beoordelen in een modal.
- **Batch-progressbar:** image-optimizer verwerkt sequentieel via AJAX met een progressbar.
- **Custom tabel:** precedent `wp_rr_meta_data`.

Er is nog **geen** interne-link-graaf en geen orphan/thin-detectie. De redirects-checker doet alleen CSV-redirect-analyse, geen link-graaf.

## Scope & fasering

**Fase 1 — augustus (deze spec):**
1. Blogs opsporen met 0 of 1 inkomende interne link.
2. Per blog relevante andere blogs zoeken op basis van keywords + inhoud.
3. Suggesties tonen voor geschikte interne links + ankerteksten.
4. Resultaten eerst handmatig kunnen controleren.

**Fase 2 — september (buiten deze spec):**
- Goedgekeurde suggesties vanuit RankRepair in de content plaatsen.
- Ankertekst en bronpagina kunnen aanpassen vóór plaatsing.
- Links veilig in batches verwerken.
- Testen met verschillende editors en paginatypes.

## Beslissingen (vastgesteld in brainstorm)

1. **Detectie op inkomende links:** orphan = 0 inkomend, thin = 1 inkomend.
2. **Matching binnen hetzelfde post-type:** blog↔blog, pagina↔pagina. Geen cross-type-suggesties.
3. **Hybride engine:** heuristiek (keyword/tekst-similariteit) vindt de kandidaten; AI schrijft de ankertekst.
4. **Suggestie-richting:** om orphan X aan inkomende links te helpen, wordt een link **náár X** voorgesteld vanuit relevante bron-blogs Yi. De suggestie is dus een gericht paar `Yi → X`. (Plaatsing pas in fase 2.)
5. **Scan-mechanisme:** batched via AJAX met progressbar (geen cron in de MVP).
6. **Read-only in fase 1:** geen enkele wijziging aan `post_content` of postmeta van de content.

## Post-types & configuratie

- Standaard meegenomen: `post` + `page`, uitbreidbaar via filter `rr_internal_links_post_types` (analoog aan `rr_scan_post_types`).
- Alleen `post_status = publish`.
- Matching gebeurt **per type-silo**: kandidaten voor een blog zijn alleen andere blogs; voor een pagina alleen andere pagina's.

## Architectuur

Nieuwe addon `addons/internal-links/`:
- `class-addon-internal-links.php` — extend `RR_Addon_Base` (`slug = 'internal-links'`, `name = 'Interne Links'`). Registreert AJAX-hooks, rendert de pagina, levert `get_stats()`.
- `internal-links.js` — scan-flow (batched), tabel-render, suggesties ophalen, review-modal.
- `internal-links.css` — styling in lijn met bestaande addons.
- Registratie: entry toevoegen aan `register_addons()` in `rankrepair.php`.

Interne PHP-verantwoordelijkheden opgesplitst in kleine, testbare units (aparte class-bestanden binnen de addon-map):
- **`class-il-graph-scanner.php`** — bouwt de link-graaf: per post de interne `<a href>`'s extraheren, resolven naar doel-post-ID, wegschrijven naar de graaf-tabel. Levert per post het inkomend/uitgaand aantal.
- **`class-il-matcher.php`** — heuristische relevantie: TF-IDF + cosine tussen een doel-post en kandidaten van hetzelfde type; Yoast-focuskeyword-boost; sluit bronnen uit die al naar het doel linken; top N.
- **`class-il-suggester.php`** — orkestreert per doel-post: kandidaten ophalen (matcher) → AI-ankertekst (via `gemini_generate`) → suggestie-objecten opslaan/cachen.

## Datamodel

**Tabel `wp_rr_internal_links`** (de gedetecteerde link-graaf, wordt per scan herbouwd):

| kolom | type | omschrijving |
|---|---|---|
| `id` | BIGINT PK AI | |
| `source_id` | BIGINT | bron-post |
| `source_type` | VARCHAR(20) | post-type bron |
| `target_id` | BIGINT | doel-post (externe/onresolvebare links worden niet opgeslagen) |
| `target_type` | VARCHAR(20) | post-type doel |
| `anchor` | TEXT | ankertekst van de bestaande link |
| `scanned_at` | DATETIME | |

Index op `target_id` (voor snel inkomend tellen) en `source_id`.

**Suggesties** worden per doel-post berekend en gecachet in een transient of option (`rr_il_suggestions_{post_id}`), niet in een permanente tabel — ze zijn afgeleide data en in fase 1 nog niet "toegepast". Structuur per suggestie:
```
{
  target_id, target_title,
  source_id, source_title, source_type,
  score,                // heuristische relevantie 0–1
  anchor_text,          // AI-voorstel
  context_sentence,     // bestaande zin in de bron waar de link zou passen (AI)
  placement_hint,       // 'inline' | 'block'  (indicatief; plaatsing = fase 2)
  editor,               // 'gutenberg' | 'classic' | 'elementor' | 'unknown'
  already_links         // false (kandidaten die al linken worden uitgesloten)
}
```

## Detectie-logica

1. Scan alle posts van de meegenomen types (`publish`), batched via AJAX.
2. Per post de content ophalen en interne links extraheren:
   - Gutenberg/Classic: `do_blocks(post_content)` → HTML → `<a href>`'s parsen.
   - Elementor: href's uit `_elementor_data` (JSON) halen.
   - Resolven met `url_to_postid()`; alleen interne, resolvebare doelen opslaan.
3. Graaf-tabel vullen (per scan eerst legen).
4. **Inkomend aantal** per post = `COUNT(DISTINCT source_id)` waar `target_id = post`.
5. Classificatie: `orphan` (0), `thin` (1), `ok` (≥2). Uitgaand aantal wordt als extra info getoond.

## Matching-engine (heuristiek)

Voor doel-post X (type T):
1. Kandidaten = alle andere `publish`-posts van type T die **nog niet** naar X linken (via graaf-tabel).
2. Tekst voorbereiden: titel + `wp_strip_all_tags(do_blocks(post_content))`, NL-stopwoorden verwijderen, tokenizen.
3. **TF-IDF-vectors** over het kandidatenkorpus; **cosine-similariteit** tussen X en elke kandidaat.
4. **Boost** wanneer het Yoast-focuskeyword (`_yoast_wpseo_focuskw`) van X of van de kandidaat gedeeld/aanwezig is.
5. Sorteer op score, neem **top N** (standaard 3, filterbaar via `rr_internal_links_max_suggestions`).

## AI-ankertekst

Per gekozen paar `Yi → X`, één call via de bestaande `gemini_generate`-machinerie met een prompt dat:
- de bron-content (Yi) en de titel/focuskeyword van X meekrijgt,
- een **bestaande zin/woordgroep in Yi** kiest die natuurlijk naar X kan linken (`context_sentence`),
- een korte, natuurlijke **ankertekst** voorstelt (`anchor_text`),
- als geen goede inline-plek bestaat, `placement_hint = 'block'` teruggeeft met een korte label-tekst.

Editor-detectie per bron (voor de `editor`/`placement_hint`-velden, nog puur informatief in fase 1): blok-markers `<!-- wp:` → gutenberg; `_elementor_data` aanwezig → elementor; anders classic. Bij `elementor`/`unknown` wordt `placement_hint` op `block` gezet.

Als er geen AI-key is geconfigureerd, valt de tool terug op een **heuristische ankertekst** (titel/focuskeyword van X) zonder `context_sentence`; de tool blijft dan volledig bruikbaar voor detectie + kandidaat-suggesties.

## UI (admin-pagina "Interne Links")

- **Scan-sectie:** knop "Scan interne links" + progressbar (image-optimizer-stijl).
- **Stat-cards:** aantal orphans, aantal thin, gemiddeld inkomende links, totaal gescande posts.
- **Tabel** met probleemposts (orphans eerst, dan thin), kolommen: titel, type, inkomend, uitgaand, knop **"Suggesties"**.
- **Suggesties-modal** (review-only): per doel-post de top-N bron-suggesties met bron-titel, score, voorgestelde ankertekst en context-zin, en het (informatieve) `inline`/`block`-label. In fase 1 zijn er **geen** "toepassen"-acties — alleen bekijken/controleren. Wel: een **export** (CSV) van alle suggesties, zodat de resultaten gedeeld/handmatig verwerkt kunnen worden.
- **Bulk:** "Genereer suggesties voor alle orphans" (vult de cache; toont voortgang).

## AJAX-endpoints

- `rr_il_scan` — batched graaf-scan (ontvangt offset/batchgrootte, geeft voortgang + tussenstand terug).
- `rr_il_stats` — stat-cards + probleemlijst.
- `rr_il_suggest` — suggesties voor één `target_id` (matcher + AI), gecachet.
- `rr_il_export` — CSV-export van alle berekende suggesties.

Alle endpoints: `check_ajax_referer('rr_admin_nonce', 'nonce')` + `current_user_can('manage_options')` (conform bestaande addons).

## Bewust buiten scope (YAGNI / fase 2)

- **Plaatsen van links in content** (fase 2, september).
- Ankertekst/bronpagina bewerken vóór plaatsing (fase 2).
- Inline-editing van Elementor-JSON.
- Externe-link-analyse, geplande/cron-scans, over-optimalisatie-checks op ankertekst.

## Testbaarheid

- **`class-il-matcher.php`** is puur (tekst in → gescoorde kandidaten uit) en los te unit-testen met synthetische posts.
- **`class-il-graph-scanner.php`** te testen met fixture-posts (Gutenberg/Classic/Elementor) op correcte extractie + resolutie van interne links.
- De AI-laag is geïsoleerd achter `class-il-suggester.php` en met een fallback, zodat detectie + matching zonder API-key testbaar blijven.
