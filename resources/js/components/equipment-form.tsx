import { CategoryFieldInputs, FieldInput } from '@/components/category-field-inputs';
import InputError from '@/components/input-error';
import { PhotoInput } from '@/components/photo-input';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { blankValues, ownFields, roleField, roleName, type CategoryOption, type FieldValues } from '@/lib/equipment-fields';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Plus } from 'lucide-react';
import { useMemo, useRef, useState, type FormEventHandler, type ReactNode } from 'react';

/** Where the form is drawn, which is all that differs between the two. */
export type EquipmentFormLayout = 'page' | 'dialog';

export interface EquipmentFormProps {
    options: {
        /** Each category with what its units are described by, and whether they come with anything. */
        types: CategoryOption[];
        /**
         * Everybody a unit may be handed to, asked for only while no colleague
         * is fixed. From a card the holder is already decided, so a card need
         * not carry every employee in the company to draw this form.
         */
        holders?: { id: number; name: string }[];
    };
    /**
     * The colleague this unit is being bought for, when the form was opened
     * from their card, and null on the ordinary add page. Who gets it is then
     * already decided, so the form does not ask again.
     */
    forEmployee: { id: number; name: string } | null;
    /**
     * A page is as wide as the monitor and a dialog is as wide as itself, so
     * the grid, the buttons and what «Отмена» does all follow from this.
     */
    layout?: EquipmentFormLayout;
    /** The form's first child, so a dialog can keep its «DialogHeader» inside the form. */
    header?: ReactNode;
    /** A save has gone through and nothing more is being added — a dialog closes itself here. */
    onSaved?: () => void;
    /** What «Отмена» does in a dialog, where there is nowhere to navigate away to. */
    onCancel?: () => void;
}

/**
 * Where «Отмена» leads and where a save returns to: the colleague's card when
 * the unit is being bought for somebody, the list of units otherwise.
 */
export const equipmentFormBack = (forEmployee: { id: number } | null) =>
    forEmployee ? route('employees.show', forEmployee.id) : route('equipment.index', { view: 'list' });

/**
 * Four fields across on a desktop, two on a tablet, one on a phone. The form
 * fills the page, but an input never stretches to the width of a monitor: a
 * serial number in a box half a metre long is unreadable. One flow, no
 * headings — every field here describes the same thing, and rules across the
 * page would only break its rhythm.
 *
 * In a dialog the viewport says nothing about the room there is, so the columns
 * are counted off the form's own width the way the hiring form counts its own,
 * and two is as many as a dialog holds.
 */
const row: Record<EquipmentFormLayout, string> = {
    page: 'grid gap-4 md:grid-cols-2 lg:grid-cols-4',
    dialog: 'grid gap-4 md:@min-[26rem]:grid-cols-2',
};

/**
 * Half a box, which is all a date needs: two of them share one field's place.
 * The narrowest phones stack them, as two dates side by side would not fit. A
 * dialog's column is too narrow for a pair of dates, so there they take a row
 * of their own and halve that instead.
 */
const half: Record<EquipmentFormLayout, string> = {
    page: 'grid gap-3 md:grid-cols-2',
    dialog: 'col-span-full grid gap-3 md:@min-[26rem]:grid-cols-2',
};

/**
 * A field worth two boxes: a long line of text or a list. Two of two is the
 * whole row in a dialog, and asking for the row itself keeps the grid honest
 * while it is still only one column wide.
 */
const wide: Record<EquipmentFormLayout, string> = { page: 'md:col-span-2', dialog: 'col-span-full' };

function Field({
    label,
    htmlFor,
    error,
    className,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid min-w-0 content-start gap-2 [&_input]:min-w-0', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** Errors come back as "photos.0"; a field shows its own, whichever it is. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/**
 * Putting a unit of equipment on the books.
 *
 * The same form fills the add page and a dialog on a colleague's card, where
 * entering a unit and handing it over is one act and leaving the card for it
 * would be a detour. A dialog that redirected itself away would be nonsense, so
 * there it saves the one unit, tells the card and closes; the page may go round
 * again as often as there are units in the delivery.
 */
export function EquipmentForm({ options, forEmployee, layout = 'page', header, onSaved, onCancel }: EquipmentFormProps) {
    const today = new Date().toISOString().slice(0, 10);
    const inAYear = new Date();
    inAYear.setFullYear(inAYear.getFullYear() + 1);

    const inDialog = layout === 'dialog';

    const inventory = useRef<HTMLInputElement>(null);
    /** Which of the two buttons was pressed, read as the form is sent. */
    const batch = useRef(false);
    /** What has been filed without leaving the page, newest last. */
    const [filed, setFiled] = useState<{ id: number; name: string; inventory_number: string }[]>([]);
    const [photos, setPhotos] = useState<File[]>([]);
    /** What is written in the chosen category's fields, by field id. */
    const [values, setValues] = useState<FieldValues>({});

    // Hardware is usually bought for somebody, so it can be handed over here
    // rather than added first and issued in a second window. Coming from a
    // colleague's card the handing over is the whole point of the form, so
    // there is nothing to tick and the unit goes across either way.
    const [issuing, setIssuing] = useState(false);
    const handingOver = forEmployee !== null || issuing;
    // What state a unit arrived in is often written later, when somebody has it
    // in their hands, so the form does not ask until asked.
    const [describing, setDescribing] = useState(false);

    const form = useForm({
        name: '',
        equipment_type_id: '',
        inventory_number: '',
        accessories: '',
        condition: '',
        checked_at: '',
        next_inventory_at: '',
        holder_user_id: forEmployee ? String(forEmployee.id) : '',
        issued_at: today,
    });

    // «Отмена» leads back where the form was opened from, which is also where
    // a save returns: the colleague's card, or the list of units.
    const back = equipmentFormBack(forEmployee);

    const category = useMemo(
        () => options.types.find((type) => String(type.id) === form.data.equipment_type_id),
        [options.types, form.data.equipment_type_id],
    );
    // Everything the category asks about, the two it names a unit by included:
    // those two are asked above in boxes of their own, so the rest is what the
    // category's own inputs are drawn from.
    const asked = category?.fields ?? [];
    const fields = ownFields(asked);

    // Another category asks other things, so what was typed for the last one is
    // not carried over — it would only be saved into fields nobody chose.
    const pickType = (id: string) => {
        form.setData('equipment_type_id', id);
        setValues(blankValues(options.types.find((type) => String(type.id) === id)?.fields ?? []));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const again = batch.current;

        form.transform((data) => ({
            ...data,
            fields: values,
            // One box, comma by comma: "Блок питания 65 Вт, Сумка". A category
            // whose units come with nothing sends nothing.
            accessories: category?.has_accessories
                ? data.accessories
                      .split(',')
                      .map((item) => item.trim())
                      .filter(Boolean)
                : [],
            photos,
            holder_user_id: handingOver ? data.holder_user_id : null,
            issued_at: handingOver ? data.issued_at : null,
            another: again,
        }));

        // The colleague the unit is for rides in the query, where the server
        // reads it from: it is not the form's to change, and it survives
        // «сохранить и добавить ещё» so the next unit goes to the same person.
        form.post(forEmployee ? route('equipment.store', { for: forEmployee.id }) : route('equipment.store'), {
            forceFormData: true,
            // State is kept whichever button was pressed. Inertia keeps it by
            // default on a post, and it has to: a rejected save re-renders the
            // page, and a remounted form would come back empty, with the
            // server's complaints lost along with what had been typed. It also
            // leaves a dialog's card where it stood: a plain save redirects to
            // the colleague, which is the page the dialog is open on.
            preserveScroll: true,
            onSuccess: (page) => {
                batch.current = false;

                if (!again) {
                    onSaved?.();

                    return;
                }

                const filed = (page.props as unknown as SharedData).flash?.equipment;

                if (filed) setFiled((before) => [...before, filed]);

                // What the next box shares with this one stays; what is its own
                // is cleared, and the cursor waits on the number from the sticker.
                form.setData((data) => ({
                    ...data,
                    serial_number: '',
                    inventory_number: '',
                    holder_user_id: forEmployee ? String(forEmployee.id) : '',
                }));
                setPhotos([]);
                inventory.current?.focus();
            },
            onError: (errors) => {
                batch.current = false;

                // The boxes carry the server's own field names, so the first
                // thing it objected to can be brought into view and focused —
                // otherwise a save refused over a box further up looks like a
                // button that does nothing.
                const first = Object.keys(errors)[0];
                const box = first ? document.getElementById(first) : null;

                box?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                box?.focus({ preventScroll: true });
            },
        });
    };

    // Said the same way wherever the buttons stand, only placed differently.
    const complaints = Object.keys(form.errors).length;
    const refused = complaints > 0 ? `Не сохранено: проверьте ${complaints === 1 ? 'поле' : 'поля'} выше.` : null;

    return (
        /* noValidate: the server's rules are the real ones. */
        /* On a phone every box is 44px tall, a comfortable target for a thumb. */
        <form
            onSubmit={submit}
            noValidate
            className={cn('flex flex-col gap-6 max-md:[&_[role=combobox]]:h-11 max-md:[&_input]:h-11', inDialog && '@container')}
        >
            {header}

            <div className={row[layout]}>
                {/* The category comes first: everything below is what it
                    asks about, down to the two a unit is named by. */}
                <Field label="Категория" htmlFor="equipment_type_id" error={form.errors.equipment_type_id}>
                    <SearchableSelect
                        id="equipment_type_id"
                        value={form.data.equipment_type_id}
                        onChange={pickType}
                        options={options.types.map((type) => ({ value: String(type.id), label: type.name }))}
                        placeholder="Выберите категорию"
                        searchPlaceholder="Поиск категории"
                        empty="Категория не найдена"
                        invalid={!!form.errors.equipment_type_id}
                    />
                </Field>

                {/* Everything else waits for a category: it is the category
                    that decides what a unit is asked about, down to the two
                    it is named by — a printer's «Наименование» may read
                    «Модель» — while what is sent stays "name" and
                    "inventory_number". */}
                {category && (
                    <>
                        <Field label={roleName(asked, 'title')} htmlFor="name" error={form.errors.name}>
                            <FieldInput
                                field={roleField(asked, 'title')}
                                id="name"
                                value={form.data.name}
                                onChange={(value) => form.setData('name', String(value))}
                                placeholder="Ноутбук Dell Latitude 5440"
                                invalid={!!form.errors.name}
                            />
                        </Field>

                        <Field label={roleName(asked, 'inventory')} htmlFor="inventory_number" error={form.errors.inventory_number}>
                            <FieldInput
                                field={roleField(asked, 'inventory')}
                                inputRef={inventory}
                                id="inventory_number"
                                value={form.data.inventory_number}
                                onChange={(value) => form.setData('inventory_number', String(value))}
                                placeholder="EV-0421"
                                invalid={!!form.errors.inventory_number}
                            />
                        </Field>

                        {/* Whatever this category asks about: a processor for
                            a laptop, a diagonal for a monitor, nothing at all
                            for a category that has no fields of its own. */}
                        <CategoryFieldInputs
                            fields={fields}
                            values={values}
                            onChange={(id, value) => setValues((held) => ({ ...held, [id]: value }))}
                            error={(key) => at(form.errors as Record<string, string | undefined>, key)}
                        />

                        {category?.has_accessories && (
                            <Field label="Комплектация" htmlFor="accessories" error={form.errors.accessories} className={wide[layout]}>
                                <Input
                                    id="accessories"
                                    value={form.data.accessories}
                                    onChange={(event) => form.setData('accessories', event.target.value)}
                                    placeholder="Блок питания 65 Вт, Сумка, Мышь Logitech M185"
                                    aria-invalid={!!form.errors.accessories}
                                />
                                <p className="text-muted-foreground text-[13px]">Через запятую.</p>
                            </Field>
                        )}

                        {/* Left out, these stay empty and are filled on the card
                            whenever somebody gets to it. Ticking the box offers
                            today and a year from now, the usual answer. */}
                        <div className="col-span-full flex items-center gap-2.5">
                            <Checkbox
                                id="describe"
                                checked={describing}
                                onCheckedChange={(on) => {
                                    const asked = on === true;
                                    setDescribing(asked);
                                    form.setData((data) => ({
                                        ...data,
                                        condition: asked ? data.condition : '',
                                        checked_at: asked ? today : '',
                                        next_inventory_at: asked ? inAYear.toISOString().slice(0, 10) : '',
                                    }));
                                    if (!asked) setPhotos([]);
                                }}
                            />
                            <Label htmlFor="describe" className="font-normal">
                                Указать состояние
                            </Label>
                        </div>

                        {describing && (
                            <>
                                <Field label="Текущее состояние" htmlFor="condition" error={form.errors.condition} className={wide[layout]}>
                                    <Input
                                        id="condition"
                                        value={form.data.condition}
                                        onChange={(event) => form.setData('condition', event.target.value)}
                                        placeholder="Новое, в упаковке"
                                        aria-invalid={!!form.errors.condition}
                                    />
                                </Field>

                                {/* Two dates in the space of one field: a date box needs no more. */}
                                <div className={half[layout]}>
                                    <Field label="Последняя проверка" htmlFor="checked_at" error={form.errors.checked_at}>
                                        <Input
                                            id="checked_at"
                                            type="date"
                                            max={today}
                                            value={form.data.checked_at}
                                            onChange={(event) => form.setData('checked_at', event.target.value)}
                                            aria-invalid={!!form.errors.checked_at}
                                        />
                                    </Field>

                                    <Field label="След. инвентаризация" htmlFor="next_inventory_at" error={form.errors.next_inventory_at}>
                                        <Input
                                            id="next_inventory_at"
                                            type="date"
                                            value={form.data.next_inventory_at}
                                            onChange={(event) => form.setData('next_inventory_at', event.target.value)}
                                            aria-invalid={!!form.errors.next_inventory_at}
                                        />
                                    </Field>
                                </div>

                                <PhotoInput
                                    photos={photos}
                                    onChange={setPhotos}
                                    error={at(form.errors, 'photos')}
                                    hint="Останутся в журнале как вид при поступлении."
                                    className="col-span-full"
                                />
                            </>
                        )}
                    </>
                )}
            </div>

            {/* Not a property of the unit but something done with it, so it stands apart. */}
            {category && (
                <div className={cn(row[layout], 'border-t pt-6')}>
                    {/* Asked for whom only where nobody is fixed; from a card it is
                        already answered, and the form says so instead of asking. */}
                    {forEmployee ? (
                        <p className="text-muted-foreground col-span-full text-sm">
                            Единицу сразу получит <b className="text-foreground font-medium">{forEmployee.name}</b>.
                        </p>
                    ) : (
                        <div className="col-span-full flex items-center gap-2.5">
                            <Checkbox id="issue" checked={issuing} onCheckedChange={(on) => setIssuing(on === true)} />
                            <Label htmlFor="issue" className="font-normal">
                                Сразу выдать сотруднику
                            </Label>
                        </div>
                    )}

                    {handingOver && (
                        <>
                            {!forEmployee && (
                                <Field label="Кому" htmlFor="holder_user_id" error={form.errors.holder_user_id}>
                                    <SearchableSelect
                                        id="holder_user_id"
                                        value={form.data.holder_user_id}
                                        onChange={(value) => form.setData('holder_user_id', value)}
                                        options={(options.holders ?? []).map((holder) => ({ value: String(holder.id), label: holder.name }))}
                                        placeholder="Выберите сотрудника"
                                        searchPlaceholder="Поиск по фамилии"
                                        empty="Сотрудник не найден"
                                        invalid={!!form.errors.holder_user_id}
                                    />
                                </Field>
                            )}

                            <Field label="Дата выдачи" htmlFor="issued_at" error={form.errors.issued_at}>
                                <Input
                                    id="issued_at"
                                    type="date"
                                    className="max-w-[11.5rem]"
                                    max={today}
                                    value={form.data.issued_at}
                                    onChange={(event) => form.setData('issued_at', event.target.value)}
                                    aria-invalid={!!form.errors.issued_at}
                                />
                            </Field>
                        </>
                    )}
                </div>
            )}

            {/* A dialog has one unit to file and the card behind it to hand back:
                there is nowhere to go round again to, and its buttons belong in
                its own footer like every other dialog's. */}
            {inDialog ? (
                <>
                    {refused && <p className="text-sm text-red-600 dark:text-red-400">{refused}</p>}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onCancel}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </>
            ) : (
                /* A phone keeps the buttons in a bar that rides just above the tab
                   bar, so saving is never a scroll away; «Отмена» is the back arrow there. */
                <div className="max-md:bg-background/90 flex flex-col gap-2 border-t pt-6 max-md:sticky max-md:bottom-[calc(3.5rem+env(safe-area-inset-bottom))] max-md:z-20 max-md:-mx-4 max-md:-mb-4 max-md:flex-row max-md:flex-wrap max-md:items-center max-md:rounded-b-2xl max-md:px-4 max-md:py-3 max-md:backdrop-blur-xl sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                    {filed.length > 0 && (
                        <p className="text-muted-foreground mr-auto text-sm max-md:basis-full">
                            Добавлено {filed.length}, последнее —{' '}
                            <Link
                                href={route('equipment.show', filed[filed.length - 1].id)}
                                className="text-brand-strong font-medium hover:underline dark:text-[#C5E27A]"
                            >
                                {filed[filed.length - 1].name}
                            </Link>{' '}
                            (инв. № {filed[filed.length - 1].inventory_number})
                        </p>
                    )}

                    {refused && <p className="mr-auto text-sm text-red-600 max-md:basis-full dark:text-red-400">{refused}</p>}

                    <Button type="button" variant="outline" className="max-md:hidden" asChild>
                        <Link href={back}>Отмена</Link>
                    </Button>
                    {/* Shorter on a phone, where two buttons share a 320px line. */}
                    <Button
                        type="submit"
                        variant="outline"
                        aria-label="Сохранить и добавить ещё"
                        className="max-md:h-11 max-md:min-w-0 max-md:flex-[1.4] max-md:px-3"
                        disabled={form.processing}
                        onClick={() => (batch.current = true)}
                    >
                        <Plus className="max-md:hidden" />
                        <span className="md:hidden">Сохранить и ещё</span>
                        <span className="max-md:hidden">Сохранить и добавить ещё</span>
                    </Button>
                    <Button type="submit" className="max-md:h-11 max-md:flex-1" disabled={form.processing}>
                        {form.processing && <LoaderCircle className="animate-spin" />}
                        Сохранить
                    </Button>
                </div>
            )}
        </form>
    );
}
