import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { languageLevelLabels, languageLevels, sexLabels, type LanguageLevel, type Sex } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, LoaderCircle, Plus, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

interface Options {
    roles: { name: string; title: string }[];
    positions: { id: number; name: string }[];
    /** The department tree flattened, parents first. */
    departments: { id: number; name: string; depth: number }[];
    languages: { id: number; name: string }[];
    nationalities: string[];
    citizenships: string[];
    /** Hardware free to hand out, for the last step; empty for whoever may not issue it. */
    stock: { id: number; name: string; inventory_number: string }[];
}

/** Whom the first step created; every later one works on this person. */
type NewEmployee = { id: number; name: string; email: string };

const steps = [
    { title: 'Основные данные', note: 'Кто это' },
    { title: 'Контакты и языки', note: 'Как связаться, чем владеет' },
    { title: 'Паспорт и семья', note: 'Документ и близкие' },
    { title: 'Образование', note: 'Где учился' },
    { title: 'Трудовая деятельность', note: 'Где работал раньше' },
    { title: 'Оборудование', note: 'Что выдаём на руки' },
] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Сотрудники', href: '/employees' },
    { title: 'Новый сотрудник', href: '/employees/create' },
];

/** Errors come back as "records.0.institution"; a field shows its own. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/* ------------------------------------------------------------------ pieces */

function Field({ label, error, children, className }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/**
 * A step that keeps a list of records — degrees, jobs. Rows are added and
 * removed here and filed in one request when the step is left.
 */
function Records<T>({
    items,
    empty,
    addLabel,
    onAdd,
    onRemove,
    children,
}: {
    items: T[];
    empty: string;
    addLabel: string;
    onAdd: () => void;
    onRemove: (index: number) => void;
    children: (item: T, index: number) => ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4">
            {items.length === 0 && <p className="text-muted-foreground text-sm">{empty}</p>}

            {items.map((item, index) => (
                <div key={index} className="rounded-lg border p-4 pt-3">
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

/* ------------------------------------------------------------- record types */

type Education = {
    institution: string;
    faculty: string;
    specialty: string;
    started_year: string;
    graduated_year: string;
    diploma_number: string;
};

const blankEducation: Education = { institution: '', faculty: '', specialty: '', started_year: '', graduated_year: '', diploma_number: '' };

type Job = {
    organization: string;
    position: string;
    country: string;
    started_month: string;
    started_year: string;
    ended_month: string;
    ended_year: string;
};

/** This month, which no date of a past job can be later than. */
const thisMonth = new Date().toISOString().slice(0, 7);

/** A year and a month as a month box reads them: "2020-09". */
const asMonth = (year: string, month: string) => (year && month ? `${year}-${String(month).padStart(2, '0')}` : '');

/** And back apart again, since the server keeps a month and a year of its own. */
const fromMonth = (value: string, field: 'started' | 'ended') => {
    const [year, month] = value.split('-');

    return { [`${field}_year`]: year ?? '', [`${field}_month`]: month ? String(Number(month)) : '' };
};

const blankJob: Job = { organization: '', position: '', country: '', started_month: '', started_year: '', ended_month: '', ended_year: '' };

type Child = { full_name: string; birth_date: string };

type SpokenLanguage = { id: string; level: LanguageLevel };

/* -------------------------------------------------------------------- page */

/**
 * A new colleague, step by step: who they are, how to reach them, their
 * papers, their studies, their previous jobs and the hardware they are given.
 *
 * The first step creates them; every step after it fills part of the profile
 * in and may be left empty, which is how it is skipped — whatever is missed
 * here is edited later on the profile, card by card. It is a page rather than
 * a dialog so that half-finished work cannot be lost to a stray key.
 */
export default function CreateEmployee({ options, canIssue }: { options: Options; canIssue: boolean }) {
    const today = new Date().toISOString().slice(0, 10);
    const [step, setStep] = useState(0);
    const [employee, setEmployee] = useState<NewEmployee | null>(null);

    // Below lg the steps are a strip that scrolls sideways; the open one is
    // brought into it, or on a phone the fourth step would open out of sight.
    const strip = useRef<HTMLOListElement>(null);
    useEffect(() => {
        const list = strip.current;
        const item = list?.children[step] as HTMLElement | undefined;

        if (!list || !item || list.scrollWidth <= list.clientWidth) return;

        list.scrollTo({ left: item.offsetLeft - list.offsetLeft - 8, behavior: 'smooth' });
    }, [step]);

    const main = useForm({
        surname: '',
        name: '',
        patronymic: '',
        sex: 'male' as Sex,
        birth_date: '',
        birth_place: '',
        citizenship: [] as string[],
        nationality: '',
        home_address: '',
        email: '',
        hired_at: today,
        roles: [] as string[],
        positions: [] as number[],
        departments: [] as number[],
        continue: true,
    });

    const contacts = useForm({ email: '', phone: '', sos_phone: '', sos_contact: '' });
    const languages = useForm({ languages: [] as SpokenLanguage[] });
    const [createdLanguages, setCreatedLanguages] = useState<string[]>([]);
    const passport = useForm({ passport_series: '', passport_number: '', passport_issued_at: '', passport_issued_by: '' });
    const family = useForm({
        marital_status: '',
        spouse_name: '',
        spouse_birth_date: '',
        has_children: '' as '' | 'yes' | 'no',
        children: [] as Child[],
    });
    const educations = useForm({ records: [] as Education[] });
    const jobs = useForm({ records: [] as Job[] });
    const equipment = useForm({ equipment: [] as number[], issued_at: today });

    const forms = [main, contacts, languages, passport, family, educations, jobs, equipment];
    const busy = forms.some((form) => form.processing);

    // Leaving with a half-filled step would throw the work away, so the browser
    // asks first. Once the last step is done there is nothing left to lose.
    const dirty = forms.some((form) => form.isDirty) && step < steps.length;
    useEffect(() => {
        if (!dirty) return;

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const toProfile = () => {
        if (employee) router.visit(route('employees.show', employee.id));
        else router.visit(route('employees.index'));
    };

    /**
     * Back to a blank first step, for whoever is filing a whole intake at
     * once. The colleague just finished is saved and left behind.
     */
    const startOver = () => {
        // Each step carries a form of its own shape; emptying one needs none of
        // its field names, so the list is read through the two calls it shares.
        const blank: { clearErrors: () => void; reset: () => void }[] = forms;

        blank.forEach((form) => {
            form.clearErrors();
            form.reset();
        });

        setEmployee(null);
        setStep(0);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /**
     * Where a step leads once it is filed: on to the next one, off to the new
     * colleague's profile, or back to a blank form for the one after them.
     */
    type After = 'next' | 'profile' | 'again';

    const land = (after: After, to: number) => {
        if (after === 'again') startOver();
        else if (after === 'profile') toProfile();
        else setStep(to);
    };

    /** Filing a step keeps the page where it is and its state intact. */
    const go = (after: After, to: number) => ({ preserveScroll: true, preserveState: true, onSuccess: () => land(after, to) }) as const;

    /**
     * Each step files what it holds, then hands over. Stopping after any one
     * of them is allowed: the colleague is on the books from the first step,
     * and whatever was skipped is filled in later from their profile.
     */
    const next = (after: After = 'next') => {
        if (step === 0) {
            if (employee) {
                // Already on the books: this step now corrects what it filed.
                // Filing again would be refused over the e-mail it has taken,
                // which is what used to strand whoever stepped back to look.
                main.transform((data) => ({
                    surname: data.surname,
                    name: data.name,
                    patronymic: data.patronymic,
                    sex: data.sex,
                    birth_date: data.birth_date,
                    birth_place: data.birth_place,
                    citizenship: data.citizenship,
                    nationality: data.nationality,
                    home_address: data.home_address,
                    roles: data.roles,
                    positions: data.positions,
                    departments: data.departments,
                }));
                main.put(route('employees.personal', employee.id), {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        main.transform((data) => ({ hired_at: data.hired_at }));
                        main.put(route('employees.employment', employee.id), go(after, 1));
                    },
                });

                return;
            }

            // A plain send: an earlier correction may have left a transform behind.
            main.transform((data) => data);
            main.post(route('employees.store'), {
                preserveScroll: true,
                preserveState: true,
                // Who was just created rides back on the page, flashed by the
                // controller: the later steps all work on that person.
                onSuccess: (fresh) => {
                    const created = (fresh.props as unknown as SharedData).flash?.employee as NewEmployee | undefined;
                    if (!created) return;

                    setEmployee(created);
                    contacts.setData('email', created.email);

                    // "Again" has nothing to go back to yet, so it starts over
                    // from the person just filed rather than from this form.
                    if (after === 'again') startOver();
                    else if (after === 'profile') router.visit(route('employees.show', created.id));
                    else setStep(1);
                },
            });

            return;
        }

        if (!employee) return;

        if (step === 1) {
            // Contacts and languages are two cards, so two requests; the step
            // is only left once both have gone through.
            contacts.put(route('employees.contacts', employee.id), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    languages.transform((data) => ({
                        languages: data.languages
                            .filter((spoken) => spoken.id !== '')
                            .map((spoken) =>
                                String(spoken.id).startsWith('new:')
                                    ? { name: String(spoken.id).slice(4), level: spoken.level }
                                    : { id: Number(spoken.id), level: spoken.level },
                            ),
                    }));

                    languages.put(route('employees.languages', employee.id), go(after, 2));
                },
            });

            return;
        }

        if (step === 2) {
            passport.put(route('employees.passport', employee.id), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    family.transform((data) => ({
                        marital_status: data.marital_status || null,
                        spouse_name: data.spouse_name,
                        spouse_birth_date: data.spouse_birth_date || null,
                        has_children: data.has_children === '' ? null : data.has_children === 'yes',
                        children: data.children.filter((child) => child.full_name.trim() !== ''),
                    }));

                    family.put(route('employees.family', employee.id), go(after, 3));
                },
            });

            return;
        }

        if (step === 3) {
            educations.post(route('employees.educations.many', employee.id), go(after, 4));

            return;
        }

        if (step === 4) {
            jobs.post(route('employees.experiences.many', employee.id), go(after, 5));

            return;
        }

        // The last step: "next" has nowhere further to go, so it finishes.
        // Handing hardware out is the right to issue it, not a line of the card:
        // without it there is nothing to file, and the step only leads on.
        if (!canIssue) {
            land(after === 'next' ? 'profile' : after, step);

            return;
        }

        equipment.post(route('employees.equipment.store', employee.id), go(after === 'next' ? 'profile' : after, step));
    };

    const setRecord = <T,>(form: { data: { records: T[] }; setData: (key: 'records', value: T[]) => void }, index: number, patch: Partial<T>) =>
        form.setData(
            'records',
            form.data.records.map((record, position) => (position === index ? { ...record, ...patch } : record)),
        );

    const dropRecord = <T,>(form: { data: { records: T[] }; setData: (key: 'records', value: T[]) => void }, index: number) =>
        form.setData(
            'records',
            form.data.records.filter((_, position) => position !== index),
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Новый сотрудник" />

            <div className="flex flex-1 flex-col gap-5 p-3 max-md:gap-3 md:px-5 md:py-4">
                <div className="flex flex-wrap items-end justify-between gap-3 max-md:flex-nowrap max-md:items-center">
                    <div className="flex min-w-0 flex-col gap-1">
                        {/* The phone's top bar already carries the page title. */}
                        <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Новый сотрудник</h1>
                        <p className="text-muted-foreground text-sm max-md:text-[13px]">
                            {employee ? (
                                <>
                                    {employee.name}
                                    {/* A phone says which step it is in the progress header below. */}
                                    <span className="max-md:hidden">{` · шаг ${step + 1} из ${steps.length}`}</span>
                                </>
                            ) : (
                                'Пароль сгенерируется сам и придёт сотруднику на почту.'
                            )}
                        </p>
                    </div>

                    {/* «Отмена» is the back arrow's job on a phone; «Закончить позже» leads somewhere else and stays. */}
                    <Button
                        variant="ghost"
                        className={cn('max-md:-mr-2 max-md:shrink-0 max-md:px-2', !employee && 'max-md:hidden')}
                        onClick={toProfile}
                    >
                        {employee ? 'Закончить позже' : 'Отмена'}
                    </Button>
                </div>

                <div className="grid grid-cols-1 gap-5 max-md:gap-3 lg:grid-cols-[18rem_1fr] lg:items-start">
                    {/* On a phone the six step buttons give way to one line and a
                    progress bar. Its segments still jump between steps once the
                    colleague is on the books, as the buttons do. */}
                    <div className="flex flex-col gap-2 px-1 md:hidden">
                        <p className="text-[15px]">
                            <span className="font-semibold">
                                Шаг {step + 1} из {steps.length}
                            </span>
                            <span className="text-muted-foreground"> · {steps[step].title}</span>
                        </p>
                        <div className="flex gap-1">
                            {steps.map((item, index) => (
                                <button
                                    key={item.title}
                                    type="button"
                                    disabled={employee === null && index !== step}
                                    aria-current={index === step ? 'step' : undefined}
                                    aria-label={`Шаг ${index + 1}: ${item.title}`}
                                    onClick={() => employee !== null && setStep(index)}
                                    className="-my-2 flex-1 py-2 disabled:cursor-default"
                                >
                                    <span
                                        className={cn(
                                            'block h-1 rounded-full transition-colors',
                                            index <= step ? 'bg-brand' : 'bg-muted-foreground/20',
                                        )}
                                    />
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* The road ahead: what is done, where you are, what is left. */}
                    <Card className="flex flex-col gap-1 rounded-xl p-3 max-md:hidden">
                        <ol
                            ref={strip}
                            className="flex gap-0.5 overflow-x-auto [scrollbar-width:none] lg:flex-col lg:overflow-visible [&::-webkit-scrollbar]:hidden"
                        >
                            {steps.map((item, index) => {
                                const done = index < step;
                                const current = index === step;
                                // Once the colleague is on the books every step is
                                // open, in both directions: each is a card of its own,
                                // filed by its own button. Before that there is
                                // nothing to file, so only the first step works.
                                const reachable = employee !== null;

                                return (
                                    <li key={item.title} className="shrink-0">
                                        <button
                                            type="button"
                                            disabled={!reachable && !current}
                                            aria-current={current ? 'step' : undefined}
                                            onClick={() => reachable && setStep(index)}
                                            className={cn(
                                                'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors',
                                                current && 'bg-muted',
                                                reachable && 'hover:bg-accent',
                                                !reachable && !current && 'cursor-default',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                                    done
                                                        ? 'bg-brand-soft text-brand-strong dark:bg-white/10 dark:text-[#C5E27A]'
                                                        : current
                                                          ? 'bg-foreground text-background'
                                                          : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {done ? <Check className="size-3.5" /> : index + 1}
                                            </span>
                                            <span className="flex min-w-0 flex-col">
                                                <span
                                                    className={cn('text-sm whitespace-nowrap lg:truncate', current ? 'font-semibold' : 'font-medium')}
                                                >
                                                    {item.title}
                                                </span>
                                                <span className="text-muted-foreground hidden truncate text-[13px] lg:block">{item.note}</span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </Card>

                    <Card className="rounded-xl p-4 max-md:rounded-2xl max-md:border-0 max-md:shadow-none md:p-6">
                        {/* noValidate: the server's rules are the real ones. */}
                        <form
                            noValidate
                            onSubmit={(event) => {
                                event.preventDefault();
                                next(step === steps.length - 1 ? 'profile' : 'next');
                            }}
                            className="@container flex flex-col gap-6 max-md:gap-4 max-md:[&_[role=combobox]]:h-11 max-md:[&_input:not([type=checkbox])]:h-11"
                        >
                            <div className="flex flex-col gap-1 max-md:contents">
                                <h2 className="text-base font-semibold max-md:sr-only">{steps[step].title}</h2>
                                {step > 0 && <p className="text-muted-foreground text-[13px]">Шаг необязательный — его можно пропустить.</p>}
                            </div>

                            {step === 0 && (
                                <div className="flex flex-col gap-4">
                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Фамилия" error={main.errors.surname}>
                                            <Input
                                                value={main.data.surname}
                                                onChange={(event) => main.setData('surname', event.target.value)}
                                                aria-invalid={!!main.errors.surname}
                                            />
                                        </Field>
                                        <Field label="Имя" error={main.errors.name}>
                                            <Input
                                                value={main.data.name}
                                                onChange={(event) => main.setData('name', event.target.value)}
                                                aria-invalid={!!main.errors.name}
                                            />
                                        </Field>
                                        <Field label="Отчество" error={main.errors.patronymic}>
                                            <Input
                                                value={main.data.patronymic}
                                                onChange={(event) => main.setData('patronymic', event.target.value)}
                                                aria-invalid={!!main.errors.patronymic}
                                            />
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="E-mail" error={main.errors.email}>
                                            <Input
                                                type="email"
                                                value={main.data.email}
                                                onChange={(event) => main.setData('email', event.target.value)}
                                                placeholder="name@evolet.tj"
                                                aria-invalid={!!main.errors.email}
                                            />
                                            <p className="text-muted-foreground text-[13px]">С этим адресом сотрудник входит в систему.</p>
                                        </Field>
                                        <Field label="Пол" error={main.errors.sex}>
                                            <SearchableSelect
                                                value={main.data.sex}
                                                onChange={(value) => main.setData('sex', value as Sex)}
                                                options={[
                                                    { value: 'male', label: sexLabels.male },
                                                    { value: 'female', label: sexLabels.female },
                                                ]}
                                                invalid={!!main.errors.sex}
                                            />
                                        </Field>
                                        <Field label="Дата рождения" error={main.errors.birth_date}>
                                            <Input
                                                type="date"
                                                max={today}
                                                value={main.data.birth_date}
                                                onChange={(event) => main.setData('birth_date', event.target.value)}
                                                aria-invalid={!!main.errors.birth_date}
                                            />
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Место рождения" error={main.errors.birth_place}>
                                            <Input
                                                value={main.data.birth_place}
                                                onChange={(event) => main.setData('birth_place', event.target.value)}
                                                placeholder="г. Худжанд"
                                                aria-invalid={!!main.errors.birth_place}
                                            />
                                        </Field>
                                        <Field
                                            label="Гражданство"
                                            // A wrong country is reported on its own line ("citizenship.1").
                                            error={
                                                main.errors.citizenship ??
                                                Object.entries(main.errors).find(([key]) => key.startsWith('citizenship.'))?.[1]
                                            }
                                        >
                                            <MultiSelect
                                                creatable
                                                options={options.citizenships.map((value) => ({ value, label: value }))}
                                                value={main.data.citizenship}
                                                onChange={(value) => main.setData('citizenship', value)}
                                                placeholder="Выберите или впишите страну"
                                                searchPlaceholder="Страна"
                                            />
                                        </Field>
                                        <Field label="Национальность" error={main.errors.nationality}>
                                            <Input
                                                list="nationalities"
                                                value={main.data.nationality}
                                                onChange={(event) => main.setData('nationality', event.target.value)}
                                                aria-invalid={!!main.errors.nationality}
                                            />
                                            <datalist id="nationalities">
                                                {options.nationalities.map((value) => (
                                                    <option key={value} value={value} />
                                                ))}
                                            </datalist>
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Начало работы" error={main.errors.hired_at}>
                                            <Input
                                                type="date"
                                                max={today}
                                                value={main.data.hired_at}
                                                onChange={(event) => main.setData('hired_at', event.target.value)}
                                                aria-invalid={!!main.errors.hired_at}
                                            />
                                        </Field>
                                        {/* An address is longer than a date, so it takes the other two. */}
                                        <Field label="Домашний адрес" error={main.errors.home_address} className="@min-[22rem]:col-span-2">
                                            <Input
                                                value={main.data.home_address}
                                                onChange={(event) => main.setData('home_address', event.target.value)}
                                                aria-invalid={!!main.errors.home_address}
                                            />
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Позиция" error={at(main.errors, 'roles')}>
                                            <MultiSelect
                                                options={options.roles.map((role) => ({
                                                    value: role.name,
                                                    label: role.title,
                                                }))}
                                                value={main.data.roles}
                                                onChange={(value) => main.setData('roles', value)}
                                            />
                                        </Field>
                                        <Field label="Должность" error={at(main.errors, 'positions')}>
                                            <MultiSelect
                                                options={options.positions.map((position) => ({ value: position.id, label: position.name }))}
                                                value={main.data.positions}
                                                onChange={(value) => main.setData('positions', value)}
                                            />
                                        </Field>
                                        <Field label="Отдел" error={at(main.errors, 'departments')}>
                                            <MultiSelect
                                                options={options.departments.map((department) => ({
                                                    value: department.id,
                                                    label: department.name,
                                                    depth: department.depth,
                                                }))}
                                                value={main.data.departments}
                                                onChange={(value) => main.setData('departments', value)}
                                            />
                                        </Field>
                                    </div>
                                </div>
                            )}

                            {step === 1 && (
                                <div className="flex flex-col gap-6">
                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Телефон" error={contacts.errors.phone}>
                                            <Input
                                                value={contacts.data.phone}
                                                onChange={(event) => contacts.setData('phone', event.target.value)}
                                                placeholder="+992 90 123 45 67"
                                                aria-invalid={!!contacts.errors.phone}
                                            />
                                        </Field>
                                        <Field label="Телефон SOS" error={contacts.errors.sos_phone}>
                                            <Input
                                                value={contacts.data.sos_phone}
                                                onChange={(event) => contacts.setData('sos_phone', event.target.value)}
                                                placeholder="+992 90 765 43 21"
                                                aria-invalid={!!contacts.errors.sos_phone}
                                            />
                                        </Field>
                                        <Field label="Чей это номер" error={contacts.errors.sos_contact ?? contacts.errors.email}>
                                            <Input
                                                value={contacts.data.sos_contact}
                                                onChange={(event) => contacts.setData('sos_contact', event.target.value)}
                                                placeholder="Супруга, Нигина"
                                                aria-invalid={!!contacts.errors.sos_contact}
                                            />
                                        </Field>
                                    </div>

                                    <div className="flex flex-col gap-3 border-t pt-6">
                                        <Label>Знание языков</Label>
                                        <InputError message={at(languages.errors, 'languages')} />

                                        {languages.data.languages.length === 0 && (
                                            <p className="text-muted-foreground text-sm">Если сведений нет, шаг можно пропустить.</p>
                                        )}

                                        {languages.data.languages.map((spoken, index) => (
                                            <div key={index} className="flex flex-wrap items-center gap-2 @min-[30rem]:flex-nowrap">
                                                <SearchableSelect
                                                    className="min-w-0 grow basis-full @min-[30rem]:basis-0"
                                                    invalid={
                                                        !!at(languages.errors, `languages.${index}.id`) ||
                                                        !!at(languages.errors, `languages.${index}.name`)
                                                    }
                                                    value={spoken.id}
                                                    onChange={(value) =>
                                                        languages.setData(
                                                            'languages',
                                                            languages.data.languages.map((item, position) =>
                                                                position === index ? { ...item, id: value } : item,
                                                            ),
                                                        )
                                                    }
                                                    options={[
                                                        ...options.languages.map((language) => ({
                                                            value: String(language.id),
                                                            label: language.name,
                                                        })),
                                                        // Typed in and not on the list yet: saved by name, added to it then.
                                                        ...createdLanguages.map((name) => ({ value: `new:${name}`, label: name })),
                                                    ]}
                                                    onCreate={(name) => {
                                                        setCreatedLanguages((current) => (current.includes(name) ? current : [...current, name]));
                                                        languages.setData(
                                                            'languages',
                                                            languages.data.languages.map((item, position) =>
                                                                position === index ? { ...item, id: `new:${name}` } : item,
                                                            ),
                                                        );
                                                    }}
                                                    placeholder="Язык"
                                                    searchPlaceholder="Поиск языка"
                                                    empty="Язык не найден"
                                                />
                                                <SearchableSelect
                                                    className="min-w-0 flex-1 @min-[30rem]:w-48 @min-[30rem]:flex-none"
                                                    invalid={!!at(languages.errors, `languages.${index}.level`)}
                                                    value={spoken.level}
                                                    onChange={(value) =>
                                                        languages.setData(
                                                            'languages',
                                                            languages.data.languages.map((item, position) =>
                                                                position === index ? { ...item, level: value as LanguageLevel } : item,
                                                            ),
                                                        )
                                                    }
                                                    options={languageLevels.map((level) => ({ value: level, label: languageLevelLabels[level] }))}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-muted-foreground size-9 shrink-0"
                                                    aria-label={`Убрать язык ${index + 1}`}
                                                    onClick={() =>
                                                        languages.setData(
                                                            'languages',
                                                            languages.data.languages.filter((_, position) => position !== index),
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        ))}

                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="self-start"
                                            onClick={() =>
                                                languages.setData('languages', [...languages.data.languages, { id: '', level: 'intermediate' }])
                                            }
                                        >
                                            <Plus />
                                            Добавить язык
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {step === 2 && (
                                <div className="flex flex-col gap-6">
                                    <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="Серия паспорта" error={passport.errors.passport_series}>
                                                <Input
                                                    value={passport.data.passport_series}
                                                    onChange={(event) => passport.setData('passport_series', event.target.value)}
                                                    placeholder="A"
                                                    aria-invalid={!!passport.errors.passport_series}
                                                />
                                            </Field>
                                            <Field label="Номер паспорта" error={passport.errors.passport_number}>
                                                <Input
                                                    value={passport.data.passport_number}
                                                    onChange={(event) => passport.setData('passport_number', event.target.value)}
                                                    placeholder="01234567"
                                                    aria-invalid={!!passport.errors.passport_number}
                                                />
                                            </Field>
                                        </div>
                                        <Field label="Дата выдачи" error={passport.errors.passport_issued_at}>
                                            <Input
                                                type="date"
                                                max={today}
                                                value={passport.data.passport_issued_at}
                                                onChange={(event) => passport.setData('passport_issued_at', event.target.value)}
                                                aria-invalid={!!passport.errors.passport_issued_at}
                                            />
                                        </Field>
                                        <Field label="Кем выдан" error={passport.errors.passport_issued_by}>
                                            <Input
                                                value={passport.data.passport_issued_by}
                                                onChange={(event) => passport.setData('passport_issued_by', event.target.value)}
                                                aria-invalid={!!passport.errors.passport_issued_by}
                                            />
                                        </Field>
                                    </div>

                                    <div className="grid gap-4 border-t pt-6 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                        <Field label="Семейное положение" error={family.errors.marital_status}>
                                            <SearchableSelect
                                                value={family.data.marital_status}
                                                onChange={(value) => family.setData('marital_status', value)}
                                                options={[
                                                    { value: 'single', label: 'Не женат / не замужем' },
                                                    { value: 'married', label: 'Женат / замужем' },
                                                ]}
                                                placeholder="Не указано"
                                                invalid={!!family.errors.marital_status}
                                            />
                                        </Field>
                                        <Field label="Дети">
                                            <SearchableSelect
                                                invalid={!!family.errors.has_children}
                                                value={family.data.has_children}
                                                onChange={(value) => family.setData('has_children', value as 'yes' | 'no')}
                                                options={[
                                                    { value: 'yes', label: 'Есть' },
                                                    { value: 'no', label: 'Нет' },
                                                ]}
                                                placeholder="Не указано"
                                            />
                                        </Field>
                                    </div>

                                    {family.data.marital_status === 'married' && (
                                        <div className="flex flex-col gap-4 @min-[22rem]:flex-row @min-[22rem]:items-end @min-[22rem]:gap-2">
                                            <Field label="ФИО супруга" className="flex-1" error={family.errors.spouse_name}>
                                                <Input
                                                    value={family.data.spouse_name}
                                                    onChange={(event) => family.setData('spouse_name', event.target.value)}
                                                    aria-invalid={!!family.errors.spouse_name}
                                                />
                                            </Field>
                                            <Field label="Дата рождения" error={family.errors.spouse_birth_date}>
                                                <Input
                                                    type="date"
                                                    max={today}
                                                    value={family.data.spouse_birth_date}
                                                    onChange={(event) => family.setData('spouse_birth_date', event.target.value)}
                                                    aria-invalid={!!family.errors.spouse_birth_date}
                                                />
                                            </Field>
                                        </div>
                                    )}

                                    {family.data.has_children === 'yes' && (
                                        <div className="flex flex-col gap-3">
                                            {family.data.children.map((child, index) => (
                                                <div key={index} className="flex flex-wrap items-end gap-2 @min-[22rem]:flex-nowrap">
                                                    <Field
                                                        label="ФИО ребёнка"
                                                        className="min-w-0 grow basis-full @min-[22rem]:basis-0"
                                                        error={at(family.errors, `children.${index}.full_name`)}
                                                    >
                                                        <Input
                                                            value={child.full_name}
                                                            onChange={(event) =>
                                                                family.setData(
                                                                    'children',
                                                                    family.data.children.map((item, position) =>
                                                                        position === index ? { ...item, full_name: event.target.value } : item,
                                                                    ),
                                                                )
                                                            }
                                                            aria-invalid={!!at(family.errors, `children.${index}.full_name`)}
                                                        />
                                                    </Field>
                                                    <Field
                                                        label="Дата рождения"
                                                        className="min-w-0 flex-1 @min-[22rem]:flex-none"
                                                        error={at(family.errors, `children.${index}.birth_date`)}
                                                    >
                                                        <Input
                                                            type="date"
                                                            max={today}
                                                            value={child.birth_date}
                                                            onChange={(event) =>
                                                                family.setData(
                                                                    'children',
                                                                    family.data.children.map((item, position) =>
                                                                        position === index ? { ...item, birth_date: event.target.value } : item,
                                                                    ),
                                                                )
                                                            }
                                                            aria-invalid={!!at(family.errors, `children.${index}.birth_date`)}
                                                        />
                                                    </Field>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground mb-[2px] size-9 shrink-0"
                                                        aria-label={`Убрать ребёнка ${index + 1}`}
                                                        onClick={() =>
                                                            family.setData(
                                                                'children',
                                                                family.data.children.filter((_, position) => position !== index),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                </div>
                                            ))}

                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="self-start"
                                                onClick={() =>
                                                    family.setData('children', [...family.data.children, { full_name: '', birth_date: '' }])
                                                }
                                            >
                                                <Plus />
                                                Добавить ребёнка
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}

                            {step === 3 && (
                                <Records
                                    items={educations.data.records}
                                    empty="Если сведений нет, шаг можно пропустить."
                                    addLabel="Добавить образование"
                                    onAdd={() => educations.setData('records', [...educations.data.records, { ...blankEducation }])}
                                    onRemove={(index) => dropRecord(educations, index)}
                                >
                                    {(record, index) => (
                                        <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                            <Field
                                                label="Учебное заведение"
                                                className="@min-[22rem]:col-span-2"
                                                error={at(educations.errors, `records.${index}.institution`)}
                                            >
                                                <Input
                                                    value={record.institution}
                                                    onChange={(event) => setRecord(educations, index, { institution: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.institution`)}
                                                />
                                            </Field>
                                            <Field label="Специальность" error={at(educations.errors, `records.${index}.specialty`)}>
                                                <Input
                                                    value={record.specialty}
                                                    onChange={(event) => setRecord(educations, index, { specialty: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.specialty`)}
                                                />
                                            </Field>
                                            <Field
                                                label="Факультет"
                                                className="@min-[22rem]:col-span-2"
                                                error={at(educations.errors, `records.${index}.faculty`)}
                                            >
                                                <Input
                                                    value={record.faculty}
                                                    onChange={(event) => setRecord(educations, index, { faculty: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.faculty`)}
                                                />
                                            </Field>
                                            <Field label="Номер диплома" error={at(educations.errors, `records.${index}.diploma_number`)}>
                                                <Input
                                                    value={record.diploma_number}
                                                    onChange={(event) => setRecord(educations, index, { diploma_number: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.diploma_number`)}
                                                />
                                            </Field>
                                            <Field label="Год поступления" error={at(educations.errors, `records.${index}.started_year`)}>
                                                <Input
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={1950}
                                                    placeholder="2015"
                                                    value={record.started_year}
                                                    onChange={(event) => setRecord(educations, index, { started_year: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.started_year`)}
                                                />
                                            </Field>
                                            <Field label="Год окончания" error={at(educations.errors, `records.${index}.graduated_year`)}>
                                                <Input
                                                    type="number"
                                                    inputMode="numeric"
                                                    min={1950}
                                                    placeholder="Пусто — ещё учится"
                                                    value={record.graduated_year}
                                                    onChange={(event) => setRecord(educations, index, { graduated_year: event.target.value })}
                                                    aria-invalid={!!at(educations.errors, `records.${index}.graduated_year`)}
                                                />
                                            </Field>
                                        </div>
                                    )}
                                </Records>
                            )}

                            {step === 4 && (
                                <Records
                                    items={jobs.data.records}
                                    empty="Если сведений нет, шаг можно пропустить."
                                    addLabel="Добавить место работы"
                                    onAdd={() => jobs.setData('records', [...jobs.data.records, { ...blankJob }])}
                                    onRemove={(index) => dropRecord(jobs, index)}
                                >
                                    {(record, index) => (
                                        <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                            <Field label="Организация" error={at(jobs.errors, `records.${index}.organization`)}>
                                                <Input
                                                    value={record.organization}
                                                    onChange={(event) => setRecord(jobs, index, { organization: event.target.value })}
                                                    aria-invalid={!!at(jobs.errors, `records.${index}.organization`)}
                                                />
                                            </Field>
                                            <Field label="Должность" error={at(jobs.errors, `records.${index}.position`)}>
                                                <Input
                                                    value={record.position}
                                                    onChange={(event) => setRecord(jobs, index, { position: event.target.value })}
                                                    aria-invalid={!!at(jobs.errors, `records.${index}.position`)}
                                                />
                                            </Field>
                                            <Field label="Страна" error={at(jobs.errors, `records.${index}.country`)}>
                                                <Input
                                                    list="job-countries"
                                                    value={record.country}
                                                    onChange={(event) => setRecord(jobs, index, { country: event.target.value })}
                                                    aria-invalid={!!at(jobs.errors, `records.${index}.country`)}
                                                />
                                            </Field>

                                            {/*
                                             * One box per date, picked as a month: that is the precision a
                                             * previous job is remembered to, and the record keeps the two
                                             * halves apart as the server does.
                                             */}
                                            <Field label="Дата вступления" error={at(jobs.errors, `records.${index}.started_year`)}>
                                                <Input
                                                    type="month"
                                                    max={thisMonth}
                                                    value={asMonth(record.started_year, record.started_month)}
                                                    onChange={(event) => setRecord(jobs, index, fromMonth(event.target.value, 'started'))}
                                                    aria-invalid={
                                                        !!at(jobs.errors, `records.${index}.started_month`) ||
                                                        !!at(jobs.errors, `records.${index}.started_year`)
                                                    }
                                                />
                                            </Field>
                                            <Field label="Дата ухода" error={at(jobs.errors, `records.${index}.ended_year`)}>
                                                <Input
                                                    type="month"
                                                    max={thisMonth}
                                                    value={asMonth(record.ended_year, record.ended_month)}
                                                    onChange={(event) => setRecord(jobs, index, fromMonth(event.target.value, 'ended'))}
                                                    aria-invalid={
                                                        !!at(jobs.errors, `records.${index}.ended_month`) ||
                                                        !!at(jobs.errors, `records.${index}.ended_year`)
                                                    }
                                                />
                                                <p className="text-muted-foreground text-[13px]">Пусто — работает там до сих пор.</p>
                                            </Field>
                                        </div>
                                    )}
                                </Records>
                            )}

                            {step === 5 && !canIssue && (
                                <p className="text-muted-foreground text-sm">
                                    Выдать оборудование здесь нельзя: для этого нужно право «Выдача» в разделе «Оборудование». Технику новому
                                    сотруднику выдаст тот, у кого оно есть.
                                </p>
                            )}

                            {step === 5 && canIssue && (
                                <div className="grid gap-4 md:@min-[22rem]:grid-cols-2 md:@min-[30rem]:grid-cols-3">
                                    <Field label="Что выдаём" className="@min-[22rem]:col-span-2" error={at(equipment.errors, 'equipment')}>
                                        <MultiSelect
                                            options={options.stock.map((unit) => ({
                                                value: unit.id,
                                                label: `${unit.name} · ${unit.inventory_number}`,
                                            }))}
                                            value={equipment.data.equipment}
                                            onChange={(value) => equipment.setData('equipment', value)}
                                            placeholder="Ничего не выбрано"
                                            searchPlaceholder="Поиск по названию или номеру"
                                        />
                                        <p className="text-muted-foreground text-[13px]">Только то, что свободно и никому не выдано.</p>
                                    </Field>

                                    <Field label="Дата выдачи" error={equipment.errors.issued_at}>
                                        <Input
                                            type="date"
                                            max={today}
                                            value={equipment.data.issued_at}
                                            onChange={(event) => equipment.setData('issued_at', event.target.value)}
                                            aria-invalid={!!equipment.errors.issued_at}
                                        />
                                    </Field>
                                </div>
                            )}

                            {/* On a phone the buttons are one bar that sticks above the tab
                            bar while the step scrolls: the two side actions shrink to icons
                            (their words stay for screen readers) and the main one takes the
                            rest of the width. */}
                            <div className="max-md:bg-background/90 flex flex-col-reverse gap-2 border-t pt-5 max-md:sticky max-md:bottom-[calc(3.5rem+env(safe-area-inset-bottom))] max-md:z-20 max-md:-mx-4 max-md:-mb-4 max-md:flex-row max-md:items-center max-md:rounded-b-2xl max-md:px-4 max-md:py-3 max-md:backdrop-blur-xl md:flex-row md:flex-wrap md:items-center md:justify-between">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="max-md:size-11 max-md:shrink-0 max-md:px-0"
                                    disabled={step === 0 || busy}
                                    onClick={() => setStep(step - 1)}
                                >
                                    <ChevronLeft />
                                    <span className="max-md:sr-only">Назад</span>
                                </Button>

                                <div className="flex flex-col-reverse gap-2 max-md:min-w-0 max-md:flex-1 max-md:flex-row md:flex-row md:flex-wrap">
                                    {/*
                                     * Filing a whole intake: save this step and start the next
                                     * colleague straight away. From the first step it is the quick
                                     * way in — name, e-mail, done, next person.
                                     */}
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="max-md:size-11 max-md:shrink-0 max-md:px-0"
                                        disabled={busy}
                                        onClick={() => next('again')}
                                    >
                                        <UserPlus />
                                        <span className="max-md:sr-only">{step === 0 ? 'Создать и добавить ещё' : 'Сохранить и добавить ещё'}</span>
                                    </Button>

                                    <Button type="submit" className="max-md:h-11 max-md:min-w-0 max-md:flex-1" disabled={busy}>
                                        {busy && <LoaderCircle className="animate-spin" />}
                                        {step === steps.length - 1 ? (
                                            'Готово'
                                        ) : step === 0 ? (
                                            // A 320px phone has no room for the whole phrase beside the
                                            // two icon buttons; the arrow says the rest.
                                            <>
                                                <span className="md:hidden">Создать</span>
                                                <span className="max-md:hidden">Создать и продолжить</span>
                                            </>
                                        ) : (
                                            'Далее'
                                        )}
                                        {step < steps.length - 1 && <ChevronRight />}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
