<?php

namespace larablocks\MapAi\Commands\Concerns;

use Symfony\Component\Console\Formatter\OutputFormatter;

trait RendersDiff
{
    /** Run diff (stub → project), display it coloured, and return the raw diff string. */
    private function showDiff(string $stub, string $project): string
    {
        $proc = proc_open(
            ['diff', '-u', '--label', 'stub', '--label', 'project/'.basename($project), $stub, $project],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($proc)) {
            $this->line('    (diff unavailable — files differ)');

            return '';
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->renderDiff((string) $output);

        return (string) $output;
    }

    /**
     * Write $content to a temp file, diff it against $project, display, and return the diff string.
     * Useful for diffing against effective (placeholder-substituted) stub content.
     */
    private function showDiffFromContent(string $content, string $project): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'map-stub-');
        file_put_contents($tmp, $content);

        try {
            return $this->showDiff($tmp, $project);
        } finally {
            unlink($tmp);
        }
    }

    private function renderDiff(string $diff): void
    {
        foreach (explode("\n", rtrim($diff)) as $line) {
            $escaped = OutputFormatter::escape($line);
            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                $this->line("    <fg=white>{$escaped}</>");
            } elseif (str_starts_with($line, '-')) {
                $this->line("    <fg=red>{$escaped}</>");
            } elseif (str_starts_with($line, '+')) {
                $this->line("    <fg=green>{$escaped}</>");
            } elseif (str_starts_with($line, '@@')) {
                $this->line("    <fg=cyan>{$escaped}</>");
            } else {
                $this->line("    {$escaped}");
            }
        }
    }

    /**
     * Compute diff with project as "from" and stub as "to" (no rendering).
     * In the result: + lines are new stub content, - lines are user customisations.
     */
    private function diffProjectToStub(string $project, string $stubContent): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'map-stub-');
        file_put_contents($tmp, $stubContent);

        try {
            $proc = proc_open(
                ['diff', '-u', '--label', 'project/'.basename($project), '--label', 'stub', $project, $tmp],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (! is_resource($proc)) {
                return '';
            }

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return (string) $output;
        } finally {
            unlink($tmp);
        }
    }

    /**
     * Parse a diff (project → stub) and return only hunks that have no - lines.
     * These are pure stub additions the user hasn't incorporated yet.
     *
     * @return list<list<string>>
     */
    private function extractAdditionHunks(string $diff): array
    {
        $lines = explode("\n", $diff);
        $hunks = [];
        $currentHunk = null;
        $hasMinus = false;
        $hasPlus = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                continue;
            }

            if (str_starts_with($line, '@@')) {
                if ($currentHunk !== null && $hasPlus && ! $hasMinus) {
                    $hunks[] = $currentHunk;
                }
                $currentHunk = [$line];
                $hasMinus = false;
                $hasPlus = false;
                continue;
            }

            if ($currentHunk !== null) {
                $currentHunk[] = $line;
                if (str_starts_with($line, '-')) {
                    $hasMinus = true;
                } elseif (str_starts_with($line, '+')) {
                    $hasPlus = true;
                }
            }
        }

        if ($currentHunk !== null && $hasPlus && ! $hasMinus) {
            $hunks[] = $currentHunk;
        }

        return $hunks;
    }

    /**
     * Extract only the new content lines from addition hunks (strips the + prefix).
     *
     * @param list<list<string>> $hunks
     * @return list<string>
     */
    private function newLinesFromHunks(array $hunks): array
    {
        $lines = [];
        foreach ($hunks as $hunk) {
            foreach ($hunk as $line) {
                if (str_starts_with($line, '+')) {
                    $lines[] = substr($line, 1);
                }
            }
        }

        return $lines;
    }

    /**
     * Render a compact preview: last 2 context lines (dim) then each new line (green).
     *
     * @param list<list<string>> $hunks
     */
    private function renderAdditionHunks(array $hunks): void
    {
        foreach ($hunks as $i => $hunk) {
            if ($i > 0) {
                $this->line('    <fg=gray>  ...</>');
            }

            $contextBefore = [];
            $seenPlus = false;

            foreach (array_slice($hunk, 1) as $line) {
                if (str_starts_with($line, '+')) {
                    if (! $seenPlus) {
                        foreach (array_slice($contextBefore, -2) as $ctx) {
                            $this->line('    <fg=gray>  '.OutputFormatter::escape(substr($ctx, 1)).'</>');
                        }
                        $seenPlus = true;
                    }
                    $this->line('    <fg=green>+ '.OutputFormatter::escape(substr($line, 1)).'</>');
                } elseif (! str_starts_with($line, '-') && ! $seenPlus) {
                    $contextBefore[] = $line;
                }
            }
        }
    }

    /**
     * Apply hunk insertions to $file in-place, using context lines as an anchor.
     * Falls back to appending at end if the anchor cannot be found.
     *
     * @param list<list<string>> $hunks
     */
    private function applyHunkInsertions(string $file, array $hunks): void
    {
        $projectLines = explode("\n", file_get_contents($file));

        foreach ($hunks as $hunk) {
            $contextBefore = [];
            $newLines = [];
            $seenPlus = false;

            foreach (array_slice($hunk, 1) as $line) {
                if (str_starts_with($line, '+')) {
                    $seenPlus = true;
                    $newLines[] = substr($line, 1);
                } elseif (! str_starts_with($line, '-') && ! $seenPlus) {
                    $contextBefore[] = substr($line, 1); // strip leading diff space
                }
            }

            if ($newLines === []) {
                continue;
            }

            $anchor = array_slice($contextBefore, -3);
            $insertAfter = $this->findContextAnchor($projectLines, $anchor);

            if ($insertAfter >= 0) {
                array_splice($projectLines, $insertAfter + 1, 0, $newLines);
            } else {
                array_push($projectLines, ...$newLines);
            }
        }

        file_put_contents($file, implode("\n", $projectLines));
    }

    /**
     * Find the last line index of $anchor sequence in $lines.
     * Returns the index of the last anchor line, or -1 if not found.
     *
     * @param list<string> $lines
     * @param list<string> $anchor
     */
    private function findContextAnchor(array $lines, array $anchor): int
    {
        if ($anchor === []) {
            return -1;
        }

        $anchorLen = count($anchor);
        $lineCount = count($lines);
        $lastMatch = -1;

        for ($i = 0; $i <= $lineCount - $anchorLen; $i++) {
            $match = true;
            for ($j = 0; $j < $anchorLen; $j++) {
                if (trim($lines[$i + $j]) !== trim($anchor[$j])) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $lastMatch = $i + $anchorLen - 1;
            }
        }

        return $lastMatch;
    }
}
