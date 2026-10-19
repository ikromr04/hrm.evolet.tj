import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-react';

/**
 * Where a colleague stands in the company: a position and the duties picked out
 * of that position's list.
 *
 * The department is not asked for. A position belongs to one and belonging to a
 * department follows from holding one of its jobs, so naming the job names both;
 * asking for the department first asked twice for one answer and let somebody be
 * filed in a department with no job in it.
 *
 * Holding a position hands nobody its whole list of duties: one or several are
 * picked out of it.
 */
export interface Placement {
    position_id: number | null;
    duty_ids: number[];
}

/**
 * Every position to choose from and every duty with the position behind it,
 * sent whole with the page so a choice costs no round trip. There are a few
 * hundred of each.
 */
export interface PlacementOptions {
    /** A position reads as «Администратор (ОАП)»: the name alone no longer says which it is. */
    positions: { id: number; name: string; display_name: string; department_id: number | null }[];
    duties: { id: number; name: string; position_id: number }[];
}

export const blankPlacement: Placement = { position_id: null, duty_ids: [] };

/**
 * What the form sends. The key always travels, even empty: the server reads a
 * missing one as nothing at all rather than as "leave it alone".
 *
 * A form nobody has touched here says nobody holds anything, so the row it opens
 * with is dropped rather than refused over a position that was never chosen.
 * Once one row names a position the others go as they are: a row somebody
 * started and left half-done is one to finish, and the server says so on the
 * very field — which it can only do while the rows it is told about are numbered
 * the way the form numbers them.
 */
export const placementsToSend = (placements: Placement[]): Placement[] =>
    placements.some((placement) => placement.position_id !== null) ? placements : [];

/** Errors come back as "placements.0.position_id"; a field shows its own and its items'. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/**
 * The job, and under it the duties of that job. The second field appears only
 * once the first is answered, because until then there is nothing to offer in
 * it, and changing the job empties what hung off it: a duty belongs to a
 * position rather than to the person.
 *
 * Somebody may hold several jobs — one in a department and another in a second,
 * or two in the same one — so the rows are a list, each in a block of its own
 * with its number on it.
 */
export function Placements({
    value,
    onChange,
    options,
    errors,
    onAnswered,
}: {
    value: Placement[];
    onChange: (placements: Placement[]) => void;
    options: PlacementOptions;
    /** The whole form's errors, as Inertia keeps them. */
    errors: Record<string, string | undefined>;
    /**
     * That a row was answered again, with the error keys the refusal had put on
     * it. What was said about the answers it held then no longer describes the
     * ones it holds now — "this position is already in the list" under a job
     * somebody has just changed argues with the field above it.
     */
    onAnswered?: (keys: string[]) => void;
}) {
    const patch = (index: number, changed: Partial<Placement>) => {
        onAnswered?.(Object.keys(errors).filter((key) => key.startsWith(`placements.${index}.`)));
        onChange(value.map((placement, position) => (position === index ? { ...placement, ...changed } : placement)));
    };

    // A position nobody has filed in a department yet is a directory entry
    // waiting to be placed: holding it would put the person nowhere, so it is
    // not offered here.
    const positions = options.positions.filter((position) => position.department_id !== null);

    return (
        <div className="flex flex-col gap-3">
            <InputError message={errors.placements} />

            {value.length === 0 && <p className="text-muted-foreground text-sm">Сотрудник пока не занимает ни одной должности.</p>}

            {value.map((placement, index) => {
                const duties = options.duties.filter((duty) => duty.position_id === placement.position_id);

                return (
                    <div key={index} className="rounded-lg border p-4 pt-3">
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-muted-foreground text-[13px] font-medium">Запись {index + 1}</span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-muted-foreground -mr-2.5 size-9 lg:-mr-2 lg:size-7"
                                aria-label={`Убрать запись ${index + 1}`}
                                onClick={() => onChange(value.filter((_, position) => position !== index))}
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>

                        <div className="grid gap-4">
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`placement-${index}-position`}>Должность</Label>
                                <SearchableSelect
                                    id={`placement-${index}-position`}
                                    value={placement.position_id === null ? '' : String(placement.position_id)}
                                    // The duties under it were picked out of this
                                    // job's list and mean nothing under another.
                                    onChange={(chosen) => patch(index, { position_id: chosen === '' ? null : Number(chosen), duty_ids: [] })}
                                    options={positions.map((position) => ({ value: String(position.id), label: position.display_name }))}
                                    placeholder="Выберите должность"
                                    searchPlaceholder="Поиск должности"
                                    empty="Должность не найдена"
                                    invalid={!!at(errors, `placements.${index}.position_id`)}
                                />
                                <p className="text-muted-foreground text-[13px]">
                                    Отдел указывать не нужно: должность принадлежит отделу, и сотрудник числится там, где его должности.
                                </p>
                                <InputError message={at(errors, `placements.${index}.position_id`)} />
                            </div>

                            {placement.position_id !== null && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor={`placement-${index}-duties`}>Обязанности</Label>
                                    {duties.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            У этой должности пока нет обязанностей — их пишут на странице должности.
                                        </p>
                                    ) : (
                                        <>
                                            <MultiSelect
                                                numbered
                                                id={`placement-${index}-duties`}
                                                options={duties.map((duty) => ({ value: duty.id, label: duty.name }))}
                                                value={placement.duty_ids}
                                                onChange={(chosen) => patch(index, { duty_ids: chosen })}
                                                placeholder="Не выбрано"
                                                searchPlaceholder="Поиск обязанности"
                                            />
                                            <p className="text-muted-foreground text-[13px]">
                                                Выберите то, за что сотрудник отвечает, — не обязательно весь список должности.
                                            </p>
                                        </>
                                    )}
                                    <InputError message={at(errors, `placements.${index}.duty_ids`)} />
                                </div>
                            )}
                        </div>
                    </div>
                );
            })}

            <Button type="button" variant="outline" size="sm" className="self-start" onClick={() => onChange([...value, { ...blankPlacement }])}>
                <Plus />
                Добавить должность
            </Button>
        </div>
    );
}
