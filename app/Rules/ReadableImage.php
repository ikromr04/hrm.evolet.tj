<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A picture the application can actually open.
 *
 * Everything that accepts a photograph goes on to cut a smaller copy of it with
 * GD, and GD reads a shorter list of formats than a browser will happily hand
 * over. Checked at the door, where a person still sees a form with a message on
 * it, rather than halfway through saving, where they would see a blank error
 * page instead.
 */
class ReadableImage implements ValidationRule
{
    /**
     * What to say when a file is not a photograph that can be opened. The
     * commonest case by far is a picture straight off an iPhone: the phone
     * records HEIC, the browser hands it over without a word, and neither
     * Laravel nor GD reads it — so the message says what to do about it rather
     * than that something is wrong. Shared with the rules that check the format
     * by name, because to the person it is all one answer.
     */
    public const MESSAGE = 'Фото должно быть в JPEG, PNG или WebP. Снимки с iPhone бывают в формате HEIC — сохраните такое фото как JPEG.';

    /**
     * What to say when a picture holds more points than can be opened. Said in
     * points rather than in megabytes, because the file may be perfectly small:
     * that is the whole trouble with it.
     */
    public const TOO_MANY_POINTS = 'Фото слишком большое: не больше 25 миллионов точек, то есть примерно 5000 × 5000. Уменьшите снимок и загрузите снова.';

    /**
     * How many points a picture may hold, whatever it weighs.
     *
     * The weight is capped already — eight megabytes for a photograph of a
     * colleague, twelve for a unit of equipment — and that cap says nothing
     * about the one thing that matters here. A file is compressed; an open
     * picture is not. GD keeps a true-colour image at four bytes a point, and
     * everything that accepts a photograph goes on to open one: Photo::upright
     * decodes it and then, for a snap taken in portrait, rotates it into a
     * second copy of the same size — so the moment of opening costs about eight
     * bytes a point, plus the file itself.
     *
     * The company's server is set to allow a request 256 megabytes
     * (docs/deploy-cpanel.md), of which the framework and the request itself
     * want some; call it 200 for the picture. Eight bytes a point puts the
     * ceiling at twenty-six million, and twenty-five is that rounded down.
     *
     * A six-megabyte PNG can be thirty thousand points square — nine hundred
     * million of them, nearly seven gigabytes once open. That is the attack: one
     * small file, sent as often as one likes, and every request dies of
     * exhaustion halfway through saving, taking whatever else the server was
     * doing with it. Twenty-five million is also no real loss: a photograph with
     * more points than that does not fit in eight megabytes of JPEG to begin
     * with, so nothing a camera produces is turned away that was not being
     * turned away already.
     */
    private const MAX_POINTS = 25_000_000;

    /**
     * What GD can open, by the type getimagesize() reports.
     *
     * @var array<int, int>
     */
    private const SUPPORTED = [
        IMAGETYPE_JPEG => IMG_JPG,
        IMAGETYPE_PNG => IMG_PNG,
        IMAGETYPE_GIF => IMG_GIF,
        IMAGETYPE_WEBP => IMG_WEBP,
        IMAGETYPE_BMP => IMG_BMP,
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not a file at all, or one refused already: the other rules say so.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        // getimagesize rather than a full decode: it reads the header, says what
        // the file really is whatever it is called, and costs no memory for a
        // twelve-megabyte photograph.
        $info = @getimagesize($value->getRealPath());

        if ($info === false || ! isset(self::SUPPORTED[$info[2]]) || (imagetypes() & self::SUPPORTED[$info[2]]) === 0) {
            $fail(self::MESSAGE);

            return;
        }

        // The header says how many points there are, which is what the opening
        // will cost. Asked here, before anything is decoded, because once it is
        // decoded the asking is too late — there is nothing left to answer with.
        if ($info[0] * $info[1] > self::MAX_POINTS) {
            $fail(self::TOO_MANY_POINTS);
        }
    }
}
