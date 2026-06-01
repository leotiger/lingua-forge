<?php
/**
 * merge-coverage.php — combine unit + integration Clover XML reports.
 *
 * Usage (from lingua-forge/dev/):
 *   php scripts/merge-coverage.php
 *
 * Inputs:
 *   coverage/unit/clover.xml
 *   coverage/integration/clover.xml
 *
 * Outputs:
 *   coverage/combined/clover.xml
 *   coverage/combined/summary.txt
 */

declare(strict_types=1);

$dev_dir        = __DIR__ . '/..';
$plugin_root    = realpath($dev_dir . '/..') . '/';
$container_root = '/var/www/html/wp-content/plugins/lingua-forge/';

$unit_clover = "$dev_dir/coverage/unit/clover.xml";
$intg_clover = "$dev_dir/coverage/integration/clover.xml";
$out_dir     = "$dev_dir/coverage/combined";
$out_clover  = "$out_dir/clover.xml";
$out_summary = "$out_dir/summary.txt";

foreach ([$unit_clover, $intg_clover] as $f) {
    if (! file_exists($f)) {
        fwrite(STDERR, "Missing: $f\nRun: composer coverage:run\n");
        exit(1);
    }
}

function load_clover(string $path, string $base): array
{
    $xml = simplexml_load_file($path);
    $files = [];
    foreach ($xml->project->package ?? [] as $pkg) {
        foreach ($pkg->file as $f) {
            $files[] = $f;
        }
    }
    foreach ($xml->project->file ?? [] as $f) {
        $files[] = $f;
    }
    $out = [];
    foreach ($files as $file) {
        $abs = (string) $file['name'];
        if (strpos($abs, $base) !== 0) continue;
        $rel = substr($abs, strlen($base));
        if (preg_match('~^(dev/|tests/|vendor/)~', $rel)) continue;
        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') continue;
            $n = (int) $line['num'];
            $c = (int) $line['count'];
            $out[$rel][$n] = ($out[$rel][$n] ?? 0) + $c;
        }
    }
    return $out;
}

$unit   = load_clover($unit_clover, $plugin_root);
$intg   = load_clover($intg_clover, $container_root);
$merged = $unit;
foreach ($intg as $rel => $lines) {
    foreach ($lines as $n => $c) {
        $merged[$rel][$n] = ($merged[$rel][$n] ?? 0) + $c;
    }
}
ksort($merged);

// Write Clover XML.
if (! is_dir($out_dir)) {
    mkdir($out_dir, 0755, true);
}

$now     = time();
$xml_out = new SimpleXMLElement(
    '<coverage generated="' . $now . '"><project name="LinguaForge" timestamp="' . $now . '"></project></coverage>'
);
$project  = $xml_out->project;
$g_stmts  = 0;
$g_hit    = 0;

foreach ($merged as $rel => $lines) {
    ksort($lines);
    $s = 0; $h = 0;
    $fe = $project->addChild('file');
    $fe->addAttribute('name', $plugin_root . $rel);
    foreach ($lines as $n => $c) {
        $le = $fe->addChild('line');
        $le->addAttribute('num', (string) $n);
        $le->addAttribute('type', 'stmt');
        $le->addAttribute('count', (string) $c);
        $s++;
        if ($c > 0) $h++;
    }
    $me = $fe->addChild('metrics');
    $me->addAttribute('statements', (string) $s);
    $me->addAttribute('coveredstatements', (string) $h);
    $g_stmts += $s;
    $g_hit   += $h;
}

$pm = $project->addChild('metrics');
$pm->addAttribute('statements', (string) $g_stmts);
$pm->addAttribute('coveredstatements', (string) $g_hit);

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace = false;
$dom->formatOutput       = true;
$dom->loadXML($xml_out->asXML());
$dom->save($out_clover);

// Write text summary.
$pct  = $g_stmts > 0 ? round($g_hit / $g_stmts * 100, 2) : 0;
$lines_out = [];
$lines_out[] = sprintf('COMBINED COVERAGE  (unit + integration)  —  %s', date('Y-m-d H:i'));
$lines_out[] = str_repeat('─', 70);
$lines_out[] = sprintf('  %-60s  %5s', 'File', 'Cov%');
$lines_out[] = str_repeat('─', 70);

foreach ($merged as $rel => $lines) {
    $s   = count($lines);
    $h   = count(array_filter($lines));
    $p   = $s > 0 ? (int) round($h / $s * 100) : 0;
    $bar = $p >= 80 ? '✅' : ($p >= 50 ? '🔶' : '❌');
    $lines_out[] = sprintf('  %s %-57s  %3d%%', $bar, $rel, $p);
}

$lines_out[] = str_repeat('─', 70);
$lines_out[] = sprintf('  TOTAL: %.2f%%  (%d / %d statements)', $pct, $g_hit, $g_stmts);
$lines_out[] = '';

file_put_contents($out_summary, implode(PHP_EOL, $lines_out));

echo implode(PHP_EOL, array_slice($lines_out, -4)) . PHP_EOL;
echo "Wrote: $out_clover\n";
echo "Wrote: $out_summary\n";
