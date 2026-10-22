import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { BODY, MEASURE_BLOCKS, type Addresses, type Editing } from '@/lib/document';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { Extension, mergeAttributes } from '@tiptap/core';
import { Highlight } from '@tiptap/extension-highlight';
import { TableCell, TableHeader, Table as TableNode, TableRow } from '@tiptap/extension-table';
import { TextAlign } from '@tiptap/extension-text-align';
import { Color, TextStyle } from '@tiptap/extension-text-style';
import { Underline } from '@tiptap/extension-underline';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Baseline,
    BetweenHorizontalStart,
    BetweenVerticalStart,
    Bold,
    Check,
    Columns3,
    Ellipsis,
    Grid2x2X,
    Heading2,
    Heading3,
    Highlighter,
    IndentDecrease,
    IndentIncrease,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    LoaderCircle,
    Minus,
    Pilcrow,
    Quote,
    Redo,
    RemoveFormatting,
    Rows3,
    Strikethrough,
    Table2,
    TableCellsMerge,
    TableCellsSplit,
    Underline as UnderlineIcon,
    Undo,
    Unlink,
} from 'lucide-react';
import { useState, type FormEventHandler, type ReactNode } from 'react';

/**
 * The editor, and everything that comes with it.
 *
 * This module is the one place the rich-text editor is named, and it is reached
 * only through a dynamic import — these pages are read by everybody and
 * written by a handful, and the half-megabyte that makes a toolbar work has no
 * business loading for a page somebody is only reading.
 */

/** The schemes a link in this document may use; everything else is refused here and stripped on the way in. */
const SCHEMES = ['http:', 'https:', 'mailto:'];

/**
 * The few colours a document is written in. A short list is one a person can
 * hold in their head and one every section of every document will look alike
 * in; a free picker is how a sheet ends up in sixteen shades of almost-red.
 *
 * Mid-tones, because the same document is read on a white page and on a dark
 * one and these are legible on both.
 */
const INK = [
    { name: 'Красный', value: '#D92D20' },
    { name: 'Оранжевый', value: '#DC6803' },
    { name: 'Зелёный', value: '#5E8C13' },
    { name: 'Синий', value: '#2563EB' },
    { name: 'Фиолетовый', value: '#7C3AED' },
    { name: 'Серый', value: '#667085' },
];

/** What a highlight may be: pale, because the words stay on top of it. */
const WASH = [
    { name: 'Жёлтый', value: '#FDE68A' },
    { name: 'Зелёный', value: '#D9F0A3' },
    { name: 'Голубой', value: '#BFDBFE' },
    { name: 'Розовый', value: '#FBCFE8' },
    { name: 'Серый', value: '#E4E4E7' },
];

/**
 * How far from the left margin a block may be set. Five rungs and no others:
 * the server keeps exactly these and a document whose every paragraph was
 * nudged by a different amount is not a document anybody can read.
 *
 * The first rung is where everything starts, and it is written as nothing at
 * all rather than as a margin of zero.
 */
const LADDER = ['0', '2em', '4em', '6em', '8em'];

/**
 * The blocks that may be set in from the margin, in the order the one under the
 * caret is looked for. A list item is asked about first, because the paragraph
 * inside it is active at the same time and moving the item moves the marker
 * with it; a cell is asked last, for the same reason in reverse.
 */
const INDENTED = ['listItem', 'heading', 'paragraph', 'tableHeader', 'tableCell'];

/**
 * A block set in from the left margin — one of the rungs above, written as the
 * margin it is.
 *
 * An attribute of the block rather than a mark on the words, which is the same
 * shape the alignment already has, so a paragraph can be both centred and set
 * in: Tiptap merges the two declarations into the one style attribute the
 * server allows.
 */
const Indent = Extension.create({
    name: 'indent',
    addGlobalAttributes() {
        return [
            {
                types: INDENTED,
                attributes: {
                    indent: {
                        default: 0,
                        // A margin that is not one of the rungs is no rung at
                        // all: whatever it was, the block starts at the margin.
                        parseHTML: (element: HTMLElement) => Math.max(0, LADDER.indexOf(element.style.marginLeft)),
                        renderHTML: (attributes: Record<string, unknown>) => {
                            const rung = Number(attributes.indent) || 0;

                            return rung > 0 ? { style: `margin-left: ${LADDER[rung]}` } : {};
                        },
                    },
                },
            },
        ];
    },
});

/** Which block the caret is in of those that can be set in, and how far in it is. */
function indentOf(editor: Editor): { type: string; rung: number } | null {
    for (const type of INDENTED) {
        if (!editor.isActive(type)) continue;

        return { type, rung: (editor.getAttributes(type).indent as number | undefined) ?? 0 };
    }

    return null;
}

/** The four ways a line may sit, and the icon each is chosen by. */
const ALIGNMENTS = [
    { value: 'left', name: 'По левому краю', Icon: AlignLeft },
    { value: 'center', name: 'По центру', Icon: AlignCenter },
    { value: 'right', name: 'По правому краю', Icon: AlignRight },
    { value: 'justify', name: 'По ширине', Icon: AlignJustify },
] as const;

/**
 * An address as it may be stored, or nothing if what was typed is not one.
 *
 * A bare domain is taken to mean https, and something shaped like an address of
 * a person is taken to mean mail, because that is what somebody typing either
 * meant. Anything on another scheme is refused here rather than on saving: the
 * server strips it silently, and a link that vanished without a word reads as
 * the application losing what somebody wrote.
 */
function address(typed: string): string | null {
    const value = typed.trim();

    if (value === '') return null;

    const whole = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? `mailto:${value}` : /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : `https://${value}`;

    try {
        return SCHEMES.includes(new URL(whole).protocol) ? whole : null;
    } catch {
        return null;
    }
}

/**
 * A table of the document, written out as the server will keep it: the two
 * attributes it allows and nothing else.
 *
 * Tiptap draws a `<colgroup>` and a minimum width on every table it hands back,
 * which is how it remembers columns somebody dragged wider. Nothing here drags
 * them, the server's allowlist does not carry them, and a document that loses
 * part of itself on the way to the database looks like a bug to whoever typed
 * it — so they are not written in the first place.
 */
const DocumentTable = TableNode.extend({
    renderHTML({ HTMLAttributes }) {
        return ['table', mergeAttributes(HTMLAttributes), ['tbody', 0]];
    },
});

/**
 * What a cell may say of itself: how many columns it spans and how many rows,
 * and only when it spans more than one — a `colspan="1"` on every cell of every
 * table is noise in a text people read in the database as well as on screen.
 *
 * How the cell is aligned is not here: that is a global attribute the alignment
 * extension puts on every node type it is given, and it reaches the cells too.
 */
function spans() {
    return {
        colspan: { default: 1, renderHTML: ({ colspan }: { colspan: number }) => (colspan > 1 ? { colspan } : {}) },
        rowspan: { default: 1, renderHTML: ({ rowspan }: { rowspan: number }) => (rowspan > 1 ? { rowspan } : {}) },
    };
}

/**
 * A highlight, which is a colour and nothing else.
 *
 * Tiptap also writes the colour into a `data-color` of its own and sets the
 * text colour to `inherit` beside it. Neither is on the server's list, both
 * would be stripped, and the colour is read back out of the style in any case —
 * so only the style is written.
 */
const DocumentHighlight = Highlight.extend({
    addAttributes() {
        return {
            color: {
                default: null,
                parseHTML: (element: HTMLElement) => element.getAttribute('data-color') || element.style.backgroundColor || null,
                renderHTML: (attributes: { color: string | null }) => (attributes.color ? { style: `background-color: ${attributes.color}` } : {}),
            },
        };
    },
});

/**
 * What the editor can make, which is exactly what the server keeps: headings of
 * the second and third level, paragraphs and line breaks, the four marks, a
 * colour and a highlight, alignment, the two kinds of list, quotations, rules,
 * links and tables.
 *
 * Code and code blocks are turned off rather than merely left out of the
 * toolbar. A keystroke or a paste can reach anything the schema allows, and the
 * server strips what its allowlist does not name — so a `<pre>` somebody managed
 * to type would simply vanish on saving. The schema and the toolbar are kept in
 * step for the same reason.
 */
const EXTENSIONS = [
    StarterKit.configure({
        code: false,
        codeBlock: false,
        // The section's own title is the heading above all this, so a heading
        // inside it starts at the second level.
        heading: { levels: [2, 3] },
        link: {
            openOnClick: false,
            defaultProtocol: 'https',
            protocols: ['http', 'https', 'mailto'],
            isAllowedUri: (uri: string) => {
                try {
                    return SCHEMES.includes(new URL(uri, 'https://x').protocol);
                } catch {
                    return false;
                }
            },
            // The server sets these itself on the way out; saying the same here
            // keeps the editor and the stored document reading alike.
            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
        // Underline comes from its own extension below, so that the marks the
        // document allows are named in one place.
        underline: false,
    }),
    Underline,
    TextStyle,
    Color,
    DocumentHighlight.configure({ multicolor: true }),
    // Every node the server lets carry an alignment, and no other.
    TextAlign.configure({ types: ['heading', 'paragraph', 'listItem', 'tableHeader', 'tableCell'] }),
    Indent,
    DocumentTable.configure({ resizable: false }),
    TableRow,
    TableHeader.extend({ addAttributes: spans }),
    TableCell.extend({ addAttributes: spans }),
];

/** One button of the toolbar: an icon, the word for it, and whether it is on. */
function ToolButton({
    label,
    active,
    disabled,
    danger,
    onPress,
    children,
}: {
    label: string;
    active?: boolean;
    disabled?: boolean;
    danger?: boolean;
    onPress: () => void;
    children: ReactNode;
}) {
    return (
        <Tooltip>
            {/* Not on focus: a press hands the focus back here, and the word
                would pop up again for no reason. A screen reader has the label. */}
            <TooltipTrigger asChild onFocus={(event) => event.preventDefault()}>
                <button
                    type="button"
                    aria-label={label}
                    aria-pressed={active}
                    disabled={disabled}
                    // The caret stays in the text: a press acts on what is
                    // selected there, and a focus that moved here would leave
                    // the command with nothing to act on.
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={onPress}
                    className={cn(
                        'text-muted-foreground hover:bg-accent hover:text-foreground inline-flex size-9 items-center justify-center rounded-md transition-colors disabled:pointer-events-none disabled:opacity-40 max-md:size-11',
                        danger && 'text-[#B42318] dark:text-[#F7A19A]',
                        active && 'bg-accent text-foreground',
                    )}
                >
                    {children}
                </button>
            </TooltipTrigger>
            <TooltipContent className="px-2 py-1 text-xs font-normal">{label}</TooltipContent>
        </Tooltip>
    );
}

/** The same button, as the thing a popover opens from. */
function ToolTrigger({ label, active, children }: { label: string; active?: boolean; children: ReactNode }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild onFocus={(event) => event.preventDefault()}>
                <PopoverTrigger
                    type="button"
                    aria-label={label}
                    className={cn(
                        'text-muted-foreground hover:bg-accent hover:text-foreground data-[state=open]:bg-accent data-[state=open]:text-foreground inline-flex size-9 items-center justify-center rounded-md transition-colors max-md:size-11',
                        active && 'bg-accent text-foreground',
                    )}
                >
                    {children}
                </PopoverTrigger>
            </TooltipTrigger>
            <TooltipContent className="px-2 py-1 text-xs font-normal">{label}</TooltipContent>
        </Tooltip>
    );
}

/** A named command inside a popover, where there is room to say what it does. */
function MenuItem({ label, disabled, onPress, children }: { label: string; disabled?: boolean; onPress: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onPress}
            className="hover:bg-accent flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm disabled:pointer-events-none disabled:opacity-40 max-md:min-h-11"
        >
            <span className="text-muted-foreground flex size-4 shrink-0 items-center justify-center [&>svg]:size-4">{children}</span>
            {label}
        </button>
    );
}

/** One of the colours on offer, with a tick on the one in use. */
function Swatch({ name, value, active, onPress }: { name: string; value: string; active: boolean; onPress: () => void }) {
    return (
        <button
            type="button"
            title={name}
            aria-label={name}
            aria-pressed={active}
            onMouseDown={(event) => event.preventDefault()}
            onClick={onPress}
            className="border-border/70 flex size-8 items-center justify-center rounded-md border max-md:size-11"
            style={{ backgroundColor: value }}
        >
            {active && <Check className="size-4 text-black" />}
        </button>
    );
}

/** A hair's breadth between two groups of buttons. */
function ToolGap() {
    return <span className="bg-border mx-1 h-6 w-px shrink-0" aria-hidden="true" />;
}

/**
 * Everything that can be done to the text, in the order it is usually wanted.
 *
 * What is pressed often stands in the row itself; what is reached for once a
 * section — a quotation, a rule, a whole table — is behind «Ещё», where there is
 * room to name it in words rather than guess at an icon. The row wraps; at the
 * width of a phone it is two lines of finger-sized targets rather than five.
 *
 * Nothing here offers what the server would strip, and nothing the server keeps
 * is missing from it.
 */
function Toolbar({ editor }: { editor: Editor }) {
    const [linkOpen, setLinkOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const [refused, setRefused] = useState(false);

    const state = useEditorState({
        editor,
        selector: ({ editor }) => ({
            undo: editor.can().undo(),
            redo: editor.can().redo(),
            paragraph: editor.isActive('paragraph'),
            h2: editor.isActive('heading', { level: 2 }),
            h3: editor.isActive('heading', { level: 3 }),
            bold: editor.isActive('bold'),
            italic: editor.isActive('italic'),
            underline: editor.isActive('underline'),
            strike: editor.isActive('strike'),
            bullet: editor.isActive('bulletList'),
            ordered: editor.isActive('orderedList'),
            quote: editor.isActive('blockquote'),
            inList: editor.isActive('listItem'),
            sink: editor.can().sinkListItem('listItem'),
            lift: editor.can().liftListItem('listItem'),
            rung: indentOf(editor)?.rung ?? 0,
            indentable: indentOf(editor) !== null,
            align: ALIGNMENTS.map((each) => each.value).find((value) => editor.isActive({ textAlign: value })) ?? null,
            ink: (editor.getAttributes('textStyle').color as string | undefined) ?? null,
            wash: (editor.getAttributes('highlight').color as string | undefined) ?? null,
            link: editor.isActive('link'),
            href: (editor.getAttributes('link').href as string | undefined) ?? '',
            inTable: editor.isActive('table'),
            merge: editor.can().mergeCells(),
            split: editor.can().splitCell(),
        }),
    });

    const chain = () => editor.chain().focus();

    /** The address box opens on whatever link the caret is already in. */
    const openLink = (open: boolean) => {
        setLinkOpen(open);
        setRefused(false);
        if (open) setDraft(state.href);
    };

    const applyLink = () => {
        const href = address(draft);

        if (href === null) {
            setRefused(true);

            return;
        }

        // Nothing selected and no link under the caret: there is no text for the
        // link to be on, so the address itself becomes that text.
        if (editor.state.selection.empty && !state.link)
            chain()
                .insertContent({ type: 'text', text: href, marks: [{ type: 'link', attrs: { href } }] })
                .run();
        else chain().extendMarkRange('link').setLink({ href }).run();

        setLinkOpen(false);
    };

    /**
     * A step away from the margin, or back towards it.
     *
     * Inside a list the obvious meaning is the list's own: the item becomes a
     * sub-item, the way Tab already does it. Where that is not possible — the
     * first item of a list, a paragraph, a heading — the block itself moves a
     * rung along the ladder. Coming back undoes them in the opposite order, so
     * whatever indenting did, outdenting takes apart.
     */
    const indent = (towards: 1 | -1) => {
        const here = indentOf(editor);

        if (towards === 1 && state.sink) return chain().sinkListItem('listItem').run();
        if (towards === -1 && (here?.rung ?? 0) === 0 && state.lift) return chain().liftListItem('listItem').run();
        if (!here) return false;

        const rung = here.rung + towards;

        return rung >= 0 && rung < LADDER.length && chain().updateAttributes(here.type, { indent: rung }).run();
    };

    const canIndent = state.sink || (state.indentable && state.rung < LADDER.length - 1);
    const canOutdent = state.rung > 0 || state.lift;
    // Said only where it is true: in a list Tab and Shift+Tab do this, and
    // outside one they belong to the table and to the page.
    const key = (suffix: string) => (state.inList ? ` (${suffix})` : '');

    const Aligned = ALIGNMENTS.find((each) => each.value === state.align)?.Icon ?? AlignLeft;

    return (
        <TooltipProvider delayDuration={400}>
            <div role="group" aria-label="Форматирование" className="border-border/70 flex flex-wrap items-center gap-0.5 border-b p-1">
                <ToolButton label="Отменить" disabled={!state.undo} onPress={() => chain().undo().run()}>
                    <Undo className="size-4" />
                </ToolButton>
                <ToolButton label="Повторить" disabled={!state.redo} onPress={() => chain().redo().run()}>
                    <Redo className="size-4" />
                </ToolButton>

                <ToolGap />

                {/* Three toggles rather than a list to choose from: a list in a
                    toolbar has to take the focus to open, which is the one thing
                    the caret cannot spare, and three buttons say at a glance
                    which of them the line already is. */}
                <ToolButton label="Обычный текст" active={state.paragraph} onPress={() => chain().setParagraph().run()}>
                    <Pilcrow className="size-4" />
                </ToolButton>
                <ToolButton label="Заголовок 2" active={state.h2} onPress={() => chain().toggleHeading({ level: 2 }).run()}>
                    <Heading2 className="size-4" />
                </ToolButton>
                <ToolButton label="Заголовок 3" active={state.h3} onPress={() => chain().toggleHeading({ level: 3 }).run()}>
                    <Heading3 className="size-4" />
                </ToolButton>

                <ToolGap />

                <ToolButton label="Полужирный" active={state.bold} onPress={() => chain().toggleBold().run()}>
                    <Bold className="size-4" />
                </ToolButton>
                <ToolButton label="Курсив" active={state.italic} onPress={() => chain().toggleItalic().run()}>
                    <Italic className="size-4" />
                </ToolButton>
                <ToolButton label="Подчёркнутый" active={state.underline} onPress={() => chain().toggleUnderline().run()}>
                    <UnderlineIcon className="size-4" />
                </ToolButton>
                <ToolButton label="Зачёркнутый" active={state.strike} onPress={() => chain().toggleStrike().run()}>
                    <Strikethrough className="size-4" />
                </ToolButton>

                <ToolGap />

                <Popover>
                    <ToolTrigger label="Цвет текста" active={state.ink !== null}>
                        <Baseline className="size-4" style={state.ink ? { color: state.ink } : undefined} />
                    </ToolTrigger>
                    <PopoverContent className="w-auto p-2" align="start">
                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap gap-1.5">
                                {INK.map((colour) => (
                                    <Swatch
                                        key={colour.value}
                                        name={colour.name}
                                        value={colour.value}
                                        active={state.ink === colour.value}
                                        onPress={() => chain().setColor(colour.value).run()}
                                    />
                                ))}
                            </div>
                            <MenuItem label="Убрать цвет" disabled={state.ink === null} onPress={() => chain().unsetColor().run()}>
                                <RemoveFormatting />
                            </MenuItem>
                        </div>
                    </PopoverContent>
                </Popover>

                <Popover>
                    <ToolTrigger label="Выделение цветом" active={state.wash !== null}>
                        <Highlighter className="size-4" style={state.wash ? { color: state.wash } : undefined} />
                    </ToolTrigger>
                    <PopoverContent className="w-auto p-2" align="start">
                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap gap-1.5">
                                {WASH.map((colour) => (
                                    <Swatch
                                        key={colour.value}
                                        name={colour.name}
                                        value={colour.value}
                                        active={state.wash === colour.value}
                                        onPress={() => chain().setHighlight({ color: colour.value }).run()}
                                    />
                                ))}
                            </div>
                            <MenuItem label="Убрать цвет" disabled={state.wash === null} onPress={() => chain().unsetHighlight().run()}>
                                <RemoveFormatting />
                            </MenuItem>
                        </div>
                    </PopoverContent>
                </Popover>

                <Popover>
                    <ToolTrigger label="Выравнивание" active={state.align !== null}>
                        <Aligned className="size-4" />
                    </ToolTrigger>
                    <PopoverContent className="w-auto p-2" align="start">
                        <div className="flex gap-0.5">
                            {ALIGNMENTS.map(({ value, name, Icon }) => (
                                <ToolButton key={value} label={name} active={state.align === value} onPress={() => chain().setTextAlign(value).run()}>
                                    <Icon className="size-4" />
                                </ToolButton>
                            ))}
                        </div>
                    </PopoverContent>
                </Popover>

                <ToolGap />

                <ToolButton label="Список" active={state.bullet} onPress={() => chain().toggleBulletList().run()}>
                    <List className="size-4" />
                </ToolButton>
                <ToolButton label="Нумерованный список" active={state.ordered} onPress={() => chain().toggleOrderedList().run()}>
                    <ListOrdered className="size-4" />
                </ToolButton>
                <ToolButton label={`Уменьшить отступ${key('Shift+Tab')}`} disabled={!canOutdent} onPress={() => indent(-1)}>
                    <IndentDecrease className="size-4" />
                </ToolButton>
                <ToolButton label={`Увеличить отступ${key('Tab')}`} disabled={!canIndent} onPress={() => indent(1)}>
                    <IndentIncrease className="size-4" />
                </ToolButton>

                <ToolGap />

                <Popover open={linkOpen} onOpenChange={openLink}>
                    <ToolTrigger label="Ссылка" active={state.link}>
                        <LinkIcon className="size-4" />
                    </ToolTrigger>
                    {/* The box takes the focus, which is what it is for; the
                        caret keeps its place in the text and the link is laid on
                        it when the address is accepted. */}
                    <PopoverContent className="w-80" align="start">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="document-link">Адрес ссылки</Label>
                            <Input
                                id="document-link"
                                className="max-md:h-11"
                                value={draft}
                                placeholder="https://example.com"
                                aria-invalid={refused}
                                onChange={(event) => {
                                    setDraft(event.target.value);
                                    setRefused(false);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key !== 'Enter') return;
                                    event.preventDefault();
                                    applyLink();
                                }}
                            />
                            {refused && <InputError message="Адрес должен начинаться с http:// или https:// либо быть адресом почты." />}
                            <div className="flex items-center justify-between gap-2">
                                <Button type="button" size="sm" className="max-md:h-11" onClick={applyLink}>
                                    Применить
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="max-md:h-11"
                                    disabled={!state.link}
                                    onClick={() => {
                                        chain().extendMarkRange('link').unsetLink().run();
                                        setLinkOpen(false);
                                    }}
                                >
                                    <Unlink />
                                    Убрать ссылку
                                </Button>
                            </div>
                        </div>
                    </PopoverContent>
                </Popover>

                <ToolGap />

                <Popover>
                    <ToolTrigger label="Ещё">
                        <Ellipsis className="size-4" />
                    </ToolTrigger>
                    <PopoverContent className="w-60 p-1.5" align="end">
                        <div className="flex flex-col">
                            <MenuItem label="Цитата" onPress={() => chain().toggleBlockquote().run()}>
                                <Quote />
                            </MenuItem>
                            <MenuItem label="Горизонтальная линия" onPress={() => chain().setHorizontalRule().run()}>
                                <Minus />
                            </MenuItem>
                            <MenuItem label="Очистить форматирование" onPress={() => chain().unsetAllMarks().clearNodes().run()}>
                                <RemoveFormatting />
                            </MenuItem>

                            <span className="text-muted-foreground mt-2 mb-1 px-2 text-[12px] font-medium tracking-wide uppercase">Таблица</span>

                            <MenuItem label="Вставить таблицу" onPress={() => chain().insertTable({ rows: 3, cols: 2, withHeaderRow: true }).run()}>
                                <Table2 />
                            </MenuItem>
                            <MenuItem label="Строка заголовков" disabled={!state.inTable} onPress={() => chain().toggleHeaderRow().run()}>
                                <Rows3 />
                            </MenuItem>
                            <MenuItem label="Добавить строку" disabled={!state.inTable} onPress={() => chain().addRowAfter().run()}>
                                <BetweenHorizontalStart />
                            </MenuItem>
                            <MenuItem label="Добавить столбец" disabled={!state.inTable} onPress={() => chain().addColumnAfter().run()}>
                                <BetweenVerticalStart />
                            </MenuItem>
                            <MenuItem label="Объединить ячейки" disabled={!state.merge} onPress={() => chain().mergeCells().run()}>
                                <TableCellsMerge />
                            </MenuItem>
                            <MenuItem label="Разделить ячейку" disabled={!state.split} onPress={() => chain().splitCell().run()}>
                                <TableCellsSplit />
                            </MenuItem>
                            <MenuItem label="Удалить строку" disabled={!state.inTable} onPress={() => chain().deleteRow().run()}>
                                <Rows3 />
                            </MenuItem>
                            <MenuItem label="Удалить столбец" disabled={!state.inTable} onPress={() => chain().deleteColumn().run()}>
                                <Columns3 />
                            </MenuItem>
                            <MenuItem label="Удалить таблицу" disabled={!state.inTable} onPress={() => chain().deleteTable().run()}>
                                <Grid2x2X />
                            </MenuItem>
                        </div>
                    </PopoverContent>
                </Popover>
            </div>
        </TooltipProvider>
    );
}

/** An editor nobody typed in still hands back a paragraph; the document has no such section. */
function blank(html: string): boolean {
    return html.replace(/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/g, '').trim() === '';
}

/**
 * Writing one section of the document: its heading, and what it says.
 *
 * Two fields, because a section is two things. The number is not among them: the
 * document numbers its sections by where they stand, so moving one is what
 * changes its number and nothing is typed.
 *
 * One field where the document is one text, which is «Положение о работе»: the
 * one section it is made of has a name nothing draws, the headings in it are
 * typed into the text like any other line, and a box for a heading nobody reads
 * would be a thing to fill in for no reason. The save then carries the body
 * alone and the server leaves the name of the section as it found it.
 *
 * And the text is written at the width it is read at. A document of sections
 * keeps the measure of a printed page on both sides of the save; one that is
 * read across its card is written across the box, or somebody would be typing
 * into a column half the width of the page they are writing.
 *
 * The dialog around this is drawn by the page; what is here is only the part
 * that needs the editor, so that the dialog is already open and titled while
 * this is still on its way.
 */
export default function SectionForm({
    editing,
    owner,
    addresses,
    whole,
    onClose,
}: {
    editing: Editing;
    owner: number;
    addresses: Addresses;
    /**
     * Whether the document is one text rather than a list of sections: then it
     * has no heading of its own to type and is written at the full width of the
     * box, the way it is read.
     */
    whole: boolean;
    onClose: () => void;
}) {
    const section = editing.section;

    // A standard section opens with the standard text already in the box: it is
    // what somebody about to rewrite it wants in front of them. The braces stay
    // — a copy goes on reading its owner's own name through them.
    const form = useForm<{ title: string; body: string }>({
        title: section.title,
        body: section.body_written ?? '',
    });

    const editor = useEditor({
        extensions: EXTENSIONS,
        content: section.body_written ?? '',
        editorProps: {
            attributes: {
                class: cn(BODY, !whole && MEASURE_BLOCKS, 'min-h-40 px-3 py-2.5 outline-none'),
                'aria-labelledby': 'document-body-label',
            },
        },
        onUpdate: ({ editor }) => form.setData('body', editor.getHTML()),
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            // A field the form does not have is a field the save does not
            // carry, and what the request leaves out the server leaves alone.
            ...(whole ? {} : { title: data.title.trim() }),
            // The text exactly as it was typed, braces and all: whatever the
            // server still answers in it, it answers when the document is read
            // rather than when it is written.
            body: blank(data.body) ? '' : data.body,
            // Said once, when the copy is made, and it is what makes it this
            // owner's wording of that section rather than a new section.
            ...(editing.mode === 'standard' ? { template_section_id: section.id } : {}),
        }));

        const options = { preserveScroll: true, onSuccess: onClose };

        // The first time a standard section is rewritten there is no row of
        // this owner's to put the text in, so one is written; after that
        // the same box edits that row. Either way the person is editing the one
        // section they opened.
        if (editing.mode === 'theirs') form.put(route(addresses.update, [owner, section.id]), options);
        else form.post(route(addresses.store, owner), options);
    };

    return (
        <form noValidate onSubmit={submit} className="flex flex-col gap-5">
            {!whole && (
                <div className="grid content-start gap-2">
                    <Label htmlFor="document-section-title">Заголовок</Label>
                    {/* A section is called «ВЗАИМОДЕЙСТВИЕ», not a sentence, so the
                        box stops where a heading does: the dialog is as wide as the
                        toolbar needs, and nothing else grows with it. */}
                    <Input
                        id="document-section-title"
                        className="max-md:h-11 sm:max-w-xl"
                        autoFocus
                        value={form.data.title}
                        placeholder="ВЗАИМОДЕЙСТВИЕ"
                        onChange={(event) => form.setData('title', event.target.value)}
                        aria-invalid={!!form.errors.title}
                    />
                    <InputError message={form.errors.title} />
                </div>
            )}

            <div className="grid content-start gap-2">
                <Label id="document-body-label">Содержание</Label>
                <div
                    className={cn(
                        'border-input focus-within:border-ring focus-within:ring-ring/50 overflow-hidden rounded-md border focus-within:ring-[3px]',
                        form.errors.body && 'border-[#B42318]',
                    )}
                >
                    <Toolbar editor={editor} />
                    {/* A cell somebody is working in is marked, which is the only
                        sign in a table that the row and column buttons will act
                        here. */}
                    <EditorContent editor={editor} className="[&_.selectedCell]:bg-accent/70" />
                </div>
                <InputError message={form.errors.body} />
            </div>

            <DialogFooter className="gap-2">
                <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                    Отмена
                </Button>
                <Button type="submit" className="max-md:h-11" disabled={form.processing}>
                    {form.processing && <LoaderCircle className="animate-spin" />}
                    Сохранить
                </Button>
            </DialogFooter>
        </form>
    );
}
