<?php

declare(strict_types=1);

namespace Blax\Workkit\Commands\Database;

use Blax\Workkit\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Dump the configured MySQL database to a compressed + APP_KEY-encrypted
 * file under storage/backups. Output filename is
 *   db_<connection>_<timestamp>.sql.xz.enc
 *
 * The DB password is passed to mysqldump via the MYSQL_PWD env var, not
 * on the CLI — process listings (`ps auxf`) don't expose it that way.
 */
class BackupCommand extends Command
{
    protected $signature = 'workkit:db:backup
        {--connection= : DB connection to back up (defaults to config(database.default))}
        {--out= : Custom output path (overrides storage/backups default)}';

    protected $description = 'Create a compressed + encrypted backup of the configured MySQL database.';

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

        try {
            BackupService::requireBinary('mysqldump');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $stamp = date('Y-m-d_H-i-s');
        $base = BackupService::backupDirectory();
        $sqlPath = $this->option('out')
            ?: "{$base}/db_{$connection}_{$stamp}.sql";

        $this->info("Dumping `{$cfg['database']}` from {$cfg['host']} → {$sqlPath}");

        try {
            $this->dump($cfg, $sqlPath);

            $this->info('Compressing with xz…');
            $xzPath = BackupService::compressFile($sqlPath);
            @unlink($sqlPath);

            $this->info('Encrypting with APP_KEY…');
            $encPath = BackupService::encryptFile($xzPath);
            @unlink($xzPath);
        } catch (RuntimeException $e) {
            // Clean up any half-written intermediate files so a failed
            // backup doesn't leave SQL with secrets sitting unencrypted
            // on disk.
            foreach ([$sqlPath, $sqlPath . '.xz'] as $leftover) {
                if (is_file($leftover)) {
                    @unlink($leftover);
                }
            }
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $size = filesize($encPath);
        $this->info(sprintf('Backup complete: %s (%s)', $encPath, self::humanBytes((int) $size)));
        return self::SUCCESS;
    }

    /**
     * Run mysqldump with credentials passed via env vars (MYSQL_PWD) so
     * the password never appears in the process listing or shell history.
     */
    private function dump(array $cfg, string $sqlPath): void
    {
        $args = [
            '--single-transaction',  // consistent dump on InnoDB without FLUSH TABLES locking
            '--quick',               // stream rows instead of buffering
            '--skip-lock-tables',
            '--user=' . escapeshellarg((string) ($cfg['username'] ?? 'root')),
            '--host=' . escapeshellarg((string) ($cfg['host'] ?? 'localhost')),
            '--port=' . escapeshellarg((string) ($cfg['port'] ?? 3306)),
            escapeshellarg((string) $cfg['database']),
        ];

        $cmd = 'mysqldump ' . implode(' ', $args) . ' > ' . escapeshellarg($sqlPath);
        BackupService::run($cmd, [
            'MYSQL_PWD' => (string) ($cfg['password'] ?? ''),
        ]);

        if (! file_exists($sqlPath) || filesize($sqlPath) === 0) {
            throw new RuntimeException("mysqldump produced no output at {$sqlPath}");
        }
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
