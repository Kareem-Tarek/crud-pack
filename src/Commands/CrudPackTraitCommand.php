<?php

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

        // Ensure directory exists
        $this->files->ensureDirectoryExists(dirname($target));

        $force = (bool) $this->option('force');

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
