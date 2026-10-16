# Alt-teksten (AI) Add-on — ontwerp

**Datum:** 2026-10-16
**Add-on:** `addons/alt-text/` (nieuw)
**Doel:** Afbeeldingen zonder alt-tekst vinden, met vision-AI een korte Nederlandse
beschrijving voorstellen, en na goedkeuring wegschrijven naar `_wp_attachment_image_alt`.

## Beslissingen (brainstorm)
1. **Scope:** hele mediabibliotheek — alle `image/*`-attachments waar het alt-veld leeg/afwezig is.
2. **Toepassen:** review-lijst; genereren is read-only, pas bij goedkeuren (per stuk of bulk) opslaan.
3. **Stijl:** visueel & kort (WCAG/toegankelijkheid), ~1 feitelijke NL-zin, geen keyword-stuffing.
4. **Taal:** Nederlands (site-taal).
5. **Afbeelding naar AI:** base64 van een geschaalde versie (medium, max ~768px) — werkt ook als de
   site afgeschermd is (dev-mode/503) en vermijdt afhankelijkheid van publieke bereikbaarheid.
6. **AI:** hergebruik gedeelde `rr_gemini_api_key` + `rr_ai_provider` + `rr_ai_model`. Leeg model →
   vision-default per provider (OpenRouter: `google/gemini-2.0-flash-001`; Google: `gemini-1.5-flash`).
7. **UI:** aparte pagina onder RankRepair (`rankrepair-alt-text`), zoals de andere addons.

## Bestanden
- `addons/alt-text/class-rr-alt-text.php` — PURE logica (geen WP): `build_prompt()`,
  `sanitize_alt($raw)`, `parse_openrouter($body)`, `parse_google($body)`, `pick_size($meta_sizes)`,
  `data_uri($mime,$base64)`. Unit-getest.
- `addons/alt-text/class-addon-alt-text.php` — addon (extend `RR_Addon_Base`): admin-pagina, scan,
  AJAX `generate`/`apply`, HTTP-call naar AI (provider-aware), `get_stats()`.
- `addons/alt-text/alt-text.js` + `alt-text.css` — review-UI.
- `tests/alt-text/test-rr-alt-text.php` — unit-tests pure logica.
- Registratie: 2 regels in `rankrepair.php` (`register_addons` + `get_available_addons`).
- `uninstall.php` — opties opruimen.

## Data-flow
1. **Scan** — `WP_Query`/`get_posts` op `post_type=attachment`, `post_mime_type=image`, meta
   `_wp_attachment_image_alt` NOT EXISTS of leeg. Gepagineerd (bv. 20/pagina), met totaalteller.
2. **Genereren** (AJAX, per attachment) — laad geschaalde file (medium, anders full; downscale naar
   max ~768px via WP image editor indien nodig), base64 → data-URI, bouw provider-specifiek
   vision-request, roep AI aan, `sanitize_alt()`. **Slaat niets op.** Retourneert voorstel.
3. **Review** — lijst: thumbnail + bestandsnaam/titel + **bewerkbaar** voorstel + checkbox.
4. **Toepassen** (AJAX) — bij goedkeuren `update_post_meta($id,'_wp_attachment_image_alt',$alt)`.
   Terug te draaien (leegmaken/bewerken via dezelfde UI of de media-bibliotheek).

## AI-request
- **Prompt (NL):** "Beschrijf deze afbeelding in één korte, feitelijke Nederlandse zin voor een
  alt-attribuut. Begin niet met 'afbeelding van' of 'foto van'. Geen aanhalingstekens. Maximaal
  ~125 tekens. Geen keyword-stuffing."
- **OpenRouter** (`https://openrouter.ai/api/v1/chat/completions`, Bearer):
  `messages=[{role:user, content:[{type:text,text:prompt},{type:image_url,image_url:{url:dataUri}}]}]`,
  `max_tokens≈100`, `temperature≈0.4`.
- **Google** (`generateContent?key=`):
  `contents=[{parts:[{text:prompt},{inline_data:{mime_type,data:base64}}]}]`.
- **sanitize_alt():** omringende quotes strippen, whitespace normaliseren naar één regel, leidende
  "afbeelding van/foto van/image of" verwijderen, lengte-cap (~125 zacht, 200 hard), trailing punt weg.

## Dashboard-stat
`get_stats()` → `['total' => <aantal image-attachments>, 'issues' => <aantal zonder alt>]`.
Kaart toont "N problemen gevonden" (zonder-alt = echte issue, past hier).

## Veiligheid & robuustheid
- Alle AJAX: nonce + `current_user_can('manage_options')`.
- Read-only tot goedkeuren.
- Ontbrekende AI-key → duidelijke melding met verwijzing naar de AI-instellingen (meta-manager).
- AI-fouten per afbeelding opvangen; batch gaat door, mislukte items gemarkeerd.
- Model moet vision-capabel zijn; lege default is dat. Niet-vision model → nette foutmelding.

## Testen
- **Unit (pure):** `sanitize_alt` (quotes/prefixes/lengte/whitespace), `parse_openrouter`/`parse_google`
  (succes + error-vormen), `build_prompt`, `pick_size`.
- **Live (Ranking Masters staging):** scan vindt afbeeldingen zonder alt → genereer echte alt via
  OpenRouter → review → pas 1 toe → controleer `_wp_attachment_image_alt`. Ingelogd werkt ondanks
  dev-mode; base64 omzeilt de 503 voor de afbeelding.

## Buiten v1 (uitbreidbaar)
Decoratieve afbeeldingen markeren (lege alt bewust), bulk-hergenereren, meertalige alt, alt ook voor
afbeeldingen in externe/CDN-bronnen, kostenraming per batch.
