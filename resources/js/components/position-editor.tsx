import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

/** A position as the window reads one: what it is called, where it sits, what it answers for. */
export interface PositionRecord {
    id: number;
    name: string;
    is_head: boolean;
    department: { id: number } | null;
    duties: string[];
}

/** One line of the department picker. */
export interface DepartmentOption {
    id: number;
    name: string;
    full_name: string;
    /** How deep the department sits, which is how far the line is indented. */
    depth?: number;
}

interface EditorData {
    /** The picker writes strings; nothing chosen yet is an empty one. */
    department_id: string;
    name: string;
    /** Whether whoever holds this heads the department it sits in. */
    is_head: boolean;
    duties: string[];
}

/**
 * Adding a position or changing one, asked in the order the answers depend on
 * each other: the department first, because the same name means a different job
 * in each of them and is only taken within one; then the name; then what the job
 * involves. The duties are entered the way a person's citizenships are — value
 * by value, each its own chip — and what other positions already say is offered
 * as suggestions, so the same duty is written the same way twice.
 */
export function PositionEditor({
    position,
    suggestions,
    departments,
    onClose,
}: {
    /** The position being changed, or nothing at all when one is being added. */
    position: PositionRecord | null;
    /** What other positions already say, offered as the next one is written. */
    suggestions: string[];
    /** The tree flattened, parents first, as the picker lists it. */
    departments: DepartmentOption[];
    onClose: () => void;
}) {
    const form = useForm<EditorData>({
        department_id: position?.department === null || position === null ? '' : String(position.department.id),
        name: position?.name ?? '',
        is_head: position?.is_head ?? false,
        duties: position?.duties ?? [],
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            department_id: data.department_id === '' ? null : Number(data.department_id),
            name: data.name.trim(),
            is_head: data.is_head,
            duties: data.duties.map((name) => ({ name })),
        }));
        const options = { preserveScroll: true, onSuccess: onClose };

        if (position) form.put(route('positions.update', position.id), options);
        else form.post(route('positions.store'), options);
    };

    const errors = form.errors as Record<string, string | undefined>;

    // The server reports a line of the list by its place in it
    // ("duties.7.name"), and the list is one field here, so whichever line it
    // is about is said under that field.
    const dutyError = Object.entries(errors).find(([key, message]) => /^duties\.\d+(\.|$)/.test(key) && message !== undefined)?.[1];

    const dutyOptions = suggestions.map((duty) => ({ value: duty, label: duty }));

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{position ? 'Изменить должность' : 'Новая должность'}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-department">Отдел</Label>
                        <SearchableSelect
                            id="position-department"
                            className="max-md:h-11"
                            value={form.data.department_id}
                            onChange={(value) => form.setData('department_id', value)}
                            options={departments.map((department) => ({
                                value: String(department.id),
                                label: department.name,
                                hint: department.full_name === department.name ? undefined : department.full_name,
                                // Indented by how deep it sits, as the employees list indents the same tree.
                                depth: department.depth,
                            }))}
                            placeholder="Выберите отдел"
                            searchPlaceholder="Отдел"
                            empty="Отдел не найден"
                            invalid={errors.department_id !== undefined}
                        />
                        <p className="text-muted-foreground text-[13px]">
                            Должность принадлежит одному отделу. Одно и то же название в разных отделах — это разные должности со своими
                            обязанностями.
                        </p>
                        <InputError message={errors.department_id} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-name">Название</Label>
                        <Input
                            id="position-name"
                            className="max-md:h-11"
                            autoFocus
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={errors.name} />
                    </div>

                    {/* The same flag the department's own dialog puts a crown on,
                        asked here as a plain checkbox because this is where a
                        position is written down in the first place — left out, it
                        would be a thing only somebody who went looking for it in
                        another window would ever know existed. */}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-is-head" className="flex items-start gap-2.5 font-normal">
                            <Checkbox
                                id="position-is-head"
                                checked={form.data.is_head}
                                onCheckedChange={(checked) => form.setData('is_head', checked === true)}
                            />
                            <span>Руководящая должность</span>
                        </Label>
                        <p className="text-muted-foreground text-[13px]">
                            Кто занимает эту должность, тот руководит отделом. У отдела может быть несколько руководящих должностей.
                        </p>
                        <InputError message={errors.is_head} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="position-duties">Обязанности</Label>
                        <MultiSelect
                            numbered
                            id="position-duties"
                            creatable
                            options={dutyOptions}
                            value={form.data.duties}
                            onChange={(duties) => form.setData('duties', duties)}
                            placeholder="Не заполнены"
                            searchPlaceholder="Обязанность"
                        />
                        <p className="text-muted-foreground text-[13px]">
                            Всё, за что отвечает должность. По одной обязанности за раз: впишите её и нажмите Enter. Порядок строк здесь — это порядок
                            на странице должности.
                        </p>
                        {/* The whole list can be complained about too ("duties"), and that
                            complaint is nobody's line in particular. */}
                        <InputError message={errors.duties ?? dutyError} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            className="max-md:h-11"
                            disabled={form.processing || form.data.department_id === '' || form.data.name.trim() === ''}
                        >
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
