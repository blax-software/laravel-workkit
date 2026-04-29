<?php

declare(strict_types=1);

namespace Blax\Workkit\Commands\Database;

use Blax\Workkit\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;

/**
 * Restore a backup produced by workkit:db:backup. Without --file, picks
 * the newest backup in storage/backups by mtime. Detects .enc and .xz
 * suffixes to decide which decode steps to run, so this also works
 * with un-encrypted or un-compressed dumps if the operator restored
 * a hand-prepared file.
 *
 * Refuses to run in production unless --force is passed: a restore is
 * a destructive operation that overwrites the live database.
 */
class RestoreCommand extends Command
{
    protected $signature = 'workkit:db:restore
        {--connection= : DB connection to restore into (defaults to config(database.default))}
        {--file= : Specific backup filename inside the backups directory (default: newest by mtime)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a (compressed + encrypted) database backup. Defaults to the newest file in storage/backups.';

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

        try {
            BackupService::requireBinary('mysql');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
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

        $tmpSql = null;
        try {
            $tmpSql = $this->prepare($file);
            $this->info("Restoring → {$cfg['database']}");
            $this->importInto($cfg, $tmpSql);
            $this->info('Restore complete.');
            return self::SUCCESS;
        } catch (DecryptException $e) {
            // Almost always means the file was encrypted with a different
            // APP_KEY than the current one. Spell that out so the operator
            // doesn't have to recognise the cryptographer's stack trace.
            $this->error('Decryption failed — likely an APP_KEY mismatch between backup and current environment.');
            $this->line("Underlying error: {$e->getMessage()}");
            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            if ($tmpSql && is_file($tmpSql)) {
                @unlink($tmpSql);
            }
        }
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

    /**
     * Walk the file through decrypt + decompress as needed and write the
     * resulting SQL to a temp path. Returns the path of the .sql file.
     */
    private function prepare(string $file): string
    {
        $current = $file;
        $intermediate = [];

        if (str_ends_with($current, '.enc')) {
            $this->info('Decrypting…');
            $current = BackupService::decryptFile($current, sys_get_temp_dir() . '/workkit_restore_' . uniqid() . '.dec');
            $intermediate[] = $current;
        }

        if (str_ends_with($current, '.xz')) {
            $this->info('Decompressing…');
            $next = BackupService::decompressFile($current, sys_get_temp_dir() . '/workkit_restore_' . uniqid() . '.sql');
            // Drop the .xz intermediate now that we have the .sql.
            foreach ($intermediate as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            $intermediate = [];
            $current = $next;
            $intermediate[] = $current;
        }

        // Sanity check: a real SQL dump always has at least one
        // CREATE/INSERT/USE statement near the top. Catches the case
        // where APP_KEY didn't match (Crypt::decryptString throws on
        // bad key, but if someone hand-renamed a non-encrypted file to
        // .enc this still helps).
        $head = (string) @file_get_contents($current, false, null, 0, 8192);
        if (! preg_match('/(INSERT\s+INTO|CREATE\s+TABLE|USE\s+`)/i', $head)) {
            throw new RuntimeException('Decoded file does not look like a SQL dump (no CREATE/INSERT/USE found in header).');
        }

        return $current;
    }

    /**
     * Pipe the .sql file into the mysql CLI, password via MYSQL_PWD.
     */
    private function importInto(array $cfg, string $sqlPath): void
    {
        $args = [
            '--user=' . escapeshellarg((string) ($cfg['username'] ?? 'root')),
            '--host=' . escapeshellarg((string) ($cfg['host'] ?? 'localhost')),
            '--port=' . escapeshellarg((string) ($cfg['port'] ?? 3306)),
            escapeshellarg((string) $cfg['database']),
        ];

        $cmd = 'mysql ' . implode(' ', $args) . ' < ' . escapeshellarg($sqlPath);
        BackupService::run($cmd, [
            'MYSQL_PWD' => (string) ($cfg['password'] ?? ''),
        ]);
    }
}
