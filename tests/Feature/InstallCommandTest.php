<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->app->setBasePath($this->tempDir);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('copies all stubs to the project root', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/AGENTS.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/CLAUDE.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/GEMINI.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/STATUS.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/BUGS.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/.claude/rules/security.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/.claude/rules/testing.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/.github/copilot-instructions.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/gotchas.example.md'))->toBeTrue();
});

it('skips scaffold files without --force', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'custom content');

    $this->artisan('map:install')
        ->expectsOutputToContain('[SKIP]')
        ->assertSuccessful();

    expect(file_get_contents($agentsPath))->toBe('custom content');
});

it('updates managed files without --force', function () {
    mkdir($this->tempDir.'/.cursor/rules', 0755, true);
    file_put_contents($this->tempDir.'/.cursor/rules/agents.mdc', 'old content');

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[UPDATE]');

    expect(file_get_contents($this->tempDir.'/.cursor/rules/agents.mdc'))->not->toBe('old content');
});

it('does not create a backup when updating managed files', function () {
    mkdir($this->tempDir.'/.cursor/rules', 0755, true);
    file_put_contents($this->tempDir.'/.cursor/rules/agents.mdc', 'old content');

    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/.cursor/rules/agents.mdc.bak'))->toBeFalse();
});

it('overwrites existing files with --force', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'custom content');

    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents($agentsPath))->not->toBe('custom content');
});

it('creates nested directories when copying stubs', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(is_dir($this->tempDir.'/docs/memory'))->toBeTrue();
    expect(is_dir($this->tempDir.'/.claude/rules'))->toBeTrue();
    expect(is_dir($this->tempDir.'/.github'))->toBeTrue();
});

it('appends map entries to an empty gitignore', function () {
    $this->artisan('map:install')->assertSuccessful();

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect($gitignore)
        ->toContain('.claude/settings.local.json')
        ->toContain('CLAUDE.local.md')
        ->toContain('docs/MEMORY.md')
        ->toContain('docs/memory/*.md')
        ->toContain('!docs/memory/*.example.md')
        ->toContain('!docs/memory/shared.md');
});

it('appends map entries after existing gitignore content', function () {
    $existing = "node_modules\n.env\n";
    file_put_contents($this->tempDir.'/.gitignore', $existing);

    $this->artisan('map:install')->assertSuccessful();

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect($gitignore)
        ->toStartWith($existing)
        ->toContain('.claude/settings.local.json');
});

it('stamps today\'s date into AGENTS.md on install', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain(date('Y-m-d'))
        ->not->toContain('[DATE]');
});

it('detects composer test script and writes it to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'scripts' => ['test' => 'vendor/bin/pest'],
    ]));

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('composer test')
        ->not->toContain('[TEST COMMAND]');
});

it('detects npm build script and writes it to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/package.json', json_encode([
        'scripts' => ['build' => 'vite build'],
    ]));
    file_put_contents($this->tempDir.'/package-lock.json', '{}');

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('npm run build')
        ->not->toContain('[BUILD COMMAND]');
});

it('detects sail when docker-compose.yml is present', function () {
    file_put_contents($this->tempDir.'/docker-compose.yml', 'services:');

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('./vendor/bin/sail up -d');
});

it('leaves undetected commands as placeholders', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('[BUILD COMMAND]')
        ->toContain('[STATIC ANALYSIS COMMAND]');
});

it('lists files to be overwritten and asks for confirmation before --force', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'my custom content');
    file_put_contents($this->tempDir.'/CLAUDE.md', 'my claude content');

    $this->artisan('map:install', ['--force' => true])
        ->expectsOutputToContain('AGENTS.md')
        ->expectsOutputToContain('CLAUDE.md')
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful();
});

it('aborts when user declines the overwrite confirmation', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'my custom content');

    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))->toBe('my custom content');
});

it('skips confirmation when --force is used but no files exist yet', function () {
    $this->artisan('map:install', ['--force' => true])
        ->assertSuccessful();

    expect(file_exists($this->tempDir.'/AGENTS.md'))->toBeTrue();
});

it('shows identical and skips backup when file content matches stub', function () {
    $stubContent = file_get_contents(\larablocks\MapAi\Installer::stubsPath().'/AGENTS.md');
    file_put_contents($this->tempDir.'/AGENTS.md', $stubContent);

    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[IDENTICAL]');

    expect(file_exists($this->tempDir.'/AGENTS.md.bak'))->toBeFalse();
});

it('outputs a backup line and creates a .bak file when force-overwriting', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'my custom content');

    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[BACKUP]');

    expect(file_exists($this->tempDir.'/AGENTS.md.bak'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/AGENTS.md.bak'))->toBe('my custom content');
});

it('detects project name from composer.json and writes it to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'name' => 'acme/my-cool-app',
    ]));

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('My Cool App')
        ->not->toContain('[PROJECT NAME]');
});

it('detects laravel and php versions from composer.json and writes to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/composer.json', json_encode([
        'require' => [
            'php' => '^8.4',
            'laravel/framework' => '^12.0',
        ],
    ]));

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('Laravel 12')
        ->toContain('PHP 8.4')
        ->not->toContain('[e.g. Laravel 13, PHP 8.5, PostgreSQL 16, Redis]');
});

it('detects database from .env.example and writes to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/.env.example', "APP_NAME=Laravel\nDB_CONNECTION=pgsql\n");

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('PostgreSQL');
});

it('detects redis from .env.example and writes to AGENTS.md', function () {
    file_put_contents($this->tempDir.'/.env.example', "REDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n");

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))
        ->toContain('Redis');
});

it('bootstraps personal files from example stubs on install', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/docs/MEMORY.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/gotchas.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/database.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/testing.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/environment.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/performance.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/agents.md'))->toBeTrue();
});

it('does not bootstrap docs/memory/framework.md — it needs a project-specific rename first', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/docs/memory/framework.md'))->toBeFalse();
});

it('does not overwrite existing personal files on re-install', function () {
    mkdir($this->tempDir.'/docs/memory', 0755, true);
    file_put_contents($this->tempDir.'/docs/memory/gotchas.md', 'my notes');

    $this->artisan('map:install')->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/docs/memory/gotchas.md'))->toBe('my notes');
});

it('does not copy shared.md as a personal file', function () {
    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/docs/memory/shared.md'))->toBeFalse();
});

it('shows [NO NEW CONTENT] when all differences are user customisations', function () {
    $stubContent = (string) file_get_contents(\larablocks\MapAi\Installer::stubsPath().'/CLAUDE.md');
    file_put_contents($this->tempDir.'/CLAUDE.md', $stubContent."\n\n## My Custom Section\n\nCustom content here.");

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[NO NEW CONTENT]');
});

it('shows diff and prompts to append when stub has new content missing from project', function () {
    $stubContent = (string) file_get_contents(\larablocks\MapAi\Installer::stubsPath().'/CLAUDE.md');
    $truncated = implode("\n", array_slice(explode("\n", $stubContent), 0, 3))."\n";
    file_put_contents($this->tempDir.'/CLAUDE.md', $truncated);

    $this->artisan('map:install')
        ->expectsOutputToContain('[MODIFIED]')
        ->expectsConfirmation('Append new stub content to CLAUDE.md?', 'no')
        ->assertSuccessful();
});

it('appends new stub content and creates a backup when user confirms', function () {
    $stubContent = (string) file_get_contents(\larablocks\MapAi\Installer::stubsPath().'/CLAUDE.md');
    $truncated = implode("\n", array_slice(explode("\n", $stubContent), 0, 3))."\n";
    file_put_contents($this->tempDir.'/CLAUDE.md', $truncated);

    $this->artisan('map:install')
        ->expectsConfirmation('Append new stub content to CLAUDE.md?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[UPDATED]');

    expect(strlen((string) file_get_contents($this->tempDir.'/CLAUDE.md')))->toBeGreaterThan(strlen($truncated));
    expect(file_exists($this->tempDir.'/CLAUDE.md.bak'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/CLAUDE.md.bak'))->toBe($truncated);
});

it('does not modify the file when user declines appending stub content', function () {
    $stubContent = (string) file_get_contents(\larablocks\MapAi\Installer::stubsPath().'/CLAUDE.md');
    $truncated = implode("\n", array_slice(explode("\n", $stubContent), 0, 3))."\n";
    file_put_contents($this->tempDir.'/CLAUDE.md', $truncated);

    $this->artisan('map:install')
        ->expectsConfirmation('Append new stub content to CLAUDE.md?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('[SKIPPED]');

    expect(file_get_contents($this->tempDir.'/CLAUDE.md'))->toBe($truncated);
    expect(file_exists($this->tempDir.'/CLAUDE.md.bak'))->toBeFalse();
});

it('does not duplicate gitignore entries on re-install', function () {
    $this->artisan('map:install')->assertSuccessful();
    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[SKIP]');

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect(substr_count($gitignore, '.claude/settings.local.json'))->toBe(1);
});

it('flags an existing settings.json that does not register the token hook', function () {
    mkdir($this->tempDir.'/.claude', 0755, true);
    file_put_contents($this->tempDir.'/.claude/settings.json', '{"hooks": {}}');

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[NEEDS REVIEW]  .claude/settings.json');

    expect(file_get_contents($this->tempDir.'/.claude/settings.json'))->toBe('{"hooks": {}}');
});

it('installs settings.json with the token hook when none exists', function () {
    $this->artisan('map:install')
        ->assertSuccessful()
        ->doesntExpectOutputToContain('token-hook-not-registered');

    expect(file_get_contents($this->tempDir.'/.claude/settings.json'))->toContain('map-token-check.sh');
    expect(file_exists($this->tempDir.'/.claude/hooks/map-token-check.sh'))->toBeTrue();
});

it('flags AGENTS.md over the token cap', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', str_repeat(str_repeat('x', 2000)."\n", 10));

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[NEEDS REVIEW]  AGENTS.md');
});

it('registers the merge driver when the project is a git repository', function () {
    shell_exec('git -C '.escapeshellarg($this->tempDir).' init -q 2>&1');

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('merge.map-ai — registered in .git/config');

    expect(\larablocks\MapAi\Installer::mergeDriverRegistered($this->tempDir))->toBeTrue();
});

it('skips the merge driver outside a git repository', function () {
    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('merge.map-ai — not a git repository');
});
