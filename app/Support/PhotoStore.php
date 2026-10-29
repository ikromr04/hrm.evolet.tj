<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Where the photographs are kept, and how one is handed to a browser.
 *
 * Every photograph — a colleague's face, a picture of a laptop — used to be a
 * static file under the web root, which meant the address was the whole of the
 * security: whoever held it read the file, forever, and no right was ever
 * consulted. A former employee, somebody the link was forwarded to, a proxy log
 * — all of them equally welcome.
 *
 * So the files sit on a disk the web server cannot reach and are answered by
 * routes that ask the question first. The columns still hold the same relative
 * paths they always did; only the disk underneath them and the address handed
 * out have changed.
 */
final class PhotoStore
{
    /** The disk every photograph is written to and read from. */
    public const DISK = 'photos';

    /**
     * TRANSITION — delete this constant, legacy(), the fallback inside disk()
     * and the second disk in forget() once every installation has run
     * `hrm:photos-private`.
     *
     * The company's server is updated by hand, PHP files over FTP, and the
     * command that moves the photographs can only be run after they arrive.
     * Between those two moments every photograph on the live site would be a
     * file the new code cannot find — so until the move has happened, a
     * photograph missing from the private disk is looked for where it used to
     * be. Reading it from there is no worse than yesterday, and the address it
     * is read through is already guarded.
     */
    private const LEGACY_DISK = 'public';

    /** The disk photographs are written to. Never the legacy one. */
    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /** Where photographs used to live, and where they may still be. */
    public static function legacy(): Filesystem
    {
        return Storage::disk(self::LEGACY_DISK);
    }

    /**
     * Whichever disk actually holds this photograph, or null when no disk does
     * — a row pointing at a file somebody deleted by hand, which is a 404 and
     * not an error.
     */
    public static function holding(?string $path): ?Filesystem
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (self::disk()->exists($path)) {
            return self::disk();
        }

        // TRANSITION: the file has not been moved off the old disk yet.
        return self::legacy()->exists($path) ? self::legacy() : null;
    }

    /**
     * Forgets the files behind a photograph, wherever they actually are.
     *
     * Both disks, because during the transition a file may be on either, and a
     * delete that only looked at one would leave the other copy readable — the
     * old one from the web root at that, which is the very thing being closed.
     *
     * @param  array<int, string|null>  $paths
     */
    public static function forget(array $paths): void
    {
        $paths = array_values(array_filter($paths));

        if ($paths === []) {
            return;
        }

        self::disk()->delete($paths);
        // TRANSITION: and the copy that may still be sitting in the web root.
        self::legacy()->delete($paths);
    }

    /**
     * The photograph itself, as an answer to a request that has already been
     * allowed to have it.
     *
     * Served from the file rather than streamed out of the disk's contents so
     * that Symfony does the conditional request for us: a page showing a hundred
     * faces asks for a hundred files, and the second time it is drawn every one
     * of them must come back as a bare 304 instead of a hundred downloads.
     * Both disks are local, so a path on the filesystem is a thing that exists.
     *
     * The caching is deliberately `private`: a shared proxy keeping a copy of a
     * colleague's face, to hand to the next person who asks for the same
     * address, would reopen the hole from the other end.
     */
    public static function answer(Request $request, Filesystem $disk, string $path): BinaryFileResponse
    {
        $response = response()->file($disk->path($path));

        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->setPrivate();
        // A day. Long enough that scrolling a list of colleagues costs nothing,
        // short enough that a photograph taken down stops being shown out of a
        // browser's own cache within the day — the address is refused from the
        // moment the row stops naming the file, but a cache does not ask.
        $response->setMaxAge(86400);

        // Turns the answer into a 304 when the browser already has this very
        // file, which is what makes the revalidation cheap.
        $response->isNotModified($request);

        return $response;
    }
}
