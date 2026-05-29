<?php

namespace larablocks\MapAi\Commands\Concerns;

trait ProcessesStubContent
{
    /** Apply file-specific placeholder substitutions before diffing or applying. */
    private function processStubContent(string $file, string $content, string $targetPath): string
    {
        if ($file !== 'AGENTS.md') {
            return $content;
        }

        $content = str_replace('[DATE]', date('Y-m-d'), $content);

        foreach (array_merge($this->detectProjectInfo($targetPath), $this->detectCommands($targetPath)) as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
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

        if (isset($composer['name']) && is_string($composer['name'])) {
            $parts = explode('/', $composer['name']);
            $detected['[PROJECT NAME]'] = ucwords(str_replace(['-', '_'], ' ', end($parts)));
        } else {
            $detected['[PROJECT NAME]'] = ucwords(str_replace(['-', '_'], ' ', basename($targetPath)));
        }

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

        if (isset($composerScripts['test'])) {
            $detected['[TEST COMMAND]'] = 'composer test';
        } elseif (file_exists($targetPath.'/vendor/bin/pest')) {
            $detected['[TEST COMMAND]'] = './vendor/bin/pest';
        } elseif (file_exists($targetPath.'/vendor/bin/phpunit')) {
            $detected['[TEST COMMAND]'] = './vendor/bin/phpunit';
        } else {
            $detected['[TEST COMMAND]'] = 'php artisan test';
        }

        if (isset($composerScripts['analyse'])) {
            $detected['[STATIC ANALYSIS COMMAND]'] = 'composer analyse';
        } elseif (isset($composerScripts['analyze'])) {
            $detected['[STATIC ANALYSIS COMMAND]'] = 'composer analyze';
        } elseif (file_exists($targetPath.'/vendor/bin/phpstan')) {
            $detected['[STATIC ANALYSIS COMMAND]'] = './vendor/bin/phpstan analyse';
        }

        if (file_exists($targetPath.'/docker-compose.yml') || file_exists($targetPath.'/docker-compose.yaml')) {
            $detected['[START COMMAND]'] = './vendor/bin/sail up -d';
        } else {
            $detected['[START COMMAND]'] = 'php artisan serve';
        }

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
