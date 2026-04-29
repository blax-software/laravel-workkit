<?php

declare(strict_types=1);

namespace Blax\Workkit\Services;

use RuntimeException;

/**
 * Streaming backup pipeline. Dumps, compresses and encrypts in a single
 * shell pipe so PHP holds zero bytes of the database content in memory —
 * regardless of dump size. The original implementation ran each stage
 * separately and ran into "Allowed memory size exhausted" on
 * mid-three-digit-MB compressed dumps because Laravel's `Crypt::encryptString`
 * reads the whole file, base64-encodes (+33%) and JSON-envelopes it.
 *
 * Encryption: AES-256-CBC with PBKDF2 (600 000 iterations, random salt)
 * via the system `openssl` binary. The passphrase is derived from
 * APP_KEY (the `base64:` prefix is stripped, the remainder is used
 * verbatim — PBKDF2 stretches it into the key). A backup is restorable
 * only by a deployment that knows the same APP_KEY.
 *
 * The output file format is the standard `openssl enc -salt` format,
 * which means it's also restorable with vanilla openssl on any host:
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass env:K \
 *     -in backup.sql.xz.enc | xz -d | mysql ...
 *
 * Required system binaries: `mysqldump`, `mysql`, `xz`, `openssl`,
 * `bash` (for `set -o pipefail`). All are standard on every reasonable
 * Linux server.
 */
class BackupService
{
    public const CIPHER = 'aes-256-cbc';
    public const PBKDF2_ITER = 600000;

    /**
     * mysqldump → xz → openssl enc → $outPath. One pipeline, zero PHP
     * memory pressure. Pipefail propagates a failure in any stage out
     * to the caller as a non-zero exit code.
     *
     * $xzLevel defaults to 3 — empirically a good balance for SQL
     * (about 10× compression with a fraction of xz -9's time cost).
     */
    public static function dumpCompressEncrypt(array $cfg, string $outPath, int $xzLevel = 3): void
    {
        self::requireBinary('mysqldump');
        self::requireBinary('xz');
        self::requireBinary('openssl');
        self::requireBinary('bash');

        $level = max(0, min(9, $xzLevel));

        $mysqldump = 'mysqldump '
            . '--single-transaction --quick --skip-lock-tables '
            . '--user=' . escapeshellarg((string) ($cfg['username'] ?? 'root')) . ' '
            . '--host=' . escapeshellarg((string) ($cfg['host'] ?? 'localhost')) . ' '
            . '--port=' . escapeshellarg((string) ($cfg['port'] ?? 3306)) . ' '
            . escapeshellarg((string) $cfg['database']);

        $xz      = "xz -{$level} -T0";
        $openssl = 'openssl enc -' . self::CIPHER . ' -pbkdf2 -iter ' . self::PBKDF2_ITER . ' -salt -pass env:WK_KEY';

        // bash -c with pipefail: any stage failing trips the whole
        // pipeline. Without pipefail, a mysqldump crash with xz still
        // running would leave the output file 0-byte and the exit
        // code 0 — silent corruption.
        $pipeline = "{$mysqldump} | {$xz} | {$openssl} > " . escapeshellarg($outPath);
        $cmd = '/bin/bash -c ' . escapeshellarg('set -o pipefail; ' . $pipeline);

        try {
            self::run($cmd, [
                'MYSQL_PWD' => (string) ($cfg['password'] ?? ''),
                'WK_KEY'    => self::passphrase(),
            ]);
        } catch (RuntimeException $e) {
            // Don't leave a half-written encrypted file lying around —
            // it's neither valid plaintext (which we'd never want)
            // nor a complete backup, just confusing partial state.
            if (is_file($outPath)) {
                @unlink($outPath);
            }
            throw $e;
        }

        if (! file_exists($outPath) || filesize($outPath) === 0) {
            throw new RuntimeException("Backup pipeline produced no output at {$outPath}");
        }
    }

    /**
     * openssl dec → xz -d → mysql. Streams through the same pipeline
     * in reverse. Does no PHP-side decoding so the file size is bounded
     * only by disk I/O.
     */
    public static function decryptDecompressImport(string $inPath, array $cfg): void
    {
        self::requireBinary('mysql');
        self::requireBinary('xz');
        self::requireBinary('openssl');
        self::requireBinary('bash');

        // Sanity check on the file's magic bytes. `openssl enc -salt`
        // output always starts with the literal "Salted__" header.
        $head = (string) @file_get_contents($inPath, false, null, 0, 8);
        if ($head !== 'Salted__') {
            throw new RuntimeException(
                "File at {$inPath} doesn't look like a streaming openssl backup. "
                . 'Legacy backups (made with the pre-streaming Crypt envelope) need '
                . 'a separate restore path; see workkit:db:restore-legacy or restore '
                . 'manually with `Crypt::decryptString` after raising memory_limit.'
            );
        }

        $openssl = 'openssl enc -d -' . self::CIPHER
            . ' -pbkdf2 -iter ' . self::PBKDF2_ITER
            . ' -pass env:WK_KEY '
            . '-in ' . escapeshellarg($inPath);

        $mysql = 'mysql '
            . '--user=' . escapeshellarg((string) ($cfg['username'] ?? 'root')) . ' '
            . '--host=' . escapeshellarg((string) ($cfg['host'] ?? 'localhost')) . ' '
            . '--port=' . escapeshellarg((string) ($cfg['port'] ?? 3306)) . ' '
            . escapeshellarg((string) $cfg['database']);

        $pipeline = "{$openssl} | xz -d -T0 | {$mysql}";
        $cmd = '/bin/bash -c ' . escapeshellarg('set -o pipefail; ' . $pipeline);

        self::run($cmd, [
            'MYSQL_PWD' => (string) ($cfg['password'] ?? ''),
            'WK_KEY'    => self::passphrase(),
        ]);
    }

    /**
     * Derive the openssl passphrase from APP_KEY. Laravel stores APP_KEY
     * as "base64:<random-bytes>"; we strip the prefix and feed the rest
     * straight to openssl, which runs PBKDF2 over it to get the AES key.
     * Same APP_KEY always derives the same key — restore is deterministic.
     */
    public static function passphrase(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is empty. Run `php artisan key:generate` before using the backup commands.');
        }
        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
        }
        return $key;
    }

    /**
     * Path of the host's backup directory, created if missing. Defaults
     * to storage/backups; overridable via config('workkit.backup.path').
     */
    public static function backupDirectory(): string
    {
        $path = config('workkit.backup.path') ?: storage_path('backups');
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return rtrim($path, '/');
    }

    /**
     * Bail loudly if a required system binary isn't on PATH. Done early
     * in each command so users get one clear message instead of a
     * cryptic exec failure halfway through.
     */
    public static function requireBinary(string $bin): void
    {
        $found = trim((string) @shell_exec('command -v ' . escapeshellarg($bin)));
        if ($found === '') {
            throw new RuntimeException("Required binary `{$bin}` not found on PATH.");
        }
    }

    /**
     * Run a shell command and throw on non-zero exit, capturing stderr
     * for the error message. Env vars are passed via proc_open's env
     * arg — they're scoped to the child process and not visible in the
     * host's `ps` listing.
     */
    public static function run(string $command, array $env = []): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envForProc = $env === [] ? null : array_merge($_ENV, $env);
        $proc = proc_open($command, $descriptors, $pipes, null, $envForProc);
        if (! is_resource($proc)) {
            throw new RuntimeException("Failed to start process: {$command}");
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                "Command failed (exit %d):\n  %s\nstderr:\n%s",
                $exit,
                self::redactCommand($command),
                trim((string) $stderr) ?: '(empty)'
            ));
        }
    }

    /**
     * Hide credential-looking flags in error messages so we don't dump
     * passwords to logs. The streaming pipeline doesn't put creds on
     * the CLI (everything goes via env vars), but defence in depth.
     */
    private static function redactCommand(string $command): string
    {
        return preg_replace('/(--password=)[^\s]+/', '$1***', $command);
    }
}
