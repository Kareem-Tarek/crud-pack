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
        $sourceBase = __DIR__ . '/../../resources/views';
        $targetBase = resource_path('views');

        $filesToInstall = [
            'layouts/app.blade.php',
            'layouts/navigation.blade.php',
            'welcome.blade.php',
        ];

        if (!$this->files->isDirectory($sourceBase)) {
            $this->error("Package views directory not found: {$sourceBase}");
            $this->line("Make sure you have: resources/views/layouts/app.blade.php, navigation.blade.php, and resources/views/welcome.blade.php in the package.");
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        foreach ($filesToInstall as $relative) {
            $source = $sourceBase . DIRECTORY_SEPARATOR . $relative;
            $target = $targetBase . DIRECTORY_SEPARATOR . $relative;

            if (!$this->files->exists($source)) {
                $this->warn("Missing in package: {$relative} (skipped)");
                continue;
            }

            // Ensure target directory
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
        $this->info('CRUD Pack layout views installed successfully.');
        $this->line('Tip: You can rerun with --force to overwrite without prompts.');
        return self::SUCCESS;
    }
}
