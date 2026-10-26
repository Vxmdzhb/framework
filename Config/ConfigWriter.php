<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Config\Diagnostic\ConfigWriteDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticLevel;

/**
 * Writes a config domain back to its PHP file, atomically.
 *
 *   1. Serialise with a recursive pretty-printer that emits short array
 *      syntax. (var_export() emits array() and can't be fixed up reliably.)
 *   2. Write to a sibling .tmp file.
 *   3. rename() it into place.
 *
 * A section is written to the file that currently declares it, whatever file
 * name the caller passed, using the same {@see ConfigDomainResolver} the
 * reading side uses.
 */
final class ConfigWriter
{
    private ?ConfigDomainResolver $resolver;

    public function __construct(?ConfigDomainResolver $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @return Result<mixed>
     */
    public function write(string $fileBaseName, array $config): Result
    {
        $diagnostics = Diagnostics::empty();

        // Group each section under the file that actually declares it. A section
        // no file declares is new and stays with the caller's chosen file.
        /** @var array<string, array<string, array<string, mixed>>> $byFile */
        $byFile = [];
        foreach ($config as $section => $keys) {
            $target = $this->resolver()->fileForSection($section) ?? $fileBaseName;
            if ($target !== $fileBaseName) {
                $diagnostics = $diagnostics->with(new ConfigWriteDiagnostic(
                    'astrx.config/write_retargeted',
                    DiagnosticLevel::WARNING,
                    $this->pathFor($target),
                    $section . ' requested in ' . $fileBaseName,
                ));
            }
            $byFile[$target][$section] = $keys;
        }

        foreach ($byFile as $target => $sections) {
            $result = $this->writeFile($this->pathFor($target), $sections);
            if (!$result->isOk()) {
                return $result->withDiagnostics($diagnostics);
            }
            $diagnostics = $diagnostics->concat($result->diagnostics());
        }

        return Result::ok(true, $diagnostics);
    }

    /**
     * Write the main config.php file.
     * Unlike write() which appends '.config.php', this writes to 'config.php' directly.
     * @param array<string, array<string, mixed>> $config
     * @return Result<mixed>
     */
    public function writeMainConfig(array $config): Result
    {
        return $this->writeFile($this->pathFor('config'), $config, merge: false);
    }

    /**
     * Serialise $config to $path via tmp-file + rename.
     *
     * @param array<string, array<string, mixed>> $config
     * @param bool $merge Merge over the file's current contents (per-section,
     *                    per-key) instead of replacing it wholesale.
     * @return Result<mixed>
     */
    private function writeFile(string $path, array $config, bool $merge = true): Result
    {
        if ($path === '') {
            // The application has no config directory: there is nowhere to
            // save, and writing relative to the working directory is worse.
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                'astrx.config/write_failed', DiagnosticLevel::ERROR, '', 'no_config_dir',
            )));
        }
        if ($merge) {
            // Merge the incoming sections and keys over the existing file rather than
            // replacing it. Many save handlers rebuild only the keys that have a form
            // field; a full replace would drop the rest, including secrets like the
            // imageboard salts or the session server_secret. Per-section, shallow: new
            // keys win, omitted keys stay, untouched sections are kept as they are.
            $existing = is_file($path) ? @include $path : null;
            if (is_array($existing)) {
                /** @var array<string, mixed> $existing */
                $merged = $existing;
                foreach ($config as $section => $keys) {
                    if (isset($merged[$section]) && is_array($merged[$section])) {
                        /** @var array<string, mixed> $prev */
                        $prev = $merged[$section];
                        $merged[$section] = array_merge($prev, $keys);
                    } else {
                        $merged[$section] = $keys;
                    }
                }
                /** @var array<string, array<string, mixed>> $merged */
                $config = $merged;
            }
        }

        $php  = $this->render($config);
        $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $tmp, 'write_failed',
                                                      )));
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $path, 'rename_failed',
                                                      )));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return Result::ok(true);
    }

    /**
     * The site's own copy of a config file ('config' is the main config.php),
     * as written — without the packages' defaults under it. [] when the site
     * has none (or no config directory).
     *
     * @return array<string, array<string, mixed>>
     */
    public function readSiteFile(string $fileBaseName): array
    {
        $path = $this->pathFor($fileBaseName);
        if ($path === '' || !is_file($path)) {
            return [];
        }
        /** @var mixed $loaded */
        $loaded = @include $path;
        if (!is_array($loaded)) {
            return [];
        }
        /** @var array<string, array<string, mixed>> $loaded */
        return $loaded;
    }

    /**
     * Absolute path of a config file base name in the site's config directory
     * (the resolver's); 'config' is the main file. '' when there is none.
     */
    private function pathFor(string $fileBaseName): string
    {
        $dir = $this->resolver()->configDir();
        if ($dir === '') {
            return '';
        }
        return $fileBaseName === 'config'
            ? $dir . 'config.php'
            : $dir . $fileBaseName . '.config.php';
    }

    private function resolver(): ConfigDomainResolver
    {
        return $this->resolver ??= new ConfigDomainResolver();
    }

    /** @param array<string,mixed> $config */
    private function render(array $config): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn " . $this->exportValue($config, 0) . ";\n";
    }

    /**
     * Recursively export a value as short-syntax PHP.
     * Produces correct, indented PHP for any mix of scalars, booleans, nulls, and arrays.
     */
    private function exportValue(mixed $value, int $depth): string
    {
        if ($value === null)              { return 'null'; }
        if ($value === true)              { return 'true'; }
        if ($value === false)             { return 'false'; }
        if (is_int($value))              { return (string) $value; }
        if (is_float($value)) {
            // Keep a float a float on round-trip: an integer-valued float (1.0)
            // must NOT serialize to "1" (which reloads as int and breaks is_float/
            // === checks). Ensure a decimal point survives.
            $s = rtrim(rtrim(sprintf('%F', $value), '0'), '.');
            if ($s === '' || $s === '-') { $s = '0'; }
            return str_contains($s, '.') ? $s : $s . '.0';
        }
        if (is_string($value))           { return "'" . addcslashes($value, "'\\") . "'"; }

        if (!is_array($value) || $value === []) {
            return '[]';
        }

        $indent     = str_repeat('    ', $depth + 1);
        $closeIndent= str_repeat('    ', $depth);
        $isList     = array_is_list($value);
        $lines      = [];

        foreach ($value as $k => $v) {
            $key    = $isList ? '' : $this->exportValue($k, 0) . ' => ';
            $lines[] = $indent . $key . $this->exportValue($v, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $closeIndent . ']';
    }
}
