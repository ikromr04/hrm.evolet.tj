import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CategoryField, type FieldType, type FieldTypeOption } from '@/lib/equipment-fields';
import { Plus, Trash2 } from 'lucide-react';

/**
 * What the two a unit is named by are read as elsewhere. A category may call
 * them whatever suits it — a printer's «Наименование» may read «Модель» — so
 * the line under the box says what the field is for instead of leaving the
 * word in it to explain itself. Since the two are dropped like any other field,
 * that line is also the only thing saying what a category gives up with them.
 */
const ROLE_HINT: Record<'title' | 'inventory', string> = {
    title: 'Так называется единица в списках и письмах.',
    inventory: 'Номер на наклейке, по нему единицу ищут.',
};

/**
 * What units of a category are described by, edited in the category's own
 * dialog: a line per field with its name, what it holds and whether it must be
 * filled in.
 *
 * Two of those lines are the ones a unit is usually named by, marked by a role.
 * The line under each says what the role is for, because a category renames
 * them as it likes; in every other way they are ordinary lines, dropped like
 * the rest. A category left without them, or without any field at all, is a
 * category that names its units by nothing, and the list says so rather than
 * standing empty.
 *
 * Dropping a field takes with it whatever the units had written in it, so the
 * line says so plainly instead of asking a second time in a dialog of its own.
 */
export function CategoryFieldsEditor({
    fields,
    types,
    onChange,
    error,
}: {
    fields: CategoryField[];
    types: FieldTypeOption[];
    onChange: (fields: CategoryField[]) => void;
    error?: (key: string) => string | undefined;
}) {
    const replace = (index: number, field: Partial<CategoryField>) =>
        onChange(fields.map((held, at) => (at === index ? { ...held, ...field } : held)));

    const add = () => onChange([...fields, { name: '', type: 'text', options: [], required: false, role: null }]);
    const remove = (index: number) => onChange(fields.filter((_, at) => at !== index));

    return (
        <div className="grid content-start gap-2">
            <Label>Поля единиц</Label>
            <p className="text-muted-foreground text-[13px]">
                Их увидят на карточке и в форме добавления техники этой категории. Новое поле встаёт в конец списка.
            </p>

            <ul className="grid gap-3">
                {fields.map((field, index) => (
                    <li key={field.id ?? `new-${index}`} className="border-border grid gap-2 rounded-lg border p-3">
                        <div className="flex flex-wrap items-start gap-2 sm:flex-nowrap">
                            <div className="grid min-w-0 flex-1 basis-full content-start gap-2 sm:basis-auto">
                                <Input
                                    className="max-md:h-11"
                                    value={field.name}
                                    placeholder="Название поля"
                                    aria-label={`Название поля ${index + 1}`}
                                    onChange={(event) => replace(index, { name: event.target.value })}
                                    aria-invalid={!!error?.(`fields.${index}.name`)}
                                />
                                <InputError message={error?.(`fields.${index}.name`)} />
                            </div>

                            <div className="grid min-w-0 flex-1 content-start gap-2 sm:w-40 sm:flex-none">
                                <Select value={field.type} onValueChange={(type) => replace(index, { type: type as FieldType })}>
                                    <SelectTrigger className="max-md:h-11" aria-label={`Тип поля ${index + 1}`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((type) => (
                                            <SelectItem key={type.key} value={type.key}>
                                                {type.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={error?.(`fields.${index}.type`)} />
                            </div>

                            <div className="flex shrink-0 gap-0.5">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-9 text-[#B42318] hover:text-[#B42318] max-md:size-10 dark:text-[#F7A19A]"
                                    aria-label={`Убрать поле: ${field.name || 'новое поле'}`}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        </div>

                        {field.type === 'select' && (
                            <div className="grid content-start gap-2">
                                <Input
                                    className="max-md:h-11"
                                    value={field.options.join(', ')}
                                    placeholder="Варианты через запятую"
                                    aria-label={`Варианты поля ${index + 1}`}
                                    onChange={(event) =>
                                        replace(index, { options: event.target.value.split(',').map((option) => option.trimStart()) })
                                    }
                                    aria-invalid={!!error?.(`fields.${index}.options`)}
                                />
                                <InputError message={error?.(`fields.${index}.options`)} />
                            </div>
                        )}

                        <label className="text-muted-foreground flex items-center gap-2 text-[13px] max-md:min-h-10">
                            <Checkbox checked={field.required} onCheckedChange={() => replace(index, { required: !field.required })} />
                            Обязательное
                        </label>

                        {field.role ? (
                            <p className="text-muted-foreground text-[13px]">{ROLE_HINT[field.role]}</p>
                        ) : (
                            field.id !== undefined && (
                                <p className="text-muted-foreground text-[13px]">
                                    Если убрать это поле, значения, записанные в него у единиц, исчезнут.
                                </p>
                            )
                        )}
                    </li>
                ))}
            </ul>

            {/* Having dropped every field, somebody should read that the category
                is saved that way on purpose rather than wonder where the list went. */}
            {fields.length === 0 && (
                <p className="text-muted-foreground border-border rounded-lg border border-dashed p-3 text-[13px]">
                    Полей нет — своих данных у единиц этой категории не будет. Так тоже можно, поля добавляются в любое время.
                </p>
            )}

            <Button type="button" variant="outline" className="h-8 justify-self-start max-md:h-11 max-md:justify-self-stretch" onClick={add}>
                <Plus />
                Добавить поле
            </Button>
            <InputError message={error?.('fields')} />
        </div>
    );
}
