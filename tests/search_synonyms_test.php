<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/search_synonyms.php';

$pairs = [
    ['latin_term' => 'alga', 'amharic_term' => 'አልጋ'],
    ['latin_term' => 'bed', 'amharic_term' => 'አልጋ'],
    ['latin_term' => 'wenber', 'amharic_term' => 'ወንበር'],
];

$tests = 0;
$failures = [];
$assertContains = static function (string $expected, array $actual, string $label) use (&$tests, &$failures): void {
    $tests++;
    if (!in_array($expected, $actual, true)) {
        $failures[] = "$label (missing " . var_export($expected, true) . ')';
    }
};

foreach (['bed', 'alga', 'አልጋ'] as $query) {
    $expanded = search_expand_terms_from_pairs($query, $pairs);
    $assertContains('bed', $expanded, "$query expands to English");
    $assertContains('alga', $expanded, "$query expands to transliteration");
    $assertContains('አልጋ', $expanded, "$query expands to Amharic");
}

$long = search_expand_terms_from_pairs('cheap alga Addis', $pairs);
$assertContains('cheap bed addis', $long, 'expands an alias inside a longer query');
$assertContains('cheap አልጋ addis', $long, 'preserves surrounding words');

$boundary = search_expand_terms_from_pairs('algae', $pairs);
$tests++;
if (in_array('bed', $boundary, true)) $failures[] = 'expanded an alias embedded in another word';

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "[FAIL] $failure\n");
    exit(1);
}
echo "Search synonyms: $tests passed.\n";
