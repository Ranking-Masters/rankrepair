=== RankRepair ===
Contributors: danique
Tags: seo, meta titles, meta descriptions, pagespeed, optimization
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Los veelvoorkomende SEO- en performance-problemen op met één klik. Dashboard met PageSpeed integratie en modulaire add-ons.

== Description ==

RankRepair is een modulaire WordPress plugin die veelvoorkomende SEO- en performance-problemen detecteert en je helpt ze met één klik op te lossen.

**Huidige functionaliteiten:**

= Dashboard =
* Google PageSpeed Insights integratie (Performance, Toegankelijkheid, Best Practices, SEO)
* Overzicht van alle problemen met directe koppeling naar de juiste add-on
* Analyse geschiedenis

= Meta Titels & Beschrijvingen =
* Importeer data uit SE Ranking (CSV/Excel)
* Automatische detectie van dubbele titels en beschrijvingen
* Inline bewerken van nieuwe titels en beschrijvingen
* Karakter-teller met kleurindicatie (te kort / goed / te lang)
* Direct toepassen op WordPress pagina's (compatibel met Yoast SEO en Rank Math)
* Filters: Alles, Duplicaten, Te Lang, Te Kort, Ontbrekend, Bewerkt, Toegepast
* Zoekfunctie

**Toekomstige add-ons (binnenkort beschikbaar):**
* Redirects Checker
* Afbeeldingen Optimizer
* Formulieren Tester

= Vereisten =

* WordPress 5.8 of hoger
* PHP 7.4 of hoger
* Google PageSpeed Insights API key (gratis)
* SE Ranking API key (optioneel, voor meta data import)

== Installation ==

1. Upload de `rankrepair` map naar `/wp-content/plugins/`
2. Activeer de plugin via het 'Plugins' menu in WordPress
3. Ga naar RankRepair > Instellingen om je API keys in te vullen
4. Begin met het analyseren en optimaliseren van je website!

== Frequently Asked Questions ==

= Hoe krijg ik een PageSpeed API key? =

Ga naar de Google Cloud Console, maak een project aan en activeer de PageSpeed Insights API. Genereer vervolgens een API key.

= Kan ik de plugin uitbreiden met eigen add-ons? =

Ja! RankRepair is modulair opgebouwd. Je kunt eenvoudig nieuwe add-ons toevoegen door de RR_Addon_Base class te extenden.

== Changelog ==

= 1.9.6 =
* Nieuw: Structured Data — placeholder `{{term_image}}` voor de afbeelding van een categorie. Volgorde: WooCommerce-thumbnail van de categorie; ontbreekt die of is het een logo, dan de afbeelding van de grootste subcategorie, daarna het eerste product. Logo's worden herkend via het RankRepair-logo, het Yoast-bedrijfslogo, het thema-logo, de site-icon en een bestandsnaam met "logo". Het resultaat wordt per categorie bewaard en bij wijzigingen vernieuwd.
* Nieuw: Structured Data — placeholders die leeg uitkomen laten de hele key weg uit de JSON-LD (geen `"image": ""` meer). Ook een leeg ImageObject-omhulsel en lege lijsten verdwijnen. Templates zonder lege placeholder blijven ongewijzigd.
* Nieuw: Structured Data — placeholder `{{org_logo}}`. De voorbeeldtemplates hebben bij Organization alleen het logo; de ProductGroup (L2/L3) en de blogcategorie-CollectionPage gebruiken `{{term_image}}`.
* Wijziging: de automatisch opgebouwde Organization toont de vaste bedrijfsafbeelding (`rr_sd_org_image`) niet meer op categoriepagina's, alleen het logo. Terug te zetten met het filter `rr_sd_org_image_on_term_pages`.
* Nieuw: Structured Data — waarschuwing in de inspector als een template die voor meerdere pagina's geldt (categorie, product, pagina, bericht, "alle pagina's") een vaste afbeelding bevat.
* Nieuw: optionele instelling "Yoast social image voor categorieën" (standaard uit). Categorieën zonder eigen social image in Yoast krijgen de categorieafbeelding als og:image; een in Yoast ingestelde afbeelding wordt nooit overschreven.
* Fix: Structured Data — categoriepagina's met veel producten konden het PHP-geheugen overschrijden zodra een eigen JSON was opgeslagen. Placeholders worden nu alleen gebouwd als de template ze gebruikt, en hasVariant gebruikt een lichte productquery zonder volledige postmeta. Bij categorieën met meer dan 50 producten kan bij gelijke menu_order één product van plek wisselen in hasVariant.

= 1.9.5 =
* Fix: Malware-scan — WebP/AVIF-beeldconversieregels (ShortPixel e.d.) in .htaccess worden niet meer als `cloaked_redirect` gemeld. Een regel met een User-Agent/Accept-conditie die alleen foto.jpg naar foto.jpg.webp/.avif herschrijft is geen cloaking. Echte cloaking (redirect, extern doel, R/P-flag, ander doel dan een beeld) blijft kritiek. Alle UA/referer-gebonden regels worden nu beoordeeld, niet alleen de eerste.

= 1.9.4 =
* Nieuw: Structured Data — `rr_sd_graph`-filter waarmee een thema per pagina nodes aan de bestaande @graph kan toevoegen, in plaats van een los <script> in de head. Voorheen was alleen site-brede JSON per contexttype mogelijk. De filter krijgt de paginacontext mee (`type`, `post_id`, enz.) en voegt uit zichzelf niets toe — bestaande sites veranderen dus niet.

= 1.9.3 =
* Nieuw: Image Optimizer — afbeeldingen uitsluiten van optimalisatie (bv. teamfoto's/handtekeningen die nooit gecomprimeerd/geconverteerd mogen worden). Uitsluiten per rij of in bulk vanuit de hoofdtabel, een overzicht met terugzet-mogelijkheid (los of in bulk), en handmatig toevoegen via de media-bibliotheek.
* Fix: Image Optimizer — de instellingenbalk en de tabel waren op smalle schermen niet horizontaal scrollbaar, waardoor rechterkolommen (Status, Optimaliseer, Uitsluiten) onbereikbaar konden zijn.
* Fix: Image Optimizer — "Optimaliseer alles (N)" en de statkaart "Nog te optimaliseren" konden een ander aantal tonen dan de daadwerkelijke lijst; beide zijn nu altijd consistent met wat er verwerkt wordt.

= 1.9.2 =
* Fix: malware-scan meldde ten onrechte "verdacht PHP-bestand" voor macOS zip-metadata (__MACOSX/._*) die na een migratie via een op een Mac gemaakte/uitgepakte back-up in uploads terecht was gekomen. Deze AppleDouble-bestanden bevatten geen uitvoerbare code en worden nu genegeerd.

= 1.9.1 =
* Fix: malware-scan meldde ten onrechte "cloaking" bij elke user-agent/referer-conditionele RewriteRule in .htaccess, ook zonder daadwerkelijke substitutie — LiteSpeed Cache's eigen mobile/webp-vary-blokken triggerden dit. Vereist nu een echte substitutie/redirect voordat het als cloaking gemeld wordt.
* Fix: malware-scan meldde ten onrechte "verdacht PHP-bestand" voor bekende databestanden van Sucuri Security (uploads/sucuri/) en MC4WP: Mailchimp for WP (uploads/mailchimp-for-wp/debug-log.php). Toegevoegd aan de allowlist.

= 1.9.0 =
* Interne Links fase 2: goedgekeurde suggesties worden nu echt in de content geplaatst.
* Werkt in Gutenberg, de klassieke editor en Elementor via één gedeelde content-laag; uitbreidbaar met de filter `rr_il_content_adapters`.
* 17 deterministische controles voor plaatsing (zelf-links, ankervorm, intro-alinea, eerste zin, topic-match, linkdichtheid, anker-overoptimalisatie, anker-ambiguïteit, verzonnen claims, tekstintegriteit).
* Eén ankertekst wijst naar één bestemming, en ankers die grammaticaal een zinsbegin zijn worden geweigerd — getoetst op de echte content van rankingmasters.nl.
* Fix: het standaard OpenRouter-model stond op google/gemini-2.0-flash-001, dat niet meer bestaat; wie het modelveld leeg liet kreeg een foutmelding. Nu google/gemini-3.8-flash.
* AI-aanroepen sturen `reasoning: low` mee en hebben een ruimer tokenbudget. Redeneermodellen besteden een deel van hun budget aan denkwerk; met de oude 300 tokens kwam er bij zo'n model niets uit, en de rekening was 39x hoger.
* Fix: add-ons worden op 'init' geladen in plaats van bij 'plugins_loaded', zodat WordPress geen "textdomain te vroeg geladen"-notice meer geeft.
* Drie plaatsingsmodi: bestaande woorden linken, zin minimaal herschrijven, korte bijzin toevoegen. Zonder AI-key werkt de eerste modus volledig.
* Elke geplaatste link is per stuk terug te draaien, ook nadat de pagina daarna is bewerkt. WordPress bewaart een revisie van elke wijziging.
* Nieuw Data-scherm met een 3D-linkgraaf en profielmetingen (spreiding, ankerdiversiteit, dichtheid, wederkerigheid).
* Suggesties staan nu in een tabel in plaats van een transient, en zijn te beoordelen, te bewerken en te exporteren.
* Prestaties: de scan bouwt een tekstindex per post, zodat relevantie bepalen niet meer elke pagina opnieuw inleest.

= 1.8.1 =
* Fix: malware-scan meldde ten onrechte "gewijzigd core-bestand" voor wp-includes/version.php op niet-Engelstalige sites — dit bestand verschilt legitiem per taal-build (locale-checksum vs. de daadwerkelijk geïnstalleerde en_US-kernbestanden). Wordt nu ook tegen de en_US-checksum gecontroleerd voordat het als wijziging gerapporteerd wordt.
* Fix: malware-scan meldde ten onrechte "verdacht PHP-bestand" voor de WP-Optimize ServerSignature-zelftest (uploads/wpo/server-signature/on|off/test.php). Toegevoegd aan de allowlist voor bekende-legitieme uploads-paden.

= 1.8.0 =
* Interne Links fase 1: detectie van pagina's met 0 of 1 inkomende interne link, plus linksuggesties met ankertekst.

= 1.6.1 =
* Image Optimizer fix: na WebP-conversie worden hardcoded afbeeldings-URL's in post-content automatisch omgeschreven naar de nieuwe .webp-URL. Voorheen bleef het originele (verwijderde) .png/.jpg in content staan → 404 op afbeeldingen.

= 1.2.0 =
* Security: escaping verbeterd in admin pagina's (esc_url, esc_attr)
* Security: model-naam URL-geëncodeerd bij Google AI Studio API aanroepen
* Security: sslverify loopback request via WordPress filter (https_local_ssl_verify)
* Betrouwbaarheid: scan en import gebruiken nu een database-transactie (DELETE + COMMIT/ROLLBACK)
* Bug: inline database-migratie verwijderd uit save_meta AJAX handler
* Bug: post_id validatie toegevoegd aan delete_meta
* Bug: H1 extractie in WordPress scan gebruikt nu dezelfde logica als loopback fetch (inclusief Elementor/ACF)
* Performance: post_content niet meer dubbel opgeladen in meta-manager query
* Post types nu uitbreidbaar via rr_scan_post_types filter
* Inline JavaScript verplaatst van settings pagina naar admin-script.js
* Versienummer gesynchroniseerd in plugin header en readme

= 1.0.0 =
* Eerste release
* Dashboard met PageSpeed Insights integratie
* Meta Titels & Beschrijvingen add-on met SE Ranking import
