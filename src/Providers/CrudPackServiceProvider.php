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
        if (!$this->app->runningInConsole()) {
            return;
        }

        // ✅ Register Artisan commands
        $this->commands([
            CrudMakeCommand::class,
            CrudPackInstallCommand::class,
        ]);

        // ✅ Allow vendor:publish for the package views
        $this->publishes([
            dirname(__DIR__, 2) . '/resources/views' => resource_path('views'),
        ], 'crud-pack-views');
    }
}
