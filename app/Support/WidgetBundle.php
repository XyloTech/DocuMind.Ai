<?php

namespace App\Support;

/**
 * Locates the built embeddable widget bundle on disk.
 *
 * Production builds land in public/build/widget.js. A copy may also exist under
 * storage when the app build ran before the widget build, so both paths are
 * checked before we tell an owner the bundle is missing.
 */
final class WidgetBundle
{
    public static function publicPath(): string
    {
        return public_path('build/widget.js');
    }

    public static function storagePath(): string
    {
        return storage_path('app/build/widget.js');
    }

    public static function resolvePath(): ?string
    {
        foreach ([self::publicPath(), self::storagePath()] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function isBuilt(): bool
    {
        return self::resolvePath() !== null;
    }

    /**
     * A short fingerprint of the built bundle.
     *
     * Used as a query-string cache buster so a preview (or any page that just
     * shipped a rebuild) can never be served a copy the browser is still
     * holding from before the build.
     */
    public static function version(): string
    {
        $path = self::resolvePath();

        return $path === null ? '0' : substr(md5_file($path) ?: '0', 0, 12);
    }
}
