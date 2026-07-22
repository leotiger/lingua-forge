#!/usr/bin/env php
<?php
/**
 * clear-fuzzy.php — surgically clear fuzzy translations in a .po file.
 *
 * msgmerge marks a string "#, fuzzy" when it auto-matched the new/changed
 * source string against a stale translation it isn't sure is still correct.
 * Left as-is, that stale guess sits in msgstr and is easy to miss — it just
 * looks "already translated". This blanks msgstr (or every msgstr[N] for
 * plurals) for every fuzzy entry and strips the 'fuzzy' token from its
 * flags line — so the string shows up as plainly untranslated and ready
 * for a clean retranslation, not a suspect one.
 *
 * Every other line — msgid text, comments, references, non-fuzzy entries,
 * line-wrapping — is left byte-for-byte untouched. This is a surgical
 * line-level edit, not a full PO reparse/rewrite, specifically so it
 * doesn't fight msgmerge's own formatting or produce noisy diffs.
 *
 * Run automatically by make-pot.sh, right after msgmerge, for every
 * locale. Can also be run by hand: php clear-fuzzy.php <file.po> [...]
 */

declare(strict_types=1);

/**
 * @return string[]|null  Trimmed flag tokens (e.g. ['fuzzy', 'php-format']),
 *                        or null if $line isn't a "#," flags line.
 */
function lf_match_flags(string $line): ?array {

    $trimmed = rtrim($line, "\r\n");
    if (preg_match('/^#,\s*(.+?)\s*$/', $trimmed, $m)) {
        return array_map('trim', explode(',', $m[1]));
    }
    return null;
}

/**
 * @return string|null  'msgstr' / 'msgstr[0]' etc. if $line opens a msgstr
 *                        value, else null.
 */
function lf_match_msgstr_key(string $line): ?string {

    $trimmed = rtrim($line, "\r\n");
    if (preg_match('/^(msgstr(?:\[\d+\])?)\s+"/', $trimmed, $m)) {
        return $m[1];
    }
    return null;
}

function lf_is_continuation_string(string $line): bool {

    $trimmed = ltrim(rtrim($line, "\r\n"));
    return isset($trimmed[0]) && $trimmed[0] === '"';
}

/**
 * Split a PO file's lines into contiguous non-blank runs (entries).
 *
 * @param  string[] $lines
 * @return array<int, array{0:int,1:int}>  [start, end) index pairs.
 */
function lf_split_entries(array $lines): array {

    $entries = [];
    $n = count($lines);
    $i = 0;

    while ($i < $n) {
        if (trim($lines[$i]) === '') {
            $i++;
            continue;
        }
        $start = $i;
        while ($i < $n && trim($lines[$i]) !== '') {
            $i++;
        }
        $entries[] = [$start, $i];
    }

    return $entries;
}

/**
 * @param  string[] $entry  Lines belonging to one PO entry (no blank lines).
 * @return array{0: string[], 1: bool}  [rewritten lines, was-fuzzy?]
 */
function lf_clear_entry(array $entry): array {

    $is_fuzzy   = false;
    $flags_idx  = null;

    foreach ($entry as $idx => $line) {
        $flags = lf_match_flags($line);
        if ($flags !== null && in_array('fuzzy', $flags, true)) {
            $is_fuzzy  = true;
            $flags_idx = $idx;
        }
    }

    if (!$is_fuzzy) {
        return [$entry, false];
    }

    // 1. Fix the flags line — drop 'fuzzy', keep any other flags
    //    (e.g. 'php-format'); remove the line entirely if fuzzy was the
    //    only flag.
    $flags     = lf_match_flags($entry[$flags_idx]);
    $remaining = array_values(array_filter($flags, static fn($f) => $f !== 'fuzzy'));

    if (!empty($remaining)) {
        $entry[$flags_idx] = '#, ' . implode(', ', $remaining) . "\n";
    } else {
        $entry[$flags_idx] = null; // marked for removal below
    }

    // 2. Blank every msgstr / msgstr[N] value, dropping its continuation
    //    lines (plain quoted-string lines with no keyword).
    $out = [];
    $n   = count($entry);
    $i   = 0;

    while ($i < $n) {
        $line = $entry[$i];

        if ($line === null) {
            $i++;
            continue;
        }

        $key = lf_match_msgstr_key($line);
        if ($key !== null) {
            $out[] = $key . " \"\"\n";
            $i++;
            while ($i < $n && $entry[$i] !== null && lf_is_continuation_string($entry[$i])) {
                $i++;
            }
            continue;
        }

        $out[] = $line;
        $i++;
    }

    return [$out, true];
}

function lf_process_file(string $path): int {

    $lines = file($path);
    if ($lines === false) {
        fwrite(STDERR, "clear-fuzzy.php: could not read $path\n");
        return -1;
    }

    $entries   = lf_split_entries($lines);
    $new_lines = [];
    $cursor    = 0;
    $cleared   = 0;

    foreach ($entries as [$start, $end]) {
        // Preserve the blank-line gap before this entry verbatim.
        for ($j = $cursor; $j < $start; $j++) {
            $new_lines[] = $lines[$j];
        }

        $slice = array_slice($lines, $start, $end - $start);
        [$out, $was_fuzzy] = lf_clear_entry($slice);

        foreach ($out as $l) {
            $new_lines[] = $l;
        }
        if ($was_fuzzy) {
            $cleared++;
        }

        $cursor = $end;
    }

    for ($j = $cursor; $j < count($lines); $j++) {
        $new_lines[] = $lines[$j];
    }

    file_put_contents($path, implode('', $new_lines));

    return $cleared;
}

// ── CLI entry ────────────────────────────────────────────────────────────

$paths = array_slice($argv, 1);

if (empty($paths)) {
    fwrite(STDERR, "Usage: php clear-fuzzy.php <file.po> [file2.po ...]\n");
    exit(1);
}

$total = 0;
foreach ($paths as $path) {
    $cleared = lf_process_file($path);
    if ($cleared < 0) {
        exit(1);
    }
    $total += $cleared;
    if ($cleared > 0) {
        echo '  ✓ ' . basename($path) . ": cleared $cleared fuzzy " . ($cleared === 1 ? 'string' : 'strings') . "\n";
    }
}

echo "Cleared $total fuzzy string(s) total.\n";
