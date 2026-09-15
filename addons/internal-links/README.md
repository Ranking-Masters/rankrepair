# Interne Links — hoe dit in elkaar zit

Deze add-on doet twee dingen: pagina's vinden die te weinig inkomende interne
links hebben (fase 1, augustus), en die links er ook echt in zetten (fase 2,
september). Dit bestand is de instap voor wie eraan verder werkt.

Ontwerpdocumenten: [`docs/superpowers/specs/2026-08-12-…-design.md`](../../docs/superpowers/specs/2026-08-12-internal-links-detection-suggestions-design.md)
en [`docs/superpowers/specs/2026-09-15-internal-links-placement-design.md`](../../docs/superpowers/specs/2026-09-15-internal-links-placement-design.md).

## De keten

```
scan ──► index ──► planner ──► gates ──► suggestie ──► review ──► applier ──► content
                     ▲                                                │
                     └──────────────── profiel ◄──────────────────────┘
```

| Bestand | Doet |
|---|---|
| `class-il-graph-scanner.php` | leest elke pagina, vult de linkgraaf én de tekstindex |
| `class-il-index.php` | woordtelling per post in postmeta, zodat relevantie niet elke keer alles opnieuw inleest |
| `class-il-matcher.php` | TF-IDF + cosine, met een boost op een gedeeld focus-keyword |
| `class-il-planner.php` | kiest bron, alinea, ankertekst en modus |
| `class-il-gates.php` | 16 deterministische controles; wijst af met reden |
| `class-il-inserter.php` | bouwt de nieuwe alinea-HTML, en haalt een link er ook weer uit |
| `class-il-applier.php` | schrijft weg, bewaart een snapshot, draait terug |
| `class-il-content.php` + `adapters/` | vertaalt elke editor van en naar segmenten |
| `class-il-profile.php` | meet het linkprofiel; levert ook de caps waar de gates op toetsen |
| `class-il-suggestions.php` | opslag in `wp_rr_il_suggestions` |
| `class-il-config.php` | instellingen met hun standaardwaarden |

## De twee regels die alles sturen

1. **De LLM kiest, de code bewaakt.** Welke zin en welke woorden is een
   redactioneel oordeel. Of die zin bestaat, of het anker er letterlijk in staat
   en of er geen tekst verdwijnt is een invariant — en die hoort in PHP, niet in
   een prompt. Wie een nieuwe regel toevoegt, voegt hem toe als gate, niet als
   zin in de prompt.
2. **Geen link is beter dan een geforceerde link.** Er is geen quotum. Vindt de
   planner niets, dan levert hij niets.

## Een editor toevoegen

Nieuwe pagebuilder ondersteunen = één adapter, verder niets:

```php
add_filter('rr_il_content_adapters', function ($adapters) {
    require_once __DIR__ . '/class-il-adapter-divi.php';
    array_unshift($adapters, 'IL_Adapter_Divi');
    return $adapters;
});
```

Een adapter erft van `IL_Adapter_Base` en implementeert `slug`, `label`,
`detect`, `read`, `apply`, `snapshot`, `restore` en `priority`. `read()` geeft
segmenten terug (`ref`, `html`, `kind`); `apply()` krijgt `ref => nieuwe html`.
De planner, de gates en de inserter veranderen niet mee — die kennen alleen
segmenten.

Twee dingen die je in `IL_Adapter_Classic` en `IL_Adapter_Elementor` terugziet
en die je zelf ook wilt:

- **Het adres moet stabiel zijn.** Elementor gebruikt element-id's en geen
  posities, omdat een widget die er bovenop komt anders alle adressen verschuift.
- **Ongewijzigd terugschrijven moet byte-identiek zijn.** `IL_Adapter_Classic`
  bewaart de scheidingstekens tussen alinea's apart, zodat `implode('')` altijd
  het origineel oplevert. Dat is precies wat de test afdwingt.

## Tests

```bash
tests/run.sh                          # alles
php tests/internal-links/test-il-gates.php    # één bestand
```

Geen phpunit en geen composer — losse PHP-scripts, net als fase 1. De gates, de
inserter, de adapters en het tekstgereedschap zijn puur en draaien zonder
WordPress; `tests/internal-links/bootstrap.php` levert de handvol
WordPress-functies die ze aanroepen. Loopt een test daarop stuk omdat er een
functie ontbreekt, dan is dat het signaal: er is WordPress de pure laag in
geslopen.

De fixtures in `test-il-gates.php` zijn echte bugs, de meeste uit het
interne-linktraject voor ferienhausniederlande.de. Voeg bij een nieuwe klacht
eerst de fixture toe en dan pas de gate.

## Wat er bewust niet in zit

- **Cron.** Er is geen geplande plaatsing. Je wilt erbij zitten als een tool
  honderden pagina's aanpast.
- **Adapters voor Divi, WPBakery en ACF.** De registry ligt klaar, de adapters
  niet.
- **Meertalige sites.** Matching blijft binnen een post-type-silo, maar er is
  geen expliciete taalcheck (WPML/Polylang).
- **Een tweede AI-ronde.** Bij Ferienhaus beoordeelt een tweede model de
  geplaatste link. Hier doet de mens dat, in het Suggesties-tabblad.

## Handmatig terugdraaien

Elke geplaatste link staat in de HTML als
`<a href="…" data-rr-il="<id>">…</a>`. Wie buiten de plugin om wil opruimen:

```sql
SELECT source_id, target_id, anchor, link_uid
FROM wp_rr_il_suggestions WHERE status = 'applied';
```

De knop "Terugdraaien" in het Suggesties-tabblad haalt precies dat ene
`<a>`-element weg en zet bij de herschrijfmodi ook de oude zin terug. Dat werkt
ook als de pagina daarna met de hand is bewerkt — een snapshot terugzetten zou
dan andermans werk overschrijven.
