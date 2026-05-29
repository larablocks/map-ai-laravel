<?php

use Illuminate\Support\Facades\File;
use larablocks\MapAi\Installer;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/map-ai-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->app->setBasePath($this->tempDir);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

it('reports not installed for scaffold files that do not exist', function () {
    $this->artisan('map:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('[NOT INSTALLED]');
});

it('reports identical for scaffold files matching the stub', function () {
    $stubContent = file_get_contents(Installer::stubsPath().'/AGENTS.md');
    file_put_contents($this->tempDir.'/AGENTS.md', $stubContent);

    $this->artisan('map:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('[IDENTICAL]');
});

it('reports modified for scaffold files that differ from the stub', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $this->artisan('map:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('[MODIFIED]')
        ->expectsOutputToContain('AGENTS.md');
});

it('diffs only the specified file when given a file argument', function () {
    file_put_contents($this->tempDir.'/AGENTS.md', 'custom content');

    $stubContent = file_get_contents(Installer::stubsPath().'/CLAUDE.md');
    file_put_contents($this->tempDir.'/CLAUDE.md', $stubContent);

    $this->artisan('map:diff', ['file' => 'AGENTS.md'])
        ->assertSuccessful()
        ->expectsOutputToContain('[MODIFIED]')
        ->expectsOutputToContain('AGENTS.md');
});

it('returns failure for an unrecognised file argument', function () {
    $this->artisan('map:diff', ['file' => 'NONEXISTENT.md'])
        ->assertFailed()
        ->expectsOutputToContain('not a scaffold file');
});

it('shows a summary line with counts', function () {
    $stubContent = file_get_contents(Installer::stubsPath().'/AGENTS.md');
    file_put_contents($this->tempDir.'/AGENTS.md', $stubContent);

    $this->artisan('map:diff')
        ->assertSuccessful()
        ->expectsOutputToContain('1 identical');
});
