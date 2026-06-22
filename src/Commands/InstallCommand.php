<?php

namespace larablocks\MapAi\Commands;

use Illuminate\Console\Command;
use larablocks\MapAi\Commands\Concerns\ProcessesStubContent;
use larablocks\MapAi\Commands\Concerns\RendersDiff;
use larablocks\MapAi\Installer;

class InstallCommand extends Command
{
    use ProcessesStubContent, RendersDiff;

    protected $signature = 'map:install {--force : Overwrite existing files}';

    protected $description = 'Install the MAP AI documentation scaffold into this project';

    public function handle(): int
    {
        $targetPath = base_path();

        $this->info('Installing MAP into: '.$targetPath);
        $this->newLine();

        if ($this->option('force')) {
            $willOverwrite = array_values(array_filter(
                Installer::SCAFFOLD_FILES,
                fn (string $file) => file_exists($targetPath.'/'.$file),
            ));

            if ($willOverwrite !== []) {
                $this->warn('The following existing files will be overwritten if changed (originals saved as .bak):');
                $this->newLine();
                foreach ($willOverwrite as $file) {
                    $this->line("  {$file}");
                }
                $this->newLine();

                if (! $this->confirm('Do you wish to continue?')) {
                    $this->info('Aborted.');

                    return self::SUCCESS;
                }

                $this->newLine();
            }
        }

        $copied = 0;
        $skipped = 0;
        $identical = 0;
        $missing = 0;

        $this->line('Copying files...');
        $this->newLine();

        $result = (new Installer)->install(
            Installer::stubsPath(),
            $targetPath,
            (bool) $this->option('force'),
            function (array $fileResult) use (&$copied, &$skipped, &$identical, &$missing): void {
                ['action' => $action, 'file' => $file, 'backed_up' => $backedUp] = $fileResult;
                if ($backedUp) {
                    $this->line("  <fg=cyan>[BACKUP]</>    {$file}.bak");
                }
                if ($action === 'copy') {
                    $this->line("  <fg=green>[COPY]</>      {$file}");
                    $copied++;
                } elseif ($action === 'update') {
                    $this->line("  <fg=blue>[UPDATE]</>    {$file}");
                    $copied++;
                } elseif ($action === 'identical') {
                    $this->line("  <fg=gray>[IDENTICAL]</> {$file}");
                    $identical++;
                } elseif ($action === 'skip') {
                    $this->line("  <fg=yellow>[SKIP]</>      {$file}  (already exists — use --force to overwrite)");
                    $skipped++;
                } elseif ($action === 'missing') {
                    $this->warn("  [WARN]      source not found — {$file}");
                    $missing++;
                }
            }
        );

        $this->processAgentsMd($result['files'], $targetPath);

        $this->newLine();
        $this->line('Merging .gitignore...');

        if ($result['gitignore'] === 'updated') {
            $this->line('  <fg=green>[UPDATE]</> .gitignore — MAP entries appended');
        } else {
            $this->line('  <fg=yellow>[SKIP]</>   .gitignore — MAP entries already present');
        }

        $this->newLine();
        $this->line('Initializing personal files...');
        $this->newLine();

        (new Installer)->bootstrapPersonalFiles(
            $targetPath,
            function (array $fileResult): void {
                ['action' => $action, 'file' => $file] = $fileResult;
                if ($action === 'copy') {
                    $this->line("  <fg=green>[INIT]</>      {$file}");
                } elseif ($action === 'skip') {
                    $this->line("  <fg=gray>[EXISTS]</>    {$file}");
                }
            }
        );

        $this->newLine();
        $summary = "Done. {$copied} file(s) copied, {$skipped} skipped, {$identical} identical";
        if ($missing > 0) {
            $summary .= ", {$missing} missing from source";
        }
        $this->info($summary.'.');

        $skippedFiles = array_column(
            array_filter($result['files'], fn (array $f) => $f['action'] === 'skip'),
            'file'
        );

        $outOfDate = array_values(array_filter(
            $skippedFiles,
            fn (string $file) => file_get_contents(Installer::stubsPath().'/'.$file) !== file_get_contents($targetPath.'/'.$file)
        ));

        if ($outOfDate !== []) {
            $this->newLine();
            $this->line('Checking for new stub content...');

            foreach ($outOfDate as $file) {
                $stub = Installer::stubsPath().'/'.$file;
                $project = $targetPath.'/'.$file;
                $existing = (string) file_get_contents($project);

                $effectiveContent = $this->processStubContent($file, (string) file_get_contents($stub), $targetPath);

                $diff = $this->diffProjectToStub($project, $effectiveContent);
                $additionHunks = $this->extractAdditionHunks($diff);

                if ($additionHunks === []) {
                    $this->line("  <fg=gray>[NO NEW CONTENT]</> {$file}");
                    continue;
                }

                $newLines = $this->newLinesFromHunks($additionHunks);

                if ($newLines === [] || str_contains($existing, implode("\n", $newLines))) {
                    $this->line("  <fg=gray>[NO NEW CONTENT]</> {$file}");
                    continue;
                }

                $this->newLine();
                $this->line("  <fg=blue>[MODIFIED]</>    {$file}");
                $this->newLine();
                $this->renderAdditionHunks($additionHunks);
                $this->newLine();

                if ($this->confirm("Append new stub content to {$file}?", true)) {
                    copy($project, $project.'.bak');
                    $this->applyHunkInsertions($project, $additionHunks);
                    $this->line("  <fg=green>[UPDATED]</>   {$file}  (inserted in-place, original saved as {$file}.bak)");
                } else {
                    $this->line("  <fg=yellow>[SKIPPED]</>   {$file}");
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /** @param list<array{action: string, file: string, backed_up: bool}> $files */
    private function processAgentsMd(array $files, string $targetPath): void
    {
        $wasWritten = false;
        foreach ($files as ['action' => $action, 'file' => $file]) {
            if ($file === 'AGENTS.md' && in_array($action, ['copy', 'update'], true)) {
                $wasWritten = true;
                break;
            }
        }

        if (! $wasWritten) {
            return;
        }

        $path = $targetPath.'/AGENTS.md';
        $content = $this->processStubContent('AGENTS.md', (string) file_get_contents($path), $targetPath);
        file_put_contents($path, $content);

        $detectedInfo = $this->detectProjectInfo($targetPath);
        $detectedCommands = $this->detectCommands($targetPath);

        $this->newLine();
        $this->line('Auto-detecting project info and commands for AGENTS.md...');

        $infoLabels = [
            '[PROJECT NAME]' => 'Project name',
            '[e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]' => 'Stack',
        ];

        foreach ($infoLabels as $placeholder => $label) {
            if (isset($detectedInfo[$placeholder])) {
                $this->line("  <fg=green>[DETECTED]</>  {$label}: {$detectedInfo[$placeholder]}");
            } else {
                $this->line("  <fg=yellow>[MANUAL]</>    {$label}: fill in manually");
            }
        }

        $this->newLine();

        $commandLabels = [
            '[TEST COMMAND]' => 'Tests',
            '[STATIC ANALYSIS COMMAND]' => 'Static analysis',
            '[START COMMAND]' => 'Start services',
            '[BUILD COMMAND]' => 'Build',
        ];

        foreach ($commandLabels as $placeholder => $label) {
            if (isset($detectedCommands[$placeholder])) {
                $this->line("  <fg=green>[DETECTED]</>  {$label}: {$detectedCommands[$placeholder]}");
            } else {
                $this->line("  <fg=yellow>[MANUAL]</>    {$label}: fill in manually");
            }
        }
    }
}
