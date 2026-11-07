import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fileSize } from '@/lib/documents';
import { cn } from '@/lib/utils';
import { Paperclip, Plus, Trash2, X } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';

/**
 * The furniture the eleven parts of «Личный листок» are built from, wherever
 * they are answered: a field, a list of records, a paper clipped to one.
 *
 * It lives beside the parts themselves rather than on either page, because the
 * page that files a new colleague and the card that corrects one later draw the
 * same questions, and furniture kept twice is furniture that stops matching.
 */

/**
 * A list to pick one entry from or to type into, where leaving it unanswered is
 * not one of the answers: everything offered, and whatever was written in that
 * the list has not heard of.
 */
export const offered = (list: string[], value: string) =>
    [...new Set([...list, ...(value === '' ? [] : [value])])].sort((a, b) => a.localeCompare(b, 'ru')).map((name) => ({ value: name, label: name }));

/**
 * A list a card points at one entry of: nothing chosen, everything on file, and
 * whatever was typed in that the list has not heard of yet.
 */
export const named = (list: string[], value: string) => [
    { value: '', label: 'Не выбрано' },
    ...[...new Set([...list, ...(value === '' ? [] : [value])])]
        .sort((a, b) => a.localeCompare(b, 'ru'))
        .map((name) => ({ value: name, label: name })),
];

/** Errors come back as "educations.0.institution"; a field shows its own. */
export const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/** The server's own floor, from EmployeeCard: nothing on a card is older. */
export const firstYear = 1950;

/**
 * The earliest month anybody in the family may have been born in, as the month
 * box reads it. Further back than the floor a card's own dates stand on, and
 * for the reason the family has always had one of its own: a grandmother may
 * well have been born in the twenties.
 */
export const firstFamilyMonth = '1900-01';

/**
 * The years a record may be dated to, newest first: a card being filled in now
 * is far likelier to name the last few years than the fifties, and a list of
 * eighty years is read from the end one scrolls to first.
 *
 * The ends are the server's own, from EmployeeCard: nothing before 1950 and
 * nothing after this year. Neither date is a plan — a year of enrolment that
 * has not happened is not a fact, and a year of graduation says the studying
 * ended, which is why the box is left empty while it has not.
 */
export const years = (from: number = firstYear) => {
    const last = new Date().getFullYear();

    return Array.from({ length: last - from + 1 }, (_, i) => String(last - i));
};

/** This month, which no date of a past job can be later than. */
export const thisMonth = new Date().toISOString().slice(0, 7);

/** A year and a month as a month box reads them: "2020-09". */
export const asMonth = (year: string, month: string) => (year && month ? `${year}-${String(month).padStart(2, '0')}` : '');

/** And back apart again, since the server keeps a month and a year of its own. */
export const fromMonth = (value: string, field: 'started' | 'ended') => {
    const [year, month] = value.split('-');

    return { [`${field}_year`]: year ?? '', [`${field}_month`]: month ? String(Number(month)) : '' };
};

/**
 * Who a card names, in the words it names them with — offered rather than
 * imposed, because a card may name a stepmother or a guardian and a directory
 * of ways to be related is not one a company keeps.
 *
 * A spouse and a child are not among them. They are asked for in blocks of
 * their own on this very page, and a list that offered them here as well would
 * invite the same person to be entered twice — once as the spouse and once as a
 * relative who happens to be the spouse — and the sheet, which prints all three
 * sources as one table, would show them twice over. A card that already names
 * one that way keeps it: the box takes whatever is on file, as it takes
 * anything else typed into it.
 */
export const relations = ['Мать', 'Отец', 'Брат', 'Сестра', 'Бабушка', 'Дедушка'];

export function Field({
    label,
    error,
    children,
    className,
    required,
    hint,
    name,
}: {
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
    /**
     * Which line of «Личный листок» this field answers, as
     * App\Support\PersonnelSheet names its lines.
     *
     * Written into the markup so that the list of what is still wanted can
     * lead to the box: a line pressed there has to put something on screen,
     * and the only thing that answers it is this field. Given where a line of
     * the sheet is counted and left out everywhere else — a field no list can
     * name has nothing to be found by.
     */
    name?: string;
    /** Marked where the server's rules will not take the form without it. */
    required?: boolean;
    /**
     * Standing advice about the field — what an empty box will mean, what the
     * address is for. Given here rather than written under the control,
     * because where it stands is not this page's choice to make twice: a
     * refusal is read before advice is, so it goes above it, and a field that
     * put the two the other way about would read as advice that was ignored.
     */
    hint?: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)} data-field={name}>
            <Label required={required}>{label}</Label>
            {children}
            <InputError message={error} />
            {hint !== undefined && <p className="text-muted-foreground text-[13px]">{hint}</p>}
        </div>
    );
}

/**
 * A part that keeps a list of records — places of study, jobs held before,
 * children, the rest of the family. Rows are added and removed here and travel
 * with the rest of the sheet.
 */
export function Records<T>({
    items,
    addLabel,
    onAdd,
    onRemove,
    children,
}: {
    items: T[];
    addLabel: string;
    onAdd: () => void;
    onRemove: (index: number) => void;
    children: (item: T, index: number) => ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4">
            {items.map((item, index) => (
                <RecordCard key={index} title={`Запись ${index + 1}`} onRemove={() => onRemove(index)} removeLabel={`Убрать запись ${index + 1}`}>
                    {children(item, index)}
                </RecordCard>
            ))}

            <Button type="button" variant="outline" size="sm" className="self-start" onClick={onAdd}>
                <Plus />
                {addLabel}
            </Button>
        </div>
    );
}

/**
 * One record of a list, in the frame every record of this sheet is read in.
 *
 * Drawn apart from the list because not everything that reads as a record is
 * one of several: the spouse is one answer that either exists or does not, and
 * it is the same handful of fields under the same kind of heading — so it is
 * given the same frame, with the name of the person in place of «Запись 1» and
 * nothing to press. A part that drew its own box with its own spacing would be
 * the one part of the sheet that looks slightly wrong and nobody can say why.
 */
export function RecordCard({
    title,
    onRemove,
    removeLabel,
    children,
}: {
    /** What stands over the record: «Запись 2», «Супруг(а)». */
    title: string;
    /** Taking the record off the list, where it is one of a list at all. */
    onRemove?: () => void;
    removeLabel?: string;
    children: ReactNode;
}) {
    return (
        // 24px round the record and 16px above it: the strip at the top carries
        // the name of the record and the button that removes it, and a strip of
        // small type needs less room over it than a field does.
        <div className="rounded-lg border p-6 pt-4">
            <div className="mb-3 flex items-center justify-between">
                <span className="text-muted-foreground text-[13px] font-medium">{title}</span>

                {onRemove !== undefined && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="text-muted-foreground -mr-2.5 size-9 lg:-mr-2 lg:size-7"
                        aria-label={removeLabel ?? 'Убрать запись'}
                        onClick={onRemove}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                )}
            </div>
            {children}
        </div>
    );
}

/**
 * What every paper of the sheet is: the one kind of file the box below takes,
 * shown beside the name and written back on after it.
 *
 * Fixed rather than read off the file, because the file can only be this: a
 * name is renamed here and a kind is not, and «Диплом.jpg» renamed to
 * «Диплом.pdf» would be refused on the way in for a reason nobody could see.
 */
const PAPER_KIND = '.pdf';

/**
 * What a paper is called without that kind on the end: «Диплом» out of
 * «Диплом.pdf».
 *
 * Only «.pdf» comes off. The dots in «Диплом Рахимов И..pdf» are an initial's
 * and belong to the name, so nothing is cut at the last dot found.
 */
const paperStem = (name: string) => name.replace(/\.pdf$/i, '');

/**
 * The one document a record of the sheet may carry: a diploma, a decree, the
 * page of a military book — a PDF, as every paper this company files is.
 *
 * A button rather than a bare file input, for the reason the photograph's own
 * picker is one — a native input shows the path of the file and the word
 * «Browse», neither of which is in Russian or says what is wanted. The input
 * itself is still there, hidden, because it is what opens the file dialog and
 * what makes the keyboard reach this control at all.
 *
 * A file chosen is then named here, in a box, the way «Документы» names the
 * files of a paper: the name of the file is the name the card will carry for
 * ever, and what comes off a scanner is called «Scan_20261105_0001.pdf». That
 * is also why no field asks for a title beside this one — the renaming happens
 * to the file itself, so one answer travels where two used to.
 */
export function DocumentInput({
    value,
    onChange,
    invalid,
    current,
    removing,
    onRemoving,
}: {
    value: File | null;
    onChange: (file: File | null) => void;
    invalid?: boolean;
    /**
     * What is already attached to this record on the card, where there is
     * something. A new colleague has nothing, and a card being corrected
     * usually has: without this the box would say «Файл не выбран» over a
     * diploma that has been on file for three years, and somebody would attach
     * it again to be sure.
     */
    current?: string | null;
    /**
     * Whether the paper on file has been asked to go — asked, and no more than
     * that: nothing happens to it until the sheet is saved, and until then it
     * is still the paper the card shows.
     */
    removing?: boolean;
    /**
     * How that asking is put, and taken back. Where it is not given there is no
     * way of asking at all, which is the state «Новый сотрудник» is in: there is
     * nothing on file there to take off.
     */
    onRemoving?: (asked: boolean) => void;
}) {
    const picker = useRef<HTMLInputElement>(null);

    /** A paper on file that is going, so the box says so instead of naming it as held. */
    const leaving = current != null && removing === true && value === null;

    return (
        // The chosen paper takes a line of its own under the button — see
        // ChosenPaper for why: the name is typed in here, and a box sharing a
        // line with «Заменить» in a column this narrow is a box showing two
        // letters of it.
        <div className="flex min-w-0 flex-wrap items-center gap-2">
            <input
                ref={picker}
                type="file"
                hidden
                // A PDF and nothing else. Every paper the company files is one,
                // so a photograph of a diploma is converted before it is filed
                // rather than after — and a card whose papers are all of one
                // kind is a card whose papers all open the same way.
                accept="application/pdf"
                onChange={(event) => {
                    // A paper chosen is also what takes back the asking for the
                    // old one to go — somebody who attached one plainly wants
                    // it — but that is written where the file is written and not
                    // from here: a record keeps the two in one answer, and two
                    // writes of one answer from one event is the second
                    // overwriting the first. The box needs nothing from it
                    // either way, because a chosen file is what it shows.
                    onChange(event.target.files?.[0] ?? null);

                    // Cleared, so that choosing the same file twice in a row is
                    // still a change the browser reports.
                    event.target.value = '';
                }}
            />

            <Button type="button" variant="outline" aria-invalid={invalid} className="shrink-0 font-normal" onClick={() => picker.current?.click()}>
                <Paperclip />
                {/* «Заменить» only while there is something to replace: a paper
                    on its way off the card is not one a new file stands in for,
                    and after the save there will be nothing there. */}
                {value || (current && !leaving) ? 'Заменить' : 'Выбрать файл'}
            </Button>

            {value ? (
                // Keyed by the file, so that choosing another one starts its
                // name afresh instead of showing the name of the file before
                // it. A rename makes a new File too, which remounts this and
                // reads the name back out of it — the same name, since that is
                // where it came from.
                <ChosenPaper
                    key={`${value.name}:${value.size}:${value.lastModified}`}
                    file={value}
                    onRename={onChange}
                    onRemove={() => onChange(null)}
                />
            ) : current ? (
                // The paper on file, named — and offered for removal wherever
                // somebody may ask for one. What that asking does not do is take
                // it away: the sheet writes nothing until «Сохранить», and a
                // paper gone the moment a button was pressed would be the one
                // thing on the page «Отмена» could not put back. So the box says
                // what will happen rather than pretending it has, and goes on
                // naming the paper it is still showing — struck through, because
                // a card that reads the same either way is a card nobody can
                // tell they have changed.
                //
                // On a line of its own under the button, as a paper just
                // chosen is: the name and what is to be done about it belong
                // together, and left to wrap in the row they came out as a
                // name on one line and a cross alone on the next.
                <div className="flex w-full min-w-0 items-center gap-2">
                    <span className={cn('text-muted-foreground min-w-0 truncate text-sm', leaving && 'line-through')} title={current}>
                        {current}
                    </span>
                    {leaving ? (
                        <>
                            <span className="text-destructive shrink-0 text-[13px]">Будет убран при сохранении</span>
                            <Button type="button" variant="ghost" size="sm" className="shrink-0 font-normal" onClick={() => onRemoving?.(false)}>
                                Вернуть
                            </Button>
                        </>
                    ) : (
                        onRemoving !== undefined && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="shrink-0"
                                aria-label="Убрать файл"
                                onClick={() => onRemoving(true)}
                            >
                                <X />
                            </Button>
                        )
                    )}
                </div>
            ) : (
                <span className="text-placeholder text-sm">Файл не выбран</span>
            )}
        </div>
    );
}

/**
 * The paper just chosen, under the name it will be filed with.
 *
 * The name is a box rather than a line of text for the reason «Документы»
 * makes it one: what comes off a scanner is called «Scan_20261105_0001.pdf»,
 * and that is the name the card then carries for ever. Renaming it here costs
 * a click; renaming it afterwards means taking the paper off and attaching it
 * again.
 *
 * Only the name is anybody's to write — «.pdf» stands beside the box and is
 * written back on below, because it is what says how the paper opens and a PDF
 * called «.jpg» would be refused on the way in for a reason nobody could see.
 *
 * Renaming hands a renamed File back rather than a name beside the file: the
 * file's own name is the name the card keeps, so there is one answer to carry
 * and no second field that could disagree with it.
 */
function ChosenPaper({ file, onRename, onRemove }: { file: File; onRename: (file: File) => void; onRemove: () => void }) {
    const stem = paperStem(file.name);
    const [name, setName] = useState(stem);

    // On the way out of the box rather than on every letter: the file is
    // replaced to be renamed, and replacing it at every keystroke would take
    // the cursor out of the box it is being typed in.
    const commit = () => {
        // The trailing dots of «Диплом Рахимов И.» are the initial's, and the
        // one in front of «pdf» is put back on here: left as it was typed, the
        // paper would be filed under «Диплом Рахимов И..pdf».
        const wanted = name.trim().replace(/\.+$/, '').trim();

        // A file has to be called something, so an empty box is not an answer —
        // it goes back to the name the file came with.
        if (wanted === '') {
            setName(stem);

            return;
        }

        // What the box says is what the card will say: the dots taken off above
        // are gone from the box too, rather than left in one that reads «Диплом
        // Рахимов И.» beside a «.pdf» written after it.
        setName(wanted);

        if (wanted !== stem) {
            onRename(new File([file], wanted + PAPER_KIND, { type: file.type, lastModified: file.lastModified }));
        }
    };

    return (
        // A line of its own, the whole width of the field: the name is written
        // here, and the parts of this card are read in a column about three
        // hundred points wide. Beside «Заменить» the box came out the width of
        // «Sc», which is a box somebody has to scroll through to read what they
        // are renaming.
        <div className="flex w-full min-w-0 items-center gap-2">
            <div className="flex min-w-0 flex-1 items-center">
                <Input
                    value={name}
                    maxLength={120}
                    aria-label="Имя файла"
                    className="focus-visible:border-ring hover:border-input h-7 min-w-0 border-transparent bg-transparent px-1 text-sm shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 md:text-sm"
                    onChange={(event) => setName(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => {
                        // Enter puts the name in rather than saving the sheet:
                        // a form this control stands in would take it as its
                        // own and submit half a card.
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            event.currentTarget.blur();
                        }

                        if (event.key === 'Escape') {
                            setName(stem);
                            event.currentTarget.blur();
                        }
                    }}
                />
                <span className="text-muted-foreground shrink-0 text-sm">{PAPER_KIND}</span>
            </div>

            {/* Read against the ten megabytes a paper may weigh, which the line
                under the field says: a scan near that figure is one to look at
                again before it is sent. */}
            <span className="text-muted-foreground shrink-0 text-[13px] tabular-nums">{fileSize(file.size)}</span>

            <Button type="button" variant="ghost" size="icon" className="shrink-0" aria-label="Убрать файл" onClick={onRemove}>
                <X />
            </Button>
        </div>
    );
}
