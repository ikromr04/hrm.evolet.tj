<?php

namespace App\Console\Commands;

use App\Support\PhotoStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Moves the photographs off the disk the web server can reach.
 *
 * Every photograph the company keeps used to be a static file under the web
 * root, which meant the address was the whole of the security: whoever held one
 * read the file, and no right was ever consulted. The application no longer
 * writes there and no longer hands out such an address — but the files already
 * on a server stay exactly where they were until somebody moves them, and the
 * server is updated by hand, so moving them is a thing to be run.
 *
 * It is written to be run whenever and twice over. The paths kept in the
 * database do not change; only the disk underneath them does, and the serving
 * code looks on the old disk for anything not yet moved, so the site is whole
 * before this is run and whole after.
 */
class MovePhotographsOutOfTheWebRoot extends Command
{
    protected $signature = 'hrm:photos-private';

    protected $description = 'Перенести фотографии из папки, открытой из интернета, в закрытое хранилище';

    public function handle(): int
    {
        $from = PhotoStore::legacy();
        $to = PhotoStore::disk();

        // Everything that is there, not only what the database names: a save
        // that fell over halfway could have left a file behind with no row
        // pointing at it, and such a file is as readable from the internet as
        // any other. The old disk held nothing but photographs, so taking all
        // of it is taking exactly the right thing.
        $files = array_values(array_filter(
            $from->allFiles(),
            // Laravel's own `.gitignore` sits in that folder and is not a
            // photograph; moving it would only puzzle the next reader.
            fn (string $path) => ! str_starts_with(basename($path), '.'),
        ));

        if ($files === []) {
            // Never run, or run twice: either way whatever folders are left
            // there hold nothing and are no use to anybody.
            $this->emptyFoldersGone($from);

            $this->components->info('Переносить нечего: в открытой папке фотографий нет.');

            return self::SUCCESS;
        }

        $moved = 0;
        $already = 0;
        $clashed = [];

        foreach ($files as $path) {
            if ($to->exists($path)) {
                // Already moved, and the copy left behind is the one still
                // readable from the internet — so it goes. Unless the two
                // differ, in which case which of them is the photograph is not
                // this command's to decide and neither is touched.
                if ($to->size($path) === $from->size($path)) {
                    $from->delete($path);
                    $already++;

                    continue;
                }

                $clashed[] = $path;

                continue;
            }

            // Streamed rather than read into a variable: a picture of a unit
            // runs to a dozen megabytes and a shared host does not have a
            // dozen megabytes to spare for each of several thousand of them.
            $stream = $from->readStream($path);
            $written = $stream !== null && $to->writeStream($path, $stream);

            // The handle was opened for us and is ours to close. Left open it
            // would not only be one of several thousand: Windows keeps a deleted
            // file listed in its folder until the last handle on it goes, and
            // the tidying up at the end then walks into a file that is gone.
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $written) {
                $this->components->error("Не удалось перенести файл: {$path}");

                return self::FAILURE;
            }

            // Only once the copy is known to be whole, so an interrupted run
            // loses nothing: whatever was not deleted is simply moved again.
            if ($to->size($path) !== $from->size($path)) {
                $this->components->error("Файл перенесён не полностью, исходный оставлен на месте: {$path}");

                return self::FAILURE;
            }

            $from->delete($path);
            $moved++;
        }

        $this->emptyFoldersGone($from);

        $this->components->info("Перенесено фотографий: {$moved}.");

        if ($already > 0) {
            $this->line("  Уже были в хранилище, лишние копии удалены: {$already}.");
        }

        if ($clashed !== []) {
            $this->components->warn('Эти файлы есть и там, и там, но они разные — оставлены как есть, разберитесь вручную:');

            foreach ($clashed as $path) {
                $this->line("  {$path}");
            }
        }

        $this->line('  Папку, открытую из интернета, вместе со ссылкой на неё теперь можно удалить — см. docs/deploy-cpanel.md.');

        return self::SUCCESS;
    }

    /**
     * The folders the files were in, now that they hold nothing.
     *
     * Left behind they would be a tree of empty directories in the web root
     * suggesting there is still something to find there.
     */
    private function emptyFoldersGone(Filesystem $from): void
    {
        // PHP remembers what it was told about a file the first time it asked,
        // and it was asked about every one of these a moment before deleting it.
        clearstatcache(true);

        foreach ($from->directories() as $directory) {
            if ($from->allFiles($directory) === []) {
                $from->deleteDirectory($directory);
            }
        }
    }
}
