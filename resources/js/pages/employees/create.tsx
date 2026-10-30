import { DatePicker } from '@/components/date-picker';
import { FormGrid } from '@/components/form-grid';
import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import { placementsToSend, type Placement, type PlacementOptions } from '@/components/placements';
import { SearchableSelect } from '@/components/searchable-select';
import { SectionNav } from '@/components/section-nav';
import { SectionTitle } from '@/components/section-title';
import { Sections } from '@/components/sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClearingForm } from '@/hooks/use-clearing-form';
import AppLayout from '@/layouts/app-layout';
import { languageLevelLabels, languageLevels, sexLabels, type LanguageLevel, type Sex } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, Plus, Trash2, UserPlus } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/** The departments, the positions of each and the duties of each position come with the page. */
interface Options extends PlacementOptions {
    roles: { name: string; title: string }[];
    languages: { id: number; name: string }[];
    nationalities: string[];
    citizenships: string[];
    /** Named rather than numbered: one missing from the list is typed in and added on the save. */
    employment_types: string[];
    /** Hardware free to hand out; empty for whoever may not issue it. */
    stock: { id: number; name: string; inventory_number: string }[];
}

/**
 * The parts of the card, in the order the page lays them out, each with the
 * fields the server may name an error on.
 *
 * The fields are here and not beside the markup because they answer one
 * question the markup cannot: which part of the page a refusal belongs to. A
 * card refused over a year somebody mistyped nine screens down has to scroll
 * there, and this is the list that says where "there" is. The server names a row
 * as «educations.2.started_year», so a name counts as its section's when the
 * error is that field or anything under it.
 */
const sections = [
    // Empty on purpose for now: the part is kept so the page has a place for
    // what belongs at the top of a card, and so the numbering below does not
    // shift a second time when it is filled.
    { title: 'Основное', note: 'Чем занят', fields: ['roles', 'placements', 'employee_number', 'employment_type', 'hired_at'] },
    { title: 'ФИО', note: 'Как зовут', fields: ['surname', 'name', 'patronymic'] },
    {
        title: 'Личные данные',
        note: 'Кто это',
        fields: ['email', 'sex', 'birth_date', 'birth_place', 'citizenship', 'nationality', 'home_address'],
    },
    { title: 'Контакты и языки', note: 'Как связаться, чем владеет', fields: ['phone', 'sos_phone', 'sos_contact', 'languages'] },
    {
        title: 'Паспорт и семья',
        note: 'Документ и близкие',
        fields: [
            'passport_series',
            'passport_number',
            'passport_issued_at',
            'passport_issued_by',
            'marital_status',
            'spouse_name',
            'spouse_birth_date',
            'has_children',
            'children',
        ],
    },
    { title: 'Образование', note: 'Где учился', fields: ['educations'] },
    { title: 'Трудовая деятельность', note: 'Где работал раньше', fields: ['experiences'] },
    { title: 'Оборудование', note: 'Что выдаём на руки', fields: ['equipment', 'issued_at'] },
] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Сотрудники', href: '/employees' },
    { title: 'Новый сотрудник', href: '/employees/create' },
];

/**
 * A list a card points at one entry of: nothing chosen, everything on file, and
 * whatever was typed in that the list has not heard of yet.
 */
const named = (list: string[], value: string) => [
    { value: '', label: 'Не указано' },
    ...[...new Set([...list, ...(value === '' ? [] : [value])])]
        .sort((a, b) => a.localeCompare(b, 'ru'))
        .map((name) => ({ value: name, label: name })),
];

/** Errors come back as "educations.0.institution"; a field shows its own. */
const at = (errors: Record<string, string | undefined>, key: string) =>
    errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];

/* ------------------------------------------------------------------ pieces */

function Field({
    label,
    error,
    children,
    className,
    required,
}: {
    label: string;
    error?: string;
    children: ReactNode;
    className?: string;
    /** Marked where the server's rules will not take the form without it. */
    required?: boolean;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label required={required}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/**
 * A part of the page: its number and name over it, and everything it asks
 * indented under the name.
 *
 * It hands its own element back up, because the nav above the page has two
 * things to do with it — scroll to it when an entry is pressed, and watch it to
 * know which entry to mark — and both of those are the element itself. The rule
 * over each part but the first is where one ends and the next begins; on one
 * page that line does the work six separate steps used to do by being separate.
 */
function Section({
    index,
    title,
    hold,
    children,
}: {
    index: number;
    title: string;
    hold: (index: number, node: HTMLElement | null) => void;
    children: ReactNode;
}) {
    return (
        <section ref={(node) => hold(index, node)} data-section={index} className="flex min-w-0 scroll-mt-4 flex-col gap-2 md:scroll-mt-6">
            <SectionTitle number={String(index + 1).padStart(2, '0')} title={title} />

            {/* Indented by the width of the figure's box and the gap after it, so
                the name of the part and everything under it stand on one line. */}
            <div className="flex min-w-0 flex-col gap-6 pl-6 max-md:gap-4 max-md:pl-0">{children}</div>
        </section>
    );
}

/**
 * A part of the page that keeps a list of records — degrees, jobs. Rows are
 * added and removed here and travel with the rest of the card.
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

/** Whom the card just filed, for the line over a form emptied for the next one. */
type NewEmployee = { id: number; name: string };

/* -------------------------------------------------------------------- page */

/**
 * A new colleague: the whole card on one page, and one button that files it.
 *
 * It used to be six steps, each saving as it was left, which meant a colleague
 * went on the books from the first of them and sat there half-written if
 * anybody stopped — and the letter with their password had already gone out.
 * Now nothing is saved until «Создать»: the page sends every section in one
 * request and the server writes it in one transaction, so either the colleague
 * is on file with everything that was typed about them or nothing happened.
 *
 * The sections stand one under another and the nav on the left scrolls to them;
 * which entry it marks follows what is on screen. A page rather than a dialog
 * because it is long, and half-filled work should survive a stray key.
 */
export default function CreateEmployee({ options, canIssue }: { options: Options; canIssue: boolean }) {
    const today = new Date().toISOString().slice(0, 10);
    // A position nobody has filed in a department yet would put the person
    // nowhere, so it is not one of the posts on offer.
    const posts = options.positions.filter((position) => position.department_id !== null);
    // Handing hardware over is not a line of the card but an operation on the
    // fleet: without the right to issue units there is no section for it, and
    // the server refuses one sent anyway.
    const parts = canIssue ? sections : sections.slice(0, -1);

    const [current, setCurrent] = useState(0);
    const [added, setAdded] = useState<NewEmployee | null>(null);
    const [createdLanguages, setCreatedLanguages] = useState<string[]>([]);

    const form = useClearingForm({
        /* Основное */
        roles: [] as string[],
        employee_number: '',
        employment_type: '',
        // Empty rather than today: the day somebody started is a fact to be
        // typed, and a date already in the box reads as one that was.
        hired_at: '',
        // Where they stand, a chain at a time. Nobody is placed anywhere until
        // somebody says so, so the list starts empty.
        placements: [] as Placement[],

        /* Профиль */
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

        /* Контакты и языки */
        phone: '',
        sos_phone: '',
        sos_contact: '',
        languages: [] as SpokenLanguage[],

        /* Паспорт и семья */
        passport_series: '',
        passport_number: '',
        passport_issued_at: '',
        passport_issued_by: '',
        marital_status: '',
        spouse_name: '',
        spouse_birth_date: '',
        has_children: '' as '' | 'yes' | 'no',
        children: [] as Child[],

        /* Образование и трудовая деятельность */
        educations: [] as Education[],
        experiences: [] as Job[],

        /* Оборудование */
        equipment: [] as number[],
        issued_at: today,
    });

    const { data, setData, errors } = form;

    /**
     * Each section's own element, so the nav can scroll to it and watch it.
     * Kept in a ref rather than in state: nothing is drawn from it, and a list
     * of six elements that changed on every render would redraw the page for
     * nothing.
     */
    const marks = useRef<(HTMLElement | null)[]>([]);
    const hold = (index: number, node: HTMLElement | null) => {
        marks.current[index] = node;
    };

    /**
     * Which entry the nav marks is a question about what is on screen, so the
     * browser answers it rather than arithmetic on every scroll event.
     *
     * The window is cropped to a quarter of the way down and watched from there
     * to the bottom, so the section marked is the first one still reaching past
     * that line — the one being read, rather than the tail of the one above it
     * disappearing off the top. That is also what gets the last section marked
     * at the foot of the page, where nothing follows it to take over.
     */
    useEffect(() => {
        const inside = new Set<number>();

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const index = Number((entry.target as HTMLElement).dataset.section);

                    if (entry.isIntersecting) inside.add(index);
                    else inside.delete(index);
                }

                // The sections tile the page, so one of them always crosses the
                // line; nothing in view at all can only mean the page is being
                // torn down, and the mark is left where it was.
                if (inside.size > 0) setCurrent(Math.min(...inside));
            },
            { rootMargin: '-25% 0px 0px 0px' },
        );

        marks.current.slice(0, parts.length).forEach((node) => node && observer.observe(node));

        return () => observer.disconnect();
    }, [parts.length]);

    /**
     * Pressing an entry of the nav. The mark moves at once rather than waiting
     * for the scrolling to arrive, so the press answers; the band above then
     * confirms it.
     */
    const show = (index: number) => {
        setCurrent(index);
        marks.current[index]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // Leaving with the card half-filled would throw all of it away now that
    // nothing is saved along the way, so the browser asks first.
    useEffect(() => {
        if (!form.isDirty) return;

        const warn = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [form.isDirty]);

    /**
     * The card as the server reads it. The page keeps what is easy to type —
     * «yes», a language the directory has not heard of, a row somebody started
     * and left blank — and this is where that turns into what the request means.
     */
    const toSend = (card: typeof data) => ({
        ...card,
        placements: placementsToSend(card.placements),
        languages: card.languages
            .filter((spoken) => spoken.id !== '')
            .map((spoken) =>
                String(spoken.id).startsWith('new:')
                    ? { name: String(spoken.id).slice(4), level: spoken.level }
                    : { id: Number(spoken.id), level: spoken.level },
            ),
        marital_status: card.marital_status || null,
        spouse_birth_date: card.spouse_birth_date || null,
        // Only «нет» carries meaning on its own; rows of their own say the rest.
        has_children: card.has_children === '' ? null : card.has_children === 'yes',
        children: card.has_children === 'yes' ? card.children.filter((child) => child.full_name.trim() !== '') : [],
    });

    /** Back to a blank page, for whoever is filing a whole intake at once. */
    const startOver = () => {
        form.clearErrors();
        form.reset();
        setCreatedLanguages([]);
        setCurrent(0);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /**
     * The one request the page makes. Either the colleague is on the books with
     * every section of it or nothing happened, so a refusal anywhere is a
     * refusal of the lot — and the page scrolls to the first section that has
     * something wrong in it, which may be nine screens from the button.
     */
    const submit = (again: boolean) => {
        // Whoever was filed a moment ago is said over a blank page; a page being
        // sent again is no longer that page, so the line goes with it.
        setAdded(null);
        form.transform((card) => ({ ...toSend(card), continue: again }));

        form.post(route('employees.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                if (!again) return;

                // «Создать и добавить ещё» stays here: the server flashes back
                // whom it has just filed, and the blank page says so over itself.
                setAdded(((page.props as unknown as SharedData).flash?.employee as NewEmployee | undefined) ?? null);
                startOver();
            },
            onError: (refused) => {
                const first = parts.findIndex((part) => part.fields.some((field) => at(refused, field) !== undefined));

                if (first >= 0) show(first);
            },
        });
    };

    /**
     * What the server said about the jobs. It names a row and a field of it —
     * «placements.0.position_id» — and the form asks for them in one list, so
     * whatever it says is said once, under that list.
     */
    const placementError = errors.placements ?? Object.entries(errors).find(([key]) => key.startsWith('placements.'))?.[1];

    /** A row of one of the two lists, edited a field at a time. */
    const setEducation = (index: number, patch: Partial<Education>) =>
        setData(
            'educations',
            data.educations.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    const setJob = (index: number, patch: Partial<Job>) =>
        setData(
            'experiences',
            data.experiences.map((row, position) => (position === index ? { ...row, ...patch } : row)),
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Новый сотрудник" />

            <PageContent>
                {/* Which lines are demanded is said by the asterisk beside them,
                    where the question is asked, rather than listed over the page. */}
                <div className="flex items-end justify-between gap-4">
                    <PageTitle subtitle="Пароль сгенерируется сам и придёт сотруднику на почту, когда карточка сохранится.">
                        Новый сотрудник
                    </PageTitle>

                    {/* Leaving without filing anybody. It stands by the name of the
                        page rather than beside «Создать»: it undoes the whole visit,
                        not the form, and a phone has the back arrow for that. */}
                    <Button
                        type="button"
                        variant="outline"
                        className="shrink-0 max-md:hidden"
                        disabled={form.processing}
                        onClick={() => router.visit(route('employees.index'))}
                    >
                        Отмена
                    </Button>
                </div>

                {added && (
                    <p className="text-[13px]">
                        Готово:{' '}
                        <Link href={route('employees.show', added.id)} className="font-medium underline underline-offset-2">
                            {added.name}
                        </Link>
                        <span className="text-muted-foreground"> — можно заполнять следующего.</span>
                    </p>
                )}

                {/* noValidate: the server's rules are the real ones. */}
                <form
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit(false);
                    }}
                    className="bg-card @container flex flex-col gap-4 rounded-xl border p-6 max-md:rounded-2xl max-md:p-4 max-md:[&_[role=combobox]]:h-11 max-md:[&_input:not([type=checkbox])]:h-11"
                >
                    <Sections
                        nav={<SectionNav label="Анкета" items={parts} current={current} onSelect={show} />}
                        sections={
                            <>
                                <Section index={0} title={sections[0].title} hold={hold}>
                                    <FormGrid>
                                        <Field label="Роль" error={at(errors, 'roles')}>
                                            <MultiSelect
                                                options={options.roles.map((role) => ({ value: role.name, label: role.title }))}
                                                value={data.roles}
                                                onChange={(value) => setData('roles', value)}
                                            />
                                        </Field>

                                        {/* The jobs stand beside the access that goes with
                                            them, a cell like any other. What each job answers
                                            for is picked out of its own list later, on the
                                            card: it can be skipped here. */}
                                        <Field label="Должность" error={placementError}>
                                            <MultiSelect
                                                options={posts.map((post) => ({
                                                    value: post.id,
                                                    label: post.display_name,
                                                    // Only where it says something the brackets do not:
                                                    // a department with no short form of its own would
                                                    // otherwise print its name twice over.
                                                    hint: post.unit && post.unit.full_name !== post.unit.name ? post.unit.full_name : undefined,
                                                }))}
                                                value={data.placements
                                                    .map((placement) => placement.position_id)
                                                    .filter((id): id is number => id !== null)}
                                                onChange={(chosen) =>
                                                    setData(
                                                        'placements',
                                                        chosen.map((id) => {
                                                            // A job already chosen keeps whatever
                                                            // duties were picked out of it on the card.
                                                            const held = data.placements.find((placement) => placement.position_id === id);

                                                            return held ?? { position_id: id, duty_ids: [] };
                                                        }),
                                                    )
                                                }
                                            />
                                        </Field>

                                        <Field label="Номер сотрудника" error={errors.employee_number}>
                                            <Input
                                                value={data.employee_number}
                                                onChange={(event) => setData('employee_number', event.target.value)}
                                                aria-invalid={!!errors.employee_number}
                                            />
                                        </Field>

                                        <Field label="Тип занятости" error={errors.employment_type}>
                                            <SearchableSelect
                                                options={named(options.employment_types, data.employment_type)}
                                                value={data.employment_type}
                                                onChange={(value) => setData('employment_type', value)}
                                                onCreate={(value) => setData('employment_type', value)}
                                                placeholder="Выберите или впишите"
                                                searchPlaceholder="Тип занятости"
                                                invalid={!!errors.employment_type}
                                            />
                                        </Field>

                                        <Field label="Начало работы" error={errors.hired_at}>
                                            {/* Nobody starts work in the future. */}
                                            <DatePicker
                                                value={data.hired_at}
                                                onChange={(value) => setData('hired_at', value)}
                                                toDate={new Date()}
                                                invalid={!!errors.hired_at}
                                            />
                                        </Field>
                                    </FormGrid>
                                </Section>

                                <Section index={1} title={sections[1].title} hold={hold}>
                                    <FormGrid>
                                        <Field label="Фамилия" error={errors.surname} required>
                                            <Input
                                                value={data.surname}
                                                onChange={(event) => setData('surname', event.target.value)}
                                                aria-invalid={!!errors.surname}
                                                aria-required
                                            />
                                        </Field>
                                        <Field label="Имя" error={errors.name} required>
                                            <Input
                                                value={data.name}
                                                onChange={(event) => setData('name', event.target.value)}
                                                aria-invalid={!!errors.name}
                                                aria-required
                                            />
                                        </Field>
                                        <Field label="Отчество" error={errors.patronymic}>
                                            <Input
                                                value={data.patronymic}
                                                onChange={(event) => setData('patronymic', event.target.value)}
                                                aria-invalid={!!errors.patronymic}
                                            />
                                        </Field>
                                    </FormGrid>
                                </Section>

                                <Section index={2} title={sections[2].title} hold={hold}>
                                    <div className="flex flex-col gap-4">
                                        <FormGrid>
                                            <Field label="E-mail" error={errors.email} required>
                                                <Input
                                                    type="email"
                                                    value={data.email}
                                                    onChange={(event) => setData('email', event.target.value)}
                                                    placeholder="name@evolet.tj"
                                                    aria-invalid={!!errors.email}
                                                    aria-required
                                                />
                                                <p className="text-muted-foreground text-[13px]">С этим адресом сотрудник входит в систему.</p>
                                            </Field>
                                            <Field label="Пол" error={errors.sex} required>
                                                <SearchableSelect
                                                    value={data.sex}
                                                    onChange={(value) => setData('sex', value as Sex)}
                                                    options={[
                                                        { value: 'male', label: sexLabels.male },
                                                        { value: 'female', label: sexLabels.female },
                                                    ]}
                                                    invalid={!!errors.sex}
                                                    required
                                                />
                                            </Field>
                                            <Field label="Дата рождения" error={errors.birth_date}>
                                                <DatePicker
                                                    value={data.birth_date}
                                                    onChange={(value) => setData('birth_date', value)}
                                                    toDate={new Date()}
                                                    invalid={!!errors.birth_date}
                                                />
                                            </Field>
                                        </FormGrid>

                                        <FormGrid>
                                            <Field label="Место рождения" error={errors.birth_place}>
                                                <Input
                                                    value={data.birth_place}
                                                    onChange={(event) => setData('birth_place', event.target.value)}
                                                    placeholder="г. Душанбе"
                                                    aria-invalid={!!errors.birth_place}
                                                />
                                            </Field>
                                            <Field
                                                label="Гражданство"
                                                // A wrong country is reported on its own line ("citizenship.1").
                                                error={at(errors, 'citizenship')}
                                            >
                                                <MultiSelect
                                                    creatable
                                                    options={options.citizenships.map((value) => ({ value, label: value }))}
                                                    value={data.citizenship}
                                                    onChange={(value) => setData('citizenship', value)}
                                                    placeholder="Выберите или впишите страну"
                                                    searchPlaceholder="Страна"
                                                />
                                            </Field>
                                            <Field label="Национальность" error={errors.nationality}>
                                                <Input
                                                    list="nationalities"
                                                    value={data.nationality}
                                                    onChange={(event) => setData('nationality', event.target.value)}
                                                    aria-invalid={!!errors.nationality}
                                                />
                                                <datalist id="nationalities">
                                                    {options.nationalities.map((value) => (
                                                        <option key={value} value={value} />
                                                    ))}
                                                </datalist>
                                            </Field>
                                        </FormGrid>

                                        <FormGrid>
                                            {/* An address runs long, so it takes a row of its own. */}

                                            <Field label="Домашний адрес" error={errors.home_address} className="md:@min-[22rem]:col-span-2">
                                                <Input
                                                    value={data.home_address}
                                                    onChange={(event) => setData('home_address', event.target.value)}
                                                    aria-invalid={!!errors.home_address}
                                                />
                                            </Field>
                                        </FormGrid>
                                    </div>
                                </Section>

                                <Section index={3} title={sections[3].title} hold={hold}>
                                    <FormGrid>
                                        <Field label="Телефон" error={errors.phone}>
                                            <Input
                                                value={data.phone}
                                                onChange={(event) => setData('phone', event.target.value)}
                                                placeholder="+992 90 123 45 67"
                                                aria-invalid={!!errors.phone}
                                            />
                                        </Field>
                                        <Field label="Телефон SOS" error={errors.sos_phone}>
                                            <Input
                                                value={data.sos_phone}
                                                onChange={(event) => setData('sos_phone', event.target.value)}
                                                placeholder="+992 90 765 43 21"
                                                aria-invalid={!!errors.sos_phone}
                                            />
                                        </Field>
                                        <Field label="Чей это номер" error={errors.sos_contact}>
                                            <Input
                                                value={data.sos_contact}
                                                onChange={(event) => setData('sos_contact', event.target.value)}
                                                placeholder="Супруга, Нигина"
                                                aria-invalid={!!errors.sos_contact}
                                            />
                                        </Field>
                                    </FormGrid>

                                    <div className="flex flex-col gap-3 pt-2">
                                        <Label>Знание языков</Label>
                                        <InputError message={at(errors, 'languages')} />

                                        {data.languages.length === 0 && (
                                            <p className="text-muted-foreground text-sm">Если сведений нет, оставьте пустым.</p>
                                        )}

                                        {data.languages.map((spoken, index) => (
                                            <div key={index} className="flex flex-wrap items-center gap-2 @min-[30rem]:flex-nowrap">
                                                <SearchableSelect
                                                    className="min-w-0 grow basis-full @min-[30rem]:basis-0"
                                                    invalid={!!at(errors, `languages.${index}.id`) || !!at(errors, `languages.${index}.name`)}
                                                    value={spoken.id}
                                                    onChange={(value) =>
                                                        setData(
                                                            'languages',
                                                            data.languages.map((item, position) =>
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
                                                        setCreatedLanguages((held) => (held.includes(name) ? held : [...held, name]));
                                                        setData(
                                                            'languages',
                                                            data.languages.map((item, position) =>
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
                                                    invalid={!!at(errors, `languages.${index}.level`)}
                                                    value={spoken.level}
                                                    onChange={(value) =>
                                                        setData(
                                                            'languages',
                                                            data.languages.map((item, position) =>
                                                                position === index ? { ...item, level: value as LanguageLevel } : item,
                                                            ),
                                                        )
                                                    }
                                                    options={languageLevels.map((level) => ({
                                                        value: level,
                                                        label: languageLevelLabels[level],
                                                    }))}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-muted-foreground size-9 shrink-0"
                                                    aria-label={`Убрать язык ${index + 1}`}
                                                    onClick={() =>
                                                        setData(
                                                            'languages',
                                                            data.languages.filter((_, position) => position !== index),
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
                                            onClick={() => setData('languages', [...data.languages, { id: '', level: 'intermediate' }])}
                                        >
                                            <Plus />
                                            Добавить язык
                                        </Button>
                                    </div>
                                </Section>

                                <Section index={4} title={sections[4].title} hold={hold}>
                                    <FormGrid>
                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="Серия паспорта" error={errors.passport_series}>
                                                <Input
                                                    value={data.passport_series}
                                                    onChange={(event) => setData('passport_series', event.target.value)}
                                                    placeholder="A"
                                                    aria-invalid={!!errors.passport_series}
                                                />
                                            </Field>
                                            <Field label="Номер паспорта" error={errors.passport_number}>
                                                <Input
                                                    value={data.passport_number}
                                                    onChange={(event) => setData('passport_number', event.target.value)}
                                                    placeholder="01234567"
                                                    aria-invalid={!!errors.passport_number}
                                                />
                                            </Field>
                                        </div>
                                        <Field label="Дата выдачи" error={errors.passport_issued_at}>
                                            <DatePicker
                                                value={data.passport_issued_at}
                                                onChange={(value) => setData('passport_issued_at', value)}
                                                toDate={new Date()}
                                                invalid={!!errors.passport_issued_at}
                                            />
                                        </Field>
                                        <Field label="Кем выдан" error={errors.passport_issued_by}>
                                            <Input
                                                value={data.passport_issued_by}
                                                onChange={(event) => setData('passport_issued_by', event.target.value)}
                                                aria-invalid={!!errors.passport_issued_by}
                                            />
                                        </Field>
                                    </FormGrid>

                                    <FormGrid className="pt-2">
                                        <Field label="Семейное положение" error={errors.marital_status}>
                                            <SearchableSelect
                                                value={data.marital_status}
                                                onChange={(value) => setData('marital_status', value)}
                                                options={[
                                                    { value: 'single', label: 'Не женат / не замужем' },
                                                    { value: 'married', label: 'Женат / замужем' },
                                                ]}
                                                placeholder="Не указано"
                                                invalid={!!errors.marital_status}
                                            />
                                        </Field>
                                        <Field label="Дети">
                                            <SearchableSelect
                                                invalid={!!errors.has_children}
                                                value={data.has_children}
                                                onChange={(value) => setData('has_children', value as 'yes' | 'no')}
                                                options={[
                                                    { value: 'yes', label: 'Есть' },
                                                    { value: 'no', label: 'Нет' },
                                                ]}
                                                placeholder="Не указано"
                                            />
                                        </Field>
                                    </FormGrid>

                                    {data.marital_status === 'married' && (
                                        <div className="flex flex-col gap-4 @min-[22rem]:flex-row @min-[22rem]:items-end @min-[22rem]:gap-2">
                                            <Field label="ФИО супруга" className="flex-1" error={errors.spouse_name}>
                                                <Input
                                                    value={data.spouse_name}
                                                    onChange={(event) => setData('spouse_name', event.target.value)}
                                                    aria-invalid={!!errors.spouse_name}
                                                />
                                            </Field>
                                            <Field label="Дата рождения" error={errors.spouse_birth_date}>
                                                <DatePicker
                                                    value={data.spouse_birth_date}
                                                    onChange={(value) => setData('spouse_birth_date', value)}
                                                    toDate={new Date()}
                                                    invalid={!!errors.spouse_birth_date}
                                                />
                                            </Field>
                                        </div>
                                    )}

                                    {data.has_children === 'yes' && (
                                        <div className="flex flex-col gap-3">
                                            {data.children.map((child, index) => (
                                                <div key={index} className="flex flex-wrap items-end gap-2 @min-[22rem]:flex-nowrap">
                                                    {/* Nobody has to be entered with children at all, but a row
                                                    that has been added is a child, and a child has a name. */}
                                                    <Field
                                                        label="ФИО ребёнка"
                                                        className="min-w-0 grow basis-full @min-[22rem]:basis-0"
                                                        error={at(errors, `children.${index}.full_name`)}
                                                        required
                                                    >
                                                        <Input
                                                            aria-required
                                                            value={child.full_name}
                                                            onChange={(event) =>
                                                                setData(
                                                                    'children',
                                                                    data.children.map((item, position) =>
                                                                        position === index ? { ...item, full_name: event.target.value } : item,
                                                                    ),
                                                                )
                                                            }
                                                            aria-invalid={!!at(errors, `children.${index}.full_name`)}
                                                        />
                                                    </Field>
                                                    <Field
                                                        label="Дата рождения"
                                                        className="min-w-0 flex-1 @min-[22rem]:flex-none"
                                                        error={at(errors, `children.${index}.birth_date`)}
                                                    >
                                                        <DatePicker
                                                            value={child.birth_date}
                                                            onChange={(value) =>
                                                                setData(
                                                                    'children',
                                                                    data.children.map((item, position) =>
                                                                        position === index ? { ...item, birth_date: value } : item,
                                                                    ),
                                                                )
                                                            }
                                                            toDate={new Date()}
                                                            invalid={!!at(errors, `children.${index}.birth_date`)}
                                                        />
                                                    </Field>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="text-muted-foreground mb-[2px] size-9 shrink-0"
                                                        aria-label={`Убрать ребёнка ${index + 1}`}
                                                        onClick={() =>
                                                            setData(
                                                                'children',
                                                                data.children.filter((_, position) => position !== index),
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
                                                onClick={() => setData('children', [...data.children, { full_name: '', birth_date: '' }])}
                                            >
                                                <Plus />
                                                Добавить ребёнка
                                            </Button>
                                        </div>
                                    )}
                                </Section>

                                <Section index={5} title={sections[5].title} hold={hold}>
                                    <Records
                                        items={data.educations}
                                        empty="Если сведений нет, оставьте раздел пустым."
                                        addLabel="Добавить образование"
                                        onAdd={() => setData('educations', [...data.educations, { ...blankEducation }])}
                                        onRemove={(index) =>
                                            setData(
                                                'educations',
                                                data.educations.filter((_, position) => position !== index),
                                            )
                                        }
                                    >
                                        {(record, index) => (
                                            /* The section may be left empty altogether, but a record added to
                                           it is answered in full: all of it but the diploma and the year
                                           it ended with. */
                                            <FormGrid>
                                                <Field
                                                    label="Учебное заведение"
                                                    className="md:@min-[22rem]:col-span-2"
                                                    error={at(errors, `educations.${index}.institution`)}
                                                    required
                                                >
                                                    <Input
                                                        aria-required
                                                        value={record.institution}
                                                        onChange={(event) => setEducation(index, { institution: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.institution`)}
                                                    />
                                                </Field>
                                                <Field label="Специальность" error={at(errors, `educations.${index}.specialty`)} required>
                                                    <Input
                                                        aria-required
                                                        value={record.specialty}
                                                        onChange={(event) => setEducation(index, { specialty: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.specialty`)}
                                                    />
                                                </Field>
                                                <Field
                                                    label="Факультет"
                                                    className="md:@min-[22rem]:col-span-2"
                                                    error={at(errors, `educations.${index}.faculty`)}
                                                    required
                                                >
                                                    <Input
                                                        aria-required
                                                        value={record.faculty}
                                                        onChange={(event) => setEducation(index, { faculty: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.faculty`)}
                                                    />
                                                </Field>
                                                <Field label="Номер диплома" error={at(errors, `educations.${index}.diploma_number`)}>
                                                    <Input
                                                        value={record.diploma_number}
                                                        onChange={(event) => setEducation(index, { diploma_number: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.diploma_number`)}
                                                    />
                                                </Field>
                                                <Field label="Год поступления" error={at(errors, `educations.${index}.started_year`)} required>
                                                    <Input
                                                        aria-required
                                                        type="number"
                                                        inputMode="numeric"
                                                        min={1950}
                                                        placeholder="2015"
                                                        value={record.started_year}
                                                        onChange={(event) => setEducation(index, { started_year: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.started_year`)}
                                                    />
                                                </Field>
                                                <Field label="Год окончания" error={at(errors, `educations.${index}.graduated_year`)}>
                                                    <Input
                                                        type="number"
                                                        inputMode="numeric"
                                                        min={1950}
                                                        placeholder="Пусто — ещё учится"
                                                        value={record.graduated_year}
                                                        onChange={(event) => setEducation(index, { graduated_year: event.target.value })}
                                                        aria-invalid={!!at(errors, `educations.${index}.graduated_year`)}
                                                    />
                                                </Field>
                                            </FormGrid>
                                        )}
                                    </Records>
                                </Section>

                                <Section index={6} title={sections[6].title} hold={hold}>
                                    <Records
                                        items={data.experiences}
                                        empty="Если сведений нет, оставьте раздел пустым."
                                        addLabel="Добавить место работы"
                                        onAdd={() => setData('experiences', [...data.experiences, { ...blankJob }])}
                                        onRemove={(index) =>
                                            setData(
                                                'experiences',
                                                data.experiences.filter((_, position) => position !== index),
                                            )
                                        }
                                    >
                                        {(record, index) => (
                                            /* As with education: the section is optional, a record in it is
                                           not — only the leaving date may stay open. */
                                            <FormGrid>
                                                <Field label="Организация" error={at(errors, `experiences.${index}.organization`)} required>
                                                    <Input
                                                        aria-required
                                                        value={record.organization}
                                                        onChange={(event) => setJob(index, { organization: event.target.value })}
                                                        aria-invalid={!!at(errors, `experiences.${index}.organization`)}
                                                    />
                                                </Field>
                                                <Field label="Должность" error={at(errors, `experiences.${index}.position`)} required>
                                                    <Input
                                                        aria-required
                                                        value={record.position}
                                                        onChange={(event) => setJob(index, { position: event.target.value })}
                                                        aria-invalid={!!at(errors, `experiences.${index}.position`)}
                                                    />
                                                </Field>
                                                <Field label="Страна" error={at(errors, `experiences.${index}.country`)} required>
                                                    <Input
                                                        aria-required
                                                        list="job-countries"
                                                        value={record.country}
                                                        onChange={(event) => setJob(index, { country: event.target.value })}
                                                        aria-invalid={!!at(errors, `experiences.${index}.country`)}
                                                    />
                                                </Field>

                                                {/*
                                                 * One box per date, picked as a month: that is the precision a
                                                 * previous job is remembered to, and the record keeps the two
                                                 * halves apart as the server does.
                                                 */}
                                                <Field label="Дата вступления" error={at(errors, `experiences.${index}.started_year`)} required>
                                                    <Input
                                                        aria-required
                                                        type="month"
                                                        max={thisMonth}
                                                        value={asMonth(record.started_year, record.started_month)}
                                                        onChange={(event) => setJob(index, fromMonth(event.target.value, 'started'))}
                                                        aria-invalid={
                                                            !!at(errors, `experiences.${index}.started_month`) ||
                                                            !!at(errors, `experiences.${index}.started_year`)
                                                        }
                                                    />
                                                </Field>
                                                <Field label="Дата ухода" error={at(errors, `experiences.${index}.ended_year`)}>
                                                    <Input
                                                        type="month"
                                                        max={thisMonth}
                                                        value={asMonth(record.ended_year, record.ended_month)}
                                                        onChange={(event) => setJob(index, fromMonth(event.target.value, 'ended'))}
                                                        aria-invalid={
                                                            !!at(errors, `experiences.${index}.ended_month`) ||
                                                            !!at(errors, `experiences.${index}.ended_year`)
                                                        }
                                                    />
                                                    <p className="text-muted-foreground text-[13px]">Пусто — работает там до сих пор.</p>
                                                </Field>
                                            </FormGrid>
                                        )}
                                    </Records>
                                </Section>

                                {canIssue && (
                                    <Section index={7} title={sections[7].title} hold={hold}>
                                        <FormGrid>
                                            <Field label="Что выдаём" className="md:@min-[22rem]:col-span-2" error={at(errors, 'equipment')}>
                                                <MultiSelect
                                                    options={options.stock.map((unit) => ({
                                                        value: unit.id,
                                                        label: `${unit.name} · ${unit.inventory_number}`,
                                                    }))}
                                                    value={data.equipment}
                                                    onChange={(value) => setData('equipment', value)}
                                                    placeholder="Ничего не выбрано"
                                                    searchPlaceholder="Поиск по названию или номеру"
                                                />
                                                <p className="text-muted-foreground text-[13px]">Только то, что свободно и никому не выдано.</p>
                                            </Field>

                                            <Field label="Дата выдачи" error={errors.issued_at}>
                                                <DatePicker
                                                    value={data.issued_at}
                                                    onChange={(value) => setData('issued_at', value)}
                                                    toDate={new Date()}
                                                    invalid={!!errors.issued_at}
                                                />
                                            </Field>
                                        </FormGrid>
                                    </Section>
                                )}
                            </>
                        }
                    />

                    {/* On a phone the buttons are one bar that sticks above the tab
                                bar while the page scrolls: the side action shrinks to an icon
                                (its words stay for screen readers) and the main one takes the
                                rest of the width. */}
                    <div className="max-md:bg-background/90 flex flex-col-reverse gap-2 max-md:sticky max-md:bottom-[calc(3.5rem+env(safe-area-inset-bottom))] max-md:z-20 max-md:-mx-4 max-md:-mb-4 max-md:flex-row max-md:items-center max-md:rounded-b-2xl max-md:px-4 max-md:py-3 max-md:backdrop-blur-xl md:flex-row md:flex-wrap md:items-center md:justify-end">
                        <div className="flex flex-col-reverse gap-2 max-md:min-w-0 max-md:flex-1 max-md:flex-row md:flex-row md:flex-wrap">
                            {/* Filing a whole intake: save this card and start the
                                        next colleague on a blank page straight away. */}
                            <Button
                                type="button"
                                variant="outline"
                                className="max-md:size-11 max-md:shrink-0 max-md:px-0"
                                disabled={form.processing}
                                onClick={() => submit(true)}
                            >
                                <UserPlus />
                                <span className="max-md:sr-only">Создать и добавить ещё</span>
                            </Button>

                            <Button type="submit" className="max-md:h-11 max-md:min-w-0 max-md:flex-1" disabled={form.processing}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                {/* A 320px phone has no room for the whole phrase
                                            beside the icon button. */}
                                <span className="md:hidden">Создать</span>
                                <span className="max-md:hidden">Создать сотрудника</span>
                            </Button>
                        </div>
                    </div>
                </form>
            </PageContent>
        </AppLayout>
    );
}
