<?php

declare(strict_types=1);

$report = simplexml_load_file(__DIR__.'/../build/coverage.xml');

if ($report === false || ! isset($report->project->metrics)) {
    fwrite(STDERR, "Cannot read coverage metrics.\n");
    exit(1);
}

$total = (int) $report->project->metrics['statements'];
$covered = (int) $report->project->metrics['coveredstatements'];

if ($total <= 0 || $covered !== $total) {
    fwrite(STDERR, "Every source statement must be covered: {$covered}/{$total}.\n");
    exit(1);
}

fwrite(STDOUT, "Statement coverage: 100% ({$covered}/{$total}).\n");
