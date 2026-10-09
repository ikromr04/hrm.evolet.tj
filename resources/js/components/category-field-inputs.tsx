import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CategoryField, type FieldValues } from '@/lib/equipment-fields';
import { cn } from '@/lib/utils';
import { type Ref } from 'react';

/**
 * One field, as the control its type asks for: a list offers its choices, a
 * date opens a date picker, a yes-or-no is a tick box, everything else is a
 * line. The label belongs to whoever draws it — the two a unit is named by are
 * labelled by the category, the rest by their own name.
 */
export function FieldInput({
    field,
    id,
    value,
    onChange,
    placeholder,
    invalid,
    inputRef,
}: {
    field: CategoryField;
    id: string;
    value: string | boolean | null | undefined;
    onChange: (value: string | boolean) => void;
    placeholder?: string;
    invalid?: boolean;
    /**
     * For a caller that puts the cursor in the box itself — the add form waits
     * on the number from the sticker after filing a unit. A field the category
     * gave another type has no line to hold the cursor, and the ref stays empty.
     */
    inputRef?: Ref<HTMLInputElement>;
}) {
    if (field.type === 'boolean') {
        return <Checkbox id={id} checked={value === true} onCheckedChange={(next) => onChange(next === true)} />;
    }

    if (field.type === 'select') {
        return (
            <Select value={String(value ?? '')} onValueChange={onChange}>
                <SelectTrigger id={id} aria-invalid={invalid}>
                    <SelectValue placeholder="Не выбрано" />
                </SelectTrigger>
                <SelectContent>
                    {field.options.map((option) => (
                        <SelectItem key={option} value={option}>
                            {option}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        );
    }

    return (
        <Input
            ref={inputRef}
            id={id}
            type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
            step={field.type === 'number' ? 'any' : undefined}
            value={String(value ?? '')}
            onChange={(event) => onChange(event.target.value)}
            placeholder={placeholder}
            aria-invalid={invalid}
        />
    );
}

/**
 * The fields the chosen category asks about, as inputs.
 *
 * Which fields these are is decided in the directory, so a monitor is never
 * asked for a processor. Each one is drawn by what it holds: a list offers its
 * choices, a date opens a date picker, a yes-or-no is a tick box.
 */
export function CategoryFieldInputs({
    fields,
    values,
    onChange,
    error,
    className,
}: {
    fields: CategoryField[];
    values: FieldValues;
    onChange: (id: number, value: string | boolean) => void;
    /** The error for "fields.<id>", as the server keys them. */
    error?: (key: string) => string | undefined;
    /** Applied to every field, so a page can place them in its own grid. */
    className?: string;
}) {
    return (
        <>
            {fields.map((field) => {
                const id = field.id!;
                const key = `fields.${id}`;
                const message = error?.(key);
                const held = values[id];
                const input = `category-field-${id}`;

                return (
                    <div key={id} className={cn('grid content-start gap-2', className)}>
                        {field.type === 'boolean' ? (
                            // The label belongs next to the box, not above it.
                            <label htmlFor={input} className="flex items-center gap-2 pt-1 text-sm">
                                <FieldInput field={field} id={input} value={held} onChange={(next) => onChange(id, next)} invalid={!!message} />
                                {field.name}
                                {field.required && <span aria-hidden>*</span>}
                            </label>
                        ) : (
                            <>
                                <Label htmlFor={input}>
                                    {field.name}
                                    {field.required && <span aria-hidden> *</span>}
                                </Label>

                                <FieldInput field={field} id={input} value={held} onChange={(next) => onChange(id, next)} invalid={!!message} />
                            </>
                        )}

                        <InputError message={message} />
                    </div>
                );
            })}
        </>
    );
}
