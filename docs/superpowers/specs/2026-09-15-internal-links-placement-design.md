# Interne Links — plaatsing & linkprofiel (fase 2: september)

**Datum:** 2026-09-15
**Add-on:** `addons/internal-links/` (uitbreiding van fase 1)
**Branch:** `feat/internal-links-placement`
**Doel:** goedgekeurde linksuggesties daadwerkelijk in de content plaatsen — in elke editor, veilig, terugdraaibaar, en sturend op een **natuurlijk intern linkprofiel** in plaats van "zoveel mogelijk links".

Fase 1 (augustus) leverde detectie + suggesties. Dat deel blijft staan. Deze spec beschrijft wat erbij komt.

---

## 1. Uitgangspunten

Vier principes, overgenomen uit het interne-linksysteem van `ferienhausniederlande.de`
(`Ferienhaus-Content-Engine`, `docs/INTERNAL-LINKING-MASTERDOCS.md`, 68 testfixtures in productie):

1. **De LLM oordeelt, de code bewaakt.** Welke zin, welke ankertekst, welke vorm — dat is een
   redactioneel oordeel. Of de zin bestaat, of de ankertekst er letterlijk in staat, of er geen
   content verdwijnt, of het geen zelf-link is — dat is een invariant en die hoort deterministisch
   in PHP, niet in een prompt.
2. **Geen link is beter dan een geforceerde link.** Er is geen quotum. Vindt de planner geen
   natuurlijke plek, dan plaatsen we niets. Liever 1 goede link dan 3 opgevulde.
3. **Nooit content stilzwijgend verliezen.** Elke insert wordt na afloop geverifieerd: de platte
   tekst vóór en ná moet identiek zijn, op de expliciet toegestane toevoeging na. Faalt die check,
   dan wordt er niet geschreven.
4. **Alles is terug te draaien.** Elke geplaatste link krijgt een eigen id in het HTML-attribuut
   `data-rr-il`. Daarmee kun je precies die ene link weer weghalen, ook als de pagina daarna nog
   met de hand is bewerkt.

## 2. Wat er nieuw is

| Onderdeel | Bestand | Verantwoordelijkheid |
|---|---|---|
| Content-adapters | `class-il-content.php` + `adapters/` | elke editor uitlezen en terugschrijven via één interface |
| Linkprofiel | `class-il-profile.php` | meet het profiel, levert de caps waar de gates op toetsen |
| Gates | `class-il-gates.php` | 16 deterministische checks; wijst af met reden |
| Planner | `class-il-planner.php` | genereert kandidaten (segment + anker + modus), laat gates beslissen |
| Inserter | `class-il-inserter.php` | bouwt de nieuwe segment-HTML + integriteitscheck |
| Applier | `class-il-applier.php` | schrijft weg, bewaart snapshot, draait terug |
| Wachtrij | `class-il-queue.php` | batchverwerking, hervatbaar |
| 3D-graaf | `vendor/3d-force-graph.min.js` | Data-scherm, zelfde bibliotheek als Ferienhaus |

## 3. Content-adapters — "één slimme gedeelde manier"

Het probleem met "werkt het ook met Elementor?" is dat je anders per editor een aparte
plaatsingsroutine krijgt, met per editor eigen bugs. De oplossing is één tussenrepresentatie.

Elke adapter vertaalt een post naar een lijst **segmenten**:

```php
[
  'ref'   => 'b:2.1',        // adapter-specifiek adres, stabiel binnen één read/write-ronde
  'html'  => '<p>…</p>',     // rijke tekst van dit segment
  'text'  => '…',            // platte tekst (afgeleid)
  'kind'  => 'paragraph',    // paragraph | heading | list | other
  'index' => 3,              // documentvolgorde
]
```

Planner, gates en inserter kennen **alleen** segmenten. Ze weten niet of dit Gutenberg of
Elementor is. Een nieuwe editor ondersteunen = één adapter toevoegen; de rest verandert niet.
Dat is de loop: `foreach (adapters as a) if (a->detect($post)) return a->read($post)`.

Meegeleverd:

| Adapter | Leest uit | Schrijft naar | Adres |
|---|---|---|---|
| `Gutenberg` | `parse_blocks(post_content)`, recursief door `innerBlocks` | `serialize_blocks()` | blok-pad `b:2.1` |
| `Classic` | `post_content` als HTML/`wpautop`-alinea's | `post_content` | alinea-index `c:3` |
| `Elementor` | `_elementor_data` JSON, widgets `text-editor` / `heading` / `theme-post-content` | `_elementor_data` + cache leegmaken | element-id + veld `e:4a7f1c2.editor` |
| `Raw` (fallback) | hele `post_content` als één segment | `post_content` | `r:0` |

Uitbreidbaar via `apply_filters('rr_il_content_adapters', $classes)` — zo kan er later een
adapter bij voor WPBakery, Divi of een ACF-veld zonder dat de plaatsingslogica wijzigt.

## 4. Plaatsingsmodi

Drie modi, in volgorde van voorkeur. Losse CTA-zinnen ("Lees ook: …") bestaan bewust niet —
die lezen als advertentie en zijn precies waar SEO-teams over klagen.

1. **`wrap`** — de ankertekst staat al letterlijk in de brontekst; we zetten er alleen `<a>` omheen.
   Nul tekstwijziging, nul hallucinatierisico. Dit is de gewenste standaard.
2. **`rewrite`** — de LLM herschrijft één bestaande zin minimaal zodat het anker er grammaticaal
   in past. Streng begrensd (zie G15).
3. **`clause`** — de LLM hangt een korte bijzin aan een bestaande zin (`, ideaal voor <anker>`).
   Max 12 woorden, max 1 per pagina.

Zonder AI-key werkt alleen `wrap`. Dat is geen degradatie maar de veiligste modus; de tool blijft
volledig bruikbaar.

## 5. De gates

Volgorde: goedkope structurele checks eerst, dan positie, dan redactioneel, dan caps.

| # | Gate | Blokkeert |
|---|---|---|
| G1 | Self-link | bron == doel |
| G2 | Reeds gelinkt | bron linkt al naar doel |
| G3 | Verboden context | anker valt binnen `<a>`, `<code>`, `<pre>`, `<script>`, `<style>`, shortcode of heading |
| G4 | Anker verbatim | anker staat niet woordgrens-exact in het resultaat (voorkomt `Dev<a>enter</a>`) |
| G5 | Ankervorm | 1–8 woorden, 3–80 tekens, geen kale URL, geen losse cijfers |
| G6 | Intro-blok | eerste alinea van de post |
| G7 | Eerste zin | eerste zin van een alinea, tenzij die zin over het ankeronderwerp gaat |
| G8 | Topic-match | gastzin deelt geen betekenisvol woord met anker of focus-keyword van het doel |
| G9 | Eén per alinea | tweede toegevoegde link in hetzelfde segment |
| G10 | Dichtheid | meer dan 1 interne link per 100 woorden, of meer dan N toevoegingen per post |
| G11 | Anker-overoptimalisatie | dit doel heeft al ≥3 inkomende links met exact dit anker |
| G12 | Ankerdiversiteit | tweede anker in dezelfde post met dezelfde prefixklasse |
| G13 | Verboden frasering | "klik hier", "lees meer", "bekijk ook", "voor meer informatie", … |
| G14 | Geen nieuwe claims | toegevoegde woorden bevatten getallen, bedragen, data of superlatieven die niet in het origineel stonden |
| G15 | Herschrijfbegrenzing | woordaantal buiten `[origineel-2, origineel+8]`, eindleesteken gewijzigd, em-dash-inkapseling, of tautologie (zelfde inhoudswoord ≥2× in de nieuwe zin) |
| G16 | Integriteit | platte tekst vóór ≠ platte tekst ná, minus de toegestane toevoeging |

G16 is de enige die ook nog draait op het moment van schrijven. Faalt die, dan slaat de applier de
suggestie over en markeert hem `failed` met reden — er wordt niets weggeschreven.

## 6. Natuurlijk linkprofiel

Fase 1 stuurde op één getal: inkomende links per pagina. Dat is te weinig; je kunt 555 orphans
oplossen en eindigen met een profiel dat er machinaal uitziet. `IL_Profile` meet daarom:

- **spreiding** — aandeel van alle inkomende links dat naar de top 10% pagina's gaat;
- **ankerdiversiteit** — per doel: unieke ankers ÷ inkomende links, en site-breed het aandeel
  exact-match ankers;
- **dichtheid** — interne links per 100 woorden per post, met een bovengrens;
- **wederkerigheid** — aandeel A↔B-paren.

Die metingen zijn niet alleen rapportage: G10 en G11 lezen er rechtstreeks uit. En bij het kiezen
tussen gelijkwaardige bronnen kiest de planner de bron met veel inkomende en weinig uitgaande
links — die heeft autoriteit over en linkbudget vrij.

## 7. Veilig wegschrijven

1. Snapshot van `post_content` (+ `_elementor_data`) en een hash ervan in `wp_rr_il_suggestions`.
2. Insert bouwen, G16 draaien.
3. `wp_update_post()` — WordPress maakt zelf een revisie.
4. Bij Elementor: `_elementor_data` bijwerken en de Elementor-CSS-cache van die post legen.
5. Terugdraaien kan op twee manieren:
   - **chirurgisch** (voorkeur): zoek `data-rr-il="<uid>"` en haal alleen dat ene `<a>`-element weg,
     tekst blijft staan. Werkt ook als de pagina daarna is bewerkt.
   - **volledig**: snapshot terugzetten, alleen als de hash nog klopt.

Batches lopen via `IL_Queue`: één AJAX-call per item, hervatbaar, met per item een status en reden.
Geen cron in deze versie — je wilt erbij zitten als een tool 500 pagina's aanpast.

## 8. UI

Vijf tabs op één pagina:

- **Overzicht** — de bestaande tabel met orphans/thin (ongewijzigd).
- **Suggesties** — reviewwachtrij. Per suggestie: bron, doel, score, modus, anker (inline te
  bewerken), de gastzin met het anker gemarkeerd, en een voor/na-diff. Goedkeuren, afwijzen,
  bron wisselen.
- **Toepassen** — batch met voortgang, per item resultaat, en een knop om alles van deze ronde
  terug te draaien.
- **Data** — 3D-linkgraaf (`3d-force-graph`, dezelfde bibliotheek als het Ferienhaus Data-scherm).
  Bollen = pagina's, grootte = inkomende links, kleur = orphan/thin/ok/hub. Lijnen = interne links;
  door RankRepair geplaatste links krijgen een eigen kleur. Naast de graaf de profielmetingen.
- **Instellingen** — post-types, caps, modi aan/uit, AI aan/uit.

## 9. Datamodel

Eén nieuwe tabel. Suggesties gaan van transient naar database, want ze worden nu bewerkt en
goedgekeurd — dan mogen ze niet verdwijnen als een cache verloopt.

**`wp_rr_il_suggestions`**

| kolom | type | omschrijving |
|---|---|---|
| `id` | BIGINT PK | |
| `target_id` / `source_id` | BIGINT | doel- en bronpost |
| `score` | DECIMAL(6,5) | heuristische relevantie |
| `mode` | VARCHAR(12) | `wrap` / `rewrite` / `clause` |
| `anchor` | VARCHAR(255) | ankertekst (bewerkbaar) |
| `segment_ref` | VARCHAR(64) | adres binnen de bron |
| `sentence_before` / `sentence_after` | TEXT | alleen bij `rewrite` / `clause` |
| `status` | VARCHAR(12) | `pending` `approved` `rejected` `applied` `failed` `undone` |
| `reason` | VARCHAR(255) | gate-reden bij afwijzing of fout |
| `link_uid` | VARCHAR(20) | id in `data-rr-il`, voor chirurgisch terugdraaien |
| `content_before` | LONGTEXT | snapshot, alleen gevuld na toepassen |
| `content_hash` | CHAR(32) | md5 van het snapshot |
| `created_at` / `applied_at` | DATETIME | |

## 10. Bewust buiten scope

- Cron/geplande plaatsing — te riskant zonder toezicht.
- Adapters voor WPBakery/Divi/ACF — de registry ligt klaar, de adapters niet.
- Externe links, redirect-ketens, over-optimalisatie van bestaande (niet door ons geplaatste) links.
- Meertalige sites (WPML/Polylang) — links blijven binnen dezelfde taal omdat matching binnen
  post-type-silo's gebeurt, maar er is geen expliciete taalcheck.

## 11. Testbaarheid

Gates, inserter, adapters en profiel zijn pure functies: HTML/array in, resultaat uit. Ze draaien
zonder WordPress, met dezelfde losse-PHP-teststijl als fase 1 (`php tests/internal-links/test-x.php`).
Fixtures zijn afgeleid van echte bugs uit het Ferienhaus-traject — woordgrens (`Enter` in
`Deventer`), tautologie, em-dash-inkapseling, link in de intro, CTA-staart.
