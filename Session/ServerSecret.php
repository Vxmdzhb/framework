<?php
declare(strict_types=1);

namespace AstrX\Session;

use AstrX\Config\InjectConfig;
use AstrX\Support\Paths;

/**
 * The installation's server secret: a stable secret for this install, or a
 * loud failure.
 *
 * Everything AstrX keys at rest derives from it:
 *   - SecureSessionHandler's HKDF encryption and MAC keys;
 *   - the identicon seed HMAC (so a fallback avatar can't be recomputed from
 *     a guessed address);
 *   - the TOTP secret envelope in UserService.
 *
 * Resolution order:
 *   1. Session.config.php `server_secret`, unless it's the publicly known
 *      value (see LEAKED_SERVER_SECRET).
 *   2. A per-install file, generated on first run. The config dir is tried
 *      first (most durable), then the temp dir (most reliably writable; under
 *      Docker the config dir often isn't writable by www-data).
 *   3. Nothing: throw.
 *
 * Throwing matters. A random key per request would make every session
 * unreadable, so every POST, login included, would fail its CSRF check with
 * nothing in the logs.
 */
final class ServerSecret
{
    /** The application's files: its config dir and temp dir hold the candidates. */
    public function __construct(private readonly Paths $paths) {}

    /**
     * A server_secret value that was once committed to the public repository,
     * so it is not secret. An install that still has it is treated as having no
     * secret configured, and gets a generated one.
     */
    public const string LEAKED_SERVER_SECRET =
        '2cadc3c3e1e0509c705e758f02e9d39c27446c2a509bc828b5ddbd6af4026ec4';

    private const string CONFIG_DIR_FILENAME = '.server_secret_generated';
    private const string TEMP_DIR_FILENAME   = 'astrx_server_secret';

    private string $configured = '';

    /** Memoised result — resolved at most once per request. */
    private ?string $resolved = null;

    /** Memoised effective uid from ext-posix; directory-independent. */
    private ?int $ownUid = null;
    private bool $ownUidResolved = false;

    /**
     * Memoised uid-probe result per probe directory — including the failure, so
     * an unwritable directory costs one syscall per request, not one per call.
     * Keyed by directory because "we could not prove our uid in /tmp" says
     * nothing about a candidate that lives somewhere else.
     *
     * @var array<string, int|null>
     */
    private array $probedUids = [];

    #[InjectConfig('server_secret')]
    public function setConfigured(string $secret): void
    {
        // A configured value arriving after the secret was handed out would re-key
        // in the middle of a request. Config setters run at construction, long
        // before the first bytes() call, so this only happens through a wiring
        // mistake; keep the value already resolved.
        if ($this->resolved !== null) {
            return;
        }
        $this->configured = $secret;
    }

    /**
     * The install's secret. Stable across requests for the life of the install.
     *
     * @throws \RuntimeException when no stable secret can be established.
     */
    public function bytes(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // 1. The secret configured by the admin (recommended). The publicly known
        //    value counts as unset. hash_equals for a constant-time comparison.
        if ($this->configured !== ''
            && !hash_equals(self::LEAKED_SERVER_SECRET, $this->configured)
        ) {
            return $this->resolved = $this->configured;
        }

        $candidates = $this->candidates();

        // 2. An already-persisted per-install secret.
        foreach ($candidates as $file) {
            $existing = $this->readTrusted($file);
            if ($existing !== null) {
                return $this->resolved = $existing;
            }
        }

        // 3. First run — create one, atomically, at the first usable candidate.
        foreach ($candidates as $file) {
            $created = $this->createExclusively($file);
            if ($created !== null) {
                return $this->resolved = $created;
            }
        }

        // 4. Nothing worked. Refuse to serve rather than run on a key that
        //    changes every request.
        throw new \RuntimeException(
            'AstrX cannot establish a stable server secret: none of ['
            . implode(', ', $candidates)
            . '] is readable-and-trusted or creatable. Set Session.server_secret in '
            . 'Session.config.php (php -r "echo bin2hex(random_bytes(32));") or make '
            . 'the config directory writable by the web user.'
        );
    }

    /** @return list<string> most-durable first */
    private function candidates(): array
    {
        $configDir = $this->paths->config;

        return array_values(array_filter([
            $configDir !== '' ? $configDir . self::CONFIG_DIR_FILENAME : null,
            // Paths::tempDir(), not sys_get_temp_dir(): it resolves to the same
            // directory in production, and gives tests (and an operator who
            // wants this off the world-writable /tmp) one place to repoint the
            // shared, predictable candidate. isSharedTempPath() reads the same
            // method, so the ownership + permission checks keep applying to
            // whatever it resolves to.
            $this->paths->tempDir() . DIRECTORY_SEPARATOR . self::TEMP_DIR_FILENAME,
        ]));
    }

    /**
     * Read a candidate, or null when it is absent or must not be trusted.
     *
     * The shared temp directory is world-writable, so a local user can sit at
     * the predictable path and hand us their own key. Ownership + permission +
     * symlink checks are applied there; the app-private config-dir candidate is
     * trusted as-is (its exact owner may legitimately differ — installer vs
     * runtime user — and tightening that would lock existing installs out of
     * their own sessions on upgrade).
     */
    private function readTrusted(string $file): ?string
    {
        if (is_link($file) || !is_file($file)) {
            return null;
        }

        if ($this->isSharedTempPath($file) && !$this->isPrivateToUs($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        // Shared lock: createExclusively() writes under LOCK_EX, so this cannot
        // observe a half-written secret from a racing first-run request.
        @flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);

        return (is_string($contents) && $contents !== '') ? $contents : null;
    }

    /**
     * Create the secret file, or null when this candidate cannot be used.
     *
     * fopen('xb') is O_CREAT|O_EXCL: it fails rather than following a symlink or
     * clobbering a file some other process (or a local attacker) put there, and
     * it is the whole race resolution — two concurrent first-run requests cannot
     * both win, and the loser re-reads what the winner wrote instead of
     * generating a second secret that would orphan the first one's sessions.
     */
    private function createExclusively(string $file): ?string
    {
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            // Either the path already exists (lost the race, or a hostile
            // pre-created file readTrusted() has already rejected) or the
            // directory is not writable. Re-read: adopt a secret a racing
            // request just created.
            return $this->readTrusted($file);
        }

        // chmod BEFORE writing: the file is still EMPTY here, so the window in
        // which it carries default (typically 0644) permissions contains no
        // secret bytes. The previous code wrote the 32 bytes first and chmod'd
        // afterwards, leaving the key world-readable for that interval.
        @chmod($file, 0600);

        $secret  = random_bytes(32);
        $written = @flock($handle, LOCK_EX)
            && fwrite($handle, $secret) === strlen($secret)
            && fflush($handle);
        @flock($handle, LOCK_UN);
        fclose($handle);

        if (!$written) {
            @unlink($file);
            return null;
        }

        return $secret;
    }

    private function isSharedTempPath(string $file): bool
    {
        $tmpDir = $this->paths->tempDir();
        return $tmpDir !== '' && str_starts_with($file, $tmpDir);
    }

    /** Owned by this process AND inaccessible to group/other. */
    private function isPrivateToUs(string $file): bool
    {
        $perms = @fileperms($file);
        if ($perms === false || ($perms & 0077) !== 0) {
            return false;
        }

        $uid = $this->effectiveUid(\dirname($file));
        if ($uid === null) {
            // We cannot prove the file is ours, and this is the world-writable
            // temp directory. Refuse: adopting a stranger's 0600 file would hand
            // a local user the session encryption key for the whole install.
            return false;
        }

        return @fileowner($file) === $uid;
    }

    /**
     * This process's effective uid, without requiring ext-posix (the shipped
     * docker/php image does not enable it): create a throwaway file and ask who
     * owns it.
     *
     * The probe goes in $probeDir — the directory of the candidate being checked
     * — and NOT in sys_get_temp_dir(). The whole reason ASTRX_TEMP_DIR exists is
     * an install where /tmp is unusable: open_basedir, a read-only tmpfs, no
     * /tmp in the container at all. Probing /tmp there returned null with posix
     * absent, isPrivateToUs() then refused the very file this class had written
     * one request earlier, createExclusively() could not recreate it (it is
     * already there), and every request after the first died with the
     * RuntimeException from bytes(). Asking the directory we are actually
     * writing to cannot fail for a reason unrelated to that directory.
     *
     * @param string $probeDir directory to create the throwaway file in
     */
    private function effectiveUid(string $probeDir): ?int
    {
        if ($this->ownUidResolved) {
            return $this->ownUid;
        }

        if (function_exists('posix_geteuid')) {
            $this->ownUidResolved = true;
            return $this->ownUid = posix_geteuid();
        }

        if (array_key_exists($probeDir, $this->probedUids)) {
            return $this->probedUids[$probeDir];
        }

        $probe = @tempnam($probeDir, 'astrx_uid_');
        if ($probe === false) {
            return $this->probedUids[$probeDir] = null;
        }
        $owner = @fileowner($probe);
        @unlink($probe);

        return $this->probedUids[$probeDir] = ($owner === false ? null : $owner);
    }
}
