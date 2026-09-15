<?php
/**
 * De planner: waar moet deze link staan?
 *
 * Fase 1 beantwoordde "welke pagina heeft links nodig" en "welke pagina's zijn
 * relevant". Dit beantwoordt de volgende vraag: in wélke alinea, aan wélke zin,
 * met wélke woorden — en of dat überhaupt kan zonder de tekst te verminken.
 *
 * Voorkeursvolgorde:
 *   1. wrap    — de woorden staan er al, we zetten er alleen een link omheen.
 *   2. rewrite — één bestaande zin minimaal herschrijven zodat het anker past.
 *   3. clause  — een korte bijzin aan een bestaande zin hangen.
 *
 * Vindt de planner niets, dan levert hij niets. Er is geen quotum: een
 * geforceerde link is slechter dan geen link.
 */

if (!defined('ABSPATH')) {
    exit;
}

class IL_Planner {

    /** Hoeveel bronkandidaten we per doel doorzoeken voor we opgeven. */
    const SOURCE_POOL = 25;

    /**
     * @return array ['accepted' => [...], 'rejected' => [...]]
     */
    public static function plan_for_target($target_id) {
        $target = get_post($target_id);
        if (!$target || $target->post_status !== 'publish') {
            return ['accepted' => [], 'rejected' => []];
        }

        // Hoeveel er nog bij mag: het laagste van "hoeveel we per pagina zoeken"
        // en "hoeveel inkomende links dit doel er per ronde bij mag krijgen".
        $open = count(IL_Suggestions::query([
            'target_id' => $target_id,
            'status'    => [
                IL_Suggestions::STATUS_PENDING,
                IL_Suggestions::STATUS_APPROVED,
                IL_Suggestions::STATUS_APPLIED,
            ],
        ]));
        $limit = min(
            (int) IL_Config::get('suggestions_per_target'),
            (int) IL_Config::get('max_links_per_target') - $open
        );
        if ($limit < 1) {
            return ['accepted' => [], 'rejected' => []];
        }

        $modes    = IL_Config::modes();
        $anchors  = self::anchor_candidates($target);
        $accepted = [];
        $rejected = [];

        if (empty($anchors) || empty($modes)) {
            return ['accepted' => [], 'rejected' => []];
        }

        $sources = self::candidate_sources($target, self::SOURCE_POOL);

        // Ronde 1 — wrap. De veiligste modus, dus eerst alle bronnen daarop proberen.
        if (in_array('wrap', $modes, true)) {
            foreach ($sources as $src) {
                if (count($accepted) >= $limit) {
                    break;
                }
                $hit = self::try_wrap($target, $src, $anchors, $accepted, $rejected);
                if ($hit) {
                    $accepted[] = $hit;
                }
            }
        }

        // Ronde 2 — de LLM mag een zin herschrijven, maar alleen als wrap
        // onvoldoende opleverde en er een AI-configuratie is.
        $ai_modes = array_values(array_intersect($modes, ['rewrite', 'clause']));
        if (count($accepted) < $limit && !empty($ai_modes) && IL_Config::ai_available()) {
            foreach ($sources as $src) {
                if (count($accepted) >= $limit) {
                    break;
                }
                if (self::already_used($src['id'], $accepted)) {
                    continue;
                }
                $hit = self::try_ai($target, $src, $ai_modes, $accepted, $rejected);
                if ($hit) {
                    $accepted[] = $hit;
                }
            }
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /* ------------------------------------------------------------- ankers */

    /**
     * Mogelijke ankerteksten voor een doelpagina, specifiek eerst.
     *
     * Het focus-keyword is de beste kandidaat: dat is waar de pagina op mikt.
     * Daarna woordgroepen uit de titel — die staan vaak letterlijk in andere
     * artikelen, en dan is wrap mogelijk.
     */
    public static function anchor_candidates(WP_Post $target) {
        $out = [];

        $kw = IL_Index::focus_keyword($target->ID);
        if ($kw !== '') {
            $out[] = $kw;
        }

        // "10 tips voor betere SEO" → "tips voor betere SEO": een leidend
        // opsommingsgetal hoort niet in een ankertekst.
        $title = preg_replace('/^\s*\d+[\.\):]?\s+/u', '', get_the_title($target->ID));
        $title = preg_replace('/\s*[\|\-–—]\s*[^|\-–—]{0,40}$/u', '', $title); // site-suffix eraf

        foreach (IL_Text::ngrams($title, 2, 6) as $gram) {
            $out[] = $gram;
        }

        $seen   = [];
        $unique = [];
        foreach ($out as $a) {
            $key = IL_Text::normalize_anchor($a);
            if ($key === '' || isset($seen[$key]) || mb_strlen($key, 'UTF-8') < 3) {
                continue;
            }
            $seen[$key] = true;
            $unique[]   = $a;
        }
        return $unique;
    }

    /* ------------------------------------------------------------ bronnen */

    /**
     * Relevante bronpagina's, gewogen op inhoud én op wat ze te geven hebben.
     *
     * Een pagina met veel inkomende links heeft autoriteit om door te geven;
     * een pagina die al vol staat met uitgaande links heeft niets meer te
     * verdelen. Dat is de eenvoudigste vorm van sturen op een gezond profiel.
     */
    public static function candidate_sources(WP_Post $target, $pool) {
        $entry = IL_Index::get($target->ID);
        if (!$entry) {
            IL_Index::build_for($target->ID);
            $entry = IL_Index::get($target->ID);
        }
        if (!$entry) {
            return [];
        }

        $exclude = array_flip(array_merge([$target->ID], IL_Graph_Scanner::sources_linking_to($target->ID)));
        $corpus  = IL_Index::corpus();

        $candidates = [];
        foreach ($corpus as $pid => $e) {
            if (isset($exclude[$pid]) || empty($e['t'])) {
                continue;
            }
            if (isset($e['p']) && $e['p'] !== $target->post_type) {
                continue; // blog linkt naar blog, pagina naar pagina
            }
            if (isset($e['s']) && $e['s'] !== 'publish') {
                continue;
            }
            $candidates[$pid] = ['tokens' => $e['t'], 'keyword' => isset($e['k']) ? $e['k'] : ''];
        }
        if (empty($candidates)) {
            return [];
        }

        $scored = IL_Matcher::score_candidates(
            ['tokens' => $entry['t'], 'keyword' => isset($entry['k']) ? $entry['k'] : ''],
            $candidates,
            (int) $pool * 2,
            IL_Index::idf()
        );

        // De index is een momentopname van de laatste scan. Een pagina die
        // daarna op concept is gezet of in de prullenbak ligt mag geen bron zijn,
        // dus controleren we de kop van de lijst nog even bij de bron zelf.
        $scored = array_values(array_filter($scored, function ($hit) {
            return get_post_status($hit['id']) === 'publish';
        }));

        $inbound = IL_Graph_Scanner::inbound_map();
        foreach ($scored as &$s) {
            $in = isset($inbound[$s['id']]) ? (int) $inbound[$s['id']] : 0;
            $s['relevance'] = $s['score'];
            $s['score']     = $s['score'] * (1 + min(0.5, 0.08 * $in));
        }
        unset($s);

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($scored, 0, (int) $pool);
    }

    /* --------------------------------------------------------------- wrap */

    private static function try_wrap(WP_Post $target, array $src, array $anchors, array $accepted, array &$rejected) {
        $source_id = (int) $src['id'];
        $segments  = IL_Content::linkable_segments($source_id);
        if (empty($segments)) {
            return null;
        }

        $ctx = self::context($target, $source_id, $accepted);
        $url = get_permalink($target->ID);

        foreach ($segments as $seg) {
            foreach ($anchors as $anchor) {
                foreach (IL_Text::phrase_offsets($seg['html'], $anchor) as $hit) {
                    $cand = [
                        'source_id'    => $source_id,
                        'target_id'    => $target->ID,
                        'mode'         => 'wrap',
                        'anchor'       => $hit['match'], // originele schrijfwijze behouden
                        'url'          => $url,
                        'uid'          => IL_Inserter::new_uid(),
                        'html'         => $seg['html'],
                        'offset'       => $hit['offset'],
                        'length'       => strlen($hit['match']),
                        'segment_ref'  => $seg['ref'],
                        'segment_kind' => $seg['kind'],
                        'score'        => $src['score'],
                    ];

                    $verdict = self::evaluate($cand, $ctx);
                    if ($verdict['ok']) {
                        return $cand;
                    }
                    $rejected[] = self::rejection($cand, $verdict);
                }
            }
        }
        return null;
    }

    /* ----------------------------------------------------------------- AI */

    /**
     * Vraagt de LLM om één plaatsing in deze bron. Het antwoord gaat door exact
     * dezelfde gates als een wrap-kandidaat — het model mag kiezen, niet beslissen.
     */
    private static function try_ai(WP_Post $target, array $src, array $modes, array $accepted, array &$rejected) {
        $source_id = (int) $src['id'];
        $segments  = IL_Content::linkable_segments($source_id);
        if (empty($segments)) {
            return null;
        }

        $ctx    = self::context($target, $source_id, $accepted);
        $plan   = self::ask_ai($target, get_post($source_id), $segments, $modes);
        if (!$plan) {
            return null;
        }

        $seg = null;
        foreach ($segments as $s) {
            if ($s['ref'] === $plan['segment_ref']) {
                $seg = $s;
                break;
            }
        }
        if (!$seg) {
            return null;
        }

        $cand = [
            'source_id'       => $source_id,
            'target_id'       => $target->ID,
            'mode'            => $plan['mode'],
            'anchor'          => $plan['anchor'],
            'url'             => get_permalink($target->ID),
            'uid'             => IL_Inserter::new_uid(),
            'html'            => $seg['html'],
            'segment_ref'     => $seg['ref'],
            'segment_kind'    => $seg['kind'],
            'sentence_before' => $plan['sentence_before'],
            'sentence_after'  => $plan['sentence_after'],
            'score'           => $src['score'],
        ];

        $verdict = self::evaluate($cand, $ctx);
        if ($verdict['ok']) {
            return $cand;
        }
        $rejected[] = self::rejection($cand, $verdict);
        return null;
    }

    private static function ask_ai(WP_Post $target, WP_Post $source, array $segments, array $modes) {
        if (!function_exists('rr_ai_complete')) {
            return null;
        }

        $paragraphs = [];
        foreach (array_slice($segments, 0, 6) as $seg) {
            $paragraphs[] = '[' . $seg['ref'] . '] ' . mb_substr($seg['text'], 0, 600, 'UTF-8');
        }

        $kw       = IL_Index::focus_keyword($target->ID);
        $modelist = implode(' of ', $modes);

        $prompt = "Je plaatst één interne link op een Nederlandse website.\n\n"
            . "DOELPAGINA: \"" . get_the_title($target->ID) . "\"\n"
            . ($kw !== '' ? "FOCUS-KEYWORD DOELPAGINA: \"{$kw}\"\n" : '')
            . "BRONPAGINA: \"" . get_the_title($source->ID) . "\"\n\n"
            . "ALINEA'S UIT DE BRONPAGINA:\n" . implode("\n\n", $paragraphs) . "\n\n"
            . "Kies de alinea waar een link naar de doelpagina het natuurlijkst past, en pas één\n"
            . "bestaande zin daaruit minimaal aan zodat de ankertekst er grammaticaal in staat.\n\n"
            . "REGELS:\n"
            . "- Neem de zin die je aanpast LETTERLIJK over in 'zin_voor', inclusief leesteken.\n"
            . "- 'zin_na' is dezelfde zin, minimaal aangepast, met de ankertekst er letterlijk in.\n"
            . "- Voeg geen getallen, bedragen, jaartallen of superlatieven toe die er niet stonden.\n"
            . "- Het eindleesteken blijft hetzelfde.\n"
            . "- Geen losse verwijszinnen ('Lees ook', 'Klik hier', 'Bekijk ook').\n"
            . "- Niet de eerste zin van een alinea, tenzij die zin echt over het onderwerp gaat.\n"
            . "- De ankertekst is 2 tot 6 woorden en beschrijft waar de link heen gaat.\n"
            . "- Past er geen link natuurlijk? Antwoord dan exact: GEEN\n\n"
            . "Modus mag zijn: {$modelist} (rewrite = zin herschrijven, clause = korte bijzin toevoegen).\n\n"
            . "Antwoord UITSLUITEND met JSON, zonder uitleg:\n"
            . '{"alinea":"<ref>","modus":"rewrite","anker":"...","zin_voor":"...","zin_na":"..."}';

        $resp = rr_ai_complete($prompt, ['max_tokens' => 800, 'temperature' => 0.3]);
        if (is_wp_error($resp) || stripos(trim($resp), 'GEEN') === 0) {
            return null;
        }

        $json = self::extract_json($resp);
        if (!$json) {
            return null;
        }

        $mode = isset($json['modus']) ? strtolower(trim($json['modus'])) : '';
        if (!in_array($mode, $modes, true)) {
            $mode = $modes[0];
        }

        $plan = [
            'segment_ref'     => isset($json['alinea']) ? trim($json['alinea'], " \t[]") : '',
            'mode'            => $mode,
            'anchor'          => isset($json['anker']) ? IL_Text::normalize_ws($json['anker']) : '',
            'sentence_before' => isset($json['zin_voor']) ? IL_Text::normalize_ws($json['zin_voor']) : '',
            'sentence_after'  => isset($json['zin_na']) ? IL_Text::normalize_ws($json['zin_na']) : '',
        ];

        foreach ($plan as $v) {
            if ($v === '') {
                return null;
            }
        }
        return $plan;
    }

    /** LLM's zetten hun JSON graag tussen ```-blokken of praatjes. */
    private static function extract_json($text) {
        $text = (string) $text;
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($json) ? $json : null;
    }

    /* ------------------------------------------------------------ context */

    /**
     * Alles wat de gates over deze bron moeten weten. Inclusief wat er in
     * dezelfde ronde al is geaccepteerd — anders komen drie suggesties in
     * dezelfde alinea terecht.
     */
    private static function context(WP_Post $target, $source_id, array $accepted) {
        $load = IL_Suggestions::source_load($source_id);

        $per_segment = $load['per_segment'];
        $prefixes    = $load['prefixes'];
        $count       = $load['count'];

        foreach ($accepted as $a) {
            if ((int) $a['source_id'] !== (int) $source_id) {
                continue;
            }
            $ref = (string) $a['segment_ref'];
            $per_segment[$ref] = isset($per_segment[$ref]) ? $per_segment[$ref] + 1 : 1;
            $prefixes[] = IL_Text::anchor_prefix_class($a['anchor']);
            $count++;
        }

        $segments  = IL_Content::segments($source_id);
        $intro_ref = null;
        foreach ($segments as $seg) {
            if ($seg['kind'] !== 'heading') {
                $intro_ref = $seg['ref'];
                break;
            }
        }

        $terms = IL_Text::remove_stopwords(IL_Text::tokenize(
            get_the_title($target->ID) . ' ' . IL_Index::focus_keyword($target->ID)
        ));

        return [
            'existing_targets'     => IL_Graph_Scanner::targets_of($source_id),
            'added_in_source'      => $count,
            'added_per_segment'    => $per_segment,
            'added_prefix_classes' => array_values(array_unique($prefixes)),
            'density_headroom'     => IL_Profile::density_headroom($source_id),
            'max_links_per_source' => (int) IL_Config::get('max_links_per_source'),
            'max_same_anchor'      => (int) IL_Config::get('max_same_anchor'),
            'target_terms'         => $terms,
            'target_keyword'       => IL_Index::focus_keyword($target->ID),
            'intro_ref'            => $intro_ref,
        ];
    }

    /* ---------------------------------------------------------- beoordelen */

    /**
     * Bouwt het resultaat en laat de gates erover oordelen. Ook de applier
     * gebruikt dit, zodat een suggestie vlak voor het wegschrijven nog één keer
     * langs exact dezelfde controles gaat.
     */
    public static function evaluate(array $cand, array $ctx) {
        $built = IL_Inserter::build($cand);
        if (isset($built['error'])) {
            return ['ok' => false, 'gate' => 'bouw', 'reason' => $built['error']];
        }
        $cand['result_html'] = $built['html'];

        // De applier zet deze twee zelf, omdat hij de suggestie die hij aan het
        // toepassen is uit zijn eigen telling moet houden.
        if (!isset($ctx['anchor_usage'])) {
            $ctx['anchor_usage'] = IL_Profile::anchor_usage($cand['target_id'], $cand['anchor']);
        }
        if (!isset($ctx['anchor_claimed_by'])) {
            $ctx['anchor_claimed_by'] = IL_Profile::anchor_claimed_by($cand['target_id'], $cand['anchor']);
        }

        return IL_Gates::check($cand, $ctx);
    }

    private static function rejection(array $cand, array $verdict) {
        return [
            'source_id'   => $cand['source_id'],
            'target_id'   => $cand['target_id'],
            'anchor'      => $cand['anchor'],
            'mode'        => $cand['mode'],
            'segment_ref' => $cand['segment_ref'],
            'gate'        => $verdict['gate'],
            'reason'      => $verdict['reason'],
        ];
    }

    private static function already_used($source_id, array $accepted) {
        foreach ($accepted as $a) {
            if ((int) $a['source_id'] === (int) $source_id) {
                return true;
            }
        }
        return false;
    }
}
