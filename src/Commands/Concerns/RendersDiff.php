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
     * Preview of the hunks Doctor::fixableHunks() would splice in — the last
     * 2 lines of the target already there (dim) as an anchor, then each new
     * line (green). Compact by design: this is only ever shown right before
     * a confirm prompt, not as a full diff.
     *
     * @param  list<array{start: int, count: int, lines: list<string>}>  $hunks
     */
    private function renderFixableHunks(string $targetFile, array $hunks): void
    {
        $targetLines = explode("\n", (string) file_get_contents($targetFile));

        foreach ($hunks as $i => $hunk) {
            if ($i > 0) {
                $this->line('    <fg=gray>  ...</>');
            }

            foreach (array_slice($targetLines, max(0, $hunk['start'] - 2), min(2, $hunk['start'])) as $ctx) {
                $this->line('    <fg=gray>  '.OutputFormatter::escape($ctx).'</>');
            }

            foreach ($hunk['lines'] as $line) {
                $this->line('    <fg=green>+ '.OutputFormatter::escape($line).'</>');
            }
        }
    }
}
