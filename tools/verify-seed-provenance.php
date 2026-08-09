<?php

/**
 * Proves that data/ui-phrases.php contains only strings this repository itself emits.
 *
 * ## The rule, and why it is worth a tool
 *
 * `trans_phrases` is shared between the applications that use this library. A phrase
 * in it belongs to whichever project contributed it and may contain anything at all,
 * including data that must not leave that project. So a seed built by reading a
 * database would exfiltrate one consumer's content into every installation of the
 * library. **A library may ship only what its own source contains.**
 *
 * This was written after exactly that mistake was made and caught: a first version of
 * data/ui-phrases.php was extracted from a shared database and was the union of two
 * projects' phrases. It never left the machine, and this tool is what makes the same
 * mistake loud rather than plausible-looking.
 *
 * ## How it checks
 *
 * It collects every string literal in src/ and view/ with PHP's own tokenizer,
 * reconstructing runs of literals joined by `.` so that a message written across four
 * source lines is compared as the one string it evaluates to. A seeded phrase must
 * match one of those exactly. Textual searching was tried first and produced false
 * positives on every wrapped string, which is worse than useless in a guard.
 *
 *   php tools/verify-seed-provenance.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);

/** @var array<string, array<string, string>> $seed */
$seed = require $root . '/data/ui-phrases.php';

/**
 * Phrases this repository emits by composing literals at runtime, which no literal
 * scan can see. Each needs a reason, and the reason has to be checkable by reading
 * the named file.
 *
 * @var array<string, string>
 */
$composedAtRuntime = [
    'Delete translation-phrase' =>
        "view/.../delete.phtml builds the heading as 'Delete ' . \$this->entity, and "
        . "JTranslateController::deleteAction() passes 'translation-phrase' as the only "
        . 'entity it ever handles.',
];

/** @return list<string> every string literal value, with `.` runs joined */
$literalsIn = static function (string $file): array {
    $tokens   = token_get_all((string) file_get_contents($file));
    $literals = [];
    $run      = null;

    foreach ($tokens as $token) {
        if (is_array($token) && T_WHITESPACE === $token[0]) {
            continue;
        }
        if (is_array($token) && T_COMMENT === $token[0]) {
            continue;
        }
        if (is_array($token) && T_DOC_COMMENT === $token[0]) {
            continue;
        }

        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $raw   = $token[1];
            $quote = $raw[0];
            $body  = substr($raw, 1, -1);
            //undo only the escapes PHP itself would, for the quote style in use
            $value = $quote === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
                : stripcslashes($body);
            $run   = null === $run ? $value : $run . $value;
            continue;
        }

        //a '.' continues the current run; anything else ends it
        if ('.' === $token) {
            continue;
        }
        if (null !== $run) {
            $literals[] = $run;
            $run        = null;
        }
    }
    if (null !== $run) {
        $literals[] = $run;
    }

    return $literals;
};

$known = [];
foreach (['/src', '/view'] as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        if (! in_array($file->getExtension(), ['php', 'phtml'], true)) {
            continue;
        }
        foreach ($literalsIn($file->getPathname()) as $literal) {
            $known[$literal] = true;
        }
    }
}

$missing = [];
foreach (array_keys($seed) as $phrase) {
    $phrase = (string) $phrase;
    if (isset($known[$phrase]) || isset($composedAtRuntime[$phrase])) {
        continue;
    }
    $missing[] = $phrase;
}

printf(
    "%d phrases, %d shipped translations, %d literals scanned\n",
    count($seed),
    array_sum(array_map('count', $seed)),
    count($known)
);
foreach (array_keys($composedAtRuntime) as $phrase) {
    printf("  composed at runtime (allowed): %s\n", $phrase);
}

if ([] === $missing) {
    echo "OK — every seeded phrase is a literal this repository's own src/ or view/ emits.\n";
    exit(0);
}

echo "\nFAIL — these seeded phrases are not strings this repository emits:\n";
foreach ($missing as $phrase) {
    printf("  %s\n", strlen($phrase) > 90 ? substr($phrase, 0, 87) . '...' : $phrase);
}
echo "\nA phrase this library does not itself emit belongs to whichever project\n"
    . "contributed it, not here. Remove it, add the literal to the UI, or — if it is\n"
    . "composed at runtime — record it in \$composedAtRuntime with a reason.\n";
exit(1);
