<?php

namespace larablocks\MapAi\Commands;

use Illuminate\Console\Command;
use larablocks\MapAi\Installer;

class InstallCommand extends Command
{
    protected $signature = 'map:install {--force : Overwrite existing files}';

    protected $description = 'Install the MAP AI documentation scaffold into this project';

    public function handle(): int
    {
        $targetPath = base_path();

        $this->info('Installing MAP into: '.$targetPath);
        $this->newLine();

        if ($this->option('force')) {
            $willOverwrite = array_values(array_filter(
                Installer::FILES,
                fn (string $file) => file_exists($targetPath.'/'.$file),
            ));

            if ($willOverwrite !== []) {
                $this->warn('The following existing files will be overwritten (originals saved as .bak):');
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

        $result = (new Installer)->install(Installer::stubsPath(), $targetPath, (bool) $this->option('force'));

        $copied = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($result['files'] as ['action' => $action, 'file' => $file, 'backed_up' => $backedUp]) {
            if ($backedUp) {
                $this->line("  <fg=cyan>[BACKUP]</> {$file}.bak");
            }
            if ($action === 'copy') {
                $this->line("  <fg=green>[COPY]</>   {$file}");
                $copied++;
            } elseif ($action === 'update') {
                $this->line("  <fg=blue>[UPDATE]</> {$file}");
                $copied++;
            } elseif ($action === 'skip') {
                $this->line("  <fg=yellow>[SKIP]</>   {$file}  (already exists — use --force to overwrite)");
                $skipped++;
            } elseif ($action === 'missing') {
                $this->warn("  [WARN]   source not found — {$file}");
                $missing++;
            }
        }

        $this->newLine();
        $this->line('Merging .gitignore...');

        if ($result['gitignore'] === 'updated') {
            $this->line('  <fg=green>[UPDATE]</> .gitignore — MAP entries appended');
        } else {
            $this->line('  <fg=yellow>[SKIP]</>   .gitignore — MAP entries already present');
        }

        $this->newLine();
        $this->info("Done. {$copied} file(s) copied, {$skipped} skipped, {$missing} missing from source.");
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Edit AGENTS.md line 2 — set project name and stack');
        $this->line('  2. Edit AGENTS.md line 3 — set today\'s date');
        $this->line('  3. Fill in the Commands section of AGENTS.md (test, build, start)');
        $this->line('  4. Each developer runs the \'cp\' commands in docs/SETUP.md step 3');
        $this->line('     to initialize their personal gitignored files (HANDOFF.md, docs/MEMORY.md, etc.)');

        return self::SUCCESS;
    }
}
