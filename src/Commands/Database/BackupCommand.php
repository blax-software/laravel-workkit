<?php

declare(strict_types=1);

namespace Blax\Workkit\Commands\Database;

use Blax\Workkit\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Stream a MySQL dump through xz and openssl into a single
 * APP_KEY-encrypted file under storage/backups. The whole pipeline
 * is one shell pipe (mysqldump | xz | openssl); PHP holds zero bytes
 * of database content in memory regardless of dump size.
 *
 * Output filename:
 *   storage/backups/db_<connection>_<timestamp>.sql.xz.enc
 */
class BackupCommand extends Command
{
    protected $signature = 'workkit:db:backup
        {--connection= : DB connection to back up (defaults to config(database.default))}
        {--out= : Custom output path (overrides storage/backups default)}
        {--xz-level= : xz compression level 0–9 (default: 3 — fast, ~10× ratio for SQL)}';

    protected $description = 'Create a streamed, compressed + APP_KEY-encrypted backup of the configured MySQL database.';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $cfg = config("database.connections.{$connection}");

        if (! $cfg) {
            $this->error("Unknown database connection: {$connection}");
            return self::FAILURE;
        }
        if (($cfg['driver'] ?? null) !== 'mysql') {
            $this->error("workkit:db:backup currently supports only MySQL connections (got: {$cfg['driver']}).");
            return self::FAILURE;
        }

        $stamp = date('Y-m-d_H-i-s');
        $base = BackupService::backupDirectory();
        $outPath = $this->option('out')
            ?: "{$base}/db_{$connection}_{$stamp}.sql.xz.enc";

        $xzLevel = (int) ($this->option('xz-level') ?? config('workkit.backup.xz_level', 3));

        $this->info(sprintf(
            'Streaming dump → xz -%d → openssl → %s',
            $xzLevel,
            $outPath,
        ));

        $startedAt = microtime(true);
        try {
            BackupService::dumpCompressEncrypt($cfg, $outPath, $xzLevel);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $elapsed = microtime(true) - $startedAt;
        $size = filesize($outPath);
        $this->info(sprintf(
            'Backup complete in %.1fs: %s (%s)',
            $elapsed,
            $outPath,
            self::humanBytes((int) $size),
        ));
        return self::SUCCESS;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.2f %s', $bytes, $units[$i]);
    }
}
