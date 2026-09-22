<?php

namespace larablocks\MapAi\Commands;

use Illuminate\Console\Command;
use larablocks\MapAi\Commands\Concerns\ProcessesStubContent;
use larablocks\MapAi\Commands\Concerns\RendersDiff;
use larablocks\MapAi\Doctor;
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
        $this->line('Wiring Claude Code SessionStart hook...');

        $claudeSettingsAction = $result['claudeSettings']['action'];
        if (in_array($claudeSettingsAction, ['copy', 'update'], true)) {
            $this->line('  <fg=green>[COPY]</>   .claude/settings.json — first-run check and token-cap hooks wired');
        } elseif ($claudeSettingsAction === 'identical') {
            $this->line('  <fg=gray>[SAME]</>   .claude/settings.json — already wired');
        } elseif ($claudeSettingsAction === 'skip') {
            $this->line('  <fg=yellow>[SKIP]</>   .claude/settings.json already exists — add the MAP hooks manually (see vendor/larablocks/map-ai/stubs/.claude/settings.json for the blocks to merge in)');
        }

        $this->newLine();
        $this->line('Registering merge driver...');

        match ($result['mergeDriver']) {
            'updated' => $this->line('  <fg=green>[UPDATE]</> merge.map-ai — registered in .git/config'),
            'skipped' => $this->line('  <fg=gray>[SAME]</>   merge.map-ai — already registered'),
            'not-a-repo' => $this->line('  <fg=yellow>[SKIP]</>   merge.map-ai — not a git repository (run map:install again after git init)'),
            default => $this->line('  <fg=yellow>[WARN]</>   merge.map-ai — could not write git config'),
        };

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

        $doctor = new Doctor;
        $findingsByFile = [];
        foreach ($doctor->check(Installer::stubsPath(), $targetPath) as $finding) {
            $findingsByFile[$finding['file']][] = $finding;
        }

        if ($skippedFiles !== []) {
            $this->newLine();
            $this->line('Checking for new stub content...');

            foreach ($skippedFiles as $file) {
                if ($file === '.github/copilot-instructions.md') {
                    continue; // handled separately below — regenerated, not diffed against a stub
                }

                $stub = Installer::stubsPath().'/'.$file;
                $project = $targetPath.'/'.$file;

                $hunks = $doctor->fixableHunks($project, $stub);

                if ($hunks === []) {
                    $ids = array_column($findingsByFile[$file] ?? [], 'id');
                    $label = in_array('outdated-scaffold-file', $ids, true)
                        ? '<fg=yellow>[NEEDS REVIEW]</>  '
                        : '<fg=gray>[NO NEW CONTENT]</>';
                    $this->line("  {$label} {$file}");

                    continue;
                }

                $this->newLine();
                $this->line("  <fg=blue>[MODIFIED]</>    {$file}");
                $this->newLine();
                $this->renderFixableHunks($project, $hunks);
                $this->newLine();

                if ($this->confirm("Append new stub content to {$file}?", true)) {
                    copy($project, $project.'.bak');
                    $doctor->applyHunks($project, $hunks);
                    $this->line("  <fg=green>[UPDATED]</>   {$file}  (patched in-place, original saved as {$file}.bak)");
                } else {
                    $this->line("  <fg=yellow>[SKIPPED]</>   {$file}");
                }
            }

            $this->newLine();
        }

        $this->syncCopilotInstructions($doctor, $targetPath);
        $this->reportReviewOnlyFindings($doctor, $targetPath);

        return self::SUCCESS;
    }

    /**
     * Findings map-ai's Doctor reports but never fixes, and that aren't tied to a
     * scaffold-file diff shown above — an AGENTS.md over its token cap, or an existing
     * .claude/settings.json (copy-if-absent, so install never touched it) that doesn't
     * register the token-check hook. Surfaced so they aren't silently missed.
     */
    private function reportReviewOnlyFindings(Doctor $doctor, string $targetPath): void
    {
        foreach ($doctor->check(Installer::stubsPath(), $targetPath) as $f) {
            if (in_array($f['id'], ['agents-md-too-long', 'token-hook-not-registered'], true)) {
                $this->line("  <fg=yellow>[NEEDS REVIEW]</>  {$f['file']}  ({$f['message']})");
            }
        }
    }

    /**
     * Re-checks (rather than reusing the earlier $findingsByFile snapshot) because the
     * scaffold-file loop above may just have patched AGENTS.md — copilot-instructions.md
     * is regenerated from AGENTS.md/security.md/testing.md, so its sync state needs to
     * reflect whatever those files look like now, not before the loop ran.
     */
    private function syncCopilotInstructions(Doctor $doctor, string $targetPath): void
    {
        $finding = null;
        foreach ($doctor->check(Installer::stubsPath(), $targetPath) as $f) {
            if ($f['id'] === 'copilot-out-of-sync') {
                $finding = $f;
                break;
            }
        }

        if ($finding === null) {
            return; // already in sync, or a source file (AGENTS.md/security.md/testing.md) is missing
        }

        if (! $finding['fixable']) {
            $this->line("  <fg=yellow>[NEEDS REVIEW]</>  .github/copilot-instructions.md  ({$finding['message']})");

            return;
        }

        $this->newLine();
        $this->line('  <fg=blue>[OUT OF SYNC]</>   .github/copilot-instructions.md  (safe to regenerate from AGENTS.md/security.md/testing.md)');

        if ($this->confirm('Regenerate .github/copilot-instructions.md?', true)) {
            $doctor->fixCopilotSync($targetPath);
            $this->line('  <fg=green>[UPDATED]</>   .github/copilot-instructions.md regenerated');
        } else {
            $this->line('  <fg=yellow>[SKIPPED]</>   .github/copilot-instructions.md');
        }
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
