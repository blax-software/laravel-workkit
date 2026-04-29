<?php

declare(strict_types=1);

namespace Blax\Workkit\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Compression + encryption primitives shared by the backup/restore
 * commands. Encryption uses Laravel's Crypt facade, so the AES key is
 * derived from APP_KEY — same key that decrypts the rest of the app's
 * encrypted columns/cookies. That means a backup is restorable only
 * by a deployment that knows the host's APP_KEY.
 *
 * Compression goes through the system `xz` binary because it gives by
 * far the best ratio for repetitive SQL dump text and is cheap to
 * stream. Hosts without `xz` installed get a clear failure rather
 * than silently falling back to a worse codec.
 */
class BackupService
{
    /**
     * Compress $in with `xz` and return the path of the .xz output.
     * Defaults to writing alongside the input.
     */
    public static function compressFile(string $in, ?string $out = null): string
    {
        $out ??= $in . '.xz';
        self::requireBinary('xz');
        // -9 for max ratio, -T0 for parallel encoding on whatever cores
        // the host has. -c emits to stdout so we don't clobber $in in
        // place — the caller decides when to delete it.
        self::run(sprintf('xz -z -9 -T0 -c %s > %s', escapeshellarg($in), escapeshellarg($out)));
        if (! file_exists($out)) {
            throw new RuntimeException("xz compression failed; output not found at {$out}");
        }
        return $out;
    }

    /**
     * Decompress an .xz file. If $out is null, strips the `.xz` suffix
     * (or generates a uniquely-named sibling if there is none).
     */
    public static function decompressFile(string $in, ?string $out = null): string
    {
        self::requireBinary('xz');
        if ($out === null) {
            $out = str_ends_with($in, '.xz')
                ? substr($in, 0, -3)
                : $in . '.decompressed';
        }
        self::run(sprintf('xz -d -T0 -c %s > %s', escapeshellarg($in), escapeshellarg($out)));
        if (! file_exists($out)) {
            throw new RuntimeException("xz decompression failed; output not found at {$out}");
        }
        return $out;
    }

    /**
     * Encrypt $in with Crypt:: (APP_KEY-derived) and return the path
     * of the .enc output.
     */
    public static function encryptFile(string $in, ?string $out = null): string
    {
        $out ??= $in . '.enc';
        $payload = Crypt::encryptString(file_get_contents($in));
        file_put_contents($out, $payload);
        return $out;
    }

    /**
     * Decrypt $in (Crypt:: payload) and write to $out. If $out is null,
     * strips the `.enc` suffix.
     */
    public static function decryptFile(string $in, ?string $out = null): string
    {
        if ($out === null) {
            $out = str_ends_with($in, '.enc')
                ? substr($in, 0, -4)
                : $in . '.decrypted';
        }
        $payload = file_get_contents($in);
        file_put_contents($out, Crypt::decryptString($payload));
        return $out;
    }

    /**
     * Path of the host's backup directory, created if missing.
     * Defaults to storage/backups; the host can override via the
     * `workkit.backup.path` config (published by the package).
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
     * Bail loudly if a required system binary isn't on $PATH. We do
     * this early in each command so users get one clear message
     * instead of a cryptic exec failure halfway through.
     */
    public static function requireBinary(string $bin): void
    {
        $found = trim((string) @shell_exec('command -v ' . escapeshellarg($bin)));
        if ($found === '') {
            throw new RuntimeException("Required binary `{$bin}` not found on PATH.");
        }
    }

    /**
     * Run a shell command and throw on non-zero exit.
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
        // We don't need stdout — most of these commands write to files.
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                "Command failed (exit %d): %s\nstderr:\n%s",
                $exit,
                self::redactCommand($command),
                trim((string) $stderr) ?: '(empty)'
            ));
        }
    }

    /**
     * Hide credential-looking flags in error messages so we don't dump
     * passwords to logs. Crude but enough for the legacy CLI flag form;
     * the commands themselves prefer MYSQL_PWD env vars.
     */
    private static function redactCommand(string $command): string
    {
        return preg_replace('/(--password=)[^\s]+/', '$1***', $command);
    }
}
