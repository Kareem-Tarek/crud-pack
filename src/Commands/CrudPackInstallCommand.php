<?php

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class CrudPackInstallCommand extends Command
{
    protected $signature = 'crud-pack:install
                            {--force : Overwrite existing files without prompting}';

    protected $description = 'Install CRUD Pack Bootstrap layout views (layouts/app, layouts/navigation, welcome).';

    public function handle(Filesystem $files): int
    {
        $targets = [
            'layouts/app.blade.php' => resource_path('views/layouts/app.blade.php'),
            'layouts/navigation.blade.php' => resource_path('views/layouts/navigation.blade.php'),
            'welcome.blade.php' => resource_path('views/welcome.blade.php'),
        ];

        $existing = [];
        $missing = [];

        foreach ($targets as $label => $path) {
            if ($files->exists($path)) {
                $existing[$label] = $path;
            } else {
                $missing[$label] = $path;
            }
        }

        // Case 1: --force => overwrite everything without prompting
        if ($this->option('force')) {
            $this->publish(force: true);
            $this->info('CRUD Pack layouts installed (forced overwrite).');
            return self::SUCCESS;
        }

        // Case 2: Nothing exists => publish normally (auto-create)
        if (empty($existing)) {
            $this->publish(force: false);
            $this->info('CRUD Pack layouts installed.');
            return self::SUCCESS;
        }

        // Case 3: Some (or all) exist => prompt whether to overwrite
        $this->warn('Some layout view files already exist in your project:');
        foreach ($existing as $label => $path) {
            $this->line("  - {$label}  ({$path})");
        }

        $overwrite = $this->confirm(
            'Do you want to overwrite existing layout files with CRUD Pack versions?',
            false
        );

        if ($overwrite) {
            $this->publish(force: true);
            $this->info('CRUD Pack layouts installed (overwritten).');
            return self::SUCCESS;
        }

        // If user says "no", we still publish missing ones (if any) without overwriting existing ones.
        if (!empty($missing)) {
            $this->info('Keeping existing files. Installing only missing CRUD Pack layout files...');
            $this->publish(force: false);
            $this->info('Missing CRUD Pack layout files installed.');
            return self::SUCCESS;
        }

        $this->info('No changes made. Existing layout files were kept.');
        return self::SUCCESS;
    }

    protected function publish(bool $force): void
    {
        $params = [
            '--tag' => 'crud-pack-layouts',
        ];

        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
    }
}
