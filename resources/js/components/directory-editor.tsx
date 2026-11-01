import { CategoryFieldsEditor } from '@/components/category-fields-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useClearingForm } from '@/hooks/use-clearing-form';
import { type CategoryField, type FieldTypeOption } from '@/lib/equipment-fields';
import { equipmentIconNames, equipmentIcons, fallbackIcon } from '@/lib/equipment-icons';
import { cn } from '@/lib/utils';
import { LoaderCircle } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';

/**
 * A record as this dialog reads it: what it writes, and nothing the list around
 * it knows besides.
 *
 * The dialog is opened from two places now — "Подразделения", where a record is
 * a row of a list, and a department's own page, where it is the department the
 * page is about — and the second of those has no counts to send. What the dialog
 * actually reads is the fields below; {@link DirectoryItem} is this plus what a
 * list draws with.
 */
export interface EditableRecord {
    id: number;
    label: string;
    /**
     * Departments only: the short name the department is known by. Every other
     * page shows it instead of the full name; this window is where it is
     * written, and the list behind it the one place that shows both.
     */
    abbreviation?: string | null;
    /** System records that can be renamed but not deleted. */
    protected?: boolean;
    /** Departments only: the tree is built from these. */
    parent_id?: number | null;
    /** Equipment categories only: which drawing stands for it. */
    icon?: string | null;
    /** Equipment categories only: what units of this category are described by. */
    fields?: CategoryField[];
    /** Equipment categories only: which part of the stock the category is of. */
    section?: string;
    /** Equipment categories only: whether its units come with anything at all. */
    has_accessories?: boolean;
}

/** One row of a reference list: the record, and what the list itself shows. */
export interface DirectoryItem extends EditableRecord {
    /** Working employees who hold it. */
    users_count: number;
}

export interface Labels {
    /** "Добавить позицию" */
    add: string;
    /** "Новая позиция" */
    create: string;
    /** "Изменить позицию" */
    edit: string;
    /** Accusative, for "Удалить позицию «…»?" */
    accusative: string;
}

/**
 * "Департамент маркетинга (ДМ)" — how a record reads in this list, and only
 * here: the rest of the system knows a department by the short name alone.
 */
export function rowLabel(row: EditableRecord): string {
    return row.abbreviation ? `${row.label} (${row.abbreviation})` : row.label;
}

/**
 * The window one record of a reference list is written in: a name, and whatever
 * else that list keeps — a short name, a placement in a tree, an icon, the
 * fields a category's units are described by.
 *
 * A department's form is about the department and says nothing about its
 * positions. Who leads it is a property of a job rather than of the unit —
 * whoever holds a leading position heads the department — so it is written
 * where a position is written, in «Должности», and read here only as the
 * «Руководитель» column of the list.
 *
 * It lives apart from {@link DirectoryManager} because the list is no longer the
 * only way in. A department is edited from "Подразделения" and from the
 * department's own page, and both are the same form saving through the same
 * address, so a second window would be a second place for the rules to drift.
 */
export function DirectoryEditor({
    item,
    items,
    field,
    abbreviated = false,
    routeName,
    labels,
    tree = false,
    icons,
    sections,
    fieldTypes,
    defaultFields,
    onSaved,
    onClose,
}: {
    item: EditableRecord | null;
    items: EditableRecord[];
    field: 'title' | 'name';
    /**
     * Whether the record also has a short name to write (departments). The five
     * reference lists have none and say nothing about it, so the field is left
     * out of the form and out of the save alike.
     */
    abbreviated?: boolean;
    routeName: string;
    labels: Labels;
    /**
     * Whether the record can be filed under another one (departments). A flat
     * list offers no placement, and a save from it carries none.
     */
    tree?: boolean;
    icons?: string[];
    /**
     * The parts of the stock a category can belong to, where a list is kept in
     * parts: each has a page of its own, and this is what puts a record on one
     * of them.
     */
    sections?: { value: string; label: string }[];
    fieldTypes?: FieldTypeOption[];
    defaultFields?: CategoryField[];
    /**
     * The save went through, with the page behind the window already redrawn
     * from the answer.
     *
     * A form keeps the component it was opened from, which is how a
     * department's page keeps the document it had open and the scroll of the
     * column beside it. What that page had asked for by name is another matter:
     * the answer to a save carries no optional prop, so a caller that reads one
     * asks for it again here.
     */
    onSaved?: () => void;
    onClose: () => void;
}) {
    const form = useClearingForm<{
        label: string;
        abbreviation: string;
        parent_id: number | null;
        icon: string | null;
        section: string;
    }>({
        label: item?.label ?? '',
        abbreviation: item?.abbreviation ?? '',
        parent_id: item?.parent_id ?? null,
        icon: item?.icon ?? null,
        // A new category joins what people work on until somebody moves it.
        section: item?.section ?? sections?.[0]?.value ?? '',
    });

    const [fields, setFields] = useState<CategoryField[]>(item?.fields ?? defaultFields ?? []);
    // A new category comes with nothing in the box until somebody says otherwise.
    const [hasAccessories, setHasAccessories] = useState(item?.has_accessories ?? false);

    /**
     * The structure is two levels deep, so the only placement worth offering is
     * under a department that is itself on the top level — never under a
     * sub-department, and never under itself.
     */
    const hasChildren = Boolean(item) && tree && items.some((other) => other.parent_id === item?.id);
    const parents = useMemo(
        () => (hasChildren ? [] : items.filter((row) => (row.parent_id ?? null) === null && row.id !== item?.id)),
        [items, item, hasChildren],
    );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        form.transform((data) => ({
            [field]: data.label.trim(),
            // Left blank it stays blank: a department with no short name of its
            // own goes on reading by its full one.
            ...(abbreviated ? { abbreviation: data.abbreviation.trim() || null } : {}),
            ...(tree ? { parent_id: data.parent_id } : {}),
            ...(icons ? { icon: data.icon } : {}),
            ...(sections ? { section: data.section } : {}),
            ...(fieldTypes
                ? {
                      has_accessories: hasAccessories,
                      // A line left blank is a line somebody started and thought
                      // better of, so it drops out rather than failing the save.
                      // That holds for the two a unit is named by as well, now
                      // that a category may let either of them go: an unnamed
                      // row is nobody's field, whatever role it was marked with.
                      fields: fields
                          .filter((field) => field.name.trim() !== '')
                          .map((field) => ({
                              ...field,
                              name: field.name.trim(),
                              options: field.type === 'select' ? field.options.map((option) => option.trim()).filter(Boolean) : [],
                          })),
                  }
                : {}),
        }));
        let saved = false;
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                saved = true;
                onClose();
            },
            // After the visit and not inside it: a caller that answers this by
            // asking the server for something of its own would otherwise be
            // asking while the save is still being finished with.
            onFinish: () => saved && onSaved?.(),
        };

        if (item) form.put(route(`${routeName}.update`, item.id), options);
        else form.post(route(`${routeName}.store`), options);
    };

    const errors = form.errors as Record<string, string | undefined>;
    /** The first error for a list and its items ("fields", "fields.0", ...). */
    const listError = (key: string) => errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            {/* The fields a category's units are described by are a list of their
                own, so the dialog that holds them asks for more room than a name
                and a couple of checkboxes. */}
            <DialogContent
                className={cn(
                    // svh rather than vh: on a phone the browser's own bars would
                    // otherwise hide the bottom of a long list of fields.
                    'max-h-[90svh] overflow-y-auto sm:max-w-md',
                    // Wide enough for the row of two names and no wider than
                    // that row needs. The longest department on file draws at
                    // 407px in this font; its input wants 26px of padding and
                    // border around that, the abbreviation beside it is a 160px
                    // column with a 16px gap, and the window itself keeps 24px
                    // either side — 657px in all, which is the first step up
                    // that holds it.
                    abbreviated && 'sm:max-w-2xl',
                    fieldTypes && 'sm:max-w-2xl',
                )}
            >
                <form noValidate onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{item ? labels.edit : labels.create}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    {/* The two names on one row where there is room for them,
                        and not in equal halves: a department is called
                        «Департамент управления человеческими ресурсами» and
                        known as «ДУЧР», so the long one takes whatever is left
                        once the short one has the little it needs.

                        Both cells start at the top and grow downward on their
                        own, so a complaint under one leaves the other where it
                        was — which a two-column row is otherwise the easiest
                        place in the app to get wrong.

                        Below `sm` there is no row: they stack full width, the
                        way every field on a phone does. */}
                    <div className={cn('grid items-start gap-4', abbreviated && 'sm:grid-cols-[minmax(0,1fr)_10rem]')}>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-label" required>
                                Название
                            </Label>
                            <Input
                                aria-required
                                id="directory-label"
                                className="max-md:h-11"
                                autoFocus
                                value={form.data.label}
                                onChange={(event) => form.setData('label', event.target.value)}
                            />
                            <InputError message={errors[field]} />
                        </div>

                        {/* The short name the rest of the system shows in place
                            of the one beside it, which is why this list shows
                            both. */}
                        {abbreviated && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="directory-abbreviation">Аббревиатура</Label>
                                <Input
                                    id="directory-abbreviation"
                                    className="max-md:h-11 sm:max-w-40"
                                    value={form.data.abbreviation}
                                    onChange={(event) => form.setData('abbreviation', event.target.value)}
                                />
                                <InputError message={errors.abbreviation} />
                            </div>
                        )}
                    </div>

                    {sections && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-section">Раздел</Label>
                            <Select value={form.data.section} onValueChange={(value) => form.setData('section', value)}>
                                <SelectTrigger id="directory-section" aria-invalid={!!errors.section}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {sections.map((one) => (
                                        <SelectItem key={one.value} value={one.value}>
                                            {one.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.section} />
                            <p className="text-muted-foreground text-[13px]">На какой странице «Инвентаризации» читаются её единицы.</p>
                        </div>
                    )}

                    {icons && (
                        <div className="grid content-start gap-2">
                            <Label>Иконка</Label>
                            {/* A grid of them: a list of names would say nothing about how each looks. */}
                            <div className="flex flex-wrap gap-2">
                                {icons.map((key) => {
                                    const Icon = equipmentIcons[key] ?? fallbackIcon;
                                    const chosen = form.data.icon === key;
                                    const name = equipmentIconNames[key] ?? key;

                                    return (
                                        <Tooltip key={key}>
                                            <TooltipTrigger asChild>
                                                <button
                                                    type="button"
                                                    aria-pressed={chosen}
                                                    aria-label={`Иконка: ${name}`}
                                                    onClick={() => form.setData('icon', chosen ? null : key)}
                                                    className={cn(
                                                        'flex size-10 items-center justify-center rounded-lg border transition-colors',
                                                        chosen
                                                            ? 'border-transparent bg-[#EEF5DC] text-[#4A6410] dark:bg-[#A8CF45]/15 dark:text-[#C5E27A]'
                                                            : 'text-muted-foreground hover:bg-accent',
                                                    )}
                                                >
                                                    <Icon className="size-5" />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent>{name}</TooltipContent>
                                        </Tooltip>
                                    );
                                })}
                            </div>
                            <InputError message={errors.icon} />
                            <p className="text-muted-foreground text-[13px]">Без выбора — обычная коробка.</p>
                        </div>
                    )}

                    {fieldTypes && (
                        <div className="grid content-start gap-2">
                            <Label>Комплектация</Label>
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox checked={hasAccessories} onCheckedChange={() => setHasAccessories(!hasAccessories)} className="mt-0.5" />
                                <span className="min-w-0">
                                    Единицы этой категории идут в комплекте
                                    <span className="text-muted-foreground block text-[13px]">
                                        У ноутбука есть блок питания и сумка, у мыши — ничего. Без галочки поля «Комплектация» не будет ни в форме, ни
                                        на карточке.
                                    </span>
                                </span>
                            </label>
                        </div>
                    )}

                    {fieldTypes && (
                        <CategoryFieldsEditor
                            fields={fields}
                            types={fieldTypes}
                            onChange={setFields}
                            error={(key) => errors[key] ?? listError(key)}
                        />
                    )}

                    {tree && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="directory-parent">Входит в</Label>
                            <Select
                                value={form.data.parent_id === null ? 'root' : String(form.data.parent_id)}
                                onValueChange={(value) => form.setData('parent_id', value === 'root' ? null : Number(value))}
                                disabled={hasChildren}
                            >
                                <SelectTrigger id="directory-parent" className="max-md:h-11">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="max-h-80">
                                    <SelectItem value="root">— Верхний уровень —</SelectItem>
                                    {parents.map((row) => (
                                        <SelectItem key={row.id} value={String(row.id)}>
                                            {rowLabel(row)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.parent_id} />
                            {hasChildren && (
                                <p className="text-muted-foreground text-[13px]">
                                    У отдела есть свои подразделения, поэтому он остаётся на верхнем уровне.
                                </p>
                            )}
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" className="max-md:h-11" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" className="max-md:h-11" disabled={form.processing || form.data.label.trim() === ''}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
