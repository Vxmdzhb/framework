<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * Publish a file so no reader ever sees a partial one: write to a unique temp
 * file in the same directory, then rename() it into place. Within one
 * filesystem rename() is atomic, so a concurrent reader sees the whole old
 * file or the whole new one.
 *
 * LOCK_EX doesn't give that: it is advisory, file_put_contents() still
 * truncates first, and a plain include takes no lock. TemplateEngine writes
 * generated PHP into its cache and then requires it, so a torn file would be
 * a ParseError, and a torn global index would break every page.
 *
 * Returns false, writing nothing, when the directory can't be created, the
 * bytes can't be written in full (a short write on a full disk must not be
 * renamed into place), or the rename fails. Callers decide whether that is
 * fatal; for caches it isn't.
 *
 * It lives in this file because plain functions can't be autoloaded:
 * src/bootstrap.php and the compiled bundle both load exactly
 * Support/constants.php.
 */
function atomicWrite(string $path, string $contents): bool
{
    $dir = \dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    // Same directory as the destination: rename() is only atomic within one
    // filesystem, and /tmp is very often a different one (tmpfs).
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

    $written = @file_put_contents($tmp, $contents);
    if ($written === false || $written !== strlen($contents)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    // Published files include generated PHP that is require'd on the next
    // request. With opcache.validate_timestamps=Off (a common production
    // setting) opcache would keep serving the pre-rename compilation.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }

    return true;
}
