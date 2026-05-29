<?php

namespace larablocks\MapAi\Commands\Concerns;

use Symfony\Component\Console\Formatter\OutputFormatter;

trait RendersDiff
{
    private function showDiff(string $stub, string $project): void
    {
        $proc = proc_open(
            ['diff', '-u', '--label', 'stub', '--label', 'project/'.basename($project), $stub, $project],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($proc)) {
            $this->line('    (diff unavailable — files differ)');

            return;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        foreach (explode("\n", rtrim((string) $output)) as $line) {
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
