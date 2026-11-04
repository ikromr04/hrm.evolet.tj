import { DatePicker } from '@/components/date-picker';
import { FormGrid } from '@/components/form-grid';
import { MultiSelect } from '@/components/multi-select';
import { PageContent } from '@/components/page-content';
import { PageTitle } from '@/components/page-title';
import {
    AwardGroup,
    ContactsGroup,
    DegreeGroup,
    EducationGroup,
    ExperienceGroup,
    FamilyGroup,
    GeneralGroup,
    LanguagesGroup,
    MilitaryGroup,
    NameGroup,
    PassportGroup,
} from '@/components/personnel-sheet/groups';
import { at, Field, named } from '@/components/personnel-sheet/pieces';
import { blankSheet, SHEET_PART_FIELDS, sheetToSend, type SheetOptions } from '@/components/personnel-sheet/values';
import { mayHold, placementsToSend, roleFromPositions, specialistRole, type Placement, type PlacementOptions } from '@/components/placements';
import { SearchableSelect } from '@/components/searchable-select';
import { SectionNav } from '@/components/section-nav';
import { SectionTitle } from '@/components/section-title';
import { Sections } from '@/components/sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClearingForm } from '@/hooks/use-clearing-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, UserPlus } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/**
 * The departments, the positions of each and the duties of each position come
 * with the page, along with the lists the eleven parts of the sheet offer.
 */
interface Options extends PlacementOptions, SheetOptions {
    roles: { name: string; title: string }[];
    /** Named rather than numbered: one missing from the list is typed in and added on the save. */
    employment_types: string[];
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
    { title: 'Основное', note: 'Чем занят', fields: ['role', 'placements', 'employee_number', 'employment_type', 'hired_at', 'email'] },
    { title: 'ФИО', note: 'Как зовут', fields: SHEET_PART_FIELDS.name },
    { title: 'Общие сведения', note: 'Кто это', fields: SHEET_PART_FIELDS.general },
    { title: 'Образование', note: 'Где учился', fields: SHEET_PART_FIELDS.educations },
    { title: 'Учёная степень, звание', note: 'Что присвоено', fields: SHEET_PART_FIELDS.degree },
    { title: 'Знание языков', note: 'Чем владеет', fields: SHEET_PART_FIELDS.languages },
    { title: 'Трудовая деятельность', note: 'Где работал раньше', fields: SHEET_PART_FIELDS.experience },
    { title: 'Государственные награды, поощрения', note: 'Чем отмечен', fields: SHEET_PART_FIELDS.award },
    { title: 'Семейное положение и состав семьи', note: 'Кто дома', fields: SHEET_PART_FIELDS.family },
    { title: 'Паспортные данные', note: 'Документ', fields: SHEET_PART_FIELDS.passport },
    { title: 'Адрес и контакты', note: 'Где живёт, как связаться', fields: SHEET_PART_FIELDS.contacts },
    { title: 'Отношение к воинской обязанности', note: 'Что в военном билете', fields: SHEET_PART_FIELDS.military },
] as const;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Сотрудники', href: '/employees' },
    { title: 'Новый сотрудник', href: '/employees/create' },
];

/* ------------------------------------------------------------------ pieces */

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
    current,
    children,
}: {
    index: number;
    title: string;
    hold: (index: number, node: HTMLElement | null) => void;
    /** Which part the nav has marked, so this one can say whether it is that one. */
    current: number;
    children: ReactNode;
}) {
    return (
        <section ref={(node) => hold(index, node)} data-section={index} className="flex min-w-0 scroll-mt-4 flex-col gap-2 md:scroll-mt-6">
            <SectionTitle number={String(index + 1).padStart(2, '0')} title={title} active={index === current} />

            {/* Indented by the width of the figure's box in the title and the gap
                after it, so the name of the part and everything under it stand on
                one line. */}
            <div className="flex min-w-0 flex-col gap-6 pl-6 max-md:gap-4 max-md:pl-0">{children}</div>
        </section>
    );
}

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
 * The eleven parts under «Основное» are «Личный листок по учёту кадров» itself,
 * and they are drawn from the same components the card's own «Личный листок»
 * tab is drawn from: the same questions asked twice would have stopped being
 * the same questions. What is this page's own is the order they stand in, the
 * nav that scrolls to them, and the one button at the foot.
 *
 * A page rather than a dialog because it is long, and half-filled work should
 * survive a stray key.
 */
export default function CreateEmployee({
    options,
    picksRole,
}: {
    options: Options;
    /**
     * Which way round the page asks for the role and the job.
     *
     * A system administrator picks the role, and the jobs on offer narrow to the
     * ones that role may hold: handing somebody authority is the answer, and the
     * job is what it is given over. Personnel pick the job and the role is read
     * off it, because what they are filling in is what the person was hired to
     * do — and the field that would let them appoint a head by hand is theirs to
     * read, not to answer.
     */
    picksRole: boolean;
}) {
    // A position nobody has filed in a department yet would put the person
    // nowhere, so it is not one of the posts on offer.
    const posts = options.positions.filter((position) => position.department_id !== null);
    const [current, setCurrent] = useState(0);
    const [added, setAdded] = useState<NewEmployee | null>(null);
    const [createdLanguages, setCreatedLanguages] = useState<string[]>([]);

    const form = useClearingForm({
        /* Основное */
        // What most cards are, which is where the field stands until somebody
        // says otherwise. «Не выбрано» is in the list all the same — the save
        // asks for a role and refuses the card without one — so the box can say
        // that nothing has been decided, it just does not start there.
        role: specialistRole,
        employee_number: '',
        employment_type: '',
        // Empty rather than today: the day somebody started is a fact to be
        // typed, and a date already in the box reads as one that was.
        hired_at: '',
        email: '',
        // Where they stand, a chain at a time. Nobody is placed anywhere until
        // somebody says so, so the list starts empty.
        placements: [] as Placement[],

        /* And the eleven parts of the sheet, each on the default that part was
           given once and for all — see the personnel-sheet components. */
        ...blankSheet,
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
     * A press holds the mark where it was pressed until the reader scrolls
     * themselves.
     *
     * Without it the part that was asked for is not always the part that ends
     * up marked: the page can only scroll so far, so a short part at the foot
     * of it never reaches the line the watching below goes by, and the mark
     * springs back to whatever is standing there instead. Pressing one entry
     * and watching another light up is the kind of thing a reader blames
     * themselves for.
     */
    const pressed = useRef(false);

    /**
     * Which entry the nav marks is a question about what is on screen, so the
     * browser answers it rather than arithmetic on every scroll event.
     *
     * The window is cropped to a quarter of the way down and watched from there
     * to the bottom, so the section marked is the first one still reaching past
     * that line — the one being read, rather than the tail of the one above it
     * disappearing off the top.
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

                // What is on screen is worked out either way — the answer is
                // needed the moment the hold ends — but while a press is being
                // answered it does not move the mark.
                if (pressed.current) return;

                // The sections tile the page, so one of them always crosses the
                // line; nothing in view at all can only mean the page is being
                // torn down, and the mark is left where it was.
                if (inside.size > 0) setCurrent(Math.min(...inside));
            },
            { rootMargin: '-25% 0px 0px 0px' },
        );

        marks.current.slice(0, sections.length).forEach((node) => node && observer.observe(node));

        return () => observer.disconnect();
    }, [sections.length]);

    /**
     * The hold ends the moment the page is scrolled by hand — a wheel, a
     * finger, or one of the keys that scroll. Typing into a field is not one of
     * them: a card being filled in would otherwise drop the mark the reader
     * just put somewhere.
     */
    useEffect(() => {
        const scrolls = new Set(['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' ']);

        const release = () => {
            pressed.current = false;
        };

        // Those keys are only scrolling while nothing is being typed into: a
        // space in «Кандидат экономических наук» scrolls nothing, and an arrow
        // in a date is a caret moving.
        const key = (event: KeyboardEvent) =>
            scrolls.has(event.key) && !(event.target as HTMLElement | null)?.closest('input, textarea, [contenteditable]') && release();

        window.addEventListener('wheel', release, { passive: true });
        window.addEventListener('touchmove', release, { passive: true });
        window.addEventListener('keydown', key);

        return () => {
            window.removeEventListener('wheel', release);
            window.removeEventListener('touchmove', release);
            window.removeEventListener('keydown', key);
        };
    }, []);

    /**
     * Answering a field is being in the part it belongs to, so the mark goes
     * there — however the reader got to it: pressed into it, tabbed into it
     * from the part above, or sent to it by a refusal.
     *
     * Held afterwards for the reason a press is held: a field near the foot of
     * the page cannot always be scrolled under the line the watching goes by,
     * and the mark would leave the part being filled in. The nav's own buttons
     * are outside every part, so pressing one is not a field taking the focus.
     */
    useEffect(() => {
        const answered = (event: Event) => {
            const part = (event.target as HTMLElement | null)?.closest<HTMLElement>('[data-section]');

            if (!part) return;

            pressed.current = true;
            setCurrent(Number(part.dataset.section));
        };

        window.addEventListener('focusin', answered);
        // And pressing a part anywhere, not only in something that takes the
        // focus: somebody who has put their finger on a part is in it, whether
        // what they touched was a field, a label or the space between two.
        window.addEventListener('click', answered);

        return () => {
            window.removeEventListener('focusin', answered);
            window.removeEventListener('click', answered);
        };
    }, []);

    /**
     * Pressing an entry of the nav. The mark moves at once rather than waiting
     * for the scrolling to arrive, so the press answers — and stays there,
     * because the page below may not be able to put that part under the line.
     */
    const show = (index: number) => {
        pressed.current = true;
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
     * The card as the server reads it. The eleven parts of the sheet are turned
     * into what the request means by the one function that does it for both
     * doors; what is left is this page's own pair of questions.
     */
    const toSend = (card: typeof data) => ({
        ...card,
        ...sheetToSend(card),
        // The role as the field shows it: where the jobs decide it, the box is
        // closed and what is in the form behind it was never anybody's answer.
        role: picksRole ? card.role : impliedRole,
        placements: placementsToSend(card.placements),
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
                const first = sections.findIndex((part) => part.fields.some((field) => at(refused, field) !== undefined));

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

    /** The jobs chosen so far, which the pair of fields is read against. */
    const chosen = data.placements.map((placement) => placement.position_id).filter((id): id is number => id !== null);

    /**
     * The role those jobs imply, shown in place of the answer where the answer
     * is not this viewer's to give. It is also what travels as «role» then: the
     * field is closed, and a closed field still has to say something true.
     */
    const impliedRole = roleFromPositions(chosen, options.positions);

    /**
     * The jobs on offer. Where the role was picked they narrow to the ones it
     * may hold — a head leads and a specialist does not — and whatever is
     * already chosen stays on the list besides, even once the role has changed
     * under it: a job the new role may not hold is what the server is about to
     * refuse, and it has to be in view for the refusal to be about anything.
     * Where the job is what decides, nothing is narrowed: the job is the answer.
     */
    const offeredPosts = picksRole ? posts.filter((post) => mayHold(data.role, post) || chosen.includes(post.id)) : posts;

    /** What every part of the sheet is handed, which on this page is all of it. */
    const part = { data, errors, setData, options };

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
                        nav={<SectionNav label="Анкета" items={sections} current={current} onSelect={show} />}
                        sections={
                            <>
                                <Section index={0} title={sections[0].title} hold={hold} current={current}>
                                    <FormGrid>
                                        {/* Picked, or read off the jobs and closed. The closed box
                                            is the same control as the open one rather than a line
                                            of text: the field stands in a row with its neighbours,
                                            and a reader who cannot change it is still reading an
                                            answer to the same question. */}
                                        <Field label="Роль" error={at(errors, 'role')} required>
                                            <SearchableSelect
                                                options={options.roles.map((role) => ({ value: role.name, label: role.title }))}
                                                value={picksRole ? data.role : impliedRole}
                                                onChange={(value) => setData('role', value)}
                                                invalid={!!at(errors, 'role')}
                                                disabled={!picksRole}
                                                required
                                            />
                                        </Field>

                                        {/* The jobs stand beside the access that goes with
                                            them, a cell like any other. What each job answers
                                            for is picked out of its own list later, on the
                                            card: it can be skipped here. */}
                                        <Field label="Должность" error={placementError}>
                                            <MultiSelect
                                                options={offeredPosts.map((post) => ({
                                                    value: post.id,
                                                    label: post.display_name,
                                                    // Only where it says something the brackets do not:
                                                    // a department with no short form of its own would
                                                    // otherwise print its name twice over.
                                                    hint: post.unit && post.unit.full_name !== post.unit.name ? post.unit.full_name : undefined,
                                                }))}
                                                value={chosen}
                                                onChange={(picked) =>
                                                    setData(
                                                        'placements',
                                                        picked.map((id) => {
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
                                                placeholder="00418"
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

                                        <Field
                                            label="Электронная почта"
                                            error={errors.email}
                                            required
                                            hint="С этим адресом сотрудник входит в систему."
                                        >
                                            <Input
                                                type="email"
                                                value={data.email}
                                                onChange={(event) => setData('email', event.target.value)}
                                                placeholder="name@evolet.co.uk"
                                                aria-invalid={!!errors.email}
                                                aria-required
                                            />
                                        </Field>
                                    </FormGrid>
                                </Section>

                                <Section index={1} title={sections[1].title} hold={hold} current={current}>
                                    <NameGroup {...part} />
                                </Section>

                                <Section index={2} title={sections[2].title} hold={hold} current={current}>
                                    <GeneralGroup {...part} />
                                </Section>

                                <Section index={3} title={sections[3].title} hold={hold} current={current}>
                                    <EducationGroup {...part} />
                                </Section>

                                <Section index={4} title={sections[4].title} hold={hold} current={current}>
                                    <DegreeGroup {...part} />
                                </Section>

                                <Section index={5} title={sections[5].title} hold={hold} current={current}>
                                    <LanguagesGroup
                                        {...part}
                                        created={createdLanguages}
                                        onCreated={(name) => setCreatedLanguages((held) => (held.includes(name) ? held : [...held, name]))}
                                    />
                                </Section>

                                <Section index={6} title={sections[6].title} hold={hold} current={current}>
                                    <ExperienceGroup {...part} />
                                </Section>

                                <Section index={7} title={sections[7].title} hold={hold} current={current}>
                                    <AwardGroup {...part} />
                                </Section>

                                <Section index={8} title={sections[8].title} hold={hold} current={current}>
                                    <FamilyGroup {...part} />
                                </Section>

                                <Section index={9} title={sections[9].title} hold={hold} current={current}>
                                    <PassportGroup {...part} />
                                </Section>

                                <Section index={10} title={sections[10].title} hold={hold} current={current}>
                                    <ContactsGroup {...part} />
                                </Section>

                                <Section index={11} title={sections[11].title} hold={hold} current={current}>
                                    <MilitaryGroup {...part} />
                                </Section>
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
