<?php

namespace App\Console\Commands;

use App\Models\ScriptRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deploy pipeline entry point: run every script in scripts/pending/, file each
 * to scripts/done/ (success) or scripts/failed/ (failure), and record the
 * outcome in script_runs.
 *
 * Idempotent across deploys. The reset --hard sync restores tracked pending/
 * files every deploy, so a script that already has a successful run is filed to
 * done/ WITHOUT re-running — only never-succeeded scripts are executed. This
 * mirrors the manual ScriptReviewController::approve() path.
 *
 * Individual script failures do NOT fail the deploy: a failed script is filed
 * to failed/ and recorded, then the deploy continues.
 */
class RunPendingScripts extends Command
{
    protected $signature = 'scripts:run-pending
                            {--no-transaction : Pass through to scripts:run-one (run without a DB transaction)}';

    protected $description = 'Run all pending one-off scripts, file them to done/ or failed/, and record results';

    public function handle(): int
    {
        $files = collect(glob(base_path('scripts/pending/*.php')) ?: [])
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->info('No pending scripts to run.');

            return self::SUCCESS;
        }

        $succeeded = $failed = $skipped = 0;

        foreach ($files as $path) {
            $name = basename($path);
            $from = 'scripts/pending/'.$name;

            // Already ran successfully before? File to done/ without re-running.
            $alreadyDone = ScriptRun::where('filename', $name)
                ->where('status', 'success')
                ->latest('id')
                ->first();

            if ($alreadyDone) {
                $this->moveFile($from, 'done', $name);
                $alreadyDone->update([
                    'approval_status' => 'approved',
                    'moved_to' => 'done',
                    'approved_at' => now(),
                ]);
                $this->line("• {$name}: already succeeded — filed to scripts/done/ (not re-run)");
                $skipped++;

                continue;
            }

            $this->line("• Running {$name} ...");

            $params = ['file' => $from];
            if ($this->option('no-transaction')) {
                $params['--no-transaction'] = true;
            }
            Artisan::call('scripts:run-one', $params, $this->getOutput());

            $run = ScriptRun::where('filename', $name)->latest('id')->first();
            $target = ($run && $run->succeeded()) ? 'done' : 'failed';

            $this->moveFile($from, $target, $name);

            if ($run) {
                $run->update([
                    'approval_status' => 'approved',
                    'moved_to' => $target,
                    'approved_at' => now(),
                ]);
            }

            if ($target === 'done') {
                $this->info("  ✓ {$name} → scripts/done/");
                $succeeded++;
            } else {
                $this->error("  ✗ {$name} → scripts/failed/");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. ✓ {$succeeded} succeeded, ✗ {$failed} failed, {$skipped} skipped (already done).");

        // Failures are expected outcomes (filed to failed/), not command errors —
        // return success so a single bad script never blocks the deploy.
        return self::SUCCESS;
    }

    /** Move a file between scripts/ subfolders. No-op if already at the target. */
    private function moveFile(string $fromRel, string $target, string $name): void
    {
        $from = base_path($fromRel);
        $to = base_path("scripts/{$target}/{$name}");

        if (realpath($from) !== false && realpath($from) === realpath($to)) {
            return;
        }

        if (is_file($from)) {
            @mkdir(dirname($to), 0775, true);
            @rename($from, $to);
        }
    }
}
