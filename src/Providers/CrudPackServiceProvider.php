<?php

namespace KareemTarek\CrudPack\Providers;

use Illuminate\Support\ServiceProvider;
use KareemTarek\CrudPack\Commands\CrudMakeCommand;
use KareemTarek\CrudPack\Commands\CrudPackInstallCommand;

class CrudPackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                CrudMakeCommand::class,
                CrudPackInstallCommand::class,
            ]);

            // Allow vendor:publish of the layout/welcome blades (optional alternative to crud:install)
            $this->publishes([
                __DIR__ . '/../../resources/views' => resource_path('views'),
            ], 'crud-pack-views');
        }
    }
}
