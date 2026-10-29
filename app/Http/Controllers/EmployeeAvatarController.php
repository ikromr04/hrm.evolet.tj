<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\ReadableImage;
use App\Support\EmployeeFields;
use App\Support\Photo;
use App\Support\PhotoStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The employee's photo. Two files are kept: the square thumbnail the interface
 * shows everywhere, and the upload itself, so the photo can be opened full size.
 *
 * Neither is a static file: both are handed out here, by somebody who has been
 * asked whether this face is theirs to look at. See App\Support\PhotoStore for
 * why that is not left to the web server.
 */
class EmployeeAvatarController extends Controller
{
    /** The interface never shows an avatar larger than this. */
    private const THUMBNAIL = 240;

    /**
     * One of the two files of this card's photograph.
     *
     * Whether a face is this viewer's to see is the same question wherever the
     * face turns up — a list of colleagues, the tree of the company, a laptop's
     * holder — so it is asked the way every page that draws one asks it, and the
     * answer is the same here as the answer that decided whether to draw it.
     */
    public function show(Request $request, User $employee, string $file): BinaryFileResponse
    {
        abort_unless(EmployeeFields::showsAvatar($request->user(), $employee), 403);

        // The card itself is the whole index of what may be read: the name in
        // the address is matched against the two files this row actually points
        // at, so there is no path assembled out of what somebody typed and
        // nothing to climb out of. It also means a photograph that has been
        // replaced or taken down stops being answered the moment the row stops
        // naming it, however many people still hold the address.
        $path = collect([$employee->getRawOriginal('avatar'), $employee->getRawOriginal('avatar_original')])
            ->filter()
            ->first(fn (string $kept) => basename($kept) === $file);

        // Nothing of this card's goes by that name — an address left over from a
        // photograph since replaced, or one somebody made up.
        abort_if($path === null, 404);

        $disk = PhotoStore::holding($path);

        // Or the row outlived the file, which somebody deleted by hand: an
        // address leading to nothing rather than something gone wrong.
        abort_if($disk === null, 404);

        return PhotoStore::answer($request, $disk, $path);
    }

    public function update(Request $request, User $employee): RedirectResponse
    {
        $request->validate([
            'avatar' => ['bail', 'required', 'image', 'mimes:jpeg,png,webp', 'max:8192', new ReadableImage],
        ], messages: [
            'avatar.image' => ReadableImage::MESSAGE,
            'avatar.mimes' => ReadableImage::MESSAGE,
        ], attributes: ['avatar' => 'фотография']);

        /** @var UploadedFile $file */
        $file = $request->file('avatar');
        $folder = "avatars/{$employee->id}";
        $name = Str::random(20);

        $original = $file->storeAs($folder, "{$name}.".$file->extension(), PhotoStore::DISK);
        $thumbnail = "{$folder}/{$name}_".self::THUMBNAIL.'.jpg';
        PhotoStore::disk()->put($thumbnail, $this->square(PhotoStore::disk()->path($original)));

        $previous = [$employee->getRawOriginal('avatar'), $employee->getRawOriginal('avatar_original')];

        DB::transaction(fn () => $employee->update(['avatar' => $thumbnail, 'avatar_original' => $original]));

        $this->forget($previous);

        return back();
    }

    public function destroy(User $employee): RedirectResponse
    {
        $files = [$employee->getRawOriginal('avatar'), $employee->getRawOriginal('avatar_original')];

        $employee->update(['avatar' => null, 'avatar_original' => null]);

        $this->forget($files);

        return back();
    }

    /**
     * @param  array<int, string|null>  $paths
     */
    private function forget(array $paths): void
    {
        // Only after the row no longer points at them, so a failed save never
        // leaves the employee with a path to a file that is gone.
        PhotoStore::forget($paths);
    }

    /**
     * The upload cropped to a centred square and scaled down, as JPEG. A photo
     * straight from a phone is several megabytes; a list of a hundred
     * colleagues would carry every one of them.
     */
    private function square(string $path): string
    {
        $source = Photo::upright($path);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = imagecreatetruecolor(self::THUMBNAIL, self::THUMBNAIL);
        // A transparent PNG would otherwise turn black once flattened to JPEG.
        imagefilledrectangle($canvas, 0, 0, self::THUMBNAIL, self::THUMBNAIL, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::THUMBNAIL, self::THUMBNAIL,
            $side, $side,
        );

        ob_start();
        imagejpeg($canvas, null, 82);
        $jpeg = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return $jpeg;
    }
}
