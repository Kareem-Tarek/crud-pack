<?php

namespace KareemTarek\CrudPack\Providers;

use Illuminate\Support\ServiceProvider;
use KareemTarek\CrudPack\Commands\CrudMakeCommand;
use KareemTarek\CrudPack\Commands\CrudPackInstallCommand;
use KareemTarek\CrudPack\Commands\CrudPackTraitCommand;
use KareemTarek\CrudPack\Commands\CrudPackPostmanCommand;

class CrudPackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Makes config('crudpack...') work even if user did NOT publish the file
        $this->mergeConfigFrom(
            dirname(__DIR__, 2) . '/config/crud-pack.php',
            'crud-pack'
        );
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        // Register Artisan commands
        $this->commands([
            CrudMakeCommand::class,
            CrudPackInstallCommand::class,
            CrudPackTraitCommand::class,
            CrudPackPostmanCommand::class,
        ]);

        // Allow vendor:publish for the package views
        $this->publishes([
            dirname(__DIR__, 2) . '/resources/views' => resource_path('views'),
        ], 'crud-pack-views');

        // Publish config
        $this->publishes([
            dirname(__DIR__, 2) . '/config/crud-pack.php' => config_path('crud-pack.php'),
        ], 'crud-pack-config');
    }
}
