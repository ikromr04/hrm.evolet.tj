<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * What may be inside the attributes a section of a document is allowed to
 * carry.
 *
 * The sanitizer that cleans a body allows an attribute or forbids it; it has no
 * opinion about the value. Two of the four on the allowlist carry a value worth
 * reading before it is kept, and this reads them — inside the sanitizer's own
 * walk of the document, as the component's hook for exactly that, so the cleaned
 * markup is never parsed and rendered a second time.
 *
 * `style`, because the toolbar aligns a paragraph, indents it and colours a run
 * of words, and all three arrive as a style attribute — but so would `position:
 * fixed`, a `url(…)` and anything else somebody can type in one. The
 * declarations are read one at a time, split on the semicolon and then on the
 * first colon, and only what the toolbar offers survives, on the elements it
 * offers it on. An attribute with nothing left in it is dropped rather than
 * written out empty.
 *
 * `start`, because a numbered list that goes on counting from somewhere counts
 * from a number. Nothing today would be harmed by a `start` that says something
 * else — it is written back out escaped and a browser ignores it — but that is a
 * property of today's renderer rather than of the data, and the data is what is
 * kept.
 */
final class DocumentAttributes implements AttributeSanitizerInterface
{
    /**
     * What each element may be styled with: where it sits on the page — which
     * side of it the text is set to, and how far in from the margin — on
     * whatever holds a line of the document, and colour on the two tags that
     * only ever mark a run of words inside one.
     *
     * @var array<string, list<string>>
     */
    private const DECLARATIONS = [
        'p' => ['text-align', 'margin-left'],
        'h2' => ['text-align', 'margin-left'],
        'h3' => ['text-align', 'margin-left'],
        'li' => ['text-align', 'margin-left'],
        'td' => ['text-align', 'margin-left'],
        'th' => ['text-align', 'margin-left'],
        'span' => ['color', 'background-color'],
        'mark' => ['color', 'background-color'],
    ];

    /** The four the toolbar has buttons for. */
    private const ALIGNMENTS = ['left', 'right', 'center', 'justify'];

    /**
     * How far in from the margin a line may be set, and the whole of how far: a
     * ladder of four steps rather than a measurement.
     *
     * A document needs steps — «сдвинуть список правее» and back again — and a
     * closed list of them is a value nobody can write anything else into: no
     * percentage, no calc(), nothing negative, no pixel count somebody picked
     * off a ruler. The toolbar's two buttons walk this same ladder.
     *
     * The first step is the margin itself, where a line already is, so it is
     * recognised and then not written down.
     *
     * @var list<string>
     */
    private const INDENTS = ['0', '2em', '4em', '6em', '8em'];

    /** @return list<string> */
    public function getSupportedElements(): ?array
    {
        return [...array_keys(self::DECLARATIONS), 'ol'];
    }

    /** @return list<string> */
    public function getSupportedAttributes(): ?array
    {
        return ['style', 'start'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return $attribute === 'start'
            ? self::countsFrom($value)
            : self::styledBy($element, $value);
    }

    /**
     * Where a numbered list goes on counting from: a number, written as one, and
     * counting from at least one. Anything else is not where a list starts and
     * the attribute goes with it.
     */
    private static function countsFrom(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[1-9][0-9]{0,4}$/', $value) ? $value : null;
    }

    /** The declarations of a style attribute that survive being read. */
    private static function styledBy(string $element, string $value): ?string
    {
        $allowed = self::DECLARATIONS[$element] ?? [];
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $setting] = explode(':', $declaration, 2);

            $property = strtolower(trim($property));
            $setting = strtolower(trim($setting));

            if (! in_array($property, $allowed, true) || ! self::reads($property, $setting)) {
                continue;
            }

            // Set in from the margin by nothing is where the line already is,
            // so there is nothing to write down — and a paragraph nobody
            // indented is left carrying no style attribute at all.
            if ($property === 'margin-left' && $setting === self::INDENTS[0]) {
                continue;
            }

            $kept[] = "{$property}: {$setting}";
        }

        return $kept === [] ? null : implode('; ', $kept);
    }

    /** Whether a declaration says what the button that writes it would say. */
    private static function reads(string $property, string $setting): bool
    {
        return match ($property) {
            'text-align' => in_array($setting, self::ALIGNMENTS, true),
            'margin-left' => in_array($setting, self::INDENTS, true),
            default => self::isColour($setting),
        };
    }

    /** A colour as the editor mixes one, or as a person names one. */
    private static function isColour(string $setting): bool
    {
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $setting)) {
            return true;
        }

        // rgb() and rgba(), with nothing between the brackets but numbers and
        // what separates them — a comma, a space, the slash before an alpha, a
        // percent sign. There is nothing left in that to be a url() or an
        // expression(), and a browser ignores whatever of it is not a colour.
        if (preg_match('#^rgba?\([0-9.,/ %]+\)$#', $setting)) {
            return true;
        }

        // A colour named rather than mixed. A bare word is either one CSS knows
        // or one it does nothing with; with no punctuation in it, it cannot be
        // anything else.
        return (bool) preg_match('/^[a-z]+$/', $setting);
    }
}
