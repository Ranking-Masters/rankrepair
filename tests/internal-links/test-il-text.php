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
