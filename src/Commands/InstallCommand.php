<?php

namespace larablocks\MapAi\Commands;

use Illuminate\Console\Command;
use larablocks\MapAi\Commands\Concerns\RendersDiff;
use larablocks\MapAi\Installer;

class InstallCommand extends Command
{
    use RendersDiff;
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

        $skipped = array_column(
            array_filter($result['files'], fn (array $f) => $f['action'] === 'skip'),
            'file'
        );

        $outOfDate = array_values(array_filter(
            $skipped,
            fn (string $file) => file_get_contents(Installer::stubsPath().'/'.$file) !== file_get_contents($targetPath.'/'.$file)
        ));

        if ($outOfDate !== []) {
            $count = count($outOfDate);
            $this->newLine();
            $this->line("Reviewing {$count} scaffold file(s) with stub updates...");

            foreach ($outOfDate as $file) {
                $stub = Installer::stubsPath().'/'.$file;
                $project = $targetPath.'/'.$file;

                $this->newLine();
                $this->line("  <fg=blue>[MODIFIED]</>  {$file}");
                $this->newLine();
                $this->showDiff($stub, $project);
                $this->newLine();

                if ($this->confirm("Apply stub changes to {$file}?", false)) {
                    copy($project, $project.'.bak');
                    copy($stub, $project);
                    $this->line("  <fg=green>[APPLIED]</>   {$file}  (original saved as {$file}.bak)");
                } else {
                    $this->line("  <fg=yellow>[KEPT]</>      {$file}");
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
        $content = (string) file_get_contents($path);
        $content = str_replace('[DATE]', date('Y-m-d'), $content);

        $detectedInfo = $this->detectProjectInfo($targetPath);
        $detectedCommands = $this->detectCommands($targetPath);

        foreach (array_merge($detectedInfo, $detectedCommands) as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        file_put_contents($path, $content);

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

    /** @return array<string, string> */
    private function detectProjectInfo(string $targetPath): array
    {
        $composer = [];
        $composerPath = $targetPath.'/composer.json';
        if (file_exists($composerPath)) {
            $composer = (array) (json_decode((string) file_get_contents($composerPath), true) ?? []);
        }
        $require = (array) ($composer['require'] ?? []);

        $detected = [];

        // Project name from composer.json "name", falling back to directory basename
        if (isset($composer['name']) && is_string($composer['name'])) {
            $parts = explode('/', $composer['name']);
            $detected['[PROJECT NAME]'] = ucwords(str_replace(['-', '_'], ' ', end($parts)));
        } else {
            $detected['[PROJECT NAME]'] = ucwords(str_replace(['-', '_'], ' ', basename($targetPath)));
        }

        // Stack: build a comma-separated list of detected components
        $stack = [];

        if (isset($require['laravel/framework']) && is_string($require['laravel/framework'])) {
            preg_match('/\d+/', $require['laravel/framework'], $m);
            $stack[] = isset($m[0]) ? "Laravel {$m[0]}" : 'Laravel';
        }

        if (isset($require['php']) && is_string($require['php'])) {
            preg_match('/\d+\.\d+/', $require['php'], $m);
            $stack[] = isset($m[0]) ? "PHP {$m[0]}" : 'PHP';
        }

        $envPath = $targetPath.'/.env.example';
        $env = file_exists($envPath) ? (string) file_get_contents($envPath) : '';

        if ($env !== '' && preg_match('/^DB_CONNECTION=(\S+)/m', $env, $m)) {
            $stack[] = match (trim($m[1])) {
                'pgsql' => 'PostgreSQL',
                'mysql' => 'MySQL',
                'sqlite' => 'SQLite',
                default => strtoupper(trim($m[1])),
            };
        }

        if (
            isset($require['predis/predis']) ||
            isset($require['illuminate/redis']) ||
            ($env !== '' && str_contains($env, 'REDIS_HOST='))
        ) {
            $stack[] = 'Redis';
        }

        if ($stack !== []) {
            $detected['[e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]'] = implode(', ', $stack);
        }

        return $detected;
    }

    /** @return array<string, string> */
    private function detectCommands(string $targetPath): array
    {
        $composer = [];
        $composerPath = $targetPath.'/composer.json';
        if (file_exists($composerPath)) {
            $composer = (array) (json_decode((string) file_get_contents($composerPath), true) ?? []);
        }
        $composerScripts = (array) ($composer['scripts'] ?? []);

        $package = [];
        $packagePath = $targetPath.'/package.json';
        if (file_exists($packagePath)) {
            $package = (array) (json_decode((string) file_get_contents($packagePath), true) ?? []);
        }
        $packageScripts = (array) ($package['scripts'] ?? []);

        $detected = [];

        // Test command
        if (isset($composerScripts['test'])) {
            $detected['[TEST COMMAND]'] = 'composer test';
        } elseif (file_exists($targetPath.'/vendor/bin/pest')) {
            $detected['[TEST COMMAND]'] = './vendor/bin/pest';
        } elseif (file_exists($targetPath.'/vendor/bin/phpunit')) {
            $detected['[TEST COMMAND]'] = './vendor/bin/phpunit';
        } else {
            $detected['[TEST COMMAND]'] = 'php artisan test';
        }

        // Static analysis
        if (isset($composerScripts['analyse'])) {
            $detected['[STATIC ANALYSIS COMMAND]'] = 'composer analyse';
        } elseif (isset($composerScripts['analyze'])) {
            $detected['[STATIC ANALYSIS COMMAND]'] = 'composer analyze';
        } elseif (file_exists($targetPath.'/vendor/bin/phpstan')) {
            $detected['[STATIC ANALYSIS COMMAND]'] = './vendor/bin/phpstan analyse';
        }

        // Start services
        if (file_exists($targetPath.'/docker-compose.yml') || file_exists($targetPath.'/docker-compose.yaml')) {
            $detected['[START COMMAND]'] = './vendor/bin/sail up -d';
        } else {
            $detected['[START COMMAND]'] = 'php artisan serve';
        }

        // Build
        if (isset($packageScripts['build'])) {
            $manager = match (true) {
                file_exists($targetPath.'/bun.lockb') => 'bun',
                file_exists($targetPath.'/yarn.lock') => 'yarn',
                default => 'npm',
            };
            $detected['[BUILD COMMAND]'] = "{$manager} run build";
        }

        return $detected;
    }
}
