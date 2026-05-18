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

it('skips existing files without --force', function () {
    $agentsPath = $this->tempDir.'/AGENTS.md';
    file_put_contents($agentsPath, 'custom content');

    $this->artisan('map:install')
        ->assertSuccessful()
        ->expectsOutputToContain('[SKIP]');

    expect(file_get_contents($agentsPath))->toBe('custom content');
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

it('outputs a backup line and creates a .bak file when force-overwriting', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'my custom content');

    $this->artisan('map:install', ['--force' => true])
        ->expectsConfirmation('Do you wish to continue?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('[BACKUP]');

    expect(file_exists($this->tempDir.'/AGENTS.md.bak'))->toBeTrue();
    expect(file_get_contents($this->tempDir.'/AGENTS.md.bak'))->toBe('my custom content');
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
