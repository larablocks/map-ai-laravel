<?php

namespace larablocks\MapAi\Commands;

use Illuminate\Console\Command;
use larablocks\MapAi\Commands\Concerns\RendersDiff;
use larablocks\MapAi\Installer;

class DiffCommand extends Command
{
    use RendersDiff;

    protected $signature = 'map:diff {file? : Specific scaffold file to diff (e.g. AGENTS.md)}';

    protected $description = 'Show differences between your scaffold files and the current package stubs';

    public function handle(): int
    {
        $targetPath = base_path();
        $stubsPath = Installer::stubsPath();

        $file = $this->argument('file');

        if ($file !== null && ! in_array($file, Installer::SCAFFOLD_FILES, true)) {
            $this->error("'{$file}' is not a scaffold file. Only scaffold files can be diffed.");

            return self::FAILURE;
        }

        $files = $file !== null ? [$file] : Installer::SCAFFOLD_FILES;

        $this->line('Diffing scaffold files against stubs...');
        $this->newLine();

        $modified = 0;
        $identical = 0;
        $missing = 0;

        foreach ($files as $relPath) {
            $stub = $stubsPath.'/'.$relPath;
            $project = $targetPath.'/'.$relPath;

            if (! file_exists($project)) {
                $this->line("  <fg=yellow>[NOT INSTALLED]</> {$relPath}  (run map:install to add)");
                $missing++;

                continue;
            }

            if (file_get_contents($stub) === file_get_contents($project)) {
                $this->line("  <fg=gray>[IDENTICAL]</>     {$relPath}");
                $identical++;

                continue;
            }

            $this->line("  <fg=blue>[MODIFIED]</>      {$relPath}");
            $this->newLine();
            $this->showDiff($stub, $project);
            $this->newLine();
            $modified++;
        }

        $this->newLine();
        $summary = "{$modified} modified, {$identical} identical";
        if ($missing > 0) {
            $summary .= ", {$missing} not installed";
        }
        $this->line($summary.'.');

        return self::SUCCESS;
    }
}
