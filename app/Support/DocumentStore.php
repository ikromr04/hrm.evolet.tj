<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The documents a card points at, kept where only the application can reach
 * them.
 *
 * The same reasoning as the photographs (App\Support\PhotoStore), and with more
 * at stake: a diploma carries a name, a date of birth and a registry number on
 * one page. A file under the web root is handed to whoever holds its address
 * and nobody is ever asked, so these live outside it and are read through a
 * route that asks first.
 *
 * Kept apart from the photographs rather than filed with them, because the two
 * are cleaned up, backed up and reasoned about separately: `hrm:photos-private`
 * knows about faces, and a folder of diplomas is not a folder of faces.
 */
final class DocumentStore
{
    /** Where they live. */
    public static function disk(): Filesystem
    {
        return Storage::disk('documents');
    }

    /**
     * Put a file away under a name of the disk's own choosing, and say where it
     * went.
     *
     * Filed under the colleague it belongs to, so that everything about one
     * person can be found — and removed — without reading every row.
     *
     * The name on the disk is the disk's and not the uploader's: a file called
     * «../../.env» or «диплом.php» is a name, not a document, and a name chosen
     * here cannot be either of those. What the document is *called* is a
     * separate matter and a column of its own — somebody types it, because
     * «scan_0007.pdf» is what a scanner called a file and not what the paper
     * is.
     */
    public static function keep(UploadedFile $file, int $employee): string
    {
        return self::disk()->putFile("employees/{$employee}", $file);
    }

    /**
     * Take a file off the disk.
     *
     * Called where a record stops naming a file: the record is deleted, or its
     * document is replaced by another. A row that forgets its file leaves a
     * diploma lying on the disk for good, which is the sort of thing nobody
     * notices until a backup is restored somewhere it should not have been.
     */
    public static function forget(?string $path): void
    {
        if ($path !== null && $path !== '') {
            self::disk()->delete($path);
        }
    }

    /**
     * Hand the file over, under the name somebody gave it.
     *
     * As an attachment rather than inline: a browser asked to display a PDF
     * from an address like this one will, and a document shown in a tab is a
     * document the next person at that desk can read. The caching is private
     * for the reason the photographs' is — a shared proxy keeping a copy would
     * reopen the hole from the other end — and there is no max-age at all,
     * because a diploma is read once in a while rather than a hundred times a
     * page, and nothing is gained by letting a copy sit in a cache.
     */
    public static function answer(Request $request, string $path, ?string $name): BinaryFileResponse
    {
        $response = response()->download(self::disk()->path($path), self::saveAs($path, $name));

        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setAutoEtag();
        $response->setAutoLastModified();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * What the file should be saved as: the name it was given, ending in the
     * extension of the file itself.
     *
     * Somebody typing «Диплом бакалавра» means the document, not the filename,
     * and a file saved without «.pdf» is a file the system will not know how to
     * open. The extension is taken from the stored path rather than from
     * anything typed, so it describes what the file actually is.
     */
    private static function saveAs(string $path, ?string $name): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($name === null || trim($name) === '') {
            return basename($path);
        }

        $name = trim($name);

        return $extension === '' || str_ends_with(mb_strtolower($name), '.'.mb_strtolower($extension))
            ? $name
            : "{$name}.{$extension}";
    }
}
