<?php

// ============================================================================
// FILE: KareemTarek/CrudPack/Commands/CrudMakeCommand.php
// UPDATED:
// - Blade stubs now behave dynamically based on policy style (none vs enabled):
//     * If policy-style = none => NO @can blocks (everything visible)
//     * If policy-style != none => UI is gated with @can / @canany
// - bulkDeleteBlockActive() now includes policy gates for:
//     * delete (row)
//     * update (edit link)
//     * deleteBulk (bulk button)
// - Index/Create/Edit/Show/Trash stubs receive blade permission placeholders.
// - Controllers always import Illuminate\Http\Request (needed for bulk/custom endpoints)
// - Controllers get: protected string $policyStyle = '{{POLICY_STYLE}}'
// - Routes point to controller methods WITHOUT "perform" prefix:
//     destroyBulk, trash, restore, restoreBulk, forceDelete, forceDeleteBulk
// - Policy generation:
//     - deleteBulk always present (in stub)
//     - soft-only methods injected when --soft-deletes
// ============================================================================

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
        {--policy-style= : Authorization style: none|authorize|gate|resource}
        {--force : Overwrite existing files/blocks}';

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

        // Wizard mode
        if (!$allProvided && !$anyExplicitGenerators) {
            $this->info('No generation options provided. Answer the following prompts:');

            // Defaults: YES for everything except request/policy (default NO)
            $this->input->setOption('routes', $this->confirm('Append routes automatically?', true));
            $this->input->setOption('model', $this->confirm('Generate Model?', true));
            $this->input->setOption('migration', $this->confirm('Generate Migration?', true));
            $this->input->setOption('request', $this->confirm('Generate Request validation (single FormRequest for store & update)?', false));
            $this->input->setOption('policy', $this->confirm('Generate Policy?', false));

            if ($isWeb) {
                $this->input->setOption('views', $this->confirm('Generate Blade views (Bootstrap 5)?', true));
            } else {
                // API: views not available
                $this->input->setOption('views', false);
            }
        }

        // Dynamic --all
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
         | Derived naming
         =========================== */
        $modelClass = $name;
        $modelVar = Str::camel($name);
        $modelVarPlural = Str::camel(Str::pluralStudly($name));
        $table = Str::snake(Str::pluralStudly($name));
        $uri = Str::kebab(Str::pluralStudly($name));
        $viewFolder = Str::snake(Str::pluralStudly($name));
        $routeName = $uri;

        $force = (bool) $this->option('force');

        $generateRoutes = (bool) $this->option('routes');
        $generateRequest = (bool) $this->option('request');
        $generateModel = (bool) $this->option('model');
        $generateMigration = (bool) $this->option('migration');
        $generatePolicy = (bool) $this->option('policy');
        $generateViews = (bool) $this->option('views');

        /* ============================================================
         | Laravel 11+ API install support
         ============================================================ */
        if ($isApi) {
            $this->ensureApiRoutesInstalled();
        }

        /* ===========================
         | Policy Style
         =========================== */
        $policyStyle = $this->resolvePolicyStyle($generatePolicy);

        /* ===========================
         | 1) Ensure shared trait exists
         =========================== */
        $this->ensureSharedTrait(force: $force);

        /* ===========================
         | 2) Controller (always)
         =========================== */
        $this->generateController(
            isWeb: $isWeb,
            generateRequest: $generateRequest,
            generatePolicy: $generatePolicy,
            policyStyle: $policyStyle,
            modelClass: $modelClass,
            modelVar: $modelVar,
            modelVarPlural: $modelVarPlural,
            table: $table,
            viewFolder: $viewFolder,
            routeName: $routeName,
            soft: $soft,
            force: $force
        );

        /* ===========================
         | 3) Request
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
                force: $force,
                askReplaceIfExists: true
            );
        }

        /* ===========================
         | 4) Model
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
                    '{{MODEL_CLASS}}'       => $modelClass,
                    '{{SOFT_MODEL_IMPORT}}' => $softImport,
                    '{{SOFT_MODEL_USE}}'    => $softUse,
                ],
                force: $force,
                askReplaceIfExists: true
            );
        }

        /* ===========================
         | 5) Migration (UNIQUE)
         =========================== */
        if ($generateMigration) {
            $this->generateUniqueMigration(
                table: $table,
                soft: $soft,
                force: $force
            );
        }

        /* ===========================
         | 6) Policy file
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
                    '{{SOFT_POLICY_METHODS}}' => $softPolicy,
                ],
                force: $force,
                askReplaceIfExists: true
            );
        }

        /* ===========================
         | 7) Views (web only)
         =========================== */
        if ($generateViews && $isWeb) {
            $viewsDir = resource_path("views/{$viewFolder}");
            $this->ensureDir($viewsDir);

            // Trash button is route-driven inside stubs (Route::has()).

            $bladeGuards = $this->bladePermissionReplacements(
                policyStyle: $generatePolicy ? $policyStyle : 'none',
                modelClass: $modelClass,
                modelVar: $modelVar
            );

            $bulkBlock = $this->bulkDeleteBlockActive(
                routeName: $routeName,
                modelVarPlural: $modelVarPlural,
                soft: $soft,
                bladeGuards: $bladeGuards
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/index.stub'),
                target: "{$viewsDir}/index.blade.php",
                replacements: array_merge([
                    '{{MODEL_CLASS}}'       => $modelClass,
                    '{{MODEL_VAR_PLURAL}}'  => $modelVarPlural,
                    '{{ROUTE_NAME}}'        => $routeName,
                    '{{BULK_DELETE_BLOCK}}' => $bulkBlock,
                ], $bladeGuards),
                force: $force,
                askReplaceIfExists: true
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/create.stub'),
                target: "{$viewsDir}/create.blade.php",
                replacements: array_merge([
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                    '{{VIEW_FOLDER}}' => $viewFolder,
                ], $bladeGuards),
                force: $force,
                askReplaceIfExists: true
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/edit.stub'),
                target: "{$viewsDir}/edit.blade.php",
                replacements: array_merge([
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                    '{{VIEW_FOLDER}}' => $viewFolder,
                ], $bladeGuards),
                force: $force,
                askReplaceIfExists: true
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/show.stub'),
                target: "{$viewsDir}/show.blade.php",
                replacements: array_merge([
                    '{{MODEL_CLASS}}' => $modelClass,
                    '{{MODEL_VAR}}'   => $modelVar,
                    '{{ROUTE_NAME}}'  => $routeName,
                ], $bladeGuards),
                force: $force,
                askReplaceIfExists: true
            );

            $this->generateFromStub(
                stub: $this->stubPath('views/_form.stub'),
                target: "{$viewsDir}/_form.blade.php",
                replacements: [
                    '{{MODEL_VAR}}' => $modelVar,
                ],
                force: $force,
                askReplaceIfExists: true
            );

            if ($soft) {
                $this->generateFromStub(
                    stub: $this->stubPath('views/trash.stub'),
                    target: "{$viewsDir}/trash.blade.php",
                    replacements: array_merge([
                        '{{MODEL_CLASS}}' => $modelClass,
                        '{{ROUTE_NAME}}'  => $routeName,
                    ], $bladeGuards),
                    force: $force,
                    askReplaceIfExists: true
                );
            }
        }

        /* ===========================
         | 8) Routes
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

        /* ===========================
         | 9) Postman (API only)
         =========================== */
        if ($isApi) {
            $this->call('crud:postman');
        }

        $this->info("Done. CRUD resource generated for [{$name}].");
        return self::SUCCESS;
    }

    /* ============================================================
     | Ensure Laravel 11+ API scaffold exists (install:api)
     ============================================================ */
    protected function ensureApiRoutesInstalled(): void
    {
        $apiRoutesPath = base_path('routes/api.php');

        if (!$this->files->exists($apiRoutesPath)) {
            $this->info('routes/api.php not found. Running: php artisan install:api');
            $this->runInstallApiCommand();

            if ($this->files->exists($apiRoutesPath)) {
                $this->cleanupBootstrapAppRoutingApiKey();
            } else {
                $this->error('install:api finished but routes/api.php is still missing. API routes will not be appended.');
            }

            return;
        }

        $this->line('routes/api.php already exists. Skipping install:api.');
        return;
    }

    protected function runInstallApiCommand(): void
    {
        try {
            $this->call('install:api');
        } catch (\Throwable $e) {
            $this->error('Failed to run install:api: ' . $e->getMessage());
        }
    }

    protected function cleanupBootstrapAppRoutingApiKey(): void
    {
        $path = base_path('bootstrap/app.php');

        if (!$this->files->exists($path)) {
            return;
        }

        $content = $this->files->get($path);

        if (substr_count($content, "api: __DIR__.'/../routes/api.php'") <= 1) {
            return;
        }

        $lines = preg_split("/\R/", $content);

        $inWithRouting = false;
        $apiSeen = false;
        $out = [];

        foreach ($lines as $line) {
            if (!$inWithRouting && str_contains($line, '->withRouting(')) {
                $inWithRouting = true;
                $apiSeen = false;
                $out[] = $line;
                continue;
            }

            if ($inWithRouting && preg_match('/^\s*\)\s*[,;]?\s*$/', $line)) {
                $inWithRouting = false;
                $out[] = $line;
                continue;
            }

            if ($inWithRouting) {
                $isApiLine = (bool) preg_match(
                    "/^\s*api\s*:\s*__DIR__\s*\/\s*'\.\.\/routes\/api\.php'\s*,?\s*$/",
                    $line
                );

                if (!$isApiLine && str_contains($line, "api: __DIR__.'/../routes/api.php'")) {
                    $isApiLine = true;
                }

                if ($isApiLine) {
                    if ($apiSeen) {
                        continue;
                    }
                    $apiSeen = true;
                    $out[] = $line;
                    continue;
                }
            }

            $out[] = $line;
        }

        $fixed = implode("\n", $out);

        if ($fixed !== $content) {
            $this->files->put($path, $fixed);
            $this->warn("Fixed duplicated 'api:' routing entry in bootstrap/app.php");
        }
    }

    /* ============================================================
     | Policy Style resolution + prompting
     ============================================================ */
    protected function resolvePolicyStyle(bool $generatePolicy): string
    {
        $style = (string) ($this->option('policy-style') ?? '');
        $style = strtolower(trim($style));

        $valid = ['none', 'authorize', 'gate', 'resource'];

        if (!$generatePolicy) {
            return 'none';
        }

        if ($style !== '' && !in_array($style, $valid, true)) {
            $this->warn('Invalid --policy-style. Allowed: none|authorize|gate|resource. Falling back to "none".');
            return 'none';
        }

        if ($style === '') {
            $choice = $this->choice(
                'Policy authorization style?',
                ['none', 'authorize', 'gate', 'resource'],
                0
            );
            return $choice ?: 'none';
        }

        return $style;
    }

    /* ============================================================
     | Trait generation
     ============================================================ */
    protected function ensureSharedTrait(bool $force): void
    {
        $target = app_path('Http/Controllers/Concerns/HandlesDeletes.php');

        if ($this->files->exists($target) && !$force) {
            return;
        }

        $this->generateFromStub(
            stub: $this->stubPath('traits/HandlesDeletes.stub'),
            target: $target,
            replacements: [], // no placeholders
            force: true,
            askReplaceIfExists: false
        );
    }

    /* ============================================================
     | Controller Generation
     ============================================================ */
    protected function generateController(
        bool $isWeb,
        bool $generateRequest,
        bool $generatePolicy,
        string $policyStyle,
        string $modelClass,
        string $modelVar,
        string $modelVarPlural,
        string $table,
        string $viewFolder,
        string $routeName,
        bool $soft,
        bool $force
    ): void {
        $stub = $isWeb ? 'controllers/web.controller.stub' : 'controllers/api.controller.stub';

        // ALWAYS import Illuminate\Http\Request (needed for destroyBulk + other custom endpoints).
        $requestImport = "use Illuminate\\Http\\Request;\n";
        $requestTypehint = 'Request';
        $requestData = '$request->all()';

        if ($generateRequest) {
            $requestImport = "use Illuminate\\Http\\Request;\nuse App\\Http\\Requests\\{$modelClass}Request;\n";
            $requestTypehint = "{$modelClass}Request";
            $requestData = '$request->validated()';
        }

        $authImport = '';
        $classTraits = 'use HandlesDeletes;';
        $constructor = '';

        if ($generatePolicy && $policyStyle === 'resource') {
            $authImport = "use Illuminate\\Foundation\\Auth\\Access\\AuthorizesRequests;\n";
            $classTraits = "use AuthorizesRequests, HandlesDeletes;";

            $constructor = <<<PHP

    public function __construct()
    {
        \$this->authorizeResource({$modelClass}::class, '{$modelVar}');
    }

PHP;
        }

        $authRepl = $this->controllerAuthReplacements(
            policyStyle: $generatePolicy ? $policyStyle : 'none',
            modelClass: $modelClass,
            modelVar: $modelVar
        );

        $target = $isWeb
            ? app_path("Http/Controllers/{$modelClass}Controller.php")
            : app_path("Http/Controllers/Api/{$modelClass}Controller.php");

        $this->generateFromStub(
            stub: $this->stubPath($stub),
            target: $target,
            replacements: array_merge([
                '{{MODEL_CLASS}}'       => $modelClass,
                '{{MODEL_VAR}}'         => $modelVar,
                '{{MODEL_VAR_PLURAL}}'  => $modelVarPlural,
                '{{TABLE}}'             => $table,
                '{{VIEW_FOLDER}}'       => $viewFolder,
                '{{ROUTE_NAME}}'        => $routeName,

                '{{REQUEST_IMPORT}}'    => $requestImport,
                '{{REQUEST_TYPEHINT}}'  => $requestTypehint,
                '{{REQUEST_DATA}}'      => $requestData,

                '{{AUTH_IMPORT}}'       => $authImport,
                '{{CLASS_TRAITS}}'      => $classTraits,
                '{{CONSTRUCTOR}}'       => $constructor,

                // Always set policy style so HandlesDeletes::crudAuthorize() works.
                '{{POLICY_STYLE}}'      => $generatePolicy ? $policyStyle : 'none',
            ], $authRepl),
            force: $force,
            askReplaceIfExists: true
        );
    }

    protected function controllerAuthReplacements(string $policyStyle, string $modelClass, string $modelVar): array
    {
        $empty = [
            '{{AUTH_INDEX}}'   => '',
            '{{AUTH_CREATE}}'  => '',
            '{{AUTH_STORE}}'   => '',
            '{{AUTH_SHOW}}'    => '',
            '{{AUTH_EDIT}}'    => '',
            '{{AUTH_UPDATE}}'  => '',
            '{{AUTH_DESTROY}}' => '',
        ];

        $policyStyle = strtolower(trim($policyStyle));

        if ($policyStyle === 'none' || $policyStyle === 'resource') {
            return $empty;
        }

        if ($policyStyle === 'authorize') {
            return [
                '{{AUTH_INDEX}}'   => "        \$this->authorize('viewAny', {$modelClass}::class);\n",
                '{{AUTH_CREATE}}'  => "        \$this->authorize('create', {$modelClass}::class);\n",
                '{{AUTH_STORE}}'   => "        \$this->authorize('create', {$modelClass}::class);\n",
                '{{AUTH_SHOW}}'    => "        \$this->authorize('view', \${$modelVar});\n",
                '{{AUTH_EDIT}}'    => "        \$this->authorize('update', \${$modelVar});\n",
                '{{AUTH_UPDATE}}'  => "        \$this->authorize('update', \${$modelVar});\n",
                '{{AUTH_DESTROY}}' => "        \$this->authorize('delete', \${$modelVar});\n",
            ];
        }

        if ($policyStyle === 'gate') {
            return [
                '{{AUTH_INDEX}}'   => "        \\Illuminate\\Support\\Facades\\Gate::authorize('viewAny', {$modelClass}::class);\n",
                '{{AUTH_CREATE}}'  => "        \\Illuminate\\Support\\Facades\\Gate::authorize('create', {$modelClass}::class);\n",
                '{{AUTH_STORE}}'   => "        \\Illuminate\\Support\\Facades\\Gate::authorize('create', {$modelClass}::class);\n",
                '{{AUTH_SHOW}}'    => "        \\Illuminate\\Support\\Facades\\Gate::authorize('view', \${$modelVar});\n",
                '{{AUTH_EDIT}}'    => "        \\Illuminate\\Support\\Facades\\Gate::authorize('update', \${$modelVar});\n",
                '{{AUTH_UPDATE}}'  => "        \\Illuminate\\Support\\Facades\\Gate::authorize('update', \${$modelVar});\n",
                '{{AUTH_DESTROY}}' => "        \\Illuminate\\Support\\Facades\\Gate::authorize('delete', \${$modelVar});\n",
            ];
        }

        return $empty;
    }

    /* ============================================================
     | Unique migration generator
     ============================================================ */
    protected function generateUniqueMigration(string $table, bool $soft, bool $force): void
    {
        $migrationsDir = database_path('migrations');

        // Laravel default: YYYY_MM_DD_HHMMSS_create_employees_table.php
        $laravelPattern = $migrationsDir . DIRECTORY_SEPARATOR . "*create{$table}_table.php";

        // Legacy buggy format we used before: YYYY_MM_DD_HHMMSScreateemployees_table.php
        $legacyPattern  = $migrationsDir . DIRECTORY_SEPARATOR . "*create{$table}_table.php";

        $laravelExisting = glob($laravelPattern) ?: [];
        $legacyExisting  = glob($legacyPattern) ?: [];

        // Prefer the proper Laravel-named migration if it exists
        $existing = !empty($laravelExisting) ? $laravelExisting : $legacyExisting;

        $softColumn = $soft
            ? "            \$table->softDeletes();\n"
            : "            // \$table->softDeletes(); // Uncomment to enable soft deletes\n";

        if (!empty($existing)) {
            sort($existing);              // stable order
            $target = end($existing);     // pick the latest match

            if (!$force) {
                $replace = $this->confirm(
                    "Migration for [{$table}] already exists (" . basename($target) . "). Replace it?",
                    false
                );

                if (!$replace) {
                    $this->warn("Skipped migration for [{$table}].");
                    return;
                }
            }

            // Overwrite the existing migration file (force OR confirmed prompt)
            $this->generateFromStub(
                stub: $this->stubPath('migrations/create_table.stub'),
                target: $target,
                replacements: [
                    '{{TABLE}}' => $table,
                    '{{SOFT_MIGRATION_COLUMN}}' => $softColumn,
                    '{{SOFT_COLUMN}}' => $softColumn,
                ],
                force: true,
                askReplaceIfExists: false
            );

            return;
        }

        // If no migration exists, create it using Laravel naming convention
        $timestamp = now()->format('Y_m_d_His');
        $filename  = "{$timestamp}create{$table}_table.php";
        $target    = $migrationsDir . DIRECTORY_SEPARATOR . $filename;

        $this->generateFromStub(
            stub: $this->stubPath('migrations/create_table.stub'),
            target: $target,
            replacements: [
                '{{TABLE}}' => $table,
                '{{SOFT_MIGRATION_COLUMN}}' => $softColumn,
                '{{SOFT_COLUMN}}' => $softColumn,
            ],
            force: true,
            askReplaceIfExists: false
        );
    }

    /* ============================================================
     | Routes
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

        // Bulk delete route ALWAYS exists. Controller method is destroyBulk (trait provides it).
        if ($isWeb) {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk'])->name('{$routeName}.destroyBulk');";
        } else {
            $lines[] = "Route::delete('{$uri}/bulk', [{$controllerFqn}, 'destroyBulk'])->name('api.{$routeName}.destroyBulk');";
        }

        $lines[] = "";

        $softRoutes = [];

        if ($isWeb) {
            $softRoutes[] = "Route::get('{$uri}/trash', [{$controllerFqn}, 'trash'])->name('{$routeName}.trash');";
            $softRoutes[] = "Route::post('{$uri}/{id}/restore', [{$controllerFqn}, 'restore'])->name('{$routeName}.restore');";
            $softRoutes[] = "Route::post('{$uri}/restore-bulk', [{$controllerFqn}, 'restoreBulk'])->name('{$routeName}.restoreBulk');";
            $softRoutes[] = "Route::delete('{$uri}/{id}/force', [{$controllerFqn}, 'forceDelete'])->name('{$routeName}.forceDelete');";
            $softRoutes[] = "Route::delete('{$uri}/force-bulk', [{$controllerFqn}, 'forceDeleteBulk'])->name('{$routeName}.forceDeleteBulk');";
        } else {
            $softRoutes[] = "Route::get('{$uri}/trash', [{$controllerFqn}, 'trash'])->name('api.{$routeName}.trash');";
            $softRoutes[] = "Route::post('{$uri}/{id}/restore', [{$controllerFqn}, 'restore'])->name('api.{$routeName}.restore');";
            $softRoutes[] = "Route::post('{$uri}/restore-bulk', [{$controllerFqn}, 'restoreBulk'])->name('api.{$routeName}.restoreBulk');";
            $softRoutes[] = "Route::delete('{$uri}/{id}/force', [{$controllerFqn}, 'forceDelete'])->name('api.{$routeName}.forceDelete');";
            $softRoutes[] = "Route::delete('{$uri}/force-bulk', [{$controllerFqn}, 'forceDeleteBulk'])->name('api.{$routeName}.forceDeleteBulk');";
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

        $main = $isWeb
            ? "Route::resource('{$uri}', {$controllerFqn});"
            : "Route::apiResource('{$uri}', {$controllerFqn})->names('api.{$routeName}');";

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
            $replace = $this->confirm("Routes block already exists for [{$blockId}]. Replace it?", false);
            if (!$replace) {
                $this->warn("Skipped routes block for [{$blockId}].");
                return;
            }
        }

        if ($hasBlock) {
            $pattern = '/' . preg_quote($start, '/') . '.?' . preg_quote($end, '/') . '\s/s';
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
     | Policy helper blocks
     ============================================================ */
    protected function policySoftMethodsActive(string $modelClass, string $modelVar): string
    {
        return <<<PHP
    /**
     * Custom ability for listing trashed records (soft deletes only).
     */
    public function trash(User \$user): bool
    {
        return true;
    }

    /**
     * Standard Laravel soft-delete ability (single restore).
     */
    public function restore(User \$user, {$modelClass} \${$modelVar}): bool
    {
        return true;
    }

    /**
     * Custom ability for bulk restore (soft deletes only).
     */
    public function restoreBulk(User \$user): bool
    {
        return true;
    }

    /**
     * Standard Laravel soft-delete ability (single force delete).
     */
    public function forceDelete(User \$user, {$modelClass} \${$modelVar}): bool
    {
        return true;
    }

    /**
     * Custom ability for bulk force delete (soft deletes only).
     */
    public function forceDeleteBulk(User \$user): bool
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
            $out[] = $line === '' ? '' : "    // " . ltrim($line);
        }
        $out[] = "";

        return implode("\n", $out);
    }

    /* ============================================================
     | Blade permission placeholders (views)
     | - If style = none => empty strings (everything visible)
     | - Else => @can/@endcan blocks
     ============================================================ */
    protected function bladePermissionReplacements(string $policyStyle, string $modelClass, string $modelVar): array
    {
        $policyStyle = strtolower(trim($policyStyle));

        // NOTE: when using authorize/gate/resource, blades always use @can
        // because @can works with Gate + Policies regardless of controller style.
        if ($policyStyle === 'none') {
            return [
                '{{BLADE_CAN_CREATE_BEGIN}}' => '',
                '{{BLADE_CAN_CREATE_END}}' => '',
                '{{BLADE_CAN_TRASH_BEGIN}}' => '',
                '{{BLADE_CAN_TRASH_END}}' => '',
                '{{BLADE_CAN_UPDATE_BEGIN}}' => '',
                '{{BLADE_CAN_UPDATE_END}}' => '',
                '{{BLADE_CAN_DELETE_BEGIN}}' => '',
                '{{BLADE_CAN_DELETE_END}}' => '',
                '{{BLADE_CAN_DELETE_BULK_BEGIN}}' => '',
                '{{BLADE_CAN_DELETE_BULK_END}}' => '',
                '{{BLADE_CAN_RESTORE_BEGIN}}' => '',
                '{{BLADE_CAN_RESTORE_END}}' => '',
                '{{BLADE_CAN_RESTORE_BULK_BEGIN}}' => '',
                '{{BLADE_CAN_RESTORE_BULK_END}}' => '',
                '{{BLADE_CAN_FORCE_DELETE_BEGIN}}' => '',
                '{{BLADE_CAN_FORCE_DELETE_END}}' => '',
                '{{BLADE_CAN_FORCE_DELETE_BULK_BEGIN}}' => '',
                '{{BLADE_CAN_FORCE_DELETE_BULK_END}}' => '',
            ];
        }

        return [
            '{{BLADE_CAN_CREATE_BEGIN}}' => "@can('create', {$modelClass}::class)\n",
            '{{BLADE_CAN_CREATE_END}}'   => "\n@endcan",

            '{{BLADE_CAN_TRASH_BEGIN}}'  => "@can('trash', {$modelClass}::class)\n",
            '{{BLADE_CAN_TRASH_END}}'    => "\n@endcan",

            '{{BLADE_CAN_UPDATE_BEGIN}}' => "@can('update', \${$modelVar})\n",
            '{{BLADE_CAN_UPDATE_END}}'   => "\n@endcan",

            '{{BLADE_CAN_DELETE_BEGIN}}' => "@can('delete', \${$modelVar})\n",
            '{{BLADE_CAN_DELETE_END}}'   => "\n@endcan",

            '{{BLADE_CAN_DELETE_BULK_BEGIN}}' => "@can('deleteBulk', {$modelClass}::class)\n",
            '{{BLADE_CAN_DELETE_BULK_END}}'   => "\n@endcan",

            '{{BLADE_CAN_RESTORE_BEGIN}}' => "@can('restore', \${$modelVar})\n",
            '{{BLADE_CAN_RESTORE_END}}'   => "\n@endcan",

            '{{BLADE_CAN_RESTORE_BULK_BEGIN}}' => "@can('restoreBulk', {$modelClass}::class)\n",
            '{{BLADE_CAN_RESTORE_BULK_END}}'   => "\n@endcan",

            '{{BLADE_CAN_FORCE_DELETE_BEGIN}}' => "@can('forceDelete', \${$modelVar})\n",
            '{{BLADE_CAN_FORCE_DELETE_END}}'   => "\n@endcan",

            '{{BLADE_CAN_FORCE_DELETE_BULK_BEGIN}}' => "@can('forceDeleteBulk', {$modelClass}::class)\n",
            '{{BLADE_CAN_FORCE_DELETE_BULK_END}}'   => "\n@endcan",
        ];
    }

    /* ============================================================
     | Bulk delete view block (dynamic confirm + label) + policy gates
     ============================================================ */
    protected function bulkDeleteBlockActive(
        string $routeName,
        string $modelVarPlural,
        bool $soft,
        array $bladeGuards
    ): string {
        $deleteIcon  = $soft ? "<i class='fa-solid fa-trash'></i>" : "<i class='fa-solid fa-skull-crossbones'></i>";
        $deleteTitle = $soft ? "Move To Trash" : "Permanently Delete";

        $bulkConfirm = $soft
            ? "return confirm('Move selected records to trash?')"
            : "return confirm('Permanently delete selected records? This cannot be undone.')";

        $bulkLabel = $soft ? "Move To Trash (Selected)" : "Permanently Delete (Selected)";

        $canDeleteBulkBegin = $bladeGuards['{{BLADE_CAN_DELETE_BULK_BEGIN}}'] ?? '';
        $canDeleteBulkEnd   = $bladeGuards['{{BLADE_CAN_DELETE_BULK_END}}'] ?? '';

        $canDeleteBegin = $bladeGuards['{{BLADE_CAN_DELETE_BEGIN}}'] ?? '';
        $canDeleteEnd   = $bladeGuards['{{BLADE_CAN_DELETE_END}}'] ?? '';

        $canUpdateBegin = $bladeGuards['{{BLADE_CAN_UPDATE_BEGIN}}'] ?? '';
        $canUpdateEnd   = $bladeGuards['{{BLADE_CAN_UPDATE_END}}'] ?? '';

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

            {$canDeleteBulkBegin}
            <button type="submit" class="btn btn-outline-danger" id="bulkDeleteBtn" disabled
            onclick="{$bulkConfirm}">
            {$bulkLabel}
            </button>
            {$canDeleteBulkEnd}
        </div>
        </div>
    </form>

    {{-- Table (no wrapping form; row actions can safely include their own forms) --}}
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
                    <a class="btn btn-md btn-outline-dark" title="Show" href="{{ route('{$routeName}.show', \$item) }}">
                        <i class='fa-solid fa-eye'></i>
                    </a>

                    {$canUpdateBegin}
                    <a class="btn btn-md btn-outline-primary" title="Edit" href="{{ route('{$routeName}.edit', \$item) }}">
                        <i class='fa-solid fa-pen-to-square'></i>
                    </a>
                    {$canUpdateEnd}

                    {$canDeleteBegin}
                    <form method="POST" action="{{ route('{$routeName}.destroy', \$item) }}" class="d-inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" title="{$deleteTitle}" class="btn btn-md btn-outline-danger"
                        onclick="return confirm('Delete?')">{$deleteIcon}</button>
                    </form>
                    {$canDeleteEnd}
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
            if (bulkBtn) bulkBtn.disabled = ids.length === 0;

            const allChecked = checks.length > 0 && ids.length === checks.length;
            if (selectAll) {
                selectAll.checked = allChecked;
                selectAll.indeterminate = ids.length > 0 && !allChecked;
            }
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
     | Stub utilities + STRICT placeholder protection
     ============================================================ */
    protected function stubPath(string $relative): string
    {
        return __DIR__ . '/../../stubs/' . $relative;
    }

    protected function generateFromStub(
        string $stub,
        string $target,
        array $replacements,
        bool $force,
        bool $askReplaceIfExists = false
    ): void {
        if (!$this->files->exists($stub)) {
            $this->error("Stub not found: {$stub}");
            return;
        }

        if ($this->files->exists($target) && !$force) {
            if ($askReplaceIfExists) {
                $replace = $this->confirm("File exists: {$target}. Replace it?", false);
                if (!$replace) {
                    $this->warn("Skipped: {$target}");
                    return;
                }
            } else {
                $this->warn("Skipped (exists): {$target}  (use --force to overwrite)");
                return;
            }
        }

        $this->ensureDir(dirname($target));
        $content = $this->files->get($stub);

        foreach ($replacements as $k => $v) {
            $content = str_replace($k, $v, $content);
        }

        // HARD FAIL if ANY {{PLACEHOLDER}} remains
        if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $content, $m)) {
            $left = array_values(array_unique($m[0]));
            $this->error("Unreplaced placeholders in stub {$stub}: " . implode(', ', $left));
            $this->error("Refusing to write {$target} to avoid broken generated code.");
            return;
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