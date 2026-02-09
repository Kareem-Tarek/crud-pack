<?php

namespace KareemTarek\CrudPack\Providers;

use Illuminate\Support\ServiceProvider;
use KareemTarek\CrudPack\Commands\CrudMakeCommand;
use KareemTarek\CrudPack\Commands\CrudPackInstallCommand;

class CrudPackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish Bootstrap layout blades (optional install step)
        $this->publishes([
            __DIR__ . '/../../resources/views/layouts/app.blade.php' =>
                resource_path('views/layouts/app.blade.php'),

            __DIR__ . '/../../resources/views/layouts/navigation.blade.php' =>
                resource_path('views/layouts/navigation.blade.php'),

            __DIR__ . '/../../resources/views/welcome.blade.php' =>
                resource_path('views/welcome.blade.php'),
        ], 'crud-pack-layouts');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CrudMakeCommand::class,
                CrudPackInstallCommand::class,
            ]);
        }
    }
}
