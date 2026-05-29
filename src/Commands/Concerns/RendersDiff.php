<?php

namespace larablocks\MapAi\Commands\Concerns;

use Symfony\Component\Console\Formatter\OutputFormatter;

trait RendersDiff
{
    /** Run diff, display it coloured, and return the raw diff string for further processing. */
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
}
