<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An address written the way addresses are actually written: in Latin letters.
 *
 * On its own `email` accepts «иван@почта.рф», and — far more dangerous — it
 * accepts a Latin address with a single letter typed in another alphabet, a «с»
 * or an «о» carried over from the keyboard layout. On screen the two letters are
 * the same letter, so nobody finds the mistake by looking at it: the address
 * passes, the account is made, and every letter sent to it goes nowhere, the
 * password the colleague was supposed to sign in with among them.
 *
 * So the rule says what an address may consist of instead of which alphabets it
 * may not. Naming one alphabet only waits for the next one, and a look-alike
 * letter is the same trap whichever alphabet it came from.
 */
class LatinEmail implements ValidationRule
{
    /**
     * What to say when an address holds something else. Somebody who cannot see
     * the difference between «c» and «с» on their screen needs to be told that
     * the difference is there at all, and then that the way out is to type the
     * address again rather than to hunt for the letter.
     */
    public const MESSAGE = 'E-mail пишется только латинскими буквами, цифрами и знаками . - _ + @. Буква из другого алфавита выглядит точно так же, как латинская, но письма по такому адресу не дойдут — наберите адрес заново латиницей.';

    /**
     * Everything an address may be made of, as the inside of a character class:
     * the Latin letters, the digits and the punctuation addresses legitimately
     * use. A space is not on the list, and neither is anything else.
     */
    private const ALLOWED = 'A-Za-z0-9.@!#$%&\'*+\-\/=?^_`{|}~';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Whether there has to be an address at all is for `required` to say.
        if (! is_string($value) || $value === '') {
            return;
        }

        // Byte by byte on purpose: a letter from another alphabet arrives as
        // bytes the allowed list does not name, so it is out without the rule
        // having to know which alphabet that was.
        if (preg_match('/\A['.self::ALLOWED.']+\z/', $value) === 1) {
            return;
        }

        $strays = self::strays($value);

        $fail(self::MESSAGE.($strays === '' ? '' : " Проверьте эти символы: {$strays}."));
    }

    /**
     * The characters that do not belong, to point at in the message — the whole
     * help the message can give, since the person cannot tell them apart from
     * the ones that do.
     */
    private static function strays(string $value): string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        // Not readable as text at all, so there is nothing to point at.
        if ($characters === false) {
            return '';
        }

        $strays = collect($characters)
            ->reject(fn (string $character) => preg_match('/\A['.self::ALLOWED.']\z/', $character) === 1)
            ->map(fn (string $character) => trim($character) === '' ? 'пробел' : "«{$character}»")
            ->unique();

        // A handful of them means the whole address was typed in another
        // alphabet, and listing all of it back points at nothing.
        return $strays->count() > 3 ? '' : $strays->implode(', ');
    }
}
