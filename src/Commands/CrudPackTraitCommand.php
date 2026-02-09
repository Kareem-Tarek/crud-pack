<?php

namespace KareemTarek\CrudPack\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class CrudPackTraitCommand extends Command
{
    protected $signature = 'crud:trait
        {--soft-deletes : Generate soft-delete methods uncommented}
        {--no-soft-deletes : Generate soft-delete methods commented}
        {--force : Overwrite existing HandlesDeletes.php without prompting}';

    protected $description = 'Create or re-generate the shared HandlesDeletes trait in the Laravel app.';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = app_path('Http/Controllers/Concerns/HandlesDeletes.php');
        $stub   = $this->stubPath('traits/HandlesDeletes.stub');

        if (!$this->files->exists($stub)) {
            $this->error("Stub not found: {$stub}");
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        // Ensure directory exists (compatible with most Laravel versions)
        $dir = dirname($target);
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        // If already exists, confirm unless --force
        if ($this->files->exists($target) && !$force) {
            $replace = $this->confirm(
                "HandlesDeletes trait already exists at:\n{$target}\n\nReplace it?",
                false
            );

            if (!$replace) {
                $this->info('Skipped. Existing HandlesDeletes trait kept.');
                return self::SUCCESS;
            }
        }

        // Decide soft deletes mode
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

        $content = $this->files->get($stub);

        // Replace SOFT_TRAIT_METHODS
        $softBlock = $soft
            ? $this->softTraitMethodsActive()
            : $this->softTraitMethodsCommented();

        $content = str_replace('{{SOFT_TRAIT_METHODS}}', $softBlock, $content);

        // Safety: refuse if placeholders remain
        if (preg_match_all('/\{\{[A-Z0-9_\-]+}}/i', $content, $m) && !empty($m[0])) {
            $left = implode(', ', array_unique($m[0]));
            $this->error("Unreplaced placeholders in HandlesDeletes.stub: {$left}");
            $this->warn("Refusing to write {$target} to avoid broken generated code.");
            return self::FAILURE;
        }

        $this->files->put($target, $content);

        $this->info("✅ HandlesDeletes trait installed/updated:");
        $this->line($target);

        return self::SUCCESS;
    }

    protected function stubPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/stubs/' . $relative;
    }

    /**
     * Soft-delete methods (ACTIVE).
     */
    protected function softTraitMethodsActive(): string
    {
        return <<<'PHP'
    /**
     * List soft-deleted records (explicit route) — soft deletes only.
     */
    public function trash()
    {
        $trashedTotal = $this->modelClass::onlyTrashed()->count();
        $items = $this->modelClass::onlyTrashed()->paginate(15);

        if (request()->expectsJson()) {
            $items = $this->modelClass::onlyTrashed();

            return response()->json([
                'total' => $trashedTotal,
                'data'  => $items,
            ]);
        }

        return view($this->viewFolder . '.trash', compact('items', 'trashedTotal'));
    }

    /**
     * Restore single (explicit route) — soft deletes only.
     */
    public function restore(int|string $id)
    {
        $model = $this->modelClass::onlyTrashed()->findOrFail($id);
        $model->restore();

        return $this->deleteResponse('Restored successfully.');
    }

    /**
     * Restore bulk (explicit route) — soft deletes only.
     */
    public function restoreBulk(Request $request)
    {
        $ids = $this->extractIds($request);

        if (!empty($ids)) {
            $this->modelClass::onlyTrashed()->whereKey($ids)->restore();
        }

        return $this->deleteResponse('Selected records restored.');
    }

    /**
     * Force delete single (explicit route) — soft deletes only.
     */
    public function forceDelete(int|string $id)
    {
        $model = $this->modelClass::onlyTrashed()->findOrFail($id);
        $model->forceDelete();

        return $this->deleteResponse('Permanently deleted.');
    }

    /**
     * Force delete bulk (explicit route) — soft deletes only.
     */
    public function forceDeleteBulk(Request $request)
    {
        $ids = $this->extractIds($request);

        if (!empty($ids)) {
            $this->modelClass::onlyTrashed()->whereKey($ids)->forceDelete();
        }

        return $this->deleteResponse('Selected records permanently deleted.');
    }

PHP;
    }

    /**
     * Soft-delete methods (COMMENTED).
     * Keeps the code visible but disabled if soft deletes are not chosen.
     */
    protected function softTraitMethodsCommented(): string
    {
        $code = $this->softTraitMethodsActive();
        $lines = explode("\n", rtrim($code, "\n"));

        $out = [];
        $out[] = "    // Soft Deletes disabled: uncomment after enabling SoftDeletes";
        foreach ($lines as $line) {
            $out[] = $line === '' ? '' : '    // ' . ltrim($line);
        }
        $out[] = "";

        return implode("\n", $out);
    }
}
