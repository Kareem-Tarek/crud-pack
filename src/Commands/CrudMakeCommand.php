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
        {--force : Overwrite existing files/blocks}
    ';

    protected $description = 'Generate CRUD resource scaffolding (controllers, requests, models, migrations, policies, views, routes).';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));

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

        // Wizard mode: if no --all and no generator options were provided
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

        // Dynamic --all behavior
        if ($allProvided) {
            $this->input->setOption('routes', true);
            $this->input->setOption('request', true);
            $this->input->setOption('model', true);
            $this->input->setOption('migration', true);
            $this->input->setOption('policy', true);
            $this->input->setOption('views', $isWeb); // never for api
        }

        // Invalid combinations
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

        // URI is kebab-case plural (best practice)
        $uri = Str::kebab(Str::pluralStudly($name));

        // Views folder can be snake_case plural
        $viewFolder = Str::snake(Str::pluralStudly($name));

        /**
         * IMPORTANT:
         * Route::resource() route names are derived from the URI by default.
         * For multi-word resources, URI becomes "product-categories", so route names are "product-categories.index".
         * Therefore, routeName must match URI to avoid route-name mismatches in generated code.
         */
        $routeName = $uri;

        $force = (bool) $this->option('force');

        $generateRoutes = (bool) $this->option('routes');
        $generateRequest = (bool) $this->option('request');
        $generateModel = (bool) $this->option('model');
        $generateMigration = (bool) $this->option('migration');
        $generatePolicy = (bool) $this->option('policy');
        $generateViews = (bool) $this->option('views');

        /* ===========================
         | 1) Ensure shared trait exists
         =========================== */
        $this->ensureSharedTrait($force);

        /* ===========================
         | 2) Generate controller (always)
         =========================== */
        $this->generateController(
            isWeb: $isWeb,
            generateRequest: $generateRequest,
            generatePolicy: $generatePolicy,
            modelClass: $modelClass,
            modelVar: $modelVar,
            modelVarPlural: $modelVarPlural,
            table: $table,
            viewFolder: $viewFolder,
            routeName: $routeName,
            force: $force
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
            $softImport = $soft
                ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n"
                : "// use Illuminate\\Database\\Eloquent\\SoftDeletes;\n";

            $softUse = $soft
                ? "    use SoftDeletes;\n"
                : "    // use SoftDeletes;\n";

            $this->generateFromStub(
                stub: $this->stubPath('models/model.stub'),
                target: app_path("Models/{$modelClass}.php"),
                replacements: [
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{SOFT_IMPORT}}' => $softImport,
                    '{{SOFT_USE}}'    => $softUse,
                ],
                force: $force
            );
        }

        /* ===========================
         | 5) Optional: Migration
         =========================== */
        if ($generateMigration) {
            $timestamp = now()->format('Y_m_d_His');
            $filename = "{$timestamp}_create_{$table}_table.php";
            $target = database_path("migrations/{$filename}");

            $softColumn = $soft
                ? "            \$table->softDeletes();\n"
                : "            // \$table->softDeletes(); // Uncomment to enable soft deletes\n";

            $this->generateFromStub(
                stub: $this->stubPath('migrations/create_table.stub'),
                target: $target,
                replacements: [
                    '{{TABLE}}'       => $table,
                    '{{SOFT_COLUMN}}' => $softColumn,
                ],
                force: $force
            );
        }

        /* ===========================
         | 6) Optional: Policy
         =========================== */
        if ($generatePolicy) {
            $softPolicy = $soft
                ? $this->policySoftMethodsActive($modelClass, $modelVar)
                : $this->policySoftMethodsCommented($modelClass, $modelVar);

            $this->generateFromStub(
                stub: $this->stubPath('policies/policy.stub'),
                target: app_path("Policies/{$modelClass}Policy.php"),
                replacements: [
                    '{{MODEL_CLASS}}'         => $modelClass,
                    '{{MODEL_VAR}}'           => $modelVar,
                    '{{SOFT_POLICY_METHODS}}' => $softPolicy,
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

            // Deleted button: only active if soft-deletes; else blueprint comment
            $deletedButton = $soft
                ? "      <a href=\"{{ route('{$routeName}.deleted') }}\" class=\"btn btn-outline-danger\">Deleted</a>\n"
                : "      {{-- Soft Deletes disabled: uncomment after enabling routes --}}\n      {{-- <a href=\"{{ route('{$routeName}.deleted') }}\" class=\"btn btn-outline-danger\">Deleted</a> --}}\n";

            // Bulk delete/table block should always be generated (works with or without soft deletes)
            $bulkBlock = $this->bulkDeleteBlockActive($routeName, $modelVarPlural);

            // index
            $this->generateFromStub(
                stub: $this->stubPath('views/index.stub'),
                target: "{$viewsDir}/index.blade.php",
                replacements: [
                    '{{MODEL_CLASS}}'        => $modelClass,
                    '{{MODEL_VAR_PLURAL}}'   => $modelVarPlural,
                    '{{ROUTE_NAME}}'         => $routeName,
                    '{{DELETED_BUTTON}}'     => $deletedButton,
                    '{{BULK_DELETE_BLOCK}}'  => $bulkBlock,
                ],
                force: $force
            );

            // create
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

            // edit
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

            // show
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

            // _form
            $this->generateFromStub(
                stub: $this->stubPath('views/_form.stub'),
                target: "{$viewsDir}/_form.blade.php",
                replacements: [
                    '{{MODEL_VAR}}' => $modelVar,
                ],
                force: $force
            );

            // deleted view only generated when soft-deletes enabled
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

    /* ===========================
     | Create shared trait once per app
     =========================== */
    protected function ensureSharedTrait(bool $force): void
    {
        $target = app_path('Http/Controllers/Concerns/HandlesDeletes.php');

        if ($this->files->exists($target) && !$force) {
            $this->info('HandlesDeletes trait already exists — skipped.');
            return;
        }

        $this->generateFromStub(
            stub: $this->stubPath('traits/HandlesDeletes.stub'),
            target: $target,
            replacements: [],
            force: true
        );
    }

    /* ===========================
     | Controller Generation
     =========================== */
    protected function generateController(
        bool $isWeb,
        bool $generateRequest,
        bool $generatePolicy,
        string $modelClass,
        string $modelVar,
        string $modelVarPlural,
        string $table,
        string $viewFolder,
        string $routeName,
        bool $force
    ): void {
        $stub = $isWeb ? 'controllers/web.controller.stub' : 'controllers/api.controller.stub';

        // If no FormRequest is generated, we MUST import Illuminate\Http\Request
        $requestImport = "use Illuminate\\Http\\Request;\n";
        $requestTypehint = 'Request';
        $requestData = '$request->all()';

        if ($generateRequest) {
            $requestImport = "use App\\Http\\Requests\\{$modelClass}Request;\n";
            $requestTypehint = "{$modelClass}Request";
            $requestData = '$request->validated()';
        }

        /**
         * POLICY FIX (Laravel 11/12):
         * authorizeResource() comes from AuthorizesRequests trait.
         * If policy is enabled, we import + use it.
         */
        $authImport = '';
        $classTraits = 'use HandlesDeletes;';
        $constructor = '';

        if ($generatePolicy) {
            $authImport = "use Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests;\n";
            $classTraits = "use AuthorizesRequests, HandlesDeletes;";

            $constructor = <<<PHP

    public function __construct()
    {
        \$this->authorizeResource({$modelClass}::class, '{$modelVar}');
    }

PHP;
        }

        $target = $isWeb
            ? app_path("Http/Controllers/{$modelClass}Controller.php")
            : app_path("Http/Controllers/Api/{$modelClass}Controller.php");

        $this->generateFromStub(
            stub: $this->stubPath($stub),
            target: $target,
            replacements: [
                '{{MODEL_CLASS}}'       => $modelClass,
                '{{MODEL_VAR}}'         => $modelVar,
                '{{MODEL_VAR_PLURAL}}'  => $modelVarPlural,
                '{{TABLE}}'             => $table,
                '{{VIEW_FOLDER}}'       => $viewFolder,
                '{{ROUTE_NAME}}'        => $routeName,

                // Request placeholders
                '{{REQUEST_IMPORT}}'    => $requestImport,
                '{{REQUEST_TYPEHINT}}'  => $requestTypehint,
                '{{REQUEST_CLASS}}'     => $requestTypehint,
                '{{REQUEST_DATA}}'      => $requestData,

                // Policy placeholders (Laravel 11/12 fix)
                '{{AUTH_IMPORT}}'       => $authImport,
                '{{CLASS_TRAITS}}'      => $classTraits,
                '{{CONSTRUCTOR}}'       => $constructor,
            ],
            force: $force
        );
    }

    /* ===========================
     | Routes Appending
     =========================== */
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

        // IMPORTANT: custom routes must be defined BEFORE resource routes to avoid conflicts
        $lines = [];

        // Always include bulk delete (works with and without soft deletes)
        if ($isWeb) {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk'])->name('{$routeName}.destroyBulk');";
        } else {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk']);";
        }

        $lines[] = "";

        // Soft-delete related routes: enabled only when soft-deletes is chosen
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

        // Main resource route LAST (prevents '/deleted' and '/bulk' from being captured as {id})
        $main = $isWeb
            ? "Route::resource('{$uri}', {$controllerFqn});"
            : "Route::apiResource('{$uri}', {$controllerFqn});";

        $lines[] = $main;

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
            $this->warn("Routes block already exists for [{$blockId}]. Use --force to overwrite it.");
            return;
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

        // Insert right after the opening php tag
        if (preg_match('/^<\?php\s*/', $contents)) {
            return preg_replace(
                '/^<\?php\s*/',
                "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n",
                $contents,
                1
            );
        }

        // Fallback
        return "use Illuminate\\Support\\Facades\\Route;\n\n" . $contents;
    }

    /* ===========================
     | Policy helper blocks
     =========================== */
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

    /* ===========================
     | View blocks for bulk delete
     =========================== */
    protected function bulkDeleteBlockActive(string $routeName, string $modelVarPlural): string
    {
        return <<<BLADE
  <form id="bulkDeleteForm" method="POST" action="{{ route('{$routeName}.destroyBulk') }}">
    @csrf
    @method('DELETE')

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
                  <input class="form-check-input row-check" type="checkbox" name="ids[]" value="{{ \$item->id }}">
                </td>
                <td>{{ \$item->id }}</td>
                <td>{{ \$item->name ?? '-' }}</td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-info" href="{{ route('{$routeName}.show', \$item) }}">Show</a>
                  <a class="btn btn-sm btn-outline-warning" href="{{ route('{$routeName}.edit', \$item) }}">Edit</a>

                  <form method="POST" action="{{ route('{$routeName}.destroy', \$item) }}" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete?')">Delete</button>
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
  </form>

  <script>
    (function () {
      const selectAll = document.getElementById('selectAll');
      const checks = Array.from(document.querySelectorAll('.row-check'));
      const bulkBtn = document.getElementById('bulkDeleteBtn');

      function syncState() {
        const anyChecked = checks.some(c => c.checked);
        bulkBtn.disabled = !anyChecked;

        const allChecked = checks.length > 0 && checks.every(c => c.checked);
        selectAll.checked = allChecked;
        selectAll.indeterminate = anyChecked && !allChecked;
      }

      if (selectAll) {
        selectAll.addEventListener('change', function () {
          checks.forEach(c => c.checked = selectAll.checked);
          syncState();
        });
      }

      checks.forEach(c => c.addEventListener('change', syncState));
      syncState();
    })();
  </script>
BLADE;
    }

    /* ===========================
     | Stub utilities
     =========================== */
    protected function stubPath(string $relative): string
    {
        return __DIR__ . '/../../stubs/' . $relative;
    }

    protected function generateFromStub(string $stub, string $target, array $replacements, bool $force): void
    {
        if (!$this->files->exists($stub)) {
            $this->error("Stub not found: {$stub}");
            return;
        }

        if ($this->files->exists($target) && !$force) {
            $this->warn("Skipped (exists): {$target}  (use --force to overwrite)");
            return;
        }

        $this->ensureDir(dirname($target));

        $content = $this->files->get($stub);

        foreach ($replacements as $k => $v) {
            $content = str_replace($k, $v, $content);
        }

        $this->files->put($target, $content);
        $this->info("Created: {$target}");
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
