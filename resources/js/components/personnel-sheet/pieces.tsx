import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Paperclip, Plus, Trash2, X } from 'lucide-react';
import { useRef, type ReactNode } from 'react';

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
}: {
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
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
        <div className={cn('grid content-start gap-2', className)}>
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
                // 24px round the record and 16px above it: the strip at the top
                // carries «Запись 1» and the button that removes it, and a strip
                // of small type needs less room over it than a field does.
                <div key={index} className="rounded-lg border p-6 pt-4">
                    <div className="mb-3 flex items-center justify-between">
                        <span className="text-muted-foreground text-[13px] font-medium">Запись {index + 1}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="text-muted-foreground -mr-2.5 size-9 lg:-mr-2 lg:size-7"
                            aria-label={`Убрать запись ${index + 1}`}
                            onClick={() => onRemove(index)}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                    {children(item, index)}
                </div>
            ))}

            <Button type="button" variant="outline" size="sm" className="self-start" onClick={onAdd}>
                <Plus />
                {addLabel}
            </Button>
        </div>
    );
}

/**
 * The one document a line or a record may carry: a diploma, a decree, the page
 * of a military book, scanned or photographed.
 *
 * A button rather than a bare file input, for the reason the photograph's own
 * picker is one — a native input shows the path of the file and the word
 * «Browse», neither of which is in Russian or says what is wanted. The input
 * itself is still there, hidden, because it is what opens the file dialog and
 * what makes the keyboard reach this control at all.
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
     * What is already attached to this line on the card, where there is
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
        <div className="flex min-w-0 items-center gap-2">
            <input
                ref={picker}
                type="file"
                hidden
                accept="application/pdf,image/jpeg,image/png"
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
                <>
                    <span className="min-w-0 truncate text-sm" title={value.name}>
                        {value.name}
                    </span>
                    <Button type="button" variant="ghost" size="icon" className="shrink-0" aria-label="Убрать файл" onClick={() => onChange(null)}>
                        <X />
                    </Button>
                </>
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
                <>
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
                </>
            ) : (
                <span className="text-placeholder text-sm">Файл не выбран</span>
            )}
        </div>
    );
}
