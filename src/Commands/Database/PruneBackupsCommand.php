<?php

declare(strict_types=1);

namespace Blax\Workkit\Commands\Database;

use Blax\Workkit\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Drop backup files older than the retention window. Defaults to 30
 * days; the host can override via --days or by setting
 * `workkit.backup.retention_days` in the published config.
 *
 * Designed to be wired into the scheduler so storage doesn't fill up
 * with stale dumps, e.g. Schedule::command('workkit:db:prune-backups')->daily();
 */
class PruneBackupsCommand extends Command
{
    protected $signature = 'workkit:db:prune-backups
        {--days= : Retention window in days (default: workkit.backup.retention_days, falling back to 30)}
        {--dry-run : Show what would be deleted without removing anything}';

    protected $description = 'Delete backup files older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('workkit.backup.retention_days', 30));
        if ($days < 1) {
            $this->error('--days must be >= 1');
            return self::FAILURE;
        }

        $base = BackupService::backupDirectory();
        $files = glob($base . '/*') ?: [];

        $cutoff = time() - $days * 86400;
        $removed = 0;
        $kept = 0;

        foreach ($files as $f) {
            if (! is_file($f)) {
                continue;
            }
            if (filemtime($f) < $cutoff) {
                if ($this->option('dry-run')) {
                    $this->line("would remove: {$f}");
                } else {
                    @unlink($f);
                    $this->line("removed: {$f}");
                }
                $removed++;
            } else {
                $kept++;
            }
        }

        $this->info(sprintf(
            'Backups: %d kept, %d %s (cutoff: %d days).',
            $kept,
            $removed,
            $this->option('dry-run') ? 'would-remove' : 'removed',
            $days,
        ));

        return self::SUCCESS;
    }
}
