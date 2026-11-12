<?php

/**
 * @file
 * Fails when custom-module line coverage in a Clover report is below a threshold.
 *
 * Usage: php scripts/ci/coverage-gate.php build/clover.xml 10
 *
 * Only files under web/modules/custom are counted (that is also the <source>
 * filter in phpunit.xml.dist); test files are skipped. Prints a per-module
 * table so a drop is easy to locate from the CI log.
 */

declare(strict_types=1);

if ($argc < 3) {
  fwrite(STDERR, "usage: coverage-gate.php <clover.xml> <min-line-percent>\n");
  exit(2);
}
[, $clover_path, $min] = $argv;
$min = (float) $min;

if (!is_file($clover_path)) {
  fwrite(STDERR, "Clover report not found: $clover_path\n");
  exit(2);
}
$xml = simplexml_load_file($clover_path);
if ($xml === FALSE) {
  fwrite(STDERR, "Could not parse $clover_path\n");
  exit(2);
}

$per_module = [];
foreach ($xml->xpath('//file') as $file) {
  $path = (string) $file['name'];
  if (!preg_match('#/web/modules/custom/([^/]+)/#', $path, $m) || str_contains($path, '/tests/')) {
    continue;
  }
  $metrics = $file->metrics;
  $per_module[$m[1]]['statements'] = ($per_module[$m[1]]['statements'] ?? 0) + (int) $metrics['statements'];
  $per_module[$m[1]]['covered'] = ($per_module[$m[1]]['covered'] ?? 0) + (int) $metrics['coveredstatements'];
}
ksort($per_module);

$total_statements = 0;
$total_covered = 0;
printf("%-28s %10s %10s %8s\n", 'module', 'lines', 'covered', 'pct');
foreach ($per_module as $module => $m) {
  $pct = $m['statements'] > 0 ? 100 * $m['covered'] / $m['statements'] : 0.0;
  printf("%-28s %10d %10d %7.1f%%\n", $module, $m['statements'], $m['covered'], $pct);
  $total_statements += $m['statements'];
  $total_covered += $m['covered'];
}
$total_pct = $total_statements > 0 ? 100 * $total_covered / $total_statements : 0.0;
printf("%-28s %10d %10d %7.1f%%\n", 'TOTAL (web/modules/custom)', $total_statements, $total_covered, $total_pct);

if ($total_statements === 0) {
  fwrite(STDERR, "No custom-module files in the report; is the <source> filter intact?\n");
  exit(1);
}
if ($total_pct + 1e-9 < $min) {
  fwrite(STDERR, sprintf("Line coverage %.1f%% is below the gate of %.1f%%.\n", $total_pct, $min));
  exit(1);
}
printf("Line coverage %.1f%% meets the gate of %.1f%%.\n", $total_pct, $min);
