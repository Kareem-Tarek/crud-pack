<?php

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class CrudPackPostmanCommand extends Command
{
    protected $signature = 'crud:postman
        {--force : Rebuild CrudPack collection from routes/api.php (overwrites CrudPack folders)}';

    protected $description = 'Generate/update Postman collection at /postman/CrudPack.postman_collection.json using routes/api.php CRUDPACK blocks.';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $routesPath = base_path('routes/api.php');

        if (!$this->files->exists($routesPath)) {
            $this->error("routes/api.php not found at: {$routesPath}");
            return self::FAILURE;
        }

        $resources = $this->parseCrudpackResourcesFromApiRoutes($this->files->get($routesPath));

        if (empty($resources)) {
            $this->warn('No CRUDPACK blocks found in routes/api.php. Nothing to generate.');
            return self::SUCCESS;
        }

        $appName = (string) config('app.name', 'Laravel');
        $collectionPath = $this->postmanCollectionPath();

        $collection = $this->loadOrCreateCollection($appName, $collectionPath);

        // Ensure top-level app folder exists and get reference
        $appFolderIndex = $this->findFolderIndexByName($collection['item'], $appName);
        if ($appFolderIndex === null) {
            $collection['item'][] = [
                'name' => $appName,
                'item' => [],
            ];
            $appFolderIndex = count($collection['item']) - 1;
        }

        $appFolder = $collection['item'][$appFolderIndex];
        $appFolder['item'] = $appFolder['item'] ?? [];

        // If --force => remove all folders that came from CRUDPACK blocks, then recreate from scratch.
        // We mark folders we generate with a description tag for safe identification.
        if ((bool) $this->option('force')) {
            $appFolder['item'] = array_values(array_filter(
                $appFolder['item'],
                fn ($it) => !isset($it['description']) || $it['description'] !== 'generated-by-crud-pack'
            ));
        }

        // Upsert each resource folder
        foreach ($resources as $resourceName => $meta) {
            $folder = $this->buildResourceFolder(
                resourceName: $resourceName,
                uri: $meta['uri'],
                soft: $meta['soft']
            );

            $existingIdx = $this->findFolderIndexByName($appFolder['item'], $resourceName);

            if ($existingIdx === null) {
                $appFolder['item'][] = $folder;
            } else {
                // Replace existing generated folder
                $appFolder['item'][$existingIdx] = $folder;
            }
        }

        $collection['item'][$appFolderIndex] = $appFolder;

        $this->ensureDir(dirname($collectionPath));
        $this->files->put($collectionPath, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info('✅ Postman collection updated:');
        $this->line($collectionPath);

        return self::SUCCESS;
    }

    /* ============================================================
     | Parsing routes/api.php (CRUDPACK blocks)
     ============================================================ */
    protected function parseCrudpackResourcesFromApiRoutes(string $contents): array
    {
        $resources = [];

        // Match blocks:
        // // CRUDPACK:Product:START
        // ...
        // // CRUDPACK:Product:END
        $pattern = '/\/\/\s*CRUDPACK:([A-Za-z0-9_]+):START\s*(.*?)\/\/\s*CRUDPACK:\1:END/s';

        if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return $resources;
        }

        foreach ($matches as $m) {
            $resourceName = $m[1];
            $block = $m[2];

            // Find apiResource uri: Route::apiResource('products', ...)
            $uri = null;
            if (preg_match("/Route::apiResource\(\s*'([^']+)'\s*,/i", $block, $mm)) {
                $uri = $mm[1];
            }

            // If no apiResource, skip (we need base uri)
            if (!$uri) {
                continue;
            }

            // soft enabled if route includes '/trash'
            $soft = (bool) preg_match("/Route::get\(\s*'".preg_quote($uri,'/')."\/trash'/i", $block);

            $resources[$resourceName] = [
                'uri' => $uri,
                'soft' => $soft,
            ];
        }

        return $resources;
    }

    /* ============================================================
     | Postman Builders
     ============================================================ */
    protected function buildResourceFolder(string $resourceName, string $uri, bool $soft): array
    {
        $singular = $resourceName;

        $items = [];

        // Basic CRUD
        $items[] = $this->postmanRequest("GetAll{$singular}", 'GET', $this->apiUrl($uri));
        $items[] = $this->postmanRequest("Get{$singular}", 'GET', $this->apiUrl("{$uri}/:id"));
        $items[] = $this->postmanRequest("Store{$singular}", 'POST', $this->apiUrl($uri), true);
        $items[] = $this->postmanRequest("Update{$singular}", 'PUT', $this->apiUrl("{$uri}/:id"), true);
        $items[] = $this->postmanRequest("Destroy{$singular}", 'DELETE', $this->apiUrl("{$uri}/:id"));

        // Bulk destroy (always)
        $items[] = $this->postmanRequest("Destroy{$singular}Bulk", 'DELETE', $this->apiUrl("{$uri}/bulk"), true);

        // Soft delete endpoints (only if enabled)
        if ($soft) {
            $items[] = $this->postmanRequest("Trash{$singular}", 'GET', $this->apiUrl("{$uri}/trash"));
            $items[] = $this->postmanRequest("Restore{$singular}", 'POST', $this->apiUrl("{$uri}/:id/restore"));
            $items[] = $this->postmanRequest("Restore{$singular}Bulk", 'POST', $this->apiUrl("{$uri}/restore-bulk"), true);
            $items[] = $this->postmanRequest("ForceDelete{$singular}", 'DELETE', $this->apiUrl("{$uri}/:id/force"));
            $items[] = $this->postmanRequest("ForceDelete{$singular}Bulk", 'DELETE', $this->apiUrl("{$uri}/force-bulk"), true);
        }

        return [
            'name' => $resourceName,
            'description' => 'generated-by-crud-pack',
            'item' => $items,
        ];
    }

    protected function postmanRequest(string $name, string $method, string $url, bool $hasBody = false): array
    {
        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
        ];

        $request = [
            'name' => $name,
            'request' => [
                'method' => strtoupper($method),
                'header' => $headers,
                'url' => $url, // string raw is valid in Postman schema
            ],
        ];

        if ($hasBody && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // For bulk delete we still include body as ids
            $request['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];

            $request['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode([
                    'name' => 'Example',
                    'ids' => '1,2,3',
                ], JSON_PRETTY_PRINT),
            ];
        }

        return $request;
    }

    protected function apiUrl(string $path): string
    {
        // Uses variables for flexibility
        // Example: {{base_url}}/{{api_prefix}}/products
        return rtrim('{{base_url}}', '/') . '/' . trim('{{api_prefix}}', '/') . '/' . ltrim($path, '/');
    }

    protected function loadOrCreateCollection(string $appName, string $path): array
    {
        if ($this->files->exists($path)) {
            $json = json_decode($this->files->get($path), true);

            if (is_array($json) && isset($json['info'], $json['item'])) {
                // Ensure variables exist
                $json['variable'] = $this->ensureVariables($json['variable'] ?? []);
                return $json;
            }
        }

        return [
            'info' => [
                'name' => 'CrudPack',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [
                [
                    'name' => $appName,
                    'item' => [],
                ],
            ],
            'variable' => $this->ensureVariables([]),
        ];
    }

    protected function ensureVariables(array $vars): array
    {
        $map = [];
        foreach ($vars as $v) {
            if (isset($v['key'])) {
                $map[$v['key']] = $v;
            }
        }

        $map['base_url'] = $map['base_url'] ?? ['key' => 'base_url', 'value' => 'http://localhost'];
        $map['api_prefix'] = $map['api_prefix'] ?? ['key' => 'api_prefix', 'value' => 'api'];

        return array_values($map);
    }

    protected function findFolderIndexByName(array $items, string $name): ?int
    {
        foreach ($items as $i => $it) {
            if (isset($it['name']) && $it['name'] === $name && isset($it['item']) && is_array($it['item'])) {
                return $i;
            }
        }
        return null;
    }

    protected function postmanCollectionPath(): string
    {
        return base_path('postman/CrudPack.postman_collection.json');
    }

    protected function ensureDir(string $dir): void
    {
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }
    }
}
