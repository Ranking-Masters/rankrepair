# Integratietest in een echte WordPress

De tests in `tests/internal-links/` draaien zonder WordPress en dekken de pure
logica: de gates, de inserter, de adapterbomen, het tekstgereedschap. Wat daar
per definitie buiten valt is of het in een échte installatie ook werkt — of
Elementor herkend wordt, of er daadwerkelijk naar de database wordt geschreven,
en of terugdraaien de pagina exact teruggeeft.

Daar is dit voor.

```bash
tests/wordpress/run.sh          # starten, vullen en testen
tests/wordpress/run.sh down     # alles weggooien
```

Eerste keer duurt een paar minuten (images ophalen), daarna seconden. Je hebt
alleen Docker nodig; PHP hoeft niet op je eigen machine te staan.

WordPress draait daarna op <http://localhost:8899>, inloggen met `admin` /
`admin`. Handig om de add-on ook met de hand te bekijken: **RankRepair →
Interne Links**. De plugin is als map gekoppeld, dus een wijziging in de code is
meteen zichtbaar — geen herstart nodig.

## Wat er getest wordt

`seed.php` maakt zeven berichten aan, waarvan één doelpagina zonder inkomende
links en drie bronpagina's in verschillende editors: Gutenberg, de klassieke
editor en Elementor (met een echte `_elementor_data`-boom in postmeta).

`smoke.php` loopt daarna de hele keten af:

1. herkent elke adapter zijn eigen editor, en levert die linkbare alinea's op;
2. dekt de tekstindex elke gescande pagina;
3. vindt de planner suggesties in alle drie de editors;
4. komt de link echt in de opgeslagen content, en blijft de platte tekst gelijk;
5. bewaart WordPress een revisie van vóór de wijziging;
6. geeft terugdraaien de pagina exact terug, zonder restanten;
7. weigeren de gates een slechte LLM-herschrijving — verzonnen getal, CTA-staart,
   halve zin, ingeklemd anker — en blijft de content dan onaangeroerd;
8. gaat een nette herschrijving er wél doorheen, en is ook die terug te draaien.

## Let op

Dit is een wegwerpomgeving. `run.sh down` gooit de database weg. Draai dit nooit
tegen een echte site: `smoke.php` plaatst en verwijdert links in de content.
