<?php

namespace App\Support;

use Dom\HTMLDocument;
use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * What the company's documents are made of, whichever of them is being read.
 *
 * There are two. «Положение о работе» belongs to a department and says how that
 * department works; «Профиль должности», the sheet the company calls СФД,
 * belongs to a position and says what the person in it is expected to be. They
 * are written, kept and printed the same way — a template of standard sections
 * every owner reads, one owner's wording in the place of a standard section it
 * rewrote, that owner's own arrangement of the whole, and a heading of its own —
 * and so they are one piece of machinery with two names rather than two
 * copies of one.
 *
 * This is the half of it that has no opinion about which document it is serving:
 * what a body may be made of, how long it may be, and how a text is read once
 * the placeholders in it are answered. What each document says for itself — its
 * placeholders, the words its heading begins with, the table it draws from data
 * — is in Regulation and PositionProfile.
 */
final class Document
{
    /**
     * The two documents, as the row of a section says which one it belongs to.
     *
     * A section of the template belongs to no owner at all, so the owner cannot
     * be what tells the two documents apart: without this the standard text of
     * «Положение» and the standard text of «СФД» would be the same four rows of
     * nothing in particular.
     */
    public const REGULATION = 'regulation';

    public const PROFILE = 'profile';

    /**
     * Who owns each of them: the column on a section that names its owner.
     *
     * A document belongs to one kind of thing and always the same kind — a
     * regulation to a department, a profile to a position — so this is the one
     * place that pairing is written down, and everything else asks.
     *
     * @var array<string, string> document => the column naming its owner
     */
    public const OWNERS = [
        self::REGULATION => 'department_id',
        self::PROFILE => 'position_id',
    ];

    /**
     * What a printed sheet says where the application has no answer — a
     * department with no head appointed, a position whose grade nobody has
     * typed. A dash, because an empty space on a sheet somebody signs reads as a
     * mistake of the printer rather than as something nobody has filled in.
     *
     * The regulation reads it into a sentence, where the gap would otherwise
     * fall mid-line. A field that travels as data — the table of «01 ОБЩАЯ
     * ИНФОРМАЦИЯ» — travels empty instead, because the page is the one drawing
     * the cell and a string pretending to be a value is harder to draw around
     * than nothing.
     */
    public const UNKNOWN = '—';

    /**
     * How long a heading may be: room for the longest owner's name after the
     * words in front of it and no more, because this is one line on a printed
     * sheet. Both columns that keep a heading hold exactly this much.
     */
    public const TITLE_LENGTH = 200;

    /**
     * How long a section's body may be, counted in characters of what was
     * typed — the markup among them, because the markup is what was typed.
     *
     * A section of a document is a few paragraphs, and the toolbar writes
     * headings, tables, links and colours around them, each of which costs more
     * tags than text. So the limit is room for several pages of a document
     * rather than of plain sentences, and it is said out loud so the editor can
     * show it and the validator can refuse by it.
     */
    public const BODY_LENGTH = 50_000;

    /**
     * The whole of what a section's body may be made of: paragraphs, line
     * breaks and the two headings under the section's own, the four marks of
     * emphasis, a quotation and a rule, the two kinds of list, a link, a run of
     * words picked out in colour, and a table.
     *
     * The document is written in an editor, so its text arrives as HTML — and
     * HTML from a browser is HTML somebody can also write by hand, which is why
     * what may be stored is listed here rather than trusted. The list is exactly
     * what the toolbar offers: a button the server would undo is worse than no
     * button, so the two are one contract.
     *
     * Of attributes there are five. A link keeps where it points and nothing
     * else — the scheme is checked and rel and target are written on it here
     * rather than read off it. A cell keeps the two that join it to its
     * neighbours. A numbered list keeps where it starts, because a list pasted
     * in the middle of a document otherwise begins again at one. And style is
     * allowed on what the toolbar aligns, indents and colours — allowed as an
     * attribute, that is: what is inside it is cut down declaration by
     * declaration in DocumentAttributes, which also reads the number a list
     * counts from. The sanitizer calls it as it goes.
     *
     * @var array<string, list<string>> tag => the attributes it may carry
     */
    private const BODY_TAGS = [
        'p' => ['style'],
        'br' => [],
        'strong' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'h2' => ['style'],
        'h3' => ['style'],
        'ol' => ['start'],
        'ul' => [],
        'li' => ['style'],
        'blockquote' => [],
        'hr' => [],
        'a' => ['href'],
        'mark' => ['style'],
        'span' => ['style'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan', 'style'],
        'td' => ['colspan', 'rowspan', 'style'],
    ];

    /**
     * Where a link in a document may point. A page, a secure page, an address to
     * write to — and nothing that runs when it is followed, which is the whole
     * of what javascript: and data: are for here.
     *
     * @var list<string>
     */
    private const LINK_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * The elements that go and take their contents with them.
     *
     * Everything else that is not on the allowlist loses its tag and keeps its
     * words: a wrapper somebody pasted from a web page is not a reason to throw
     * away the sentence inside it, and «мой текст пропал» is a complaint nobody
     * can answer afterwards. These are the ones where the text is the danger —
     * what a script says is what it does — so for them the tag and what it holds
     * go together.
     *
     * @var list<string>
     */
    private const DROPPED = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'template',
        'noscript',
        'head',
        'title',
        'svg',
        'math',
    ];

    /** Built from the list above the first time a body is written. */
    private static ?HtmlSanitizer $sanitizer = null;

    /**
     * Which column names the owner of this document's sections — and, in
     * passing, that there is such a document at all: a row filed under a name
     * nothing here knows would otherwise be read as a section of everything.
     */
    public static function owner(string $document): string
    {
        return self::OWNERS[$document]
            ?? throw new InvalidArgumentException("Документа «{$document}» не существует.");
    }

    /**
     * The text as it is read, with the placeholders answered. Anything in braces
     * that is not one of them is left exactly as somebody typed it.
     *
     * The text is HTML, and this still works on it: a placeholder is typed as
     * plain text, so the editor keeps it inside one text node and the braces and
     * the word between them reach the stored markup side by side. Emphasis put
     * on half a token would break it — but a token is a word in braces that
     * nobody formats, and the editor offers it as one piece to insert.
     *
     * A document with no placeholders at all passes nothing to answer and reads
     * exactly as it was written, which is what «Профиль должности» does.
     *
     * @param  array<string, string>  $values  token => what is read in its place
     */
    public static function resolve(?string $text, array $values): ?string
    {
        return $text === null || $values === [] ? $text : strtr($text, $values);
    }

    /**
     * A body as it may be stored: what the editor wrote, with everything that is
     * not on the list above taken out of it, and nothing left in it to execute.
     *
     * This is done once, where the row is written, so what is on file is already
     * safe and no page, print or export downstream has to remember to clean it.
     * A tag nobody allowed loses the tag and keeps its words — losing somebody's
     * sentence because of the wrapper it was pasted in would be the worst thing
     * this could do — except for the few named in DROPPED, where the text is
     * what is dangerous.
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = self::withoutDeadLinks(trim(self::sanitizer()->sanitize(self::withoutDropped($html))));

        // An editor with nothing in it still writes one empty paragraph, and
        // that is nothing, which is kept as nothing.
        return in_array($clean, ['', '<p></p>', '<p><br></p>', '<p><br /></p>'], true) ? null : $clean;
    }

    /**
     * The same markup with the elements of DROPPED taken out of it, contents and
     * all, before the sanitizer is given it.
     *
     * The sanitizer is told to drop them too, and for most of them that is the
     * whole of it. Three it will not: `style`, `head` and `title` are filed as
     * elements of a document's `<head>`, and the rules the sanitizer builds for
     * a document's body leave out everything filed there — an instruction to
     * drop one included. So those three would fall through to the default, which
     * unwraps what it does not recognise, and the text of a stylesheet would
     * come out in the middle of the document as words.
     *
     * It is done here rather than afterwards because this reads the markup
     * somebody sent, and the sanitizer still runs on whatever comes out: nothing
     * this pass gets wrong can let anything through. The whole list goes through
     * it so that there is one rule about these elements and not two.
     */
    private static function withoutDropped(string $html): string
    {
        if (! preg_match('/<('.implode('|', self::DROPPED).')[\s>\/]/i', $html)) {
            return $html;
        }

        $document = HTMLDocument::createFromString('<body>'.$html, LIBXML_NOERROR);

        foreach (self::DROPPED as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                $element->remove();
            }
        }

        return $document->body->innerHTML;
    }

    /**
     * The same markup with any link that points nowhere unwrapped — the words
     * kept, the tag gone.
     *
     * A link whose address the sanitizer refused keeps its text and loses its
     * href, and an `<a>` with no href is not a link: it is a tag that does
     * nothing, and anybody who looks at the stored markup afterwards reads it as
     * the application having kept something it did not keep.
     *
     * The markup is read again to do it, which is why it is done here and not
     * before: what is parsed at this point is what the sanitizer has already
     * cleaned, so nothing about this pass can let anything through. And it is
     * only reached when there is a dead link to unwrap, so the markup of a
     * body that has none is the sanitizer's own output, untouched.
     */
    private static function withoutDeadLinks(string $clean): string
    {
        if (! preg_match('/<a(?![^>]*\shref=)[\s>]/i', $clean)) {
            return $clean;
        }

        $document = HTMLDocument::createFromString('<body>'.$clean, LIBXML_NOERROR);

        foreach (iterator_to_array($document->getElementsByTagName('a')) as $link) {
            if ($link->hasAttribute('href')) {
                continue;
            }

            while ($link->firstChild !== null) {
                $link->parentNode->insertBefore($link->firstChild, $link);
            }

            $link->remove();
        }

        return $document->body->innerHTML;
    }

    /**
     * The sanitizer the allowlist describes, built once: it compiles the list
     * into a visitor per document context and the page writes section after
     * section.
     */
    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // A tag nobody allowed is unwrapped and its words are kept. The few
            // whose contents are the danger are named below and dropped whole.
            ->defaultAction(HtmlSanitizerAction::Block)
            // Where a link may point, and that it must point somewhere whole: a
            // relative address means nothing in a document that is also
            // printed, and an href this refuses is dropped from the link.
            ->allowLinkSchemes(self::LINK_SCHEMES)
            ->allowRelativeLinks(false)
            // Written on every link rather than read off it, so a link opens
            // beside the document and hands the page it opens nothing.
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            // What is inside the attributes that carry a value, which the
            // component itself has no opinion about.
            ->withAttributeSanitizer(new DocumentAttributes);

        foreach (self::BODY_TAGS as $tag => $attributes) {
            $config = $config->allowElement($tag, $attributes);
        }

        foreach (self::DROPPED as $tag) {
            $config = $config->dropElement($tag);
        }

        // The component counts its own limit in bytes while the field is
        // counted in characters, and it cuts a string too long for it off
        // silently. A Russian letter is two bytes and the markup around it is
        // one each, so four times the length of the field is room for anything
        // the validator has already let through.
        return self::$sanitizer = new HtmlSanitizer($config->withMaxInputLength(self::BODY_LENGTH * 4));
    }
}
