<?php

declare(strict_types=1);

namespace Blax\Workkit\Commands\Database;

use Blax\Workkit\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Restore a backup produced by workkit:db:backup. Streams the file
 * through openssl decrypt → xz decompress → mysql in a single pipe,
 * matching the backup pipeline exactly. PHP allocates nothing for the
 * payload, so even multi-GB backups restore without bumping memory_limit.
 *
 * Without --file, picks the newest backup in storage/backups by mtime.
 * Refuses to run unless --force is passed: a restore overwrites whatever
 * is currently in the target database.
 */
class RestoreCommand extends Command
{
    protected $signature = 'workkit:db:restore
        {--connection= : DB connection to restore into (defaults to config(database.default))}
        {--file= : Specific backup filename inside the backups directory (default: newest by mtime)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a streaming, APP_KEY-encrypted database backup. Defaults to the newest file in storage/backups.';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $cfg = config("database.connections.{$connection}");

        if (! $cfg) {
            $this->error("Unknown database connection: {$connection}");
            return self::FAILURE;
        }
        if (($cfg['driver'] ?? null) !== 'mysql') {
            $this->error("workkit:db:restore currently supports only MySQL connections (got: {$cfg['driver']}).");
            return self::FAILURE;
        }

        $file = $this->resolveFile();
        if (! $file) {
            $this->error('No backup found.');
            return self::FAILURE;
        }
        if (! file_exists($file)) {
            $this->error("Backup file not found: {$file}");
            return self::FAILURE;
        }

        $this->warn(sprintf(
            'About to restore `%s`@%s from: %s',
            $cfg['database'],
            $cfg['host'],
            $file,
        ));
        $this->warn('This will OVERWRITE any data that conflicts with the dump.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        try {
            BackupService::decryptDecompressImport($file, $cfg);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            // openssl's password mismatch error has a recognisable shape;
            // translate it into something an operator can act on.
            if (str_contains($msg, 'bad decrypt') || str_contains($msg, 'bad magic')) {
                $this->error('Decryption failed — likely an APP_KEY mismatch between this host and the host that produced the backup.');
                $this->line($msg);
            } else {
                $this->error($msg);
            }
            return self::FAILURE;
        }

        $elapsed = microtime(true) - $startedAt;
        $this->info(sprintf('Restore complete in %.1fs.', $elapsed));
        return self::SUCCESS;
    }

    /**
     * Resolve the file argument:
     *   - --file=path/to/X.sql.xz.enc → use as-is if it exists
     *   - --file=basename             → look up inside storage/backups
     *   - omitted                     → pick newest in storage/backups
     */
    private function resolveFile(): ?string
    {
        $base = BackupService::backupDirectory();
        $opt = $this->option('file');

        if ($opt) {
            if (is_file($opt)) {
                return $opt;
            }
            $candidate = $base . '/' . ltrim((string) $opt, '/');
            return is_file($candidate) ? $candidate : null;
        }

        $files = glob($base . '/*');
        if (! $files) {
            return null;
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }
}
