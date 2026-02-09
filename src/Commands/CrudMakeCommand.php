<?php

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class CrudMakeCommand extends Command
{
    protected $signature = 'crud:make
        {name : Resource name (singular StudlyCase, e.g. Category)}
        {--web : Generate a web controller}
        {--api : Generate an API controller}
        {--soft-deletes : Enable soft deletes}
        {--no-soft-deletes : Disable soft deletes}
        {--all : Generate all optional files (dynamic, exclusive)}
        {--routes : Append routes to routes/web.php or routes/api.php}
        {--request : Generate FormRequest}
        {--model : Generate Model}
        {--migration : Generate Migration}
        {--views : Generate Blade views (web only)}
        {--policy : Generate Policy}
        {--force : Overwrite existing files/blocks without prompting}
    ';

    protected $description = 'Generate CRUD resource scaffolding (controllers, requests, models, migrations, policies, views, routes).';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = Str::studly((string) $this->argument('name'));

        if ($name === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $this->error('Invalid resource name. Use singular StudlyCase like Category or ProductCategory.');
            return self::FAILURE;
        }

        /* ===========================
         | Required: Controller Type
         =========================== */
        $isWeb = (bool) $this->option('web');
        $isApi = (bool) $this->option('api');

        if ($isWeb && $isApi) {
            $this->error('Choose either --web or --api, not both.');
            return self::FAILURE;
        }

        if (!$isWeb && !$isApi) {
            $choice = $this->choice('Controller type?', ['web', 'api'], 0);
            $isWeb = $choice === 'web';
            $isApi = $choice === 'api';
        }

        /* ===========================
         | Required: Soft Deletes Mode
         =========================== */
        $soft = (bool) $this->option('soft-deletes');
        $noSoft = (bool) $this->option('no-soft-deletes');

        if ($soft && $noSoft) {
            $this->error('Choose either --soft-deletes or --no-soft-deletes, not both.');
            return self::FAILURE;
        }

        if (!$soft && !$noSoft) {
            $choice = $this->choice('Soft deletes?', ['soft-deletes', 'no-soft-deletes'], 0);
            $soft = $choice === 'soft-deletes';
            $noSoft = !$soft;
        }

        /* ===========================
         | --all exclusivity + Wizard
         =========================== */
        $allProvided = $this->optionWasProvided('all');
        $anyExplicitGenerators = $this->anyGeneratorOptionWasProvided();

        if ($allProvided && $anyExplicitGenerators) {
            $this->error('Do not combine --all with explicit generator options (--routes/--request/--model/--migration/--policy/--views).');
            return self::FAILURE;
        }

        if (!$allProvided && !$anyExplicitGenerators) {
            $this->info('No generation options provided. Answer the following prompts:');

            $this->input->setOption('routes', $this->confirm('Append routes automatically?', true));
            $this->input->setOption('model', $this->confirm('Generate Model?', false));
            $this->input->setOption('migration', $this->confirm('Generate Migration?', false));
            $this->input->setOption('request', $this->confirm('Generate Request validation (single FormRequest)?', false));
            $this->input->setOption('policy', $this->confirm('Generate Policy?', false));

            if ($isWeb) {
                $this->input->setOption('views', $this->confirm('Generate Blade views (Bootstrap 5)?', false));
            } else {
                $this->input->setOption('views', false);
            }
        }

        if ($allProvided) {
            $this->input->setOption('routes', true);
            $this->input->setOption('request', true);
            $this->input->setOption('model', true);
            $this->input->setOption('migration', true);
            $this->input->setOption('policy', true);
            $this->input->setOption('views', $isWeb);
        }

        if ($isApi && $this->option('views')) {
            $this->error('Views can only be generated for WEB controllers.');
            return self::FAILURE;
        }

        /* ===========================
         | Derived naming (Laravel conventions)
         =========================== */
        $modelClass = $name;
        $modelVar = Str::camel($name);
        $modelVarPlural = Str::camel(Str::pluralStudly($name));
        $table = Str::snake(Str::pluralStudly($name));
        $uri = Str::kebab(Str::pluralStudly($name));
        $viewFolder = Str::snake(Str::pluralStudly($name));

        // Keep routeName == uri to match Route::resource naming (especially multi-word resources).
        $routeName = $uri;

        $force = (bool) $this->option('force');

        $generateRoutes = (bool) $this->option('routes');
        $generateRequest = (bool) $this->option('request');
        $generateModel = (bool) $this->option('model');
        $generateMigration = (bool) $this->option('migration');
        $generatePolicy = (bool) $this->option('policy');
        $generateViews = (bool) $this->option('views');

        /* ===========================
         | Dynamic soft-delete blocks (active vs commented)
         =========================== */
        [$softModelImport, $softModelUse] = $this->softModelBlocks($soft);
        $softMigrationColumn = $soft
            ? "            \$table->softDeletes();\n"
            : "            // \$table->softDeletes(); // Uncomment to enable soft deletes\n";

        $softPolicyMethods = $soft
            ? $this->policySoftMethodsActive($modelClass, $modelVar)
            : $this->policySoftMethodsCommented($modelClass, $modelVar);

        $softTraitMethods = $soft
            ? $this->softTraitMethodsActive()
            : $this->softTraitMethodsCommented();

        $softControllerMethodsWeb = $soft
            ? $this->softControllerMethodsWebActive()
            : $this->softControllerMethodsWebCommented();

        $softControllerMethodsApi = $soft
            ? $this->softControllerMethodsApiActive()
            : $this->softControllerMethodsApiCommented();

        /* ===========================
         | 1) Ensure shared trait exists (prompt to replace unless --force)
         =========================== */
        $this->ensureSharedTrait($force, $softTraitMethods);

        /* ===========================
         | 2) Generate controller (always)
         | NOTE: No authorizeResource() and no middleware() usage here,
         | to avoid framework-structure assumptions.
         =========================== */
        $this->generateController(
            isWeb: $isWeb,
            generateRequest: $generateRequest,
            modelClass: $modelClass,
            modelVar: $modelVar,
            modelVarPlural: $modelVarPlural,
            table: $table,
            viewFolder: $viewFolder,
            routeName: $routeName,
            force: $force,
            softControllerMethodsWeb: $softControllerMethodsWeb,
            softControllerMethodsApi: $softControllerMethodsApi
        );

        /* ===========================
         | 3) Optional: Request
         =========================== */
        if ($generateRequest) {
            $this->generateFromStub(
                stub: $this->stubPath('requests/request.stub'),
                target: app_path("Http/Requests/{$modelClass}Request.php"),
                replacements: [
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{TABLE}}'       => $table,
                ],
                force: $force
            );
        }

        /* ===========================
         | 4) Optional: Model
         =========================== */
        if ($generateModel) {
            $this->generateFromStub(
                stub: $this->stubPath('models/model.stub'),
                target: app_path("Models/{$modelClass}.php"),
                replacements: [
                    '{{MODEL_CLASS}}'       => $modelClass,
                    '{{SOFT_MODEL_IMPORT}}' => $softModelImport,
                    '{{SOFT_MODEL_USE}}'    => $softModelUse,
                ],
                force: $force
            );
        }

        /* ===========================
         | 5) Optional: Migration
         | IMPORTANT: One migration per table.
         | - If one exists, prompt replace (unless --force).
         | - If none exists, create new timestamped migration.
         =========================== */
        if ($generateMigration) {
            $existing = $this->findExistingCreateTableMigration($table);

            if ($existing) {
                $target = $existing;

                if (!$force) {
                    $replace = $this->confirm(
                        "Migration already exists for [{$table}]. Replace it?\n{$existing}",
                        false
                    );

                    if (!$replace) {
                        $this->warn("Skipped migration for [{$table}].");
                        goto afterMigration;
                    }
                }

                $this->generateFromStub(
                    stub: $this->stubPath('migrations/create_table.stub'),
                    target: $target,
                    replacements: [
                        '{{TABLE}}'       => $table,
                        '{{SOFT_COLUMN}}' => $softMigrationColumn,
                    ],
                    force: true // already handled prompt above
                );
            } else {
                $timestamp = now()->format('Y_m_d_His');
                $filename = "{$timestamp}_create_{$table}_table.php";
                $target = database_path("migrations/{$filename}");

                $this->generateFromStub(
                    stub: $this->stubPath('migrations/create_table.stub'),
                    target: $target,
                    replacements: [
                        '{{TABLE}}'       => $table,
                        '{{SOFT_COLUMN}}' => $softMigrationColumn,
                    ],
                    force: $force
                );
            }

            afterMigration:
        }

        /* ===========================
         | 6) Optional: Policy
         =========================== */
        if ($generatePolicy) {
            $this->generateFromStub(
                stub: $this->stubPath('policies/policy.stub'),
                target: app_path("Policies/{$modelClass}Policy.php"),
                replacements: [
                    '{{MODEL_CLASS}}'         => $modelClass,
                    '{{MODEL_VAR}}'           => $modelVar,
                    '{{SOFT_POLICY_METHODS}}' => $softPolicyMethods,
                ],
                force: $force
            );
        }

        /* ===========================
         | 7) Optional: Views (web only)
         =========================== */
        if ($generateViews && $isWeb) {
            $viewsDir = resource_path("views/{$viewFolder}");
            $this->ensureDir($viewsDir);

            $deletedButton = $soft
                ? "      <a href=\"{{ route('{$routeName}.deleted') }}\" class=\"btn btn-outline-danger\">Deleted</a>\n"
                : "      {{-- Soft Deletes disabled: uncomment after enabling routes --}}\n      {{-- <a href=\"{{ route('{$routeName}.deleted') }}\" class=\"btn btn-outline-danger\">Deleted</a> --}}\n";

            $bulkBlock = $this->bulkDeleteBlockActive($routeName, $modelVarPlural);

            // index
            $this->generateFromStub(
                stub: $this->stubPath('views/index.stub'),
                target: "{$viewsDir}/index.blade.php",
                replacements: [
                    '{{MODEL_CLASS}}'       => $modelClass,
                    '{{MODEL_VAR_PLURAL}}'  => $modelVarPlural,
                    '{{ROUTE_NAME}}'        => $routeName,
                    '{{DELETED_BUTTON}}'    => $deletedButton,
                    '{{BULK_DELETE_BLOCK}}' => $bulkBlock,
                ],
                force: $force
            );

            // create/edit/show/_form
            $this->generateFromStub(
                stub: $this->stubPath('views/create.stub'),
                target: "{$viewsDir}/create.blade.php",
                replacements: [
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                    '{{VIEW_FOLDER}}' => $viewFolder,
                ],
                force: $force
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/edit.stub'),
                target: "{$viewsDir}/edit.blade.php",
                replacements: [
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                    '{{VIEW_FOLDER}}' => $viewFolder,
                ],
                force: $force
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/show.stub'),
                target: "{$viewsDir}/show.blade.php",
                replacements: [
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                ],
                force: $force
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/_form.stub'),
                target: "{$viewsDir}/_form.blade.php",
                replacements: [
                    '{{MODEL_VAR}}' => $modelVar,
                ],
                force: $force
            );

            // deleted view only generated when soft-deletes enabled (active)
            if ($soft) {
                $this->generateFromStub(
                    stub: $this->stubPath('views/deleted.stub'),
                    target: "{$viewsDir}/deleted.blade.php",
                    replacements: [
                        '{{MODEL_CLASS}}' => $modelClass,
                        '{{ROUTE_NAME}}'  => $routeName,
                    ],
                    force: $force
                );
            }
        }

        /* ===========================
         | 8) Optional: Routes
         =========================== */
        if ($generateRoutes) {
            $this->appendRoutes(
                name: $name,
                isWeb: $isWeb,
                soft: $soft,
                uri: $uri,
                routeName: $routeName,
                modelClass: $modelClass,
                force: $force
            );
        }

        $this->info("Done. CRUD resource generated for [{$name}].");
        return self::SUCCESS;
    }

    /* ============================================================
     | Trait generation (dynamic soft methods inserted into stub)
     ============================================================ */
    protected function ensureSharedTrait(bool $force, string $softTraitMethods): void
    {
        $target = app_path('Http/Controllers/Concerns/HandlesDeletes.php');

        $this->generateFromStub(
            stub: $this->stubPath('traits/HandlesDeletes.stub'),
            target: $target,
            replacements: [
                '{{SOFT_TRAIT_METHODS}}' => $softTraitMethods,
            ],
            force: $force
        );
    }

    /* ============================================================
     | Controller generation
     ============================================================ */
    protected function generateController(
        bool $isWeb,
        bool $generateRequest,
        string $modelClass,
        string $modelVar,
        string $modelVarPlural,
        string $table,
        string $viewFolder,
        string $routeName,
        bool $force,
        string $softControllerMethodsWeb,
        string $softControllerMethodsApi
    ): void {
        $stub = $isWeb ? 'controllers/web.controller.stub' : 'controllers/api.controller.stub';

        $requestImport = "use Illuminate\\Http\\Request;\n";
        $requestTypehint = 'Request';
        $requestData = '$request->all()';

        if ($generateRequest) {
            $requestImport = "use App\\Http\\Requests\\{$modelClass}Request;\n";
            $requestTypehint = "{$modelClass}Request";
            $requestData = '$request->validated()';
        }

        $target = $isWeb
            ? app_path("Http/Controllers/{$modelClass}Controller.php")
            : app_path("Http/Controllers/Api/{$modelClass}Controller.php");

        $this->generateFromStub(
            stub: $this->stubPath($stub),
            target: $target,
            replacements: [
                '{{MODEL_CLASS}}'             => $modelClass,
                '{{MODEL_VAR}}'               => $modelVar,
                '{{MODEL_VAR_PLURAL}}'        => $modelVarPlural,
                '{{TABLE}}'                   => $table,
                '{{VIEW_FOLDER}}'             => $viewFolder,
                '{{ROUTE_NAME}}'              => $routeName,
                '{{REQUEST_IMPORT}}'          => $requestImport,
                '{{REQUEST_TYPEHINT}}'        => $requestTypehint,
                '{{REQUEST_CLASS}}'           => $requestTypehint,
                '{{REQUEST_DATA}}'            => $requestData,
                '{{SOFT_CONTROLLER_METHODS_WEB}}' => $softControllerMethodsWeb,
                '{{SOFT_CONTROLLER_METHODS_API}}' => $softControllerMethodsApi,
            ],
            force: $force
        );
    }

    /* ============================================================
     | Routes appending (order avoids ambiguity)
     ============================================================ */
    protected function appendRoutes(
        string $name,
        bool $isWeb,
        bool $soft,
        string $uri,
        string $routeName,
        string $modelClass,
        bool $force
    ): void {
        $routesPath = $isWeb ? base_path('routes/web.php') : base_path('routes/api.php');

        $controllerFqn = $isWeb
            ? "\\App\\Http\\Controllers\\{$modelClass}Controller::class"
            : "\\App\\Http\\Controllers\\Api\\{$modelClass}Controller::class";

        $lines = [];

        // Bulk delete always exists
        if ($isWeb) {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk'])->name('{$routeName}.destroyBulk');";
        } else {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk']);";
        }

        $lines[] = "";

        // Soft routes: active OR commented depending on choice
        $softRoutes = [];

        if ($isWeb) {
            $softRoutes[] = "Route::get('{$uri}/deleted', [{$controllerFqn}, 'deleted'])->name('{$routeName}.deleted');";
            $softRoutes[] = "Route::post('{$uri}/{id}/restore', [{$controllerFqn}, 'restore'])->name('{$routeName}.restore');";
            $softRoutes[] = "Route::post('{$uri}/restore-bulk', [{$controllerFqn}, 'restoreBulk'])->name('{$routeName}.restoreBulk');";
            $softRoutes[] = "Route::delete('{$uri}/{id}/force', [{$controllerFqn}, 'forceDelete'])->name('{$routeName}.forceDelete');";
            $softRoutes[] = "Route::delete('{$uri}/force-bulk', [{$controllerFqn}, 'forceDeleteBulk'])->name('{$routeName}.forceDeleteBulk');";
        } else {
            $softRoutes[] = "Route::get('{$uri}/deleted', [{$controllerFqn}, 'deleted']);";
            $softRoutes[] = "Route::post('{$uri}/{id}/restore', [{$controllerFqn}, 'restore']);";
            $softRoutes[] = "Route::post('{$uri}/restore-bulk', [{$controllerFqn}, 'restoreBulk']);";
            $softRoutes[] = "Route::delete('{$uri}/{id}/force', [{$controllerFqn}, 'forceDelete']);";
            $softRoutes[] = "Route::delete('{$uri}/force-bulk', [{$controllerFqn}, 'forceDeleteBulk']);";
        }

        if ($soft) {
            foreach ($softRoutes as $r) {
                $lines[] = $r;
            }
        } else {
            $lines[] = "// Soft Deletes disabled: uncomment the routes below after enabling SoftDeletes";
            foreach ($softRoutes as $r) {
                $lines[] = "// " . $r;
            }
        }

        $lines[] = "";

        // Resource route LAST (prevents /deleted or /bulk from being treated as {id})
        $lines[] = $isWeb
            ? "Route::resource('{$uri}', {$controllerFqn});"
            : "Route::apiResource('{$uri}', {$controllerFqn});";

        $snippet = implode("\n", $lines);
        $this->upsertRoutesBlock($routesPath, $name, $snippet, $force);

        $this->info("Routes updated in: " . ($isWeb ? 'routes/web.php' : 'routes/api.php'));
    }

    protected function upsertRoutesBlock(string $routesPath, string $blockId, string $snippet, bool $force): void
    {
        $start = "// CRUDPACK:{$blockId}:START";
        $end   = "// CRUDPACK:{$blockId}:END";

        $block = $start . "\n" . $snippet . "\n" . $end . "\n";

        $contents = $this->files->exists($routesPath)
            ? $this->files->get($routesPath)
            : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n";

        $contents = $this->ensureRouteFacadeImported($contents);

        $hasBlock = str_contains($contents, $start) && str_contains($contents, $end);

        if ($hasBlock && !$force) {
            $replace = $this->confirm("Routes block already exists for [{$blockId}]. Replace it?", false);
            if (!$replace) {
                $this->warn("Skipped routes block for [{$blockId}].");
                return;
            }
        }

        if ($hasBlock) {
            $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '\s*/s';
            $contents = preg_replace($pattern, $block, $contents);
        } else {
            $contents .= "\n" . $block;
        }

        $this->files->put($routesPath, $contents);
    }

    protected function ensureRouteFacadeImported(string $contents): string
    {
        if (str_contains($contents, 'use Illuminate\\Support\\Facades\\Route;')) {
            return $contents;
        }

        if (preg_match('/^<\?php\s*/', $contents)) {
            return preg_replace(
                '/^<\?php\s*/',
                "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n",
                $contents,
                1
            );
        }

        return "use Illuminate\\Support\\Facades\\Route;\n\n" . $contents;
    }

    /* ============================================================
     | Policy blocks
     ============================================================ */
    protected function policySoftMethodsActive(string $modelClass, string $modelVar): string
    {
        return <<<PHP
    public function restore(User \$user, {$modelClass} \${$modelVar}): bool
    {
        return true;
    }

    public function forceDelete(User \$user, {$modelClass} \${$modelVar}): bool
    {
        return true;
    }

PHP;
    }

    protected function policySoftMethodsCommented(string $modelClass, string $modelVar): string
    {
        $code = $this->policySoftMethodsActive($modelClass, $modelVar);
        $lines = explode("\n", rtrim($code, "\n"));
        $out = [];
        $out[] = "    // Soft Deletes disabled: uncomment after enabling SoftDeletes";
        foreach ($lines as $line) {
            $out[] = $line === '' ? "" : "    // " . ltrim($line);
        }
        $out[] = "";
        return implode("\n", $out);
    }

    /* ============================================================
     | Soft blocks: model / trait / controller
     ============================================================ */
    protected function softModelBlocks(bool $soft): array
    {
        if ($soft) {
            return [
                "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n",
                "    use SoftDeletes;\n",
            ];
        }

        return [
            "// use Illuminate\\Database\\Eloquent\\SoftDeletes;\n",
            "    // use SoftDeletes;\n",
        ];
    }

    protected function softTraitMethodsActive(): string
    {
        // inserted into HandlesDeletes.stub at {{SOFT_TRAIT_METHODS}}
        return <<<PHP
    /**
     * List soft-deleted records (explicit route) — soft deletes only.
     */
    public function performDeleted()
    {
        \$items = \$this->modelClass::onlyTrashed()->paginate(15);

        if (request()->expectsJson()) {
            return response()->json(\$items);
        }

        return view(\$this->viewFolder . '.deleted', compact('items'));
    }

    /**
     * Restore single (explicit route) — soft deletes only.
     */
    public function performRestore(int|string \$id)
    {
        \$model = \$this->modelClass::onlyTrashed()->findOrFail(\$id);
        \$model->restore();

        return \$this->deleteResponse('Restored successfully.');
    }

    /**
     * Restore bulk (explicit route) — soft deletes only.
     */
    public function performRestoreBulk(Request \$request)
    {
        \$ids = \$this->extractIds(\$request);

        if (empty(\$ids)) {
            return \$this->deleteResponse('No records selected.');
        }

        \$this->modelClass::onlyTrashed()->whereKey(\$ids)->restore();

        return \$this->deleteResponse('Selected records restored.');
    }

    /**
     * Force delete single (explicit route) — soft deletes only.
     */
    public function performForceDelete(int|string \$id)
    {
        \$model = \$this->modelClass::onlyTrashed()->findOrFail(\$id);
        \$model->forceDelete();

        return \$this->deleteResponse('Permanently deleted.');
    }

    /**
     * Force delete bulk (explicit route) — soft deletes only.
     */
    public function performForceDeleteBulk(Request \$request)
    {
        \$ids = \$this->extractIds(\$request);

        if (empty(\$ids)) {
            return \$this->deleteResponse('No records selected.');
        }

        \$this->modelClass::onlyTrashed()->whereKey(\$ids)->forceDelete();

        return \$this->deleteResponse('Selected records permanently deleted.');
    }
PHP;
    }

    protected function softTraitMethodsCommented(): string
    {
        $active = $this->softTraitMethodsActive();
        $lines = explode("\n", rtrim($active, "\n"));
        $out = [];
        $out[] = "    // Soft Deletes disabled: uncomment after enabling SoftDeletes";
        foreach ($lines as $line) {
            $out[] = $line === '' ? "" : "    // " . ltrim($line);
        }
        $out[] = "";
        return implode("\n", $out);
    }

    protected function softControllerMethodsWebActive(): string
    {
        return <<<PHP
    public function deleted()
    {
        return \$this->performDeleted();
    }

    public function restore(int|string \$id)
    {
        return \$this->performRestore(\$id);
    }

    public function restoreBulk(\\Illuminate\\Http\\Request \$request)
    {
        return \$this->performRestoreBulk(\$request);
    }

    public function forceDelete(int|string \$id)
    {
        return \$this->performForceDelete(\$id);
    }

    public function forceDeleteBulk(\\Illuminate\\Http\\Request \$request)
    {
        return \$this->performForceDeleteBulk(\$request);
    }

PHP;
    }

    protected function softControllerMethodsWebCommented(): string
    {
        $active = $this->softControllerMethodsWebActive();
        $lines = explode("\n", rtrim($active, "\n"));
        $out = [];
        $out[] = "    // Soft Deletes disabled: uncomment after enabling SoftDeletes";
        foreach ($lines as $line) {
            $out[] = $line === '' ? "" : "    // " . ltrim($line);
        }
        $out[] = "";
        return implode("\n", $out);
    }

    protected function softControllerMethodsApiActive(): string
    {
        // same signatures for api controller
        return $this->softControllerMethodsWebActive();
    }

    protected function softControllerMethodsApiCommented(): string
    {
        return $this->softControllerMethodsWebCommented();
    }

    /* ============================================================
     | Views: bulk delete block (stable working version)
     ============================================================ */
    protected function bulkDeleteBlockActive(string $routeName, string $modelVarPlural): string
    {
        return <<<BLADE
{{-- Bulk Delete Toolbar (NO table wrapper form; avoids nested form bug) --}}
<form id="bulkDeleteForm" method="POST" action="{{ route('{$routeName}.destroyBulk') }}" class="mb-3">
  @csrf
  @method('DELETE')

  <input type="hidden" name="ids" id="bulkIds" value="">

  <div class="card">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="selectAll">
        <label class="form-check-label" for="selectAll">Select All</label>
      </div>

      <button type="submit" class="btn btn-outline-danger" id="bulkDeleteBtn" disabled
        onclick="return confirm('Delete selected records?')">
        Delete Selected
      </button>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-striped table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th style="width:50px;"></th>
          <th style="width:90px;">ID</th>
          <th>Name</th>
          <th style="width:260px;" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        @forelse(\${$modelVarPlural} as \$item)
          <tr>
            <td>
              <input class="form-check-input row-check" type="checkbox" value="{{ \$item->id }}">
            </td>
            <td>{{ \$item->id }}</td>
            <td>{{ \$item->name ?? '-' }}</td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-info" href="{{ route('{$routeName}.show', \$item) }}">Show</a>
              <a class="btn btn-sm btn-outline-warning" href="{{ route('{$routeName}.edit', \$item) }}">Edit</a>

              <form method="POST" action="{{ route('{$routeName}.destroy', \$item) }}" class="d-inline">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"
                  onclick="return confirm('Delete?')">Delete</button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="4" class="text-center text-muted py-4">No records found.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
  const selectAll = document.getElementById('selectAll');
  const checks = Array.from(document.querySelectorAll('.row-check'));
  const bulkBtn = document.getElementById('bulkDeleteBtn');
  const bulkIds = document.getElementById('bulkIds');
  const bulkForm = document.getElementById('bulkDeleteForm');

  function selectedIds() {
    return checks.filter(c => c.checked).map(c => c.value);
  }

  function syncState() {
    const ids = selectedIds();
    bulkBtn.disabled = ids.length === 0;

    const allChecked = checks.length > 0 && ids.length === checks.length;
    selectAll.checked = allChecked;
    selectAll.indeterminate = ids.length > 0 && !allChecked;
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checks.forEach(c => c.checked = selectAll.checked);
      syncState();
    });
  }

  checks.forEach(c => c.addEventListener('change', syncState));

  if (bulkForm) {
    bulkForm.addEventListener('submit', function () {
      bulkIds.value = selectedIds().join(',');
    });
  }

  syncState();
})();
</script>
BLADE;
    }

    /* ============================================================
     | Migration uniqueness helper
     ============================================================ */
    protected function findExistingCreateTableMigration(string $table): ?string
    {
        $dir = database_path('migrations');
        if (!$this->files->isDirectory($dir)) {
            return null;
        }

        $files = $this->files->files($dir);
        $needle = "_create_{$table}_table.php";

        foreach ($files as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, $needle)) {
                return $path;
            }
        }

        return null;
    }

    /* ============================================================
     | Stub utilities
     ============================================================ */
    protected function stubPath(string $relative): string
    {
        return __DIR__ . '/../../stubs/' . $relative;
    }

    /**
     * File creation behavior:
     * - If file does NOT exist => create.
     * - If exists and --force => overwrite (no prompt).
     * - If exists and NO --force => prompt replace.
     */
    protected function generateFromStub(string $stub, string $target, array $replacements, bool $force): void
    {
        if (!$this->files->exists($stub)) {
            $this->error("Stub not found: {$stub}");
            return;
        }

        $exists = $this->files->exists($target);

        if ($exists && !$force) {
            $replace = $this->confirm("File exists: {$target}\nReplace it?", false);
            if (!$replace) {
                $this->warn("Skipped: {$target}");
                return;
            }
        }

        $this->ensureDir(dirname($target));

        $content = $this->files->get($stub);
        foreach ($replacements as $k => $v) {
            $content = str_replace($k, $v, $content);
        }

        $this->files->put($target, $content);
        $this->info(($exists ? 'Updated: ' : 'Created: ') . $target);
    }

    protected function ensureDir(string $dir): void
    {
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }
    }

    protected function optionWasProvided(string $longOption): bool
    {
        return $this->input->hasParameterOption("--{$longOption}");
    }

    protected function anyGeneratorOptionWasProvided(): bool
    {
        return $this->optionWasProvided('routes')
            || $this->optionWasProvided('request')
            || $this->optionWasProvided('model')
            || $this->optionWasProvided('migration')
            || $this->optionWasProvided('policy')
            || $this->optionWasProvided('views');
    }
}
