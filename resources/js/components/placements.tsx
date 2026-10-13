import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Plus, Trash2 } from 'lucide-react';

/**
 * Where a colleague stands in the company: a department, a position of that
 * department and the duties picked out of that position's list.
 *
 * A position may be left unchosen — belonging to a department without holding a
 * position in it is an ordinary thing — and holding a position hands nobody its
 * whole list of duties: one or several are picked out of it.
 */
export interface Placement {
    department_id: number | null;
    position_id: number | null;
    duty_ids: number[];
}

/**
 * Everything the three fields narrow each other by, sent whole with the page so
 * a choice costs no round trip. There are a few hundred positions and duties.
 */
export interface PlacementOptions {
    /** The department tree flattened, parents first; `depth` indents the children. */
    departments: { id: number; name: string; full_name: string; depth: number }[];
    /** A position reads as «Администратор (ОАП)»: the name alone no longer says which it is. */
    positions: { id: number; name: string; display_name: string; department_id: number | null }[];
    duties: { id: number; name: string; position_id: number }[];
}

export const blankPlacement: Placement = { department_id: null, position_id: null, duty_ids: [] };

/**
 * What the form sends. The key always travels, even empty: the server reads a
 * missing one as nothing at all rather than as "leave it alone".
 *
 * A form nobody has touched here says nobody is placed anywhere, so the chain
 * it opens with is dropped rather than refused over a department that was never
 * asked for. Once one chain names a department the others go as they are: a
 * chain somebody started and left half-done is one to finish, and the server
 * says so on the very field — which it can only do while the chains it is told
 * about are numbered the way the form numbers them.
 */
export const placementsToSend = (placements: Placement[]): Placement[] =>
    placements.some((placement) => placement.department_id !== null) ? placements : [];

/** Errors come back as "placements.0.position_id"; a field shows its own and its items'. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/**
 * The chain, asked in the order it hangs together: the department first, then
 * the positions of that department, then the duties of that position. A field
 * appears only once the one above it is answered, because until then there is
 * nothing to offer in it.
 *
 * Changing an answer empties what hung off it. The positions of one department
 * are not the positions of the next, and a duty belongs to a position rather
 * than to the person, so a department put right leaves no stray position behind
 * and a position put right leaves no stray duties.
 *
 * Somebody may stand in several places at once — a position in one department
 * and another in a second — so the chains are a list, each in a block of its
 * own with its number on it, and the same department may come back with a
 * different position.
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
     * That a chain was answered again, with the error keys the refusal had put
     * on it. What was said about the answers it held then no longer describes
     * the ones it holds now — "this department is already in the list" under a
     * department somebody has just changed argues with the field above it.
     */
    onAnswered?: (keys: string[]) => void;
}) {
    const patch = (index: number, changed: Partial<Placement>) => {
        onAnswered?.(Object.keys(errors).filter((key) => key.startsWith(`placements.${index}.`)));
        onChange(value.map((placement, position) => (position === index ? { ...placement, ...changed } : placement)));
    };

    return (
        <div className="flex flex-col gap-3">
            <InputError message={errors.placements} />

            {value.length === 0 && <p className="text-muted-foreground text-sm">Сотрудник пока ни в одном отделе не числится.</p>}

            {value.map((placement, index) => {
                // A position nobody has placed yet belongs to no department, so
                // it matches none of these and can never be chosen here.
                const positions = options.positions.filter((position) => position.department_id === placement.department_id);
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
                                <Label htmlFor={`placement-${index}-department`}>Отдел</Label>
                                <SearchableSelect
                                    id={`placement-${index}-department`}
                                    value={placement.department_id === null ? '' : String(placement.department_id)}
                                    // The two fields under it were filled out of
                                    // this one and mean nothing under another.
                                    onChange={(chosen) => patch(index, { department_id: Number(chosen), position_id: null, duty_ids: [] })}
                                    options={options.departments.map((department) => ({
                                        value: String(department.id),
                                        label: department.name,
                                        // A department is named by its short name
                                        // everywhere, and a list one picks from is
                                        // not a link one can follow to find out
                                        // what that stands for, so it says so here.
                                        hint: department.full_name === department.name ? undefined : department.full_name,
                                        depth: department.depth,
                                    }))}
                                    placeholder="Выберите отдел"
                                    searchPlaceholder="Поиск отдела"
                                    empty="Отдел не найден"
                                    invalid={!!at(errors, `placements.${index}.department_id`)}
                                />
                                <InputError message={at(errors, `placements.${index}.department_id`)} />
                            </div>

                            {placement.department_id !== null && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor={`placement-${index}-position`}>Должность</Label>
                                    {positions.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">
                                            В этом отделе пока нет должностей — их добавляют в справочнике «Должности».
                                        </p>
                                    ) : (
                                        <SearchableSelect
                                            id={`placement-${index}-position`}
                                            value={placement.position_id === null ? '' : String(placement.position_id)}
                                            onChange={(chosen) => patch(index, { position_id: chosen === '' ? null : Number(chosen), duty_ids: [] })}
                                            options={[
                                                // Standing in a department without
                                                // holding a position there is an
                                                // answer, so there is a way back to it.
                                                { value: '', label: 'Без должности' },
                                                ...positions.map((position) => ({ value: String(position.id), label: position.display_name })),
                                            ]}
                                            placeholder="Без должности"
                                            searchPlaceholder="Поиск должности"
                                            empty="Должность не найдена"
                                            invalid={!!at(errors, `placements.${index}.position_id`)}
                                        />
                                    )}
                                    <InputError message={at(errors, `placements.${index}.position_id`)} />
                                </div>
                            )}

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
                Добавить отдел
            </Button>
        </div>
    );
}
