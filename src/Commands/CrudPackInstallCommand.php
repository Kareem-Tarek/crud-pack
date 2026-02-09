<?php

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class CrudPackInstallCommand extends Command
{
    protected $signature = 'crud:install
        {--force : Overwrite existing files without prompting}';

    protected $description = 'Install CRUD Pack Bootstrap layout views (app, navigation, welcome).';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Package resources/views (at package root)
        $packageViewsPath = dirname(__DIR__, 2) . '/resources/views';

        if (!$this->files->isDirectory($packageViewsPath)) {
            $this->error("Package views directory not found: {$packageViewsPath}");
            $this->line('Expected files:');
            $this->line('- resources/views/layouts/app.blade.php');
            $this->line('- resources/views/layouts/navigation.blade.php');
            $this->line('- resources/views/welcome.blade.php');
            return self::FAILURE;
        }

        $targetBase = resource_path('views');

        $filesToInstall = [
            'layouts/app.blade.php',
            'layouts/navigation.blade.php',
            'welcome.blade.php',
        ];

        $force = (bool) $this->option('force');

        $this->info('Installing CRUD Pack layout views...');

        foreach ($filesToInstall as $relative) {
            $source = $packageViewsPath . DIRECTORY_SEPARATOR . $relative;
            $target = $targetBase . DIRECTORY_SEPARATOR . $relative;

            if (!$this->files->exists($source)) {
                $this->warn("Missing in package: {$relative} (skipped)");
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($target));

            if ($this->files->exists($target) && !$force) {
                $replace = $this->confirm("File exists: {$relative}. Replace it?", false);
                if (!$replace) {
                    $this->info("Skipped: {$relative}");
                    continue;
                }
            }

            $this->files->copy($source, $target);
            $this->info("Installed: {$relative}");
        }

        $this->newLine();
        $this->info('✅ CRUD Pack layout views installed successfully.');
        $this->line('Tip: run again with --force to overwrite without prompts.');
        $this->line('Optional: php artisan vendor:publish --tag=crud-pack-views');

        return self::SUCCESS;
    }
}
