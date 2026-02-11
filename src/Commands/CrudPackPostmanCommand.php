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

        $pattern = '/\/\/\s*CRUDPACK:([A-Za-z0-9_]+):START\s*(.*?)\/\/\s*CRUDPACK:\1:END/s';

        if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return $resources;
        }

        foreach ($matches as $m) {
            $resourceName = $m[1];
            $block = $m[2];

            $uri = null;
            if (preg_match("/Route::apiResource\(\s*'([^']+)'\s*,/i", $block, $mm)) {
                $uri = $mm[1];
            }

            if (!$uri) {
                continue;
            }

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
        $plural = $resourceName . 's';

        $items = [];

        // Basic CRUD
        // NOTE: Index is paginated => show disabled "page" param in Postman
        $items[] = $this->postmanRequest("GetAll{$plural}", 'GET', $this->apiUrlWithPageParam($uri));
        $items[] = $this->postmanRequest("Get{$singular}", 'GET', $this->apiUrl("{$uri}/:id"));

        // Store / Update => request validation body (NO ids)
        $items[] = $this->postmanRequest("Store{$singular}", 'POST', $this->apiUrl($uri), [
            'name' => 'Example',
        ]);
        $items[] = $this->postmanRequest("Update{$singular}", 'PUT', $this->apiUrl("{$uri}/:id"), [
            'name' => 'Example',
        ]);

        // Destroy single => NO body
        $items[] = $this->postmanRequest("Destroy{$singular}", 'DELETE', $this->apiUrl("{$uri}/:id"));

        // Bulk destroy => ONLY ids[] body
        $items[] = $this->postmanRequest("DestroyBulk{$singular}", 'DELETE', $this->apiUrl("{$uri}/bulk"), [
            'ids' => [1, 2, 3],
        ]);

        // Soft delete endpoints (only if enabled)
        if ($soft) {
            // Trash is paginated => show disabled "page" param in Postman
            $items[] = $this->postmanRequest("Trash{$plural}", 'GET', $this->apiUrlWithPageParam("{$uri}/trash"));

            // Restore single => NO body
            $items[] = $this->postmanRequest("Restore{$singular}", 'POST', $this->apiUrl("{$uri}/:id/restore"));

            // Restore bulk => ONLY ids[] body
            $items[] = $this->postmanRequest("RestoreBulk{$singular}", 'POST', $this->apiUrl("{$uri}/restore-bulk"), [
                'ids' => [1, 2, 3],
            ]);

            // Force delete single => NO body
            $items[] = $this->postmanRequest("ForceDelete{$singular}", 'DELETE', $this->apiUrl("{$uri}/:id/force"));

            // Force delete bulk => ONLY ids[] body
            $items[] = $this->postmanRequest("ForceDeleteBulk{$singular}", 'DELETE', $this->apiUrl("{$uri}/force-bulk"), [
                'ids' => [1, 2, 3],
            ]);
        }

        return [
            'name' => $resourceName,
            'description' => 'generated-by-crud-pack',
            'item' => $items,
        ];
    }

    /**
     * If $body === null => no request body is included.
     * If $body is array => raw JSON body included + Content-Type header set.
     *
     * $url can be:
     * - string (simple Postman URL)
     * - array (Postman URL object)
     */
    protected function postmanRequest(string $name, string $method, string|array $url, ?array $body = null): array
    {
        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
        ];

        $request = [
            'name' => $name,
            'request' => [
                'method' => strtoupper($method),
                'header' => $headers,
                'url' => $url,
            ],
        ];

        if (is_array($body)) {
            $request['request']['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['request']['body'] = [
                'mode' => 'raw',
                'raw' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $request;
    }

    /**
     * Normal URL string (works everywhere)
     */
    protected function apiUrl(string $path): string
    {
        return rtrim('{{base_url}}', '/') . '/' . trim('{{api_prefix}}', '/') . '/' . ltrim($path, '/');
    }

    /**
     * Postman URL object with a disabled pagination query param.
     * IMPORTANT: Must include "raw", otherwise Postman may show an empty URL on import.
     */
    protected function apiUrlWithPageParam(string $path): array
    {
        $raw = $this->apiUrl($path);

        return [
            'raw' => $raw,
            'query' => [
                [
                    'key' => 'page',
                    'value' => '1',
                    'disabled' => true,
                ],
            ],
        ];
    }

    protected function loadOrCreateCollection(string $appName, string $path): array
    {
        if ($this->files->exists($path)) {
            $json = json_decode($this->files->get($path), true);

            if (is_array($json) && isset($json['info'], $json['item'])) {
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