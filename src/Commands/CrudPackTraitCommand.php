<?php

// ============================================================================
// FILE: KareemTarek/CrudPack/Commands/CrudPackTraitCommand.php
// UPDATED: remove --soft-deletes/--no-soft-deletes (trait is always superset)
// ============================================================================

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class CrudPackTraitCommand extends Command
{
    protected $signature = 'crud:trait
        {--force : Overwrite existing HandlesDeletes.php without prompting}';

    protected $description = 'Create or re-generate the shared HandlesDeletes trait in the Laravel app.';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = app_path('Http/Controllers/Concerns/HandlesDeletes.php');
        $stub   = $this->stubPath('traits/HandlesDeletes.stub');

        if (!$this->files->exists($stub)) {
            $this->error("Stub not found: {$stub}");
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        // Ensure directory exists
        $dir = dirname($target);
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        // If already exists, confirm unless --force
        if ($this->files->exists($target) && !$force) {
            $replace = $this->confirm(
                "HandlesDeletes trait already exists at:\n{$target}\n\nReplace it?",
                false
            );

            if (!$replace) {
                $this->info('Skipped. Existing HandlesDeletes trait kept.');
                return self::SUCCESS;
            }
        }

        $content = $this->files->get($stub);

        // Safety: refuse if placeholders remain
        if (preg_match_all('/\{\{[A-Z0-9_\-]+}}/i', $content, $m) && !empty($m[0])) {
            $left = implode(', ', array_unique($m[0]));
            $this->error("Unreplaced placeholders in HandlesDeletes.stub: {$left}");
            $this->warn("Refusing to write {$target} to avoid broken generated code.");
            return self::FAILURE;
        }

        $this->files->put($target, $content);

        $this->info("✅ HandlesDeletes trait installed/updated:");
        $this->line($target);

        return self::SUCCESS;
    }

    protected function stubPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/stubs/' . $relative;
    }
}

