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
        ->expectsConfirmation('Apply stub changes to AGENTS.md?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($agentsPath))->toBe('custom content');
});

it('updates managed files without --force', function () {
    mkdir($this->tempDir.'/.claude/rules', 0755, true);
    file_put_contents($this->tempDir.'/.claude/rules/security.md', 'old content');

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[UPDATE]');

    expect(file_get_contents($this->tempDir.'/.claude/rules/security.md'))->not->toBe('old content');
});

it('does not create a backup when updating managed files', function () {
    mkdir($this->tempDir.'/.claude/rules', 0755, true);
    file_put_contents($this->tempDir.'/.claude/rules/security.md', 'old content');

    $this->artisan('map:install')->assertSuccessful();

    expect(file_exists($this->tempDir.'/.claude/rules/security.md.bak'))->toBeFalse();
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
        ->toContain('HANDOFF.md')
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
        ->toContain('HANDOFF.md');
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

    expect(file_exists($this->tempDir.'/HANDOFF.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/CLAUDE.local.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/MEMORY.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/gotchas.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/framework.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/database.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/testing.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/environment.md'))->toBeTrue();
    expect(file_exists($this->tempDir.'/docs/memory/agents.md'))->toBeTrue();
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

it('shows diff and prompts for each out-of-date scaffold file', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $this->artisan('map:install')
        ->expectsOutputToContain('[MODIFIED]')
        ->expectsConfirmation('Apply stub changes to AGENTS.md?', 'no')
        ->assertSuccessful();
});

it('applies stub changes and creates a backup when user confirms', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $this->artisan('map:install')
        ->expectsConfirmation('Apply stub changes to AGENTS.md?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))->not->toBe('custom content');
    expect(file_exists($this->tempDir.'/AGENTS.md.bak'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/AGENTS.md.bak'))->toBe('custom content');
});

it('keeps the existing file when user declines stub changes', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $this->artisan('map:install')
        ->expectsConfirmation('Apply stub changes to AGENTS.md?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('[KEPT]');

    expect(file_get_contents($this->tempDir.'/AGENTS.md'))->toBe('custom content');
    expect(file_exists($this->tempDir.'/AGENTS.md.bak'))->toBeFalse();
});

it('does not duplicate gitignore entries on re-install', function () {
    $this->artisan('map:install')->assertSuccessful();
    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[SKIP]');

    $gitignore = file_get_contents($this->tempDir.'/.gitignore');

    expect(substr_count($gitignore, 'HANDOFF.md'))->toBe(1);
});
