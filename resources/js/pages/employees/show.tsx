import { DatePicker } from '@/components/date-picker';
import { DepartmentName } from '@/components/department-name';
import { EmployeeActions } from '@/components/employee-actions';
import { EmployeeDocumentsList, requiredAccepted, type EmployeeDocuments } from '@/components/employee-documents';
import { ChangeLines } from '@/components/equipment-changes';
import { EquipmentForm, type EquipmentFormProps } from '@/components/equipment-form';
import { CategoryChip } from '@/components/equipment-icon';
import { EquipmentMoveDialog, moveLabel, type AskedMove } from '@/components/equipment-move-dialog';
import InputError from '@/components/input-error';
import { LevelBadge } from '@/components/language-badges';
import { MultiSelect } from '@/components/multi-select';
import { PageContent } from '@/components/page-content';
import { Panel } from '@/components/panel';
import { faceEdge, PersonAvatar } from '@/components/person-avatar';
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
import { at } from '@/components/personnel-sheet/pieces';
import {
    asSaid,
    blankSheet,
    carriesFile,
    isAnswered,
    papersWanted,
    SHEET_PART_FIELDS,
    sheetToSend,
    type SheetOptions,
    type SheetValues,
} from '@/components/personnel-sheet/values';
import { SosPhone } from '@/components/phones';
import { Photos, type Photo } from '@/components/photo-viewer';
import { mayHold, roleFromPositions, type Placement, type PlacementOptions } from '@/components/placements';
import { PositionName, positionTitle, type PositionRef } from '@/components/position-name';
import { RecordTable } from '@/components/record-table';
import { SearchableSelect } from '@/components/searchable-select';
import { Section as NumberedSection } from '@/components/section';
import { SectionNav } from '@/components/section-nav';
import { SectionTitle } from '@/components/section-title';
import { Sections } from '@/components/sections';
import { StatusBadge, statusTones, type StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useShows, VisibleFields } from '@/components/visible-fields';
import { useClearingForm } from '@/hooks/use-clearing-form';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/access';
import {
    formatDate,
    formatMonth,
    formatPeriod,
    formatPhone,
    languageLevels,
    maritalLabels,
    monthNames,
    sexLabels,
    tenure,
    type CardDocument,
    type Education,
    type Equipment,
    type LanguageLevel,
    type PrivateDetails,
    type Sex,
    type SpokenLanguage,
    type WorkExperience,
} from '@/lib/employee';
import { eventLabel, eventTone, type EventChanges, type EventKind, type NameLookup } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    Camera,
    ChevronLeft,
    ChevronRight,
    FileText,
    LoaderCircle,
    Lock,
    Mail,
    Phone,
    Plus,
    Trash2,
    Upload,
    type LucideIcon,
} from 'lucide-react';
import { createContext, useContext, useEffect, useRef, useState, type FormEventHandler, type ReactNode } from 'react';

/** One line of the equipment journal, narrowed to this colleague's spells. */
interface HistoryEvent {
    id: number;
    unit: { id: number; name: string; inventory_number: string; type: string | null; open: boolean } | null;
    kind: EventKind;
    changes: EventChanges;
    note: string | null;
    at: string | null;
    actor: { id: number; name: string; avatar: string | null } | null;
    photos: Photo[];
}

/**
 * A unit the person holds, as this card lists it: what it is, and which of the
 * moves on it are this viewer's to make. The two are asked of the unit itself,
 * so a right over the fleet is not a right over a unit one cannot see.
 */
interface HeldUnit extends Equipment {
    id: number;
    /** The mark its category is known by, as the fleet's own lists draw it. */
    type_icon: string | null;
    /** What the card says about it now, which the write-off form opens on. */
    condition: string | null;
    can_take: boolean;
    can_write_off: boolean;
}

interface ProfilePrivate extends PrivateDetails {
    educations: (Education & { id: number })[];
    /**
     * Whether anybody was asked about those, answered before the list is filled
     * the way the children are: an empty list said both «нет» and «никто не
     * спрашивал», and the sheet counted the two alike. It travels under the
     * right to read the list it is about.
     */
    has_educations: boolean | null;
    /** The documents behind those two lines; the lines themselves are private details. */
    degree_document: CardDocument | null;
    award_document: CardDocument | null;
    military_document: CardDocument | null;
    /** The latest first. */
    work_experiences: (WorkExperience & { id: number })[];
    /** The jobs' own answer, asked and read exactly as the schools' above. */
    has_experience: boolean | null;
    /** Grouped by kind, which is resolved to its directory name here. */
    equipment: HeldUnit[];
    /** What has passed through their hands and what happened to it meanwhile. */
    equipment_history: { events: HistoryEvent[]; names: NameLookup };
    birth_place: string | null;
    passport: {
        series: string | null;
        number: string | null;
        issued_at: string | null;
        issued_by: string | null;
        /** The number the tax office files them under; a line of the block like the rest. */
        tax_number: string | null;
    };
}

/**
 * A card. Only the name and the photograph are certain: every other line is a
 * field the viewer may or may not read, and what they may not read does not
 * travel at all.
 */
interface Employee {
    id: number;
    name: string;
    surname: string;
    /** Square thumbnail for the interface. */
    avatar: string | null;
    /** The upload itself, opened at full size. */
    avatar_original: string | null;
    status: 'active' | 'transferred' | 'fired';
    status_changed_at: string | null;
    /** Where they were transferred or why they were let go; managers only. */
    status_note: string | null;
    patronymic?: string | null;
    sex?: Sex;
    email?: string;
    /** The one role they hold, shown as «Роль»; null until somebody gives them one. */
    role?: string | null;
    /**
     * Positions, shown as «Должность»; an employee can hold several. Each is
     * numbered for whoever may open the job and left a word for everybody else,
     * and carries the department it belongs to.
     */
    positions?: PositionRef[];
    departments?: {
        id: number;
        name: string;
        full_name: string;
        path: string;
        full_path: string;
        is_head: boolean;
        /** Whoever leads it, this person left out. Empty where the page does not load them. */
        heads: { id: number; name: string }[];
    }[];
    /** The best known first. */
    languages?: SpokenLanguage[];
    /** The company's own number for them, written with its leading noughts. */
    employee_number?: string | null;
    /** The kind of work they were taken on for, and the place they work from. */
    employment_type?: string | null;
    /** Null when the viewer may not see this person's private data. */
    private: ProfilePrivate | null;
}

/** What the card already holds, as the window of «Основное» addresses it. */
interface Assigned {
    /**
     * The role held, by name rather than by title: a name is what the picker
     * submits, and null is a card nobody has given a role to yet.
     */
    role: string | null;
    /**
     * Where they stand: the jobs held, and the duties picked out of each. The
     * window changes the jobs and leaves the duties as they were.
     */
    placements: Placement[];
}

/**
 * Sections of the profile. Education, experience and equipment hold private
 * data, so they are offered only to viewers who may see it; the last four have
 * no data behind them yet.
 */
const TABS = [
    { key: 'profile', title: 'Профиль' },
    { key: 'sheet', title: 'Личный листок' },
    { key: 'equipment', title: 'Оборудование', private: true, field: 'equipment' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

/** The open section rides in the URL hash, so a tab can be linked and survives a reload. */
function useTab(available: readonly TabKey[]): [TabKey, (key: TabKey) => void] {
    const fromHash = () => {
        const key = window.location.hash.replace('#', '') as TabKey;

        return available.includes(key) ? key : 'profile';
    };
    const [tab, setTab] = useState<TabKey>(fromHash);

    // Back and forward move between tabs, like between pages.
    useEffect(() => {
        const onHashChange = () => setTab(fromHash());
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    });

    return [
        // Moving to a colleague keeps the open tab, but they may not offer it:
        // a viewer who sees one person's private data need not see another's.
        available.includes(tab) ? tab : 'profile',
        (key: TabKey) => {
            setTab(key);
            // The state travels with the address. Inertia keeps the page of this
            // history entry in it, and writing null here would throw that away:
            // going back would then land on an entry it cannot restore, leaving
            // the previous page's hash on the page one is already looking at.
            window.history.replaceState(window.history.state, '', key === 'profile' ? window.location.pathname : `#${key}`);
        },
    ];
}

function ProfileTabs({
    tabs,
    active,
    onChange,
}: {
    tabs: readonly { key: TabKey; title: string }[];
    active: TabKey;
    onChange: (key: TabKey) => void;
}) {
    return (
        // One strip that scrolls sideways on a phone: wrapped, the line under the
        // open tab would sit between two rows and read as belonging to neither.
        // On a phone the strip also stays under the top bar while the card scrolls,
        // on a frosted band that runs from edge to edge of the screen.
        <Tabs value={active} onValueChange={(next) => onChange(next as TabKey)} className="min-w-0">
            <TabsList
                variant="line"
                aria-label="Разделы профиля"
                className="max-md:bg-background/90 overflow-x-auto [scrollbar-width:none] max-md:sticky max-md:top-12 max-md:z-20 max-md:-mx-3 max-md:gap-x-5 max-md:px-4 max-md:backdrop-blur-xl [&::-webkit-scrollbar]:hidden"
            >
                {tabs.map((tab) => (
                    <TabsTrigger key={tab.key} value={tab.key}>
                        {tab.title}
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    );
}

/** The dialog narrows a department into its positions and a position into its duties, so all three travel. */
/** A department as the window names it while a job is being picked. */
interface Unit {
    id: number;
    name: string;
    full_name: string;
    path: string;
    full_path: string;
    /** Whoever leads it, as the line «Руководитель» reads them. */
    heads: { id: number; name: string }[];
}

interface EditOptions extends PlacementOptions {
    nationalities: string[];
    citizenships: string[];
    /** Every department with whoever leads it: the job decides both lines. */
    units: Unit[];
    /** The two short lists a card points at one entry of, by name. */
    employment_types: string[];
    /** The roles this viewer may hand out, chosen by name. */
    roles: { name: string; title: string }[];
    languages: { id: number; name: string }[];
    /** Why jobs have ended before, as suggestions for a previous job. */
    reasons: string[];
    equipment_types: { id: number; name: string }[];
    /**
     * The four lists only «Личный листок» asks for: what diplomas have made of
     * people here, and what the three free-text lines say on the cards already
     * filed.
     *
     * Asked for as the page may or may not have them, because every one of them
     * is a suggestion and none is binding — a box handed nothing still takes
     * whatever is typed into it, and a card may well name something no list has
     * heard of.
     */
    qualifications?: string[];
    degrees?: string[];
    awards?: string[];
    militaries?: string[];
}

/** The area left under the tabs, split into a main column and a sidebar. */
const paneGrid = 'grid gap-4 max-md:gap-4 lg:grid-cols-[1fr_24rem]';

/** One column of that area. */
const pane = 'flex min-w-0 flex-col gap-4 max-md:gap-4';

/**
 * The narrower of the two, which follows the page the way the nav of a long
 * sheet does: what stands in it is read beside whatever part of the main column
 * somebody has scrolled to, so it has no business scrolling away.
 *
 * `self-start` because a grid stretches its items to the height of the row, and
 * a box already as tall as the row has nowhere to stick to. It scrolls within
 * itself where it is taller than the window, or its foot would be unreachable.
 */
const sidePane = `${pane} lg:sticky lg:top-6 lg:max-h-[calc(100dvh-4.5rem)] lg:self-start lg:overflow-y-auto lg:pb-2`;

/**
 * Contacts are dialled and written to, so they carry the brand colour and an
 * icon rather than looking like the plain text of the fields around them.
 */
const contactLink = 'text-brand-strong flex items-center gap-1.5 hover:underline max-md:justify-end dark:text-[#C5E27A]';

/** A badge that may break onto a second line rather than run off a phone screen. */
const wrappingBadge = 'h-auto min-h-[22px] max-w-full py-0.5 whitespace-normal';

/** The "Контакты" card in a form; the server normalises the phones to E.164. */
function ContactsDialog({ employee, details, onClose }: { employee: Employee; details: ProfilePrivate; onClose: () => void }) {
    const form = useClearingForm({
        email: employee.email,
        phone: details.phone ?? '',
        sos_phone: details.sos_phone ?? '',
        sos_contact: details.sos_contact ?? '',
    });

    // Contacts are allowed line by line too; whose number the SOS one is goes
    // with the number itself.
    const canEdit = useCanEdit();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('employees.contacts', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Контакты</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                        {canEdit('email') && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                {/* The address is how the person signs in, so whoever may
                                    change it may not clear it. */}
                                <Label htmlFor="contacts-email" required>
                                    Электронная почта
                                </Label>
                                <Input
                                    aria-required
                                    id="contacts-email"
                                    type="email"
                                    autoComplete="off"
                                    placeholder="name@evolet.co.uk"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    aria-invalid={!!form.errors.email}
                                />
                                <InputError message={form.errors.email} />
                                <p className="text-muted-foreground text-[13px]">С этим адресом сотрудник входит в систему.</p>
                            </div>
                        )}

                        {canEdit('phone') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="contacts-phone">Телефон</Label>
                                <Input
                                    id="contacts-phone"
                                    type="tel"
                                    inputMode="tel"
                                    placeholder="90 123 45 67"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    aria-invalid={!!form.errors.phone}
                                />
                                <InputError message={form.errors.phone} />
                            </div>
                        )}

                        {canEdit('sos_phone') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="contacts-sos-phone">Экстренный контакт</Label>
                                <Input
                                    id="contacts-sos-phone"
                                    type="tel"
                                    inputMode="tel"
                                    placeholder="90 123 45 67"
                                    value={form.data.sos_phone}
                                    onChange={(e) => form.setData('sos_phone', e.target.value)}
                                    aria-invalid={!!form.errors.sos_phone}
                                />
                                <InputError message={form.errors.sos_phone} />
                            </div>
                        )}

                        {canEdit('sos_phone') && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor="contacts-sos-contact">Чей это номер</Label>
                                <Input
                                    id="contacts-sos-contact"
                                    placeholder="Мама — Дилором"
                                    value={form.data.sos_contact}
                                    onChange={(e) => form.setData('sos_contact', e.target.value)}
                                    aria-invalid={!!form.errors.sos_contact}
                                />
                                <InputError message={form.errors.sos_contact} />
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The quarters behind the figure, newest last. Nothing counts any of this yet:
 * these are the design's numbers, so the block can be read and agreed on
 * before there is anything behind it.
 */
const KPI_QUARTERS = [
    { id: '2025-4', title: "4 кв. '25", value: 89 },
    { id: '2026-1', title: "1 кв. '26", value: 91 },
    { id: '2026-2', title: "2 кв. '26", value: 93 },
    { id: '2026-3', title: "3 кв. '26", value: 97 },
];

/** What the figure is made of: a line per indicator, with its weight. */
const KPI_LINES = [
    { name: 'Подготовка аналитических отчётов', weight: '30%', plan: '24 шт.', fact: '26 шт.', done: 108 },
    { name: 'Соблюдение сроков задач', weight: '25%', plan: '95%', fact: '91%', done: 96 },
    { name: 'Качество данных (ошибки в отчётах)', weight: '20%', plan: '≤ 2', fact: '1', done: 100 },
    { name: 'Обучение и развитие', weight: '15%', plan: '40 ч', fact: '34 ч', done: 85 },
    { name: 'Оценка руководителя', weight: '10%', plan: '4,0', fact: '4,3', done: 108 },
];

/** What the quarter was aiming at, drawn across the columns as a line. */
const KPI_GOAL = 90;

/**
 * Short of the goal, a line is a warning rather than a weaker green: the two
 * read apart at a glance, and the figure beside the bar says which it is
 * without anybody having to tell the colours apart.
 */
const kpiShort = (done: number) => done < KPI_GOAL;

/**
 * «KPI сотрудника»: the quarter's figure with the quarters behind it, and the
 * indicators that make it up.
 *
 * The columns are drawn from nought to a hundred, each in a track of its own,
 * so a quarter is read against the whole scale and against the goal line
 * across it rather than against a baseline chosen to flatter it.
 */
function KpiSection() {
    const current = KPI_QUARTERS[KPI_QUARTERS.length - 1];

    return (
        <Panel
            title="KPI сотрудника"
            action={
                <Button variant="link" className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]">
                    Все периоды
                </Button>
            }
        >
            <div className="grid gap-6 max-md:py-4 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] md:gap-8 [&>*]:min-w-0">
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1">
                        <p className="text-muted-foreground text-xs">Итоговый KPI</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-brand-strong text-4xl leading-none font-semibold tabular-nums dark:text-[#C5E27A]">
                                {current.value}%
                            </span>
                            <StatusBadge tone="success">+4 п.п.</StatusBadge>
                        </div>
                        <p className="text-muted-foreground text-xs">к прошлому кварталу · цель {KPI_GOAL}%</p>
                    </div>

                    {/* A column per quarter, the figure over each: four of them, so
                        every one is named rather than one in three. */}
                    <div className="flex items-end gap-2">
                        {KPI_QUARTERS.map((quarter) => {
                            const now = quarter.id === current.id;

                            return (
                                <div key={quarter.id} className="flex min-w-0 flex-1 flex-col items-center gap-1">
                                    <span
                                        className={cn(
                                            'text-xs tabular-nums',
                                            now ? 'text-brand-strong font-semibold dark:text-[#C5E27A]' : 'text-muted-foreground',
                                        )}
                                    >
                                        {quarter.value}
                                    </span>
                                    <div className="bg-muted relative flex h-[72px] w-full items-end overflow-hidden rounded-md">
                                        {/* What the quarter was aiming at, at its own height in
                                            every column: one level across all four. */}
                                        <div
                                            className="border-muted-foreground/40 absolute inset-x-0 z-10 border-t border-dashed"
                                            style={{ bottom: `${KPI_GOAL}%` }}
                                        />
                                        <div
                                            className={cn('w-full rounded-md', now ? 'bg-brand' : 'bg-muted-foreground/25')}
                                            style={{ height: `${quarter.value}%` }}
                                        />
                                    </div>
                                    <span className={cn('text-[11px] whitespace-nowrap', now ? 'font-medium' : 'text-muted-foreground')}>
                                        {quarter.title}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* The lines of the quarter. On a phone the table scrolls
                    sideways rather than squeezing five columns into a screen. */}
                <div className="min-w-0 md:pl-8">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="text-muted-foreground px-0 text-xs font-normal">Показатель</TableHead>
                                <TableHead className="text-muted-foreground w-16 text-xs font-normal">Вес</TableHead>
                                <TableHead className="text-muted-foreground w-20 text-xs font-normal">План</TableHead>
                                <TableHead className="text-muted-foreground w-20 text-xs font-normal">Факт</TableHead>
                                <TableHead className="text-muted-foreground w-64 text-xs font-normal">Выполнение</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {KPI_LINES.map((line) => (
                                <TableRow key={line.name} className="hover:bg-transparent">
                                    <TableCell className="px-0 py-3 font-medium break-words whitespace-normal">{line.name}</TableCell>
                                    <TableCell className="text-muted-foreground py-3 tabular-nums">{line.weight}</TableCell>
                                    <TableCell className="text-muted-foreground py-3 tabular-nums">{line.plan}</TableCell>
                                    <TableCell className="py-3 font-medium tabular-nums">{line.fact}</TableCell>
                                    <TableCell className="py-3">
                                        <div className="flex items-center gap-3">
                                            <Progress
                                                // Over the plan fills the bar; the figure
                                                // beside it is where the surplus is read.
                                                value={Math.min(line.done, 100)}
                                                aria-label={`Выполнение: ${line.done}%`}
                                                className={cn(
                                                    'min-w-16 flex-1',
                                                    kpiShort(line.done) && 'bg-[#F5A524]/20 [&>*]:bg-[#D97706] dark:[&>*]:bg-[#F5A524]',
                                                )}
                                            />
                                            <span className="w-10 shrink-0 text-right text-sm font-medium tabular-nums">{line.done}%</span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </Panel>
    );
}

/**
 * How far this card has got through «Личный листок по учёту кадров»: the figure
 * the band over the sheet reads, and the lines it is still short of.
 *
 * Counted on the server, over the lines this viewer may read and no others — a
 * figure taken over the whole card would be a statement about the lines they
 * may not see, and the list of what is wanted would name them outright. What
 * is counted and what is not is App\Support\PersonnelSheet's to say.
 */
interface SheetProgress {
    /** A whole number of per cent. */
    filled: number;
    /** The lines nobody has answered yet, as the sheet names them, in the order it asks. */
    missing: string[];
    /**
     * And the lines that are answered and still short of the paper that proves
     * it — a degree with no diploma, a place of study somebody left with
     * nothing on file. Not part of the figure above: a document is not an
     * answer, and a line that said «Не заполнено» because a scan had not
     * arrived would send somebody to ask a question that has been answered.
     */
    papers: string[];
    /**
     * Every counted line this viewer may read, answered or not, keyed as
     * App\Support\PersonnelSheet keys them.
     *
     * The two above are the saved truth and are shown whenever nobody is
     * changing anything. This is what the same two are worked out from while
     * somebody is: the server says which lines are counted — that is a question
     * about rights, and the browser has no business guessing it — and the form's
     * own values say which of them have an answer yet.
     */
    lines: { key: string; title: string }[];
}

/**
 * One part of the sheet as the page plans it out, before any of it is drawn:
 * what the paper form calls it and numbers it, whether this viewer may read it
 * at all, what stands in it, and whatever answers for the whole of it.
 *
 * Planned as a list rather than written out one part after another because two
 * things now have to agree about the parts — the sheet itself and the nav
 * beside it — and a list is the only way of saying once which parts there are.
 */
interface SheetPartPlan {
    /** Already padded as the form prints it — «03», not 3: the paper's figure, not the part's place in what is drawn. */
    number: string;
    title: string;
    /** A part this viewer may not read is drawn nowhere and listed nowhere. */
    shown: boolean;
    /**
     * The form keys this part owns, so a refusal can be shown where it belongs.
     * The whole sheet is saved at once, which means it is also refused at once:
     * a complaint about a year in the sixth part arrives while somebody is
     * looking at the tenth, and without this there would be nothing to say
     * which part to put on screen.
     */
    fields: string[];
    /** The control that answers for the whole part, where it has one. */
    action?: ReactNode;
    body: ReactNode;
    /**
     * The same part as questions rather than as answers, where this reader has
     * anything in it to answer. A part with nothing editable in it has none,
     * and goes on reading as it does while the rest of the sheet is typed into:
     * a box somebody can fill in and the save would drop is worse than no box,
     * because they would believe they had corrected it.
     */
    edit?: ReactNode;
}

/**
 * A way to another page where that page is this viewer's to open, and the same
 * words without a link where it is not.
 *
 * Somebody shown their own card and nothing else has every one of these
 * refused — the department they are filed in, the job they hold, whoever leads
 * the unit. A link that answers «нет доступа» is worse than a line of text: it
 * says there is somewhere to go and then takes it back, and the words it is
 * made of were the whole of what the card had to say.
 */
function Way({
    linked,
    href,
    title,
    className,
    children,
}: {
    linked: boolean;
    href: string;
    title?: string;
    className?: string;
    children: ReactNode;
}) {
    return linked ? (
        <Link href={href} title={title} className={className}>
            {children}
        </Link>
    ) : (
        <span className={className}>{children}</span>
    );
}

/**
 * One numbered part of the sheet: its name, and everything said under the name.
 *
 * The furniture is the application's own — the figure in a box of its own in
 * front of the name, the body indented behind it — so that a part of this sheet
 * and a part of «Новый сотрудник» are one thing drawn one way, down to the
 * twenty-four points the answers stand in from. What is the sheet's own is the
 * control at the end of the name, where a part is acted on rather than only
 * read: it belongs to the whole part, as the action in the header strip of a
 * block belongs to the whole block.
 *
 * The number is written onto the element as well as into the name, because the
 * nav beside the sheet has to find this part to scroll to it and to watch it,
 * and the paper's figure is the one name for a part that does not change when
 * the part above it drops out.
 */
function SheetPart({
    number,
    title,
    action,
    active,
    onOpen,
    children,
}: {
    number: string;
    title: string;
    action?: ReactNode;
    /** Whether the nav has this part marked; the title shows it too. */
    active?: boolean;
    /** Reading a part is being in it, so touching one marks it in the nav. */
    onOpen?: () => void;
    children: ReactNode;
}) {
    return (
        <NumberedSection
            data-part={number}
            // Scrolled to from the nav, so the name of the part does not arrive
            // flush against the top of the window.
            className="scroll-mt-4 md:scroll-mt-6"
            // Pressing anywhere in a part marks it, which is what somebody
            // reading it has already done. Not a button and not focusable: it
            // is the whole part, there is nothing to tab to, and the nav beside
            // it is the way in for a keyboard.
            onClick={onOpen}
            title={
                <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <SectionTitle as="h3" number={number} title={title} active={active} className="min-w-0" />
                    {action}
                </div>
            }
            body={children}
        />
    );
}

/**
 * The lines of a part: a label over its answer, in as many columns as fit. A
 * line nobody has answered is a dash in the colour of a label, the way every
 * other line of the card writes one — the label is the question, and a sheet
 * has to be able to show that it was asked and not answered.
 */
function SheetFacts({ rows, columns = 3 }: { rows: (string | null)[][]; columns?: 3 | 4 }) {
    return (
        <dl className={cn('grid gap-x-6 gap-y-5', columns === 4 ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-3')}>
            {rows.map(([label, value]) => (
                <div key={label ?? value} className="flex min-w-0 flex-col gap-1">
                    <dt className="text-muted-foreground text-xs">{label}</dt>
                    <dd className="text-sm font-medium break-words">{value || <span className="text-muted-foreground font-normal">—</span>}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * The people a card names, in the one table all three of them are read in: the
 * spouse, the children and the rest of the family are asked the same three
 * things, so the three stand in columns of the same width under the same heads.
 *
 * Only the last of them is asked how it is related, and only it carries the
 * column: a table standing under «Супруга» whose one row then said «Супруга»
 * again would be answering the question its own heading has just answered, and
 * a column holding one word over and over reads as an answer and is not one.
 */
function FamilyTable({ rows, relation = false }: { rows: ReactNode[][]; relation?: boolean }) {
    return (
        <RecordTable
            head={[...(relation ? ['Родство'] : []), 'ФИО', 'Дата рождения', 'Работа, учёба']}
            rows={rows}
            // Said in parts of the whole rather than in pixels, so that the
            // tables answer a narrow card the way they answer a wide one, and
            // the same for every table: the spouse and the children are two
            // tables of one list, and a «Дата рождения» column that was the
            // width of «сен. 1988» in one and of «май 2018» in the other would
            // read as two lists that merely look alike.
            widths={relation ? ['16%', '30%', '22%', '32%'] : ['38%', '22%', '40%']}
        />
    );
}

/**
 * A paper named in the sheet, which is also the way to it.
 *
 * Opened in a window of its own: the sheet may be half filled in, and a card
 * that navigated away to show a scan would take the unsaved answers with it.
 */
function Paper({ paper }: { paper: { name: string | null; url: string } }) {
    return (
        <a
            href={paper.url}
            target="_blank"
            rel="noopener"
            className="hover:text-brand-strong inline-flex min-w-0 items-center gap-1.5 underline-offset-4 hover:underline"
        >
            {/* The same mark the three lines of the card clip their papers with,
                so that a document is known for one wherever the sheet shows it. */}
            <FileText className="size-3.5 shrink-0" />

            {/* A file on the card whose own name was never kept is still a file
                to be opened, so it is called what it is rather than nothing. */}
            <span className="min-w-0 break-words">{paper.name ?? 'Документ'}</span>
        </a>
    );
}

/**
 * A part the card answers in one line, and the parts whose list is empty: what
 * it says, or the same dash a line of «Основное» leaves where nobody has said
 * anything. The question has still been asked, so the part is drawn either way.
 *
 * Where a paper stands behind the answer — a diploma of a degree, the order of
 * an award, a military card — it is clipped to the end of the line as a mark
 * rather than named: the line has already said what the paper is about, and the
 * name of a file says only what somebody called it when they uploaded it. It
 * never stands beside a dash, since the card refuses a paper for a line that
 * answers nothing.
 */
function SheetLine({ children, paper }: { children?: ReactNode; paper?: CardDocument | null }) {
    if (!paper) {
        return <p className="text-sm font-medium">{children || <span className="text-muted-foreground font-normal">—</span>}</p>;
    }

    return (
        <p className="text-sm font-medium">
            {/* The answer itself is the way to the paper behind it, mark and
                words together: a target the width of an icon is a target to be
                missed, and the line already says what the paper is about. The
                mark leads, so that a part carrying one is known for it before
                the answer is read. */}
            <a
                href={paper.url}
                target="_blank"
                rel="noopener"
                title={paper.name}
                className="hover:text-brand-strong inline-flex min-w-0 items-center gap-1.5 underline-offset-4 hover:underline"
            >
                <FileText className="size-4 shrink-0" />
                <span className="min-w-0 break-words">{children}</span>
            </a>
        </p>
    );
}

/**
 * Every line of the card the eleven parts of the sheet answer, as
 * App\Support\EmployeeFields names them.
 *
 * Written out because two questions are asked of it: whether there is anything
 * at all on this sheet for this viewer to change — if not, nothing is offered —
 * and, part by part, which of its fields to draw as a box rather than as a line
 * of text. Not the sheet's own line keys: those name questions, and these name
 * the rights, and «Серия и номер» is one right over two boxes.
 */
const SHEET_FIELDS = [
    'surname',
    'name',
    'patronymic',
    'sex',
    'birth_date',
    'birth_place',
    'nationality',
    'citizenship',
    'educations',
    'degree',
    'languages',
    'work_experiences',
    'award',
    'marital_status',
    'spouse',
    'children',
    'relatives',
    'passport_number',
    'passport_issued_at',
    'passport_issued_by',
    'tax_number',
    'home_address',
    'phone',
    'sos_phone',
    'military',
];

/** The sheet itself, and beside it what is still wanted for it. */
function SheetTab({
    employee,
    sheet,
    documents,
    options,
}: {
    employee: Employee;
    sheet: SheetProgress;
    /**
     * The papers the company asks everybody for, and what this card answers
     * them with. Null for a viewer who may not read them at all — the block
     * then shows the sheet's own papers and says nothing about the rest.
     */
    documents: EmployeeDocuments | null;
    options: EditOptions | null;
}) {
    const details = employee.private;
    const shows = useShows();
    const canEdit = useCanEdit();

    /**
     * Says there are none, or puts the question back to unanswered. Only those
     * two: «есть» is read off the records, so the doors that take this refuse
     * it. Scroll is kept because the answer is given halfway down a long sheet.
     */
    /**
     * Whether the sheet is being answered rather than read. One flag for the
     * whole of it, because that is what the thing is: eleven parts are saved in
     * one request, so they are opened and shut as one.
     */
    const [editing, setEditing] = useState(false);

    /** Languages typed in that the directory has not heard of yet. */
    const [createdLanguages, setCreatedLanguages] = useState<string[]>([]);

    /**
     * The sheet as the card holds it, read into the answers a form is made of.
     *
     * Every line is taken from the card whether this viewer may change it or
     * not. A line they may only read is drawn as it was and travels nowhere —
     * but the list of what is still wanted counts it, and it has to count it
     * off the same values as the lines beside it.
     *
     * Read afresh each time the sheet is opened or shut rather than once: what
     * «Отмена» puts back is what the card says now, which after a save is not
     * what it said when the page arrived.
     */
    const saved = (): SheetValues => ({
        ...blankSheet,
        surname: employee.surname,
        name: employee.name,
        patronymic: employee.patronymic ?? '',
        sex: employee.sex ?? '',
        birth_date: details?.birth_date ?? '',
        birth_place: details?.birth_place ?? '',
        nationality: details?.nationality ?? '',
        citizenship: details?.citizenship ?? [],
        // A part with records in it has already said «есть», whatever the flag
        // beside them happens to hold: the server reads the answer off the
        // records, and a box that opened on «не выбрано» over three universities
        // would throw them away on the next save.
        has_educations: (details?.educations ?? []).length > 0 ? 'yes' : asSaid(details?.has_educations),
        educations: (details?.educations ?? []).map((education) => ({
            // Which row this is, so the save corrects it where it stands rather
            // than writing it again — which is what leaves its diploma alone.
            id: education.id,
            institution: education.institution,
            faculty: education.faculty,
            specialty: education.specialty,
            qualification: education.qualification ?? '',
            started_year: String(education.started_year),
            graduated_year: education.graduated_year === null ? '' : String(education.graduated_year),
            document: null,
            document_name: education.document?.name ?? '',
            // What is already clipped to this record, so that an empty file box
            // does not read as a diploma that went missing.
            attached: education.document?.name ?? undefined,
        })),
        degree: details?.degree ?? '',
        // A card naming no language opens on the one empty row «Новый
        // сотрудник» opens on, and for the same reason: everybody speaks
        // something, and a part that opened empty would read as a part that may
        // be skipped.
        languages:
            (employee.languages ?? []).length > 0
                ? (employee.languages ?? []).map((language) => ({ id: String(language.id), level: language.level }))
                : [{ id: '', level: 'intermediate' }],
        has_experience: (details?.work_experiences ?? []).length > 0 ? 'yes' : asSaid(details?.has_experience),
        experiences: (details?.work_experiences ?? []).map((job) => ({
            organization: job.organization,
            position: job.position,
            started_month: String(job.started_month),
            started_year: String(job.started_year),
            ended_month: job.ended_month === null ? '' : String(job.ended_month),
            ended_year: job.ended_year === null ? '' : String(job.ended_year),
            reason: job.reason ?? '',
        })),
        award: details?.award ?? '',
        marital_status: details?.marital_status ?? '',
        spouse_name: details?.spouse_name ?? '',
        spouse_birth_date: details?.spouse_birth_date ?? '',
        spouse_occupation: details?.spouse_occupation ?? '',
        has_children: (details?.children ?? []).length > 0 ? 'yes' : asSaid(details?.has_children),
        // The month and what they do, as the card keeps them for everybody it
        // names: the three lists are read into three rows of the same shape.
        children: (details?.children ?? []).map((child) => ({
            full_name: child.full_name,
            birth_date: child.birth_date ?? '',
            occupation: child.occupation ?? '',
        })),
        relatives: (details?.relatives ?? []).map((relative) => ({
            relation: relative.relation,
            full_name: relative.full_name,
            birth_date: relative.birth_date ?? '',
            occupation: relative.occupation ?? '',
        })),
        passport_series: details?.passport.series ?? '',
        passport_number: details?.passport.number ?? '',
        passport_issued_at: details?.passport.issued_at ?? '',
        passport_issued_by: details?.passport.issued_by ?? '',
        tax_number: details?.passport.tax_number ?? '',
        home_address: details?.home_address ?? '',
        phone: details?.phone ?? '',
        sos_phone: details?.sos_phone ?? '',
        sos_contact: details?.sos_contact ?? '',
        military: details?.military ?? '',
    });

    const form = useClearingForm<SheetValues>(saved());

    /**
     * Opening the sheet to be answered, and shutting it again. Both put every
     * box back to what the card says, so «Отмена» is the one and only way out
     * that writes nothing — and so that a sheet opened a second time does not
     * still hold what somebody typed into it and abandoned.
     */
    const answer = (open: boolean) => {
        form.clearErrors();
        form.setData(saved());
        setCreatedLanguages([]);
        setEditing(open);
    };

    /**
     * The whole sheet in one request.
     *
     * Two ways out, and which one is taken is not a matter of taste. A paper
     * being uploaded can only travel as multipart, and Inertia cannot send a
     * PUT that way — so a save that carries one goes as a POST that says it is
     * a PUT, which is what every window of this card that takes a file does.
     *
     * But a multipart body cannot say that a list is empty, and the save asks
     * for all five of the sheet's lists outright: an empty list appends no
     * field, so its name never arrives and the server reads a sheet that forgot
     * to mention the children. So the spoofing is kept for the saves that have
     * no choice, and every other one goes as the PUT it is, with a body that
     * can hold an empty list.
     *
     * A refusal leaves the boxes open with what was typed in them, and the
     * complaints land under the fields they name, since both doors are answered
     * in the same names. What it must not do is leave the reader looking at a
     * part that has nothing wrong with it: the whole sheet is saved at once, so
     * the whole sheet is refused at once, and a card filed years ago can be
     * turned down over a rule that did not exist when it was written —
     * «Причина ухода» on the second job, nine screens from the button. So the
     * page goes to the first part that was complained about and the nav marks
     * it, exactly as «Новый сотрудник» does with its own refusals.
     */
    const save = () => {
        const url = route('employees.sheet', employee.id);
        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setEditing(false),
            onError: (refused: Record<string, string>) => {
                // Over the parts actually drawn and in the order they stand in,
                // because that is what the nav counts: a complaint about a part
                // this reader cannot see is not one they can be sent to.
                const first = drawn.findIndex((part) => part.fields.some((field) => at(refused, field) !== undefined));

                if (first >= 0) show(first);
            },
        };

        if (carriesFile(form.data)) {
            form.transform((card) => ({ ...card, ...sheetToSend(card), _method: 'put' }));
            form.post(url, { ...options, forceFormData: true });

            return;
        }

        form.transform((card) => ({ ...card, ...sheetToSend(card) }));
        form.put(url, options);
    };

    /**
     * Whether one line of the sheet is this reader's to answer.
     *
     * Both halves asked, and the reading half is not a formality: a card sends
     * only the lines somebody may read, so a box opened over a line they may
     * not read would stand empty over a value that is on file — and saving the
     * sheet would write the emptiness back over it.
     */
    const answers = (field: string) => shows(field) && canEdit(field);

    /** Whether any line of this part is theirs to answer. */
    const editable = (...fields: string[]) => fields.some(answers);

    /**
     * The lists the parts offer an answer out of, as this page was given them.
     * Empty where it was given nothing — for a viewer who may edit none of the
     * card there is no form to offer them in, and a box handed an empty list
     * still takes whatever is typed.
     */
    const sheetOptions: SheetOptions = {
        nationalities: options?.nationalities ?? [],
        citizenships: options?.citizenships ?? [],
        languages: options?.languages ?? [],
        qualifications: options?.qualifications ?? [],
        degrees: options?.degrees ?? [],
        awards: options?.awards ?? [],
        militaries: options?.militaries ?? [],
        reasons: options?.reasons ?? [],
    };

    /**
     * What every part of the sheet is handed. `can` is the card's own answer
     * about this reader, so a part draws a box exactly where the save would
     * take one and leaves the rest reading as they did.
     */
    const asked = { data: form.data, errors: form.errors, setData: form.setData, options: sheetOptions, can: answers };

    /**
     * The lines of a part this viewer may read, in the order the sheet asks
     * them. A line that is closed is dropped rather than drawn empty: a label
     * over a dash says the line was asked and not answered, which about a line
     * nobody may read is a statement that it is there to be read.
     */
    const lines = (rows: [label: string, field: string, value: string | null][]) =>
        rows.filter(([, field]) => shows(field)).map(([label, , value]) => [label, value]);

    const general = lines([
        ['Пол', 'sex', employee.sex ? sexLabels[employee.sex] : null],
        ['Дата рождения', 'birth_date', formatDate(details?.birth_date ?? null)],
        ['Место рождения', 'birth_place', details?.birth_place ?? null],
        ['Национальность', 'nationality', details?.nationality ?? null],
        // There may be several, and the sheet asks the line once.
        ['Гражданство', 'citizenship', (details?.citizenship ?? []).join(', ') || null],
    ]);

    const passport = lines([
        // The series and the number are one line of the sheet, as they are one
        // line of a passport.
        ['Серия и номер', 'passport_number', [details?.passport.series, details?.passport.number].filter(Boolean).join(' ') || null],
        ['Дата выдачи', 'passport_issued_at', formatDate(details?.passport.issued_at ?? null)],
        ['Кем выдан', 'passport_issued_by', details?.passport.issued_by ?? null],
        ['ИНН', 'tax_number', details?.passport.tax_number ?? null],
    ]);

    const contacts = lines([
        ['Домашний адрес', 'home_address', details?.home_address ?? null],
        ['Телефон', 'phone', details?.phone ? formatPhone(details.phone) : null],
        [
            // Whose number it is goes in the label with the number, the way the
            // «Контакты» block of the card keeps the two together.
            ['Экстренный контакт', details?.sos_contact].filter(Boolean).join(' · '),
            'sos_phone',
            details?.sos_phone ? formatPhone(details.sos_phone) : null,
        ],
    ]);

    const languages = employee.languages ?? [];

    // Said of the person it is about: «Не женат» and «Не замужем» are the same
    // answer in two words. Where the card does not say which, the masculine
    // form stands — Russian has no third one to fall back on.
    const marital = details?.marital_status ? maritalLabels[employee.sex ?? 'male'][details.marital_status] : null;

    /**
     * Where the person studied, a record at a time: the institution and the
     * years it took over them, and under that what the place of study made of
     * them. Five answers, of which two are usually unanswered, which is why
     * this part is read down the page rather than ruled into columns.
     */
    const educations = (details?.educations ?? []).map((education) => [
        // The place and the faculty inside it read as one address, the way the
        // subject and what it made of them read as one phrase in the next
        // column: a faculty on its own answers nothing, and a column of its own
        // would stand empty on most cards.
        [education.institution, education.faculty].filter(Boolean).join(', '),
        [education.specialty, education.qualification].filter(Boolean).join(', '),
        `${education.started_year}–${education.graduated_year ?? 'н. в.'}`,
        // The diploma is a file rather than a number now, so its name is the way
        // to it: pressed, the paper opens in a window of its own, because a
        // sheet somebody is reading — or filling in — is not a page to be
        // navigated away from.
        education.document ? <Paper paper={education.document} /> : '—',
    ]);

    /** A date the card knows to the month, or the dash an empty cell reads. */
    const month = (value: string | null) => formatMonth(value) ?? '—';

    const jobs = (details?.work_experiences ?? []).map((job) => [
        // The two ends of a span, in figures: they are read against each other
        // rather than as dates of their own, which is why they are written
        // unlike the family's dates of birth just below.
        `${formatPeriod(`${job.started_year}-${job.started_month}`)} — ${
            job.ended_year !== null && job.ended_month !== null ? formatPeriod(`${job.ended_year}-${job.ended_month}`) : 'н. в.'
        }`,
        job.organization,
        job.position,
        job.reason ?? '—',
    ]);

    // Said of the spouse and not of the person whose sheet this is, so the word
    // is the other one of the pair: a man's spouse is «Супруга». Where the card
    // does not say which, the masculine card stands, as «Семейное положение»
    // above settles the same question the same way.
    const spouse = (employee.sex ?? 'male') === 'female' ? 'Супруг' : 'Супруга';

    /**
     * The whole household, as the paper sheet's one table lists it: the spouse,
     * then the children, then the rest of the family the card names. The three
     * are kept in three places and read under three rights, so each is let into
     * the table only by its own — a viewer who may read the relatives and not
     * the children sees the relatives alone.
     *
     * All three are now asked the same three questions, so all three fill the
     * same three columns, and a line that says nothing reads the dash an
     * unfilled cell of this table already reads.
     */
    const spouseRows =
        shows('spouse') && details?.spouse_name ? [[details.spouse_name, month(details.spouse_birth_date), details.spouse_occupation ?? '—']] : [];

    const childRows = shows('children')
        ? (details?.children ?? []).map((child) => [child.full_name, month(child.birth_date), child.occupation ?? '—'])
        : [];

    const relativeRows = shows('relatives')
        ? (details?.relatives ?? []).map((relative) => [
              relative.relation,
              relative.full_name,
              month(relative.birth_date),
              relative.occupation ?? '—',
          ])
        : [];

    /** «Есть», «Нет», or nothing where nobody has been asked. */
    const hasChildren = details?.has_children === null || details?.has_children === undefined ? null : details.has_children ? 'Есть' : 'Нет';

    // Every paper the card holds, in the order the sheet asks for the lines
    // behind them. A line this viewer may not read sends no paper either, so
    // there is nothing to keep out here.
    const papers = [
        ...(details?.educations ?? []).map((education) => education.document),
        details?.degree_document,
        details?.award_document,
        details?.military_document,
    ].flatMap((paper) => (paper ? [paper] : []));

    /**
     * The sheet part by part, in the order the paper form asks them: every part
     * the form has, the ones this viewer may not read among them, marked as
     * closed rather than left out so that the figures stay the form's own.
     *
     * A part nobody may read drops out of the sheet and out of the nav at once,
     * and what is left may read 01, 02, 09, 10. That is the sheet being honest:
     * the figure says which question of the form is being answered, not how far
     * down the page the answer happens to stand.
     */
    const parts: SheetPartPlan[] = [
        {
            number: '01',
            title: 'ФИО',
            shown: true,
            fields: SHEET_PART_FIELDS.name,
            body: (
                <SheetFacts
                    rows={lines([
                        ['Фамилия', 'surname', employee.surname],
                        ['Имя', 'name', employee.name],
                        ['Отчество', 'patronymic', employee.patronymic ?? null],
                    ])}
                />
            ),
            edit: editable('surname', 'name', 'patronymic') && <NameGroup {...asked} />,
        },
        {
            number: '02',
            title: 'Общие сведения',
            shown: general.length > 0,
            fields: SHEET_PART_FIELDS.general,
            body: <SheetFacts rows={general} />,
            edit: editable('sex', 'birth_date', 'birth_place', 'nationality', 'citizenship') && <GeneralGroup {...asked} />,
        },
        {
            number: '03',
            title: 'Образование',
            shown: shows('educations'),
            fields: SHEET_PART_FIELDS.educations,
            body:
                educations.length === 0 ? (
                    // «Нет» is an answer here as it is under «Учёная степень»,
                    // and only a part nobody has been asked about keeps the dash.
                    <SheetLine>{details?.has_educations === false ? 'Нет' : null}</SheetLine>
                ) : (
                    <RecordTable head={['Учебное заведение, факультет', 'Специальность, квалификация', 'Период', 'Документ']} rows={educations} />
                ),
            edit: answers('educations') && <EducationGroup {...asked} />,
        },
        {
            number: '04',
            title: 'Учёная степень, звание',
            shown: shows('degree'),
            fields: SHEET_PART_FIELDS.degree,
            body: <SheetLine paper={details?.degree_document}>{details?.degree}</SheetLine>,
            edit: answers('degree') && <DegreeGroup {...asked} attached={details?.degree_document?.name ?? null} />,
        },
        {
            number: '05',
            title: 'Знание языков',
            shown: shows('languages'),
            fields: SHEET_PART_FIELDS.languages,
            // Each with its level beside it, said the way the card's own «Знание
            // языков» block says it — the bars and the word — but running along
            // the line and wrapping, because the sheet reads a part at a glance
            // and a column of one language a row would be the longest part of it.
            body:
                languages.length === 0 ? (
                    <SheetLine />
                ) : (
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        {languages.map((language) => (
                            <span key={language.id} className="flex items-center gap-1.5 text-sm font-medium">
                                {language.name}
                                <LevelBadge level={language.level} />
                            </span>
                        ))}
                    </div>
                ),
            edit: answers('languages') && (
                <LanguagesGroup
                    {...asked}
                    created={createdLanguages}
                    onCreated={(name) => setCreatedLanguages((held) => (held.includes(name) ? held : [...held, name]))}
                />
            ),
        },
        {
            number: '06',
            title: 'Трудовая деятельность',
            shown: shows('work_experiences'),
            fields: SHEET_PART_FIELDS.experience,
            body:
                jobs.length === 0 ? (
                    <SheetLine>{details?.has_experience === false ? 'Нет' : null}</SheetLine>
                ) : (
                    <RecordTable head={['Период', 'Организация', 'Должность', 'Причина ухода']} rows={jobs} />
                ),
            edit: answers('work_experiences') && <ExperienceGroup {...asked} />,
        },
        {
            number: '07',
            title: 'Государственные награды, поощрения',
            shown: shows('award'),
            fields: SHEET_PART_FIELDS.award,
            body: <SheetLine paper={details?.award_document}>{details?.award}</SheetLine>,
            edit: answers('award') && <AwardGroup {...asked} attached={details?.award_document?.name ?? null} />,
        },
        {
            number: '08',
            title: 'Семейное положение и состав семьи',
            shown: shows('marital_status') || shows('spouse') || shows('children') || shows('relatives'),
            fields: SHEET_PART_FIELDS.family,
            // Three answers, each with the people it brings: the status and the
            // spouse it implies, then whether there are children and who they
            // are, then whoever else the card names. One table apiece rather
            // than one table for all three, because each answers a question of
            // its own and the question is what somebody came to read; the
            // tables are the same table, so the three still line up.
            body: (
                <div className="flex flex-col gap-5">
                    {shows('marital_status') && <SheetFacts rows={[['Семейное положение', marital]]} />}
                    {spouseRows.length > 0 && (
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-muted-foreground text-xs">{spouse}</p>
                            <FamilyTable rows={spouseRows} />
                        </div>
                    )}

                    {shows('children') && <SheetFacts rows={[['Дети', hasChildren]]} />}
                    {childRows.length > 0 && <FamilyTable rows={childRows} />}

                    {/* The rest of the family, under a name written as a field's
                        own is written: it is the name of what the table holds and
                        there is no single answer to put beneath it, the table
                        being the answer. Nobody named, nothing shown — an empty
                        heading would be asking a question this part does not. */}
                    {relativeRows.length > 0 && (
                        <div className="flex min-w-0 flex-col gap-1">
                            <p className="text-muted-foreground text-xs">Состав семьи</p>
                            <FamilyTable rows={relativeRows} relation />
                        </div>
                    )}
                </div>
            ),
            edit: editable('marital_status', 'spouse', 'children', 'relatives') && <FamilyGroup {...asked} />,
        },
        {
            number: '09',
            title: 'Паспортные данные',
            shown: passport.length > 0,
            fields: SHEET_PART_FIELDS.passport,
            body: <SheetFacts rows={passport} columns={4} />,
            edit: editable('passport_number', 'passport_issued_at', 'passport_issued_by', 'tax_number') && <PassportGroup {...asked} />,
        },
        {
            number: '10',
            title: 'Адрес и контакты',
            shown: contacts.length > 0,
            fields: SHEET_PART_FIELDS.contacts,
            body: <SheetFacts rows={contacts} />,
            edit: editable('home_address', 'phone', 'sos_phone') && <ContactsGroup {...asked} />,
        },
        {
            number: '11',
            title: 'Отношение к воинской обязанности',
            shown: shows('military'),
            fields: SHEET_PART_FIELDS.military,
            body: <SheetLine paper={details?.military_document}>{details?.military}</SheetLine>,
            edit: answers('military') && <MilitaryGroup {...asked} attached={details?.military_document?.name ?? null} />,
        },
    ];

    /**
     * The parts actually drawn, in the order they stand on the page.
     *
     * This one list answers for the sheet and for the nav at the same time, so
     * there is an entry for every part drawn and for no other. A part's place in
     * this list is what the nav marks; the figure it carries stays the paper's,
     * and the two being different numbers is the point.
     */
    const drawn = parts.filter((part) => part.shown);

    /**
     * Which parts those are, by figure, so the watching below is set up again if
     * ever the set changes — a right withdrawn, a part whose every line closed.
     */
    const drawnNumbers = drawn.map((part) => part.number).join();

    const [current, setCurrent] = useState(0);

    /**
     * What the sheet is drawn inside, so that looking a part up finds the parts
     * of this sheet and nothing else on the page that might carry a figure.
     */
    const holder = useRef<HTMLDivElement>(null);

    /**
     * A press holds the mark where it was pressed until the reader scrolls
     * themselves, as it does on «Новый сотрудник» and for the same reason: the
     * page can only scroll so far, so a short part at the foot of the sheet
     * never reaches the line the watching goes by, and the mark would spring
     * back to whatever is standing there instead.
     */
    const pressed = useRef(false);

    /**
     * Which entry the nav marks is a question about what is on screen, so the
     * browser answers it rather than arithmetic on every scroll event. The
     * window is cropped a quarter of the way down and watched from there, so the
     * part marked is the first one still reaching past that line.
     *
     * The window and not the card: the sheet scrolls with the page whose tab it
     * is, and has no box of its own to scroll inside. Which also means the parts
     * do not tile what is watched the way a whole page of them would — the card
     * above the sheet and the blocks beside it are parts of nothing — so with
     * none of them in view the mark is left where it was.
     */
    useEffect(() => {
        const numbers = drawnNumbers.split(',');
        const inside = new Set<number>();

        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    // The nav counts the parts that are drawn and the element
                    // says which part of the form it is: this is where the two
                    // numberings are made to agree, and the only place they meet.
                    const index = numbers.indexOf((entry.target as HTMLElement).dataset.part ?? '');

                    if (index < 0) continue;

                    if (entry.isIntersecting) inside.add(index);
                    else inside.delete(index);
                }

                // Worked out either way — the answer is wanted the moment the
                // hold ends — but a press is not overruled while it stands.
                if (pressed.current) return;

                if (inside.size > 0) setCurrent(Math.min(...inside));
            },
            { rootMargin: '-25% 0px 0px 0px' },
        );

        for (const number of numbers) {
            const node = holder.current?.querySelector<HTMLElement>(`[data-part="${number}"]`);

            if (node) observer.observe(node);
        }

        return () => observer.disconnect();
    }, [drawnNumbers]);

    /**
     * The hold ends the moment the page is scrolled by hand — a wheel, a finger,
     * or one of the keys that scroll.
     */
    useEffect(() => {
        const scrolls = new Set(['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' ']);

        const release = () => {
            pressed.current = false;
        };

        // Those keys are only scrolling while nothing is being answered with
        // them: a reader picking «Нет» out of a list with the arrows is not
        // leaving the part, and neither is one typing a space into a box while
        // the sheet is being filled in.
        const key = (event: KeyboardEvent) =>
            scrolls.has(event.key) &&
            !(event.target as HTMLElement | null)?.closest('input, textarea, [contenteditable], [role="combobox"], [role="listbox"]') &&
            release();

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
     * Pressing an entry of the nav. The mark moves at once rather than waiting
     * for the scrolling to arrive, so the press answers — and stays answered,
     * because the page below may not be able to put that part under the line.
     */
    const show = (index: number) => {
        mark(index);
        holder.current?.querySelector<HTMLElement>(`[data-part="${drawn[index].number}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /**
     * Marking a part without going anywhere — what pressing the part itself
     * does, since somebody touching a part is already in it.
     *
     * The mark is held against the watching for the same reason a press is: the
     * part somebody chose stays chosen, rather than being overruled a moment
     * later by whichever part the scrolling happens to be nearest.
     */
    const mark = (index: number) => {
        pressed.current = true;
        setCurrent(index);
    };

    // A set that has just lost a part could leave the mark past the end of what
    // is left, for the one paint before the watching answers again.
    const marked = Math.min(current, drawn.length - 1);

    /** Drawing one planned part, wherever in the rows it ends up standing. */
    const draw = (part: SheetPartPlan) => {
        const index = drawn.indexOf(part);

        return (
            <SheetPart
                key={part.number}
                number={part.number}
                title={part.title}
                // The one control a part carries while it is read answers for the
                // whole of it, and while the part is being filled in that answer
                // is one of the fields: two of them side by side would be two
                // ways of saying the same thing.
                action={editing ? undefined : part.action}
                active={index === marked}
                onOpen={() => mark(index)}
            >
                {editing && part.edit ? part.edit : part.body}
            </SheetPart>
        );
    };

    /**
     * How far the sheet has got, and what it is still short of.
     *
     * Saved while the sheet is read: the server counted it over the lines this
     * viewer may read, and that count is a statement about the card. Worked out
     * here while it is being answered, off the same lines and the same rule, so
     * that filling a box in takes its line out of the list at once rather than
     * at the next save — and so that a reload without saving puts the line back.
     */
    const counted = sheet.lines ?? [];
    const unanswered = counted.filter((line) => !isAnswered(line.key, form.data));
    const missing = editing ? unanswered.map((line) => line.title) : sheet.missing;
    // A hundred where there is nothing to count, as PersonnelSheet has it: a
    // reader who may read none of these lines is told nothing rather than
    // nought, which would read as a card nobody has filled in.
    const filled = editing ? (counted.length === 0 ? 100 : Math.round(((counted.length - unanswered.length) / counted.length) * 100)) : sheet.filled;

    // And the same reading for the block of documents beside it: how many of
    // the papers the company insists on have been accepted. Null for a viewer
    // who may not read the documents at all, and for a company that insists on
    // nothing.
    const accepted = documents === null ? null : requiredAccepted(documents.items);

    /**
     * And what the sheet is short of on paper, which is the other errand the
     * office has: a line can be answered and still be waiting for the document
     * behind it. Read out of the boxes while the sheet is open and off the card
     * while it is not, as the two above are — and named out of the lines the
     * server counted for this reader, so a line that is not theirs to see
     * cannot be named here either.
     */
    const unattached = editing
        ? papersWanted(
              form.data,
              {
                  degree: details?.degree_document != null,
                  award: details?.award_document != null,
                  military: details?.military_document != null,
              },
              (line) => counted.find((counted) => counted.key === line)?.title,
          )
        : sheet.papers;

    /** Whether there is anything on this sheet for this reader to answer at all. */
    const answerable = SHEET_FIELDS.some(answers);

    return (
        <div className={paneGrid}>
            <div className={pane}>
                {/* Whose sheet this is and under what number, in the block the
                    rest of the page is built from rather than in a heading of
                    the document's own — the sheet is read here as part of a
                    card, and printed as a document elsewhere. */}
                <Panel
                    // What the browser is to put on paper: this block and
                    // nothing else of the application around it.
                    data-print=""
                    title="Личный листок по учёту кадров"
                    action={
                        // What can be done with the sheet, in the header of the
                        // block it belongs to — the way every other block of
                        // this card carries its own one action. Not printed: a
                        // sheet on paper has nothing to press.
                        <span className="flex flex-wrap items-center gap-4 print:hidden">
                            {editing ? (
                                <>
                                    {/* Taking back the whole of it, which is why
                                        it reads in the colour of the page: the
                                        one thing in this strip that writes
                                        anything is the one in the brand's. */}
                                    <Button
                                        type="button"
                                        variant="link"
                                        disabled={form.processing}
                                        onClick={() => answer(false)}
                                        className="text-foreground h-auto p-0 text-xs font-normal"
                                    >
                                        Отмена
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="link"
                                        disabled={form.processing}
                                        onClick={save}
                                        className="text-brand-strong h-auto gap-1.5 p-0 text-xs font-normal dark:text-[#C5E27A]"
                                    >
                                        {form.processing && <LoaderCircle className="size-3.5 animate-spin" />}
                                        Сохранить
                                    </Button>
                                </>
                            ) : (
                                <>
                                    {/* Taking a copy away is not changing anything, so
                                        the two read in the colour of the page and only
                                        «Изменить» is written in the brand's. */}
                                    <Button
                                        type="button"
                                        variant="link"
                                        onClick={() => window.print()}
                                        className="text-foreground h-auto p-0 text-xs font-normal"
                                    >
                                        Печать
                                    </Button>
                                    <Button type="button" variant="link" className="text-foreground h-auto p-0 text-xs font-normal">
                                        Скачать
                                    </Button>
                                    {/* Offered only to somebody who may answer
                                        something on it: a sheet that opened into
                                        eleven parts of plain text would be a
                                        promise the save could not keep. */}
                                    {answerable && (
                                        <Button
                                            type="button"
                                            variant="link"
                                            onClick={() => answer(true)}
                                            className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]"
                                        >
                                            Изменить
                                        </Button>
                                    )}
                                </>
                            )}
                        </span>
                    }
                    // How much of the sheet is filled in: the block's own line
                    // about itself, between its header and the sheet.
                    //
                    // Not printed: how far the filling in has got is something
                    // the office keeps, not something the sheet says of itself.
                    infoClassName="print:hidden"
                    info={
                        <>
                            <span className="text-muted-foreground text-[13px]">Заполнение</span>

                            {/* The same bar the KPI block reads its indicators
                                with, down to the figure beside it: two places on
                                one card showing how far something has got have
                                no business looking like two different things. */}
                            <Progress value={filled} aria-label={`Заполнение: ${filled}%`} className="h-1.5 w-[140px] shrink-0" />

                            <span className="text-sm font-medium tabular-nums">{filled}%</span>
                        </>
                    }
                >
                    {/* Only on paper: the block's own name says what the document
                        is, and a sheet off somebody's card has to say whose it
                        is as well — on screen the card around it already does. */}
                    <p className="hidden text-sm font-semibold print:block">
                        {[employee.surname, employee.name, employee.patronymic].filter(Boolean).join(' ')}
                    </p>

                    {/* The sheet laid out the way «Новый сотрудник» lays out
                        the parts of its own form: what the paper is made of down
                        the left, and the parts themselves beside it, so that a
                        sheet of eleven parts can be read from the one somebody
                        came for. The band above keeps the whole width — how far
                        the sheet has got is said of the sheet, not of any one
                        part of it — and the nav and the parts sit under it. */}
                    {/* While the sheet is being answered its boxes stand as tall
                        on a phone as the boxes of «Новый сотрудник» do — 44pt is
                        what a finger wants, and the two pages ask the same
                        questions in the same controls. */}
                    <div
                        ref={holder}
                        className={cn('min-w-0', editing && 'max-md:[&_[role=combobox]]:h-11 max-md:[&_input:not([type=checkbox])]:h-11')}
                    >
                        <Sections
                            nav={
                                // The whole column follows the page, not the nav
                                // alone: a sticky nav with something under it
                                // slides over that something as soon as it
                                // sticks. So the two travel together, and the
                                // nav inside stands where it is put — and the
                                // column scrolls within itself where it is
                                // taller than the window, or its last entries
                                // would be unreachable.
                                // Not printed: a way about the page is of no use
                                // on paper, and neither is a list of what the
                                // office still has to ask for.
                                <div className="flex flex-col gap-5 lg:sticky lg:top-6 lg:max-h-[calc(100dvh-4.5rem)] lg:overflow-y-auto lg:pb-2 print:hidden">
                                    {/* Numbered from the paper and not from the
                                        list: a part this reader may not open
                                        leaves a gap in the sequence, and a column
                                        counting its own entries would say «03»
                                        beside what the sheet itself calls «09». */}
                                    <SectionNav
                                        className="lg:static"
                                        label="Части листка"
                                        items={drawn.map(({ number, title }) => ({ number, title }))}
                                        current={marked}
                                        onSelect={show}
                                    />

                                    {/* Under the parts rather than off to the
                                        side: what is still wanted is a list of
                                        the sheet's own lines, so it belongs in
                                        the column that says what the sheet is
                                        made of — and it goes altogether once
                                        there is nothing left to want. A heading
                                        over an empty list is a block saying it
                                        has nothing to say. */}
                                    {missing.length > 0 && (
                                        <section className="flex flex-col gap-2">
                                            <div className="flex items-center justify-between gap-3">
                                                <h3 className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-xs font-normal">
                                                    Не заполнено
                                                    <span className="tabular-nums">{missing.length}</span>
                                                </h3>

                                                {/* Asking the person to finish their own sheet, which is
                                                the one thing to be done about a list of lines nobody
                                                has answered — and the reason the list is here rather
                                                than only in the parts themselves. */}
                                                <Button
                                                    variant="link"
                                                    className="text-brand-strong h-auto shrink-0 p-0 text-xs font-normal dark:text-[#C5E27A]"
                                                >
                                                    Запросить
                                                </Button>
                                            </div>

                                            <ul className="flex flex-col">
                                                {missing.map((what) => (
                                                    // In the colour the nav above writes an entry
                                                    // nobody is on, since this is the same column
                                                    // saying the same kind of thing: the names of
                                                    // parts of the sheet, read and not pressed.
                                                    <li key={what} className="text-muted-foreground flex items-center gap-2 py-1.5 text-[13px]">
                                                        <span className="size-1.5 shrink-0 rounded-full bg-[#F5A524]" />
                                                        <span className="min-w-0 break-words">{what}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}

                                    {/* And what is still to be handed in, under
                                        the lines that are still to be answered:
                                        the same column, the same kind of list,
                                        and two errands that are not one — a
                                        degree can be written down and its
                                        diploma still be sitting at home. Marked
                                        with the clip the card shows its papers
                                        with everywhere else, so that the two
                                        lists are told apart by what they are
                                        short of and not by their headings. */}
                                    {unattached.length > 0 && (
                                        <section className="flex flex-col gap-2">
                                            <h3 className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-xs font-normal">
                                                Документы не приложены
                                                <span className="tabular-nums">{unattached.length}</span>
                                            </h3>

                                            <ul className="flex flex-col">
                                                {unattached.map((what, index) => (
                                                    // By where it stands as well
                                                    // as by its name: a card may
                                                    // name two places of study
                                                    // with one name, and two
                                                    // papers are missing and not
                                                    // one.
                                                    <li
                                                        key={`${what}-${index}`}
                                                        className="text-muted-foreground flex items-center gap-2 py-1.5 text-[13px]"
                                                    >
                                                        <FileText className="size-3.5 shrink-0" />
                                                        <span className="min-w-0 break-words">{what}</span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    )}
                                </div>
                            }
                            sections={drawn.map(draw)}
                        />
                    </div>

                    {/* And the same two again at the foot of the sheet, as
                        ordinary buttons this time: eleven parts are a long way
                        down, and somebody who has just answered the last of them
                        should not have to go back up to the header strip to say
                        so. «Новый сотрудник» ends its own form this way, and
                        which of the two is quiet is settled there — «Отмена» is
                        outlined and «Сохранить» is the filled one. Not printed,
                        along with the rest of what there is to press. */}
                    {editing && (
                        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center md:justify-end print:hidden">
                            {/* Said in words and not only in the marked parts: a
                                button pressed with nothing happening afterwards
                                reads as a button that is broken, and a whole
                                sheet can be turned down over a line in a part
                                nobody touched. */}
                            {form.hasErrors && (
                                <p className="text-destructive min-w-0 flex-1 text-[13px]">Листок не сохранён: части, где нужен ответ, отмечены.</p>
                            )}

                            <div className="flex flex-col-reverse gap-2 md:flex-row">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="max-md:h-11"
                                    disabled={form.processing}
                                    onClick={() => answer(false)}
                                >
                                    Отмена
                                </Button>
                                <Button type="button" className="max-md:h-11" disabled={form.processing} onClick={save}>
                                    {form.processing && <LoaderCircle className="animate-spin" />}
                                    Сохранить
                                </Button>
                            </div>
                        </div>
                    )}
                </Panel>
            </div>

            <aside className={sidePane}>
                {/* The papers the company asks everybody for, with this person's
                    answers against them — and, at the foot of the same block,
                    the ones the sheet itself carries: the diplomas, and the one
                    document each of the three lines of the card that keeps one
                    holds. Two different things that are both documents on this
                    card, which is why they are read in one place and kept
                    apart inside it.

                    A viewer who may not read the documents at all is shown the
                    sheet's papers alone, under the same heading: the block is
                    about what is on file either way. */}
                <Panel
                    title="Документы (Чек-лист)"
                    // Putting a paper on file from the head of the block, for
                    // whoever came with one in hand rather than to read the
                    // list: the header's own action, so it is where every other
                    // block of the card keeps the thing it is for.
                    action={
                        // Named in a hint rather than beside the sign: a word
                        // in the header would stand against the title and read
                        // as part of it, and a plus at the head of a list of
                        // papers is only half an answer on its own.
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button variant="link" aria-label="Добавить документ" className="h-auto p-0 [&_svg]:size-4">
                                    <Plus />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>Добавить документ</TooltipContent>
                        </Tooltip>
                    }
                    // How far the papers the company insists on have got, read
                    // the way the sheet beside it reads its own filling in.
                    // Nothing is said where nothing is insisted on.
                    info={
                        accepted !== null && (
                            <>
                                <span className="text-muted-foreground text-[13px]">Принято</span>

                                <Progress
                                    value={accepted.percent}
                                    aria-label={`Принято ${accepted.done} из ${accepted.total} обязательных документов`}
                                    className="h-1.5 w-[110px] shrink-0"
                                />

                                <span className="text-sm font-medium tabular-nums">{accepted.percent}%</span>

                                {/* Asking the person for the papers still
                                    missing, which is the one thing to be done
                                    about a bar short of full — and so it goes
                                    once the bar is full and there is nothing
                                    left to ask for. */}
                                {accepted.done < accepted.total && (
                                    <Button
                                        variant="link"
                                        className="text-brand-strong ml-auto h-auto shrink-0 p-0 text-xs font-normal dark:text-[#C5E27A]"
                                    >
                                        Запросить
                                    </Button>
                                )}
                            </>
                        )
                    }
                >
                    {documents === null && papers.length === 0 ? (
                        <p className={emptyNote}>Документы не приложены</p>
                    ) : (
                        <EmployeeDocumentsList
                            employee={employee.id}
                            documents={documents ?? { canEdit: false, canReview: false, items: [] }}
                            papers={papers}
                        />
                    )}
                </Panel>
            </aside>
        </div>
    );
}

/**
 * The kinds of thing the plan works on, each with the colour it is known by —
 * the badge in the plan, the marks in its own block of skills. Telling a hard
 * skill from a soft one is the colour's whole job here, so it says the same
 * thing in both places.
 */
const SKILL_KINDS = {
    hard: {
        title: 'Жёсткие навыки (Hard skills)',
        label: 'Hard skill',
        tone: 'teal' as StatusTone,
        fill: 'bg-[#0D9488] dark:bg-[#2DD4BF]',
        outline: 'border-[#0D9488] dark:border-[#2DD4BF]',
    },
    soft: {
        title: 'Мягкие навыки (Soft skills)',
        label: 'Soft skill',
        tone: 'violet' as StatusTone,
        fill: 'bg-[#7C3AED] dark:bg-[#A78BFA]',
        outline: 'border-[#7C3AED] dark:border-[#A78BFA]',
    },
    // A language is neither, and has no block of its own to be read in.
    language: {
        title: 'Языки',
        label: 'Язык',
        tone: 'neutral' as StatusTone,
        fill: 'bg-muted-foreground',
        outline: 'border-muted-foreground',
    },
};

type SkillKind = keyof typeof SKILL_KINDS;

/**
 * A bar in the colour of the state written beside it, so a row says one
 * thing rather than two: green where it is done, blue while it is going on,
 * and grey where it has not started.
 */
const BAR_TONES: Partial<Record<StatusTone, string>> = {
    success: 'bg-brand/20 [&>*]:bg-brand',
    info: 'bg-[#3B82F6]/20 [&>*]:bg-[#3B82F6] dark:bg-[#93B4FA]/20 dark:[&>*]:bg-[#93B4FA]',
    neutral: 'bg-muted [&>*]:bg-muted-foreground/40',
};

/**
 * The plan and what has come of it. The design's goals again: nothing keeps
 * any of this yet, so the block can be read and agreed on before it does.
 */
const PLAN = {
    year: 2026,
    mentor: 'Каримов Фарход',
    meeting: '2026-11-05',
    done: 47,
    goals: [
        {
            title: 'Освоить Power BI, сертификат PL-300',
            kind: 'hard' as SkillKind,
            due: '2026-11-30',
            done: 70,
            status: 'В процессе',
            tone: 'info' as StatusTone,
        },
        {
            title: 'SQL: оконные функции и оптимизация запросов',
            kind: 'hard' as SkillKind,
            due: '2026-10-15',
            done: 100,
            status: 'Выполнено',
            tone: 'success' as StatusTone,
        },
        {
            title: 'Презентация результатов для руководства',
            kind: 'soft' as SkillKind,
            due: '2026-12-20',
            done: 40,
            status: 'В процессе',
            tone: 'info' as StatusTone,
        },
        {
            title: 'Английский язык: B2 → C1',
            kind: 'language' as SkillKind,
            due: '2027-06-30',
            done: 25,
            status: 'В процессе',
            tone: 'info' as StatusTone,
        },
        { title: 'Менторство стажёра', kind: 'soft' as SkillKind, due: '2027-01-31', done: 0, status: 'Не начато', tone: 'neutral' as StatusTone },
    ],
};

/**
 * «План индивидуального развития»: what the year is working towards, goal by
 * goal, and how far each of them has got.
 */
function PlanSection() {
    return (
        <Panel
            title="План индивидуального развития (ПИР)"
            action={
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span className="text-muted-foreground text-xs">
                        Наставник: <span className="text-foreground font-medium">{PLAN.mentor}</span>
                    </span>
                    <span className="text-muted-foreground text-xs">
                        Следующая встреча: <span className="text-foreground font-medium">{formatDate(PLAN.meeting)}</span>
                    </span>
                    {/* The same green word the other blocks put at the end of
                        their header strip. */}
                    <Button variant="link" className="text-brand-strong h-auto gap-1 p-0 text-xs font-normal dark:text-[#C5E27A]">
                        <Plus className="size-3.5" />
                        Цель
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-4 max-md:py-4">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <span className="text-muted-foreground text-xs">Общий прогресс · {PLAN.year}</span>
                    <Progress value={PLAN.done} aria-label={`Общий прогресс: ${PLAN.done}%`} className="min-w-32 flex-1" />
                    <span className="w-10 shrink-0 text-right text-sm font-medium tabular-nums">{PLAN.done}%</span>
                </div>

                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="text-muted-foreground px-0 text-xs font-normal">Цель</TableHead>
                            <TableHead className="text-muted-foreground w-28 text-xs font-normal">Тип</TableHead>
                            <TableHead className="text-muted-foreground w-32 text-xs font-normal">Срок</TableHead>
                            <TableHead className="text-muted-foreground w-64 text-xs font-normal">Прогресс</TableHead>
                            <TableHead className="text-muted-foreground w-32 text-xs font-normal">Статус</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {PLAN.goals.map((goal) => (
                            <TableRow key={goal.title} className="hover:bg-transparent">
                                <TableCell className="px-0 py-3 font-medium break-words whitespace-normal">{goal.title}</TableCell>
                                <TableCell className="py-3">
                                    <StatusBadge tone={SKILL_KINDS[goal.kind].tone}>{SKILL_KINDS[goal.kind].label}</StatusBadge>
                                </TableCell>
                                <TableCell className="text-muted-foreground py-3 whitespace-nowrap tabular-nums">{formatDate(goal.due)}</TableCell>
                                <TableCell className="py-3">
                                    <div className="flex items-center gap-3">
                                        <Progress
                                            value={goal.done}
                                            aria-label={`Прогресс: ${goal.done}%`}
                                            // The colour of the status at the end of the row.
                                            className={cn('min-w-16 flex-1', BAR_TONES[goal.tone])}
                                        />
                                        <span className="w-10 shrink-0 text-right text-sm font-medium tabular-nums">{goal.done}%</span>
                                    </div>
                                </TableCell>
                                <TableCell className="py-3">
                                    <StatusBadge tone={goal.tone}>{goal.status}</StatusBadge>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Panel>
    );
}

/** What a skill is worth out of five, and what the plan asks it to become. */
interface Skill {
    name: string;
    level: number;
    goal?: number;
}

const SKILL_SCALE = 5;

const HARD_SKILLS: Skill[] = [
    { name: 'SQL', level: 4, goal: 5 },
    { name: 'Python', level: 3, goal: 4 },
    { name: 'Power BI', level: 3, goal: 4 },
    { name: 'Excel / Google Sheets', level: 5 },
    { name: 'Статистика', level: 4 },
    { name: 'ClickHouse', level: 2, goal: 3 },
];

const SOFT_SKILLS: Skill[] = [
    { name: 'Коммуникация', level: 4 },
    { name: 'Командная работа', level: 5 },
    { name: 'Тайм-менеджмент', level: 3, goal: 4 },
    { name: 'Критическое мышление', level: 4 },
    { name: 'Лидерство', level: 2, goal: 3 },
    { name: 'Адаптивность', level: 4 },
];

/** When the lot was last looked at, by everybody who works with them. */
const SKILLS_ASSESSED = '2026-10-10';

/**
 * A level out of five, as five marks: filled to where the skill stands, and
 * outlined from there to where the plan asks it to be. The figures beside it
 * say the same thing, so neither half is read by its colour alone.
 */
function SkillLevel({ level, goal, kind }: Skill & { kind: SkillKind }) {
    return (
        <span className="flex shrink-0 items-center gap-1" aria-hidden="true">
            {Array.from({ length: SKILL_SCALE }, (_, i) => (
                <span
                    key={i}
                    className={cn(
                        'h-1.5 w-5 rounded-full',
                        i < level ? SKILL_KINDS[kind].fill : i < (goal ?? 0) ? cn('border border-dashed', SKILL_KINDS[kind].outline) : 'bg-muted',
                    )}
                />
            ))}
        </span>
    );
}

/** One of the two blocks of skills: the same list, read the same way. */
function SkillsSection({ kind, skills }: { kind: SkillKind; skills: Skill[] }) {
    return (
        <Panel
            title={SKILL_KINDS[kind].title}
            action={<span className="text-muted-foreground text-xs">Оценка 360° · {formatDate(SKILLS_ASSESSED)}</span>}
        >
            <div className="flex flex-col gap-4 max-md:py-4">
                <ul className="flex flex-col">
                    {skills.map((skill) => (
                        <li key={skill.name} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b py-2.5 last:border-0">
                            <span className="min-w-0 text-sm font-medium break-words">{skill.name}</span>
                            <span className="flex items-center gap-3">
                                <SkillLevel {...skill} kind={kind} />
                                <span className="w-16 shrink-0 text-right text-sm tabular-nums">
                                    <span className="font-medium">{skill.level}</span>
                                    <span className="text-muted-foreground">/{SKILL_SCALE}</span>
                                    {skill.goal !== undefined && <span className="text-muted-foreground"> → {skill.goal}</span>}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>

                <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
                    <span className="flex items-center gap-1.5">
                        <span className={cn('h-1.5 w-5 shrink-0 rounded-full', SKILL_KINDS[kind].fill)} />
                        Текущий уровень
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className={cn('h-1.5 w-5 shrink-0 rounded-full border border-dashed', SKILL_KINDS[kind].outline)} />
                        Цель по ПИР
                    </span>
                </div>
            </div>
        </Panel>
    );
}

/**
 * The four ways a day is counted, each with the colour the card gives it: the
 * tint a day of the month is filled with, and the solid dot that names it in
 * the lines above and below the month.
 */
const DAY_KINDS = {
    present: { title: 'Присутствовал', tone: 'success' as StatusTone, dot: 'bg-brand' },
    remote: { title: 'Удалённо', tone: 'info' as StatusTone, dot: 'bg-[#3B82F6]' },
    late: { title: 'Опоздание', tone: 'warning' as StatusTone, dot: 'bg-[#F5A524]' },
    sick: { title: 'Больничный', tone: 'danger' as StatusTone, dot: 'bg-[#EF4444]' },
};

type DayKind = keyof typeof DAY_KINDS;

/**
 * The month the block shows and what each of its days came to. Nothing records
 * any of this yet: the days are the design's, so the block can be read and
 * agreed on before there is anything behind it.
 */
const ATTENDANCE = {
    year: 2026,
    /** Nought is January, as a date reads it. */
    month: 9,
    today: 19,
    days: {
        1: 'present',
        2: 'present',
        5: 'present',
        6: 'late',
        7: 'present',
        8: 'remote',
        9: 'remote',
        12: 'sick',
        13: 'present',
        14: 'late',
        15: 'remote',
        16: 'remote',
        19: 'present',
    } as Record<number, DayKind>,
    totals: [
        { title: 'Явка', value: '92%', dot: DAY_KINDS.present.dot },
        { title: 'Опоздания', value: '2', dot: DAY_KINDS.late.dot },
        { title: 'Удалённо', value: '3 дн.', dot: DAY_KINDS.remote.dot },
        { title: 'Больничный', value: '1 дн.', dot: DAY_KINDS.sick.dot },
    ],
};

const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

/**
 * «Посещаемость»: what the month came to, and the month itself.
 *
 * A day is read by the figure on it and by the word the legend gives its
 * colour, never by the colour alone — and the hover says the same word again.
 */
function AttendanceSection() {
    const first = new Date(ATTENDANCE.year, ATTENDANCE.month, 1);
    // The week starts on Monday here, and a date counts from Sunday.
    const lead = (first.getDay() + 6) % 7;
    const length = new Date(ATTENDANCE.year, ATTENDANCE.month + 1, 0).getDate();
    const cells = [...Array(lead).fill(null), ...Array.from({ length }, (_, i) => i + 1)];

    return (
        <Panel
            // The month reads as part of a sentence here, not as a heading of
            // its own, so it is written the way it is spoken: «октябрь 2026».
            title={`Посещаемость · ${monthNames[ATTENDANCE.month].toLocaleLowerCase('ru')} ${ATTENDANCE.year}`}
            action={
                <Button variant="link" className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]">
                    Табель
                </Button>
            }
        >
            <div className="flex flex-col gap-4 max-md:py-4">
                <div className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                    {ATTENDANCE.totals.map((total) => (
                        <div key={total.title} className="flex min-w-0 flex-col gap-1">
                            <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                <span className={cn('size-2 shrink-0 rounded-full', total.dot)} />
                                {total.title}
                            </span>
                            <span className="text-xl leading-none font-semibold tabular-nums">{total.value}</span>
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-7 gap-1">
                    {WEEKDAYS.map((day) => (
                        <span key={day} className="text-muted-foreground pb-1 text-center text-xs">
                            {day}
                        </span>
                    ))}

                    {cells.map((day, index) => {
                        if (day === null) {
                            return <span key={`lead-${index}`} />;
                        }

                        const kind = ATTENDANCE.days[day];
                        const rest = index % 7 >= 5;

                        return (
                            <span
                                key={day}
                                title={kind ? DAY_KINDS[kind].title : undefined}
                                className={cn(
                                    'flex h-9 items-center justify-center rounded-md text-sm tabular-nums',
                                    kind ? statusTones[DAY_KINDS[kind].tone] : rest ? 'text-muted-foreground/60' : 'text-muted-foreground',
                                    // The day it is, outlined rather than filled: it is
                                    // not another kind of day, it is this one.
                                    day === ATTENDANCE.today && 'ring-brand ring-2',
                                )}
                            >
                                {day}
                            </span>
                        );
                    })}
                </div>

                <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
                    {Object.entries(DAY_KINDS).map(([key, kind]) => (
                        <span key={key} className="flex items-center gap-1.5">
                            <span className={cn('size-2 shrink-0 rounded-full', kind.dot)} />
                            {kind.title}
                        </span>
                    ))}
                    <span className="flex items-center gap-1.5">
                        <span className="ring-brand size-2 shrink-0 rounded-full ring-2" />
                        Сегодня
                    </span>
                </div>
            </div>
        </Panel>
    );
}

/**
 * The year's leave: what is left of it, what it was spent on and what is
 * booked. The design's numbers again — nothing keeps any of this yet.
 */
const LEAVE = {
    year: 2026,
    total: 28,
    parts: [
        { key: 'used', title: 'Использовано', days: 14, bar: 'bg-muted-foreground/40', dot: 'bg-muted-foreground/40' },
        { key: 'planned', title: 'Запланировано', days: 7, bar: 'bg-[#3B82F6]', dot: 'bg-[#3B82F6]' },
        { key: 'available', title: 'Доступно', days: 7, bar: 'bg-brand', dot: 'bg-brand' },
    ],
    records: [
        { span: '21–27 дек. 2026', kind: 'Ежегодный', days: '7 дн.', status: 'Согласован', tone: 'success' as StatusTone },
        { span: '13–26 июл. 2026', kind: 'Ежегодный', days: '14 дн.', status: 'Использован', tone: 'neutral' as StatusTone },
    ],
};

/** «Отпуск»: how much of the year is left, and what has been asked for. */
function LeaveSection() {
    const available = LEAVE.parts.find((part) => part.key === 'available')?.days ?? 0;

    return (
        <Panel
            title={`Отпуск · ${LEAVE.year}`}
            action={
                <Button variant="link" className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]">
                    История
                </Button>
            }
        >
            <div className="flex flex-col gap-4 max-md:py-4">
                <p className="flex flex-wrap items-baseline gap-2">
                    <span className="text-brand-strong text-4xl leading-none font-semibold tabular-nums dark:text-[#C5E27A]">{available}</span>
                    <span className="text-muted-foreground text-sm">дней доступно из {LEAVE.total}</span>
                </p>

                <div className="flex flex-col gap-2">
                    {/* The year as one bar, its parts a hair apart so they are
                        three lengths rather than one with stripes on it. */}
                    <div className="flex h-2 gap-0.5">
                        {LEAVE.parts.map((part) => (
                            <span key={part.key} className={cn('rounded-full', part.bar)} style={{ width: `${(part.days / LEAVE.total) * 100}%` }} />
                        ))}
                    </div>

                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs">
                        {LEAVE.parts.map((part) => (
                            <span key={part.key} className="flex items-center gap-1.5">
                                <span className={cn('size-2 shrink-0 rounded-full', part.dot)} />
                                {part.title} {part.days}
                            </span>
                        ))}
                    </div>
                </div>

                <div className="flex flex-col gap-2">
                    {LEAVE.records.map((record) => (
                        <div
                            key={record.span}
                            className="bg-muted/40 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-lg px-3 py-2.5"
                        >
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-sm font-medium">{record.span}</span>
                                <span className="text-muted-foreground text-xs">
                                    {record.kind} · {record.days}
                                </span>
                            </span>
                            <StatusBadge tone={record.tone}>{record.status}</StatusBadge>
                        </div>
                    ))}
                </div>

                <Button variant="outline" className="w-full">
                    <Plus />
                    Запросить отпуск
                </Button>
            </div>
        </Panel>
    );
}

/**
 * The window behind «Изменить» on «Основное».
 *
 * The job is the answer the block is built around: a department follows from
 * the job and whoever leads it follows from the department, so those two lines
 * are shown filling themselves in rather than asked for a second time. The
 * probationary period is shown the same way, until there is somewhere to keep
 * it.
 */
function MainDialog({
    employee,
    details,
    options,
    assigned,
    roleLocked,
    positionsLocked,
    picksRole,
    onClose,
}: {
    employee: Employee;
    details: ProfilePrivate | null;
    options: EditOptions;
    assigned: Assigned;
    /** Why the role on this card is nobody's to change, or null when it is somebody's. */
    roleLocked: string | null;
    /** And why the jobs on it are not, on the same terms. */
    positionsLocked: string | null;
    /**
     * Which way round the window asks the pair: the system administrator picks
     * the role and the jobs narrow to the ones it may hold, while personnel pick
     * the job and the role is read off it.
     */
    picksRole: boolean;
    onClose: () => void;
}) {
    const canEdit = useCanEdit();
    // The jobs are one answer and two lines of the card, so they are offered
    // only where both are this viewer's to change: half a placement would file
    // somebody in a department the save is not allowed to write.
    const places = canEdit('positions') && canEdit('departments');
    // The role is a line like the others, except that one card is nobody's to
    // reassign — the system administrator's own — and there it is shown as it
    // stands rather than offered: the box is closed and the reason stands
    // beside it, instead of being found out by pressing «Сохранить».
    const assigns = canEdit('role') && roleLocked === null;
    // And the jobs on a card that belongs to the system administrator alone are
    // shown the same way: the list is closed and says why, and the window leaves
    // that line out of the save rather than letting a refusal announce the lock.
    const rearranges = places && positionsLocked === null;

    /**
     * Whether the jobs in the window are no longer the ones the card holds.
     *
     * Read as a set and not as a list, because the order a window happens to
     * put them in is not a change to anybody's job.
     */
    const moved = (chosen: Placement[]) => {
        const ids = (placements: Placement[]) =>
            placements
                .map((placement) => placement.position_id)
                .sort()
                .join(',');

        return ids(chosen) !== ids(assigned.placements);
    };

    const form = useClearingForm({
        // By name, which is what the box submits; an empty box is a card with
        // no role on it yet, and the server reads the job for one.
        role: assigned.role ?? '',
        placements: assigned.placements,
        employee_number: employee.employee_number ?? '',
        employment_type: employee.employment_type ?? '',
        hired_at: details?.hired_at ?? '',
    });

    // The card is edited in several windows and they share its lines out, so a
    // line this one did not show is one it does not send — and the server, in
    // turn, leaves alone whatever it was not sent.
    const hire = canEdit('hired_at') && details !== null;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            // Where the jobs decide the role, the closed box shows what they
            // decided and that is what travels: the value left in the form
            // behind it was never anybody's answer here. But only once the jobs
            // have actually moved — a card written before the two were made to
            // agree must not have its role quietly rewritten by somebody who
            // came to this window to fix a telephone number.
            ...(assigns && (picksRole || moved(data.placements)) ? { role: picksRole ? data.role : impliedRole } : {}),
            ...(rearranges ? { placements: data.placements } : {}),
            ...(canEdit('employee_number') ? { employee_number: data.employee_number } : {}),
            ...(canEdit('employment_type') ? { employment_type: data.employment_type } : {}),
            ...(hire ? { hired_at: data.hired_at } : {}),
        }));
        form.put(route('employees.personal', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    // What the jobs say about the two lines under them: a department per job,
    // each named once however many jobs are held in it, and whoever leads those
    // — never this person, since a card does not answer to itself.
    const posts = new Map(options.positions.map((post) => [post.id, post]));
    const units = new Map(options.units.map((unit) => [unit.id, unit]));
    const held = form.data.placements.map((placement) => placement.position_id).filter((id): id is number => id !== null);
    const filed = [...new Set(held.map((id) => posts.get(id)?.department_id).filter((id): id is number => typeof id === 'number'))]
        .map((id) => units.get(id))
        .filter((unit): unit is Unit => unit !== undefined);
    const heads = [
        ...new Map(
            filed
                .flatMap((unit) => unit.heads)
                .filter((head) => head.id !== employee.id)
                .map((head) => [head.id, head]),
        ).values(),
    ];

    // A job that was never chosen is reported on its own line ("placements.0.position_id").
    const placementError = form.errors.placements ?? Object.entries(form.errors).find(([key]) => key.startsWith('placements.'))?.[1];

    /**
     * The role the jobs imply, shown in place of the answer where the answer is
     * not this viewer's to give. A card that is locked is a different matter
     * altogether: there the box shows the role the card actually holds, because
     * a closed field reading «Специалист» over «HR-специалист» would be telling
     * the reader something untrue about somebody.
     */
    const impliedRole = roleFromPositions(held, options.positions);

    /**
     * The jobs on offer. Where the role was picked they narrow to the ones it
     * may hold, and whatever the card already carries stays on the list besides,
     * even after the role has changed under it: a job the new role may not hold
     * is what the server is about to refuse, and it has to be in view for the
     * refusal to be about anything. Where the job decides the role, nothing is
     * narrowed — the job is the answer.
     */
    const offered = options.positions
        .filter((post) => post.department_id !== null)
        .filter((post) => !picksRole || mayHold(form.data.role, post) || held.includes(post.id));

    /**
     * One of the two short lists, as its box offers it: what is on the list,
     * whatever the card already says — an entry since renamed or deleted is
     * still the answer on file — and an empty line, since neither is required.
     */
    const named = (list: string[], value: string) => [
        { value: '', label: 'Не выбрано' },
        ...[...new Set([...list, ...(value === '' ? [] : [value])])]
            .sort((a, b) => a.localeCompare(b, 'ru'))
            .map((name) => ({ value: name, label: name })),
    ];

    /**
     * The roles on offer: the ones the server says this viewer may hand out,
     * and the one the card already holds where that is not among them.
     *
     * The second half is what keeps a locked card readable. The card nobody may
     * reassign is the system administrator's own, and «Системный администратор»
     * is a role nobody is handed — so the list it arrives with does not name it,
     * and a box offered only that list would say «Не выбрано» about somebody who
     * plainly holds a role.
     */
    const roles = [
        ...options.roles.map((role) => ({ value: role.name, label: role.title })),
        ...(assigned.role !== null && !options.roles.some((role) => role.name === assigned.role)
            ? [{ value: assigned.role, label: employee.role ?? assigned.role }]
            : []),
    ];

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-xl">
                {/* noValidate: the server's rules are the real ones, and they say
                    what is wrong in words rather than in a browser bubble. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Основное</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                        {/* What they may do in the system, as against the job they
                            do in the company — the pair «Новый сотрудник» asks for
                            together, and the pair this window opens on. */}
                        {canEdit('role') && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor="main-role" required>
                                    Роль
                                </Label>
                                <SearchableSelect
                                    id="main-role"
                                    options={roles}
                                    value={picksRole || roleLocked !== null ? form.data.role : impliedRole}
                                    onChange={(value) => form.setData('role', value)}
                                    invalid={!!form.errors.role}
                                    disabled={roleLocked !== null || !picksRole}
                                />
                                {roleLocked !== null && <p className="text-muted-foreground text-sm">{roleLocked}</p>}
                                <InputError message={form.errors.role} />
                            </div>
                        )}

                        {places && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor="main-positions">Должность</Label>
                                <MultiSelect
                                    id="main-positions"
                                    options={offered.map((post) => ({
                                        value: post.id,
                                        label: post.display_name,
                                        // As «Новый сотрудник» offers them, and for its reason:
                                        // three letters in brackets say nothing by themselves, so
                                        // the department stands under the job in full — but only
                                        // where it says something the brackets do not, or a unit
                                        // with no short form would print its name twice over.
                                        hint: post.unit && post.unit.full_name !== post.unit.name ? post.unit.full_name : undefined,
                                    }))}
                                    value={held}
                                    onChange={(chosen) =>
                                        form.setData(
                                            'placements',
                                            // A job already held keeps the duties picked out of it.
                                            chosen.map(
                                                (id) =>
                                                    form.data.placements.find((placement) => placement.position_id === id) ?? {
                                                        position_id: id,
                                                        duty_ids: [],
                                                    },
                                            ),
                                        )
                                    }
                                    disabled={positionsLocked !== null}
                                />
                                {positionsLocked !== null && <p className="text-muted-foreground text-sm">{positionsLocked}</p>}
                                <InputError message={placementError} />
                            </div>
                        )}

                        {/* Neither of these two is typed: the job says both, and
                            they are shown so that the answer can be checked. */}
                        <div className="grid content-start gap-2">
                            <Label htmlFor="main-unit">Отдел</Label>
                            <Input
                                id="main-unit"
                                readOnly
                                value={filed.map((unit) => unit.path).join(', ')}
                                placeholder="По должности"
                                title={filed.map((unit) => unit.full_path).join(', ')}
                                className="bg-muted/40"
                            />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="main-head">Руководитель</Label>
                            <Input
                                id="main-head"
                                readOnly
                                value={heads.map((head) => head.name).join(', ')}
                                placeholder="По отделу"
                                className="bg-muted/40"
                            />
                        </div>

                        {canEdit('employee_number') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="main-number">Номер сотрудника</Label>
                                <Input
                                    id="main-number"
                                    value={form.data.employee_number}
                                    onChange={(e) => form.setData('employee_number', e.target.value)}
                                    aria-invalid={!!form.errors.employee_number}
                                />
                                <InputError message={form.errors.employee_number} />
                                <p className="text-muted-foreground text-[13px]">Можно оставить пустым.</p>
                            </div>
                        )}

                        {canEdit('employment_type') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="main-employment-type">Тип занятости</Label>
                                <SearchableSelect
                                    id="main-employment-type"
                                    options={named(options.employment_types, form.data.employment_type)}
                                    value={form.data.employment_type}
                                    onChange={(value) => form.setData('employment_type', value)}
                                    onCreate={(value) => form.setData('employment_type', value)}
                                    placeholder="Выберите или впишите"
                                    searchPlaceholder="Тип занятости"
                                    invalid={!!form.errors.employment_type}
                                />
                                <InputError message={form.errors.employment_type} />
                            </div>
                        )}

                        {hire && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="main-hired-at">Начало работы</Label>
                                {/* Nobody starts work in the future. */}
                                <DatePicker
                                    id="main-hired-at"
                                    value={form.data.hired_at}
                                    onChange={(value) => form.setData('hired_at', value)}
                                    toDate={new Date()}
                                    invalid={!!form.errors.hired_at}
                                />
                                <InputError message={form.errors.hired_at} />
                                <p className="text-muted-foreground text-[13px]">Стаж считается от неё.</p>
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** The "Знание языков" card in a form: a level per language, each language once. */
function LanguagesDialog({ employee, options, onClose }: { employee: Employee; options: EditOptions; onClose: () => void }) {
    // A row holds the language's id, or "new:<name>" for one typed in that is not on the list yet.
    const form = useClearingForm({
        languages: (employee.languages ?? []).map((language) => ({ id: String(language.id), level: language.level })),
    });
    const [created, setCreated] = useState<string[]>([]);
    const choices = [
        ...options.languages.map((option) => ({ value: String(option.id), label: option.name })),
        ...created.map((name) => ({ value: `new:${name}`, label: name })),
    ];

    const errors = form.errors as Record<string, string | undefined>;

    const setLanguage = (index: number, patch: Partial<{ id: string; level: LanguageLevel }>) =>
        form.setData(
            'languages',
            form.data.languages.map((language, i) => (i === index ? { ...language, ...patch } : language)),
        );

    // Each language once: a new row takes the first one not picked yet.
    const unused = choices.find((choice) => !form.data.languages.some((l) => l.id === choice.value));

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        // A language typed in goes by its name; the server puts it on the list.
        form.transform((data) => ({
            languages: data.languages.map(({ id, level }) =>
                id.startsWith('new:') ? { name: id.slice(4), level } : { id: id === '' ? null : Number(id), level },
            ),
        }));
        form.put(route('employees.languages', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Знание языков</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-3">
                        {form.data.languages.length === 0 && <p className="text-muted-foreground text-sm">Не указаны</p>}

                        {form.data.languages.map((language, index) => (
                            <div key={index} className="flex items-start gap-2">
                                <div className="grid min-w-0 flex-1 gap-2 sm:grid-cols-[1fr_9.5rem]">
                                    <div className="flex flex-col gap-1">
                                        <SearchableSelect
                                            value={language.id}
                                            onChange={(value) => setLanguage(index, { id: value })}
                                            options={choices.filter(
                                                (choice) => choice.value === language.id || !form.data.languages.some((l) => l.id === choice.value),
                                            )}
                                            onCreate={(name) => {
                                                setCreated((current) => (current.includes(name) ? current : [...current, name]));
                                                setLanguage(index, { id: `new:${name}` });
                                            }}
                                            searchPlaceholder="Язык"
                                            invalid={Boolean(errors[`languages.${index}.id`] ?? errors[`languages.${index}.name`])}
                                        />
                                        <InputError message={errors[`languages.${index}.id`] ?? errors[`languages.${index}.name`]} />
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <Select
                                            value={language.level}
                                            onValueChange={(value) => setLanguage(index, { level: value as LanguageLevel })}
                                        >
                                            <SelectTrigger aria-label="Уровень">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {/* The same badge the profile shows, so the colour is picked, not guessed. */}
                                                {languageLevels.map((level) => (
                                                    <SelectItem key={level} value={level}>
                                                        <LevelBadge level={level} />
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors[`languages.${index}.level`]} />
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="text-muted-foreground shrink-0"
                                    aria-label="Убрать язык"
                                    onClick={() =>
                                        form.setData(
                                            'languages',
                                            form.data.languages.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}

                        <Button
                            type="button"
                            variant="outline"
                            className="self-start"
                            // With every language on the list taken, the new row starts empty for one to be typed in.
                            onClick={() => form.setData('languages', [...form.data.languages, { id: unused?.value ?? '', level: 'intermediate' }])}
                        >
                            <Plus />
                            Добавить язык
                        </Button>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Сохранить
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Confirms removing one record; deleting cannot be undone. */
function DeleteRecordDialog({
    title,
    description,
    onConfirm,
    onClose,
}: {
    title: string;
    description: string;
    onConfirm: () => void;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={() => {
                            setProcessing(true);
                            onConfirm();
                        }}
                    >
                        {processing && <LoaderCircle className="animate-spin" />}
                        Удалить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * A block of the profile. A title always becomes a header strip ruled off from
 * the body — with the action on the right where there is one, and the same
 * height either way, so blocks with a pencil and blocks without still read as
 * one set. A card that fills a whole tab passes no title: the tab names it.
 */
/**
 * The action of a block: the word «Изменить» in the brand colour at the end of
 * the header strip, and the same word above the card on a phone.
 *
 * It used to be a pencil on a wide screen. An icon on its own is a small target
 * with nothing to say what it opens, and the blocks that came later put the
 * word there instead — so now they all do.
 */
function EditButton({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <Button
            variant="link"
            aria-label={label}
            onClick={onClick}
            className="text-brand-strong h-auto p-0 text-xs font-normal max-md:h-10 max-md:text-[15px] max-md:font-medium max-md:active:opacity-60 dark:text-[#C5E27A]"
        >
            Изменить
        </Button>
    );
}

/** A note in place of an empty list; on a phone it is padded like a row of the card it sits in. */
const emptyNote = 'text-muted-foreground text-sm max-md:py-3.5 max-md:text-[15px]';

/**
 * An action in a block's header strip, for blocks whose action is not a pencil:
 * text in the brand colour rather than a button, so the strip keeps the height
 * it has without one.
 */
const stripAction = 'text-brand-strong flex shrink-0 items-center gap-1.5 text-[13px] font-medium hover:underline dark:text-[#C5E27A]';

/** A record in a list card: rows ruled off from each other, flush with the card from `md` up. */
const recordRow = 'border-t py-3 first:border-t-0 md:first:pt-0 md:last:pb-0';

/**
 * Fields in newspaper columns: they read top to bottom, up to three across.
 * Narrow cards in the sidebar pass `columns={1}`, where three would be
 * unreadable.
 *
 * A multi-column layout has no row gap, so the spacing lives on each field.
 * Columns end at different heights, so the gap below stays on the last one
 * too — trimming it would leave the card lopsided.
 */
/**
 * Which of those lines the viewer may change. Asked apart from reading, because
 * they are not the same question: plenty of people should read a passport and
 * nobody but HR should ever retype one.
 */
const EditableFields = createContext<string[] | null>(null);

/**
 * The blocks a card is edited in, each with the lines behind it. One pencil
 * opens one dialog for a whole block, so it is worth drawing as soon as any one
 * of that block's lines can be changed.
 */
const BLOCK_FIELDS = {
    // «Основное»: what they may do in the system, where somebody stands, the
    // number they are filed under, the terms they work on and when they were
    // born.
    personal: ['role', 'positions', 'departments', 'employee_number', 'employment_type', 'hired_at'],
    passport: ['passport_number', 'passport_issued_at', 'passport_issued_by'],
    contacts: ['email', 'phone', 'sos_phone'],
    family: ['marital_status', 'spouse', 'children'],
    languages: ['languages'],
    education: ['educations'],
    experience: ['work_experiences'],
    equipment: ['equipment'],
};

type Block = keyof typeof BLOCK_FIELDS;

/**
 * Whether one line may be changed, for the dialogs that save their lines one by
 * one: they offer only what they are allowed to save, so nobody is asked to
 * retype a field the server would drop.
 */
function useCanEdit(): (field: string) => boolean {
    const editable = useContext(EditableFields);

    // No list means nothing is locked — as with useShows(), for a form outside the card.
    return (field) => editable === null || editable.includes(field);
}

/**
 * Both lists, handed down from one place: a block asks what it may show and what
 * it may change where it is drawn, instead of either being threaded through
 * every level as a prop.
 */
function ProfileFields({ visible, editable, children }: { visible: string[]; editable: string[]; children: ReactNode }) {
    return (
        <VisibleFields.Provider value={visible}>
            <EditableFields.Provider value={editable}>{children}</EditableFields.Provider>
        </VisibleFields.Provider>
    );
}

/**
 * The lines of a block, read across and then down.
 *
 * A grid rather than CSS columns: columns fill themselves top to bottom, so the
 * second line of a block would land under the first instead of beside it, and the
 * order on the page would not be the order the card is written in.
 */
function Fields({ children, columns }: { children: ReactNode; columns?: 1 | 2 }) {
    // A phone always gets one column of ruled rows; the columns start at `md`.
    const fixed = { 1: 'grid-cols-1', 2: 'grid-cols-1 md:grid-cols-2' } as const;

    // Counted from the card's own width rather than the window's: from `lg` the
    // main column shares the row with the sidebar, and with the menu open a
    // 1024px screen leaves it too narrow for three.
    return (
        <div className="@container">
            <dl
                className={cn(
                    'grid md:gap-x-6 md:gap-y-4',
                    columns ? fixed[columns] : 'grid-cols-1 md:@min-[22rem]:grid-cols-2 md:@min-[28rem]:grid-cols-3',
                )}
            >
                {children}
            </dl>
        </div>
    );
}

/**
 * Facts side by side: a label over its value, the lot wrapping onto as many
 * lines as the card's width asks for. The blocks of rows below read down; this
 * one reads across, which is what the heading of a card wants.
 *
 * A grid rather than a wrapping row, so the columns of one line stand under the
 * columns of the next: as many 200px columns as fit, each taking an even share
 * of what is over.
 */
function Facts({ children }: { children: ReactNode }) {
    return <dl className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-x-10 gap-y-4">{children}</dl>;
}

/** One of them: the name of the line, and under it what it says. */
function Fact({ label, field, children }: { label: string; field?: string; children?: ReactNode }) {
    if (!useShows()(field)) {
        return null;
    }

    // The width is the grid's to decide; this only has to let a long value
    // break inside its column rather than widen it.
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm font-medium break-words">{children || <span className="text-muted-foreground font-normal">—</span>}</dd>
        </div>
    );
}

/**
 * One line of a card. Named after a field, it disappears when that is hidden.
 * On a phone it is one row, the label on the left and the value on the right;
 * from `md` up the label sits above the value.
 */
function Field({ label, field, className, children }: { label: string; field?: string; className?: string; children: ReactNode }) {
    if (!useShows()(field)) {
        return null;
    }

    return (
        <div
            className={cn(
                'border-border/60 flex min-w-0 flex-col gap-1 max-md:flex-row max-md:items-baseline max-md:justify-between max-md:gap-4 max-md:py-2.5 max-md:not-last:border-b',
                className,
            )}
        >
            <dt className="text-muted-foreground text-[13px] max-md:shrink-0 max-md:text-[15px]">{label}</dt>
            <dd className="text-sm font-medium break-words max-md:min-w-0 max-md:text-right max-md:text-[15px] max-md:font-normal">
                {children ?? <span className="text-muted-foreground font-normal">—</span>}
            </dd>
        </div>
    );
}

/** One language per row: the name on the left, the level as a badge on the right. */
function Languages({ items }: { items: SpokenLanguage[] }) {
    const can = useCan();
    // The name leads to everyone who speaks it, for a viewer the staff list would let filter by it.
    const links = can('employees.view') && can('employees.field.languages');

    return (
        <ul className="flex flex-col">
            {items.map((language) => (
                <li key={language.id} className={cn(recordRow, 'flex items-center justify-between gap-3 md:py-2.5')}>
                    {links ? (
                        <Link
                            href={route('employees.index', { language: [language.id] })}
                            title={`Владеют языком: ${language.name}`}
                            className="hover:text-brand-strong truncate text-sm font-medium underline-offset-4 hover:underline max-md:text-[15px] max-md:font-normal"
                        >
                            {language.name}
                        </Link>
                    ) : (
                        <span className="truncate text-sm font-medium max-md:text-[15px] max-md:font-normal">{language.name}</span>
                    )}
                    <LevelBadge level={language.level} />
                </li>
            ))}
        </ul>
    );
}

/**
 * Everything that happened to those units while they were here: handed over,
 * looked after, checked, handed on. One story, so the entries are read together
 * and each says which unit it is about.
 */
function EquipmentJournal({ events, names }: { events: HistoryEvent[]; names: NameLookup }) {
    return (
        <Panel title="Журнал">
            {events.length === 0 ? (
                <p className={emptyNote}>Оборудование за этим сотрудником не числилось</p>
            ) : (
                <ol className="flex flex-col">
                    {events.map((event) => (
                        <li key={event.id} className={cn(recordRow, 'flex flex-col gap-1.5')}>
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={eventTone[event.kind]}>{eventLabel[event.kind]}</StatusBadge>
                                <span className="text-muted-foreground text-[13px] tabular-nums">{formatDate(event.at)}</span>
                            </div>

                            {event.unit &&
                                (event.unit.open ? (
                                    <Link href={route('equipment.show', event.unit.id)} className="text-sm font-medium hover:underline">
                                        {event.unit.name}
                                    </Link>
                                ) : (
                                    <span className="text-sm font-medium">{event.unit.name}</span>
                                ))}

                            <ChangeLines changes={event.changes} kind={event.kind} names={names} note={event.note} />

                            <Photos photos={event.photos} />

                            <span className="text-muted-foreground text-[13px]">{event.actor?.name ?? 'Система'}</span>
                        </li>
                    ))}
                </ol>
            )}
        </Panel>
    );
}

/** A unit on the balance sheet that nobody holds, as the issuing dialog lists it. */
interface StockUnit {
    id: number;
    name: string;
    inventory_number: string;
}

/**
 * Handing equipment over from the card, both ways in one window: take what is
 * already on the balance sheet, or enter a unit that is not on the books yet,
 * which is handed over the moment it is entered. The second way asks everything
 * the add form asks — a unit has a category and a category asks its own
 * questions — so it is that very form, in the same window rather than a page
 * away: whoever came to hand something over does not lose the card for it.
 */
function IssueEquipmentDialog({
    employee,
    stock,
    types,
    onClose,
}: {
    employee: Employee;
    stock: StockUnit[];
    types: EquipmentFormProps['options']['types'];
    onClose: () => void;
}) {
    const can = useCan();
    const today = new Date().toISOString().slice(0, 10);
    // Handed over today unless somebody says otherwise, as the intake wizard has it.
    const form = useClearingForm({ equipment: [] as number[], issued_at: today });
    const canCreate = can('equipment.create');
    /** Which of the two ways is open; the window starts on the balance sheet. */
    const [entering, setEntering] = useState(false);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('employees.equipment.store', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    // The new unit goes to this colleague by itself, so the form is the add
    // form with the tick box gone and the card as the place a save returns to.
    if (entering) {
        return (
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-2xl">
                    <EquipmentForm
                        layout="dialog"
                        options={{ types }}
                        forEmployee={{ id: employee.id, name: `${employee.surname} ${employee.name}` }}
                        header={
                            <DialogHeader>
                                <DialogTitle>Поставить на учёт и выдать</DialogTitle>
                                <DialogDescription>Единица встаёт на баланс и сразу уходит этому сотруднику.</DialogDescription>
                            </DialogHeader>
                        }
                        onSaved={onClose}
                        // Back to what is already on the balance sheet, which is
                        // where this window started.
                        onCancel={() => setEntering(false)}
                    />
                </DialogContent>
            </Dialog>
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Выдать оборудование</DialogTitle>
                        <DialogDescription>
                            {canCreate
                                ? 'Выберите то, что стоит на балансе, или поставьте на учёт новое.'
                                : 'Выберите то, что стоит на балансе и никому не выдано.'}
                        </DialogDescription>
                    </DialogHeader>

                    {stock.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Свободного оборудования на балансе нет: всё выдано или списано.</p>
                    ) : (
                        <div className="grid gap-x-4 gap-y-4 sm:grid-cols-3">
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor="issue-equipment">Что выдаём</Label>
                                <MultiSelect
                                    id="issue-equipment"
                                    options={stock.map((unit) => ({ value: unit.id, label: `${unit.name} · ${unit.inventory_number}` }))}
                                    value={form.data.equipment}
                                    onChange={(value) => form.setData('equipment', value)}
                                    searchPlaceholder="Поиск по названию или номеру"
                                />
                                <InputError message={form.errors.equipment} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="issue-date">Дата выдачи</Label>
                                <DatePicker
                                    id="issue-date"
                                    value={form.data.issued_at}
                                    onChange={(value) => form.setData('issued_at', value)}
                                    toDate={new Date()}
                                    invalid={!!form.errors.issued_at}
                                />
                                <InputError message={form.errors.issued_at} />
                            </div>
                        </div>
                    )}

                    {canCreate && (
                        <div className="flex flex-col items-start gap-1.5 border-t pt-4">
                            <p className="text-muted-foreground text-sm">Оборудования ещё нет на балансе?</p>
                            {/* The same window asks what the add form asks, so the card is not lost on the way. */}
                            <button type="button" onClick={() => setEntering(true)} className={stripAction}>
                                <Plus className="size-4" />
                                Поставить на учёт и выдать
                            </button>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {stock.length === 0 ? 'Закрыть' : 'Отмена'}
                        </Button>
                        {stock.length > 0 && (
                            <Button type="submit" disabled={form.processing || form.data.equipment.length === 0}>
                                {form.processing && <LoaderCircle className="animate-spin" />}
                                Выдать
                            </Button>
                        )}
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * One move on a unit, as an icon at the end of its row. Nothing but the icon
 * fits there, so the word for it arrives on hover and reaches a screen reader as
 * the button's name — and it is the word the form itself is titled with.
 */
function MoveButton({ kind, icon: Icon, danger, onClick }: { kind: AskedMove; icon: LucideIcon; danger?: boolean; onClick: () => void }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={cn('size-9 lg:size-7', danger ? 'text-[#B42318] hover:text-[#B42318] dark:text-[#F7A19A]' : 'text-muted-foreground')}
                    aria-label={moveLabel[kind]}
                    onClick={onClick}
                >
                    <Icon className="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{moveLabel[kind]}</TooltipContent>
        </Tooltip>
    );
}

/**
 * The columns the equipment somebody holds is read in.
 *
 * One track list rules both the heads, which stand in the panel's own line, and
 * every row underneath them. Written out twice they would be two lists inside a
 * month, and a head standing over the wrong column is worse than no head at all.
 *
 * A phone has no room for four columns and no row of heads above them, so there
 * the row is a single file and each answer says what it answers.
 *
 * The last column holds what may be done to a unit — taking it back, striking
 * it off — and is measured out rather than left to the buttons standing in it:
 * the heads are a row of their own, with no buttons in them, and a column the
 * rows size for themselves would leave every head short of its column by the
 * width of two buttons. Where nothing in the list may be moved there is no such
 * column at all, rather than an empty strip down the side of the block.
 */
const unitColumns = (moves: boolean) =>
    cn(
        'grid gap-x-3 max-md:grid-cols-1 max-md:gap-y-0.5 md:items-start',
        moves
            ? 'md:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_4.75rem] lg:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)_minmax(0,0.9fr)_minmax(0,0.8fr)_3.75rem]'
            : 'md:grid-cols-[minmax(0,1fr)_minmax(0,1.7fr)_minmax(0,0.9fr)_minmax(0,0.8fr)]',
    );

/** Whether anything in the list may be moved, which decides that last column. */
const unitMoves = (items: HeldUnit[]) => items.some((unit) => unit.can_take || unit.can_write_off);

/** What a column says where the unit was never asked its question. */
const noAnswer = <span className="text-muted-foreground">—</span>;

/** Those heads, for the panel's info line. */
function EquipmentHeads() {
    return (
        <>
            {['Тип', 'Наименование', 'Инв. №', 'Выдано'].map((head) => (
                <span key={head} className="text-muted-foreground min-w-0 text-[13px]">
                    {head}
                </span>
            ))}
        </>
    );
}

/**
 * A unit under the «Наименование» head, drawn as the fleet draws one: the mark
 * of its category, the name in the colour of a link, and under the name what
 * the category asks about first.
 *
 * The line under the name wraps rather than being cut: it is a processor and a
 * disk, and half of either says nothing. The unit's own card is a matter of the
 * fleet, so a viewer who may not open one reads the same row without the link.
 */
function UnitName({ unit, className }: { unit: HeldUnit; className?: string }) {
    const named = (
        <>
            <CategoryChip icon={unit.type_icon} size={28} iconSize={14} className="mt-px" />
            <span className="flex min-w-0 flex-col">
                <span
                    className={cn(
                        'text-sm leading-tight font-medium break-words',
                        unit.open && 'text-brand-strong group-hover:underline dark:text-[#C5E27A]',
                    )}
                >
                    {unit.name}
                </span>
                {unit.details && <span className="text-muted-foreground text-[13px] leading-tight break-words">{unit.details}</span>}
            </span>
        </>
    );

    return unit.open ? (
        <Link
            href={route('equipment.show', unit.id)}
            className={cn('group flex min-w-0 items-start gap-2', className)}
            title={`Открыть: ${unit.name}`}
        >
            {named}
        </Link>
    ) : (
        <div className={cn('flex min-w-0 items-start gap-2', className)}>{named}</div>
    );
}

/**
 * One answer of a row, under the head standing over its column — or beside the
 * head, where the columns have come down to a single file and there is no row
 * of heads left to look up to.
 */
function UnitCell({ head, children }: { head: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col break-words max-md:flex-row max-md:items-baseline max-md:gap-1.5">
            <span className="text-muted-foreground shrink-0 text-[13px] md:hidden">{head}</span>
            {children}
        </div>
    );
}

/**
 * What the person holds right now, and what can be done about it from here:
 * this card is where somebody notices that the thing is back on the desk or past
 * saving, so a unit is taken back and struck off from the row itself, through
 * the same forms its own card opens. Each move shows only where it is this
 * viewer's to make on that very unit.
 */
function EquipmentList({ items }: { items: ProfilePrivate['equipment'] }) {
    /** Which unit a move was picked on, and which move, until the form closes. */
    const [asking, setAsking] = useState<{ unit: HeldUnit; kind: AskedMove } | null>(null);

    // Asked of the list rather than of the row: the column is the same width
    // down the whole block, or the answers in it stand in a ragged line.
    const moves = unitMoves(items);

    return (
        <>
            <ul className="flex flex-col">
                {items.map((unit) => (
                    <li key={unit.id} className={cn(unitColumns(moves), 'py-2.5 first:pt-0 last:pb-0')}>
                        <UnitCell head="Тип">
                            <span className="min-w-0 text-sm break-words">{unit.type ?? noAnswer}</span>
                        </UnitCell>

                        {/* The unit named as the fleet's own lists name one: its
                            category's mark, the name in the colour of a link,
                            and under it what the category asks about first.

                            On a phone the row is a single file with no heads
                            over it, and there the thing's own name goes first:
                            a list that opened on «Ноутбуки» over and over would
                            be read down the wrong column. */}
                        <UnitName unit={unit} className="max-md:order-first" />

                        <UnitCell head="Инв. №">
                            <span className="min-w-0 text-sm break-words tabular-nums">{unit.inventory_number}</span>
                        </UnitCell>

                        <UnitCell head="Выдано">
                            <span className="min-w-0 text-sm tabular-nums">{unit.issued_at ? formatDate(unit.issued_at) : noAnswer}</span>
                        </UnitCell>

                        {/* Never squeezed: a long name wraps in the column beside
                            this one rather than pushing the icons off a phone. */}
                        {(unit.can_take || unit.can_write_off) && (
                            <div className="flex shrink-0 gap-1 max-md:pt-1">
                                {unit.can_take && <MoveButton kind="take" icon={ArrowDownToLine} onClick={() => setAsking({ unit, kind: 'take' })} />}
                                {unit.can_write_off && (
                                    <MoveButton kind="write-off" icon={Trash2} danger onClick={() => setAsking({ unit, kind: 'write-off' })} />
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            {/* The list rebuilds itself: the move comes back to this card, and the
                props with it. Holders are the issuing form's business, not these two. */}
            {asking && <EquipmentMoveDialog unit={asking.unit} kind={asking.kind} holders={[]} onClose={() => setAsking(null)} />}
        </>
    );
}

/**
 * The photo, and the ways to change it. The thumbnail is what the interface
 * shows; the upload itself opens in a new tab, so a face can be seen properly.
 */
/**
 * The photograph, or the place where one would be.
 *
 * A card with no photograph shows the initials — that is somebody who never
 * uploaded one. A photograph this viewer may not see is a different answer, and
 * says so: a closed square rather than a face, so nobody reads "no photo" into a
 * line that is simply not theirs to read.
 */
function LockedFace() {
    return (
        <div
            className={cn(
                'bg-muted text-muted-foreground flex size-[72px] shrink-0 items-center justify-center rounded-full md:size-[88px]',
                faceEdge,
            )}
            title="Фотография закрыта"
            aria-label="Фотография закрыта"
        >
            <Lock className="size-7" />
        </div>
    );
}

function Avatar({ employee, canEdit, onDelete }: { employee: Employee; canEdit: boolean; onDelete: () => void }) {
    const picker = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    // Why the last upload was refused — too large, not a picture — said under the
    // photograph, since there is no form here for the message to sit in.
    const [error, setError] = useState<string | null>(null);

    const upload = (file: File) =>
        router.post(
            route('employees.avatar.update', employee.id),
            { avatar: file },
            {
                preserveScroll: true,
                forceFormData: true,
                onStart: () => {
                    setError(null);
                    setUploading(true);
                },
                onError: (errors) => setError(errors.avatar ?? Object.values(errors)[0] ?? 'Не удалось загрузить фотографию.'),
                onFinish: () => setUploading(false),
            },
        );

    const face = employee.avatar ? (
        <img
            src={employee.avatar}
            alt={`Фотография: ${employee.surname} ${employee.name}`}
            className={cn('size-[72px] rounded-full object-cover md:size-[88px]', faceEdge)}
        />
    ) : (
        <PersonAvatar name={`${employee.name} ${employee.surname}`} className="size-[72px] text-2xl md:size-[88px] md:text-3xl" />
    );

    return (
        <div className="flex max-w-56 shrink-0 flex-col gap-2 self-center max-md:items-center max-md:text-center md:self-auto">
            <div className="relative md:self-start">
                {employee.avatar_original ? (
                    <a href={employee.avatar_original} target="_blank" rel="noopener" title="Открыть оригинал" className="block">
                        {face}
                    </a>
                ) : (
                    face
                )}

                {uploading && (
                    <span className="absolute inset-0 flex items-center justify-center rounded-full bg-black/40">
                        <LoaderCircle className="size-6 animate-spin text-white" />
                    </span>
                )}

                {canEdit && (
                    <>
                        <input
                            ref={picker}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="hidden"
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                // Cleared so picking the same file twice fires again.
                                event.target.value = '';
                                if (file) upload(file);
                            }}
                        />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="absolute right-0 bottom-0 size-9 rounded-full shadow-sm max-md:-right-1 max-md:-bottom-1 max-md:size-8 lg:size-8"
                                    aria-label="Изменить фотографию"
                                >
                                    <Camera className="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="w-52">
                                <DropdownMenuItem onSelect={() => picker.current?.click()}>
                                    <Upload />
                                    {employee.avatar ? 'Заменить фотографию' : 'Загрузить фотографию'}
                                </DropdownMenuItem>
                                {employee.avatar && (
                                    <DropdownMenuItem
                                        onSelect={onDelete}
                                        className="text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] [&_svg]:text-current!"
                                    >
                                        <Trash2 />
                                        Удалить фотографию
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                )}
            </div>
            {error && (
                <p role="alert" className="text-destructive text-[13px] leading-snug">
                    {error}
                </p>
            )}
        </div>
    );
}

/** A round contact button with a word under it, as a phone's contact card has them. */
function QuickAction({ href, label, title, Icon }: { href: string; label: string; title: string; Icon: typeof Phone }) {
    return (
        <a href={href} title={title} aria-label={`${label}: ${title}`} className="group flex w-16 flex-col items-center gap-1">
            <span className="bg-card text-brand-strong flex size-11 items-center justify-center rounded-full shadow-xs transition-transform group-active:scale-95 dark:text-[#C5E27A]">
                <Icon className="size-5" />
            </span>
            <span className="text-brand-strong text-[11px] font-medium dark:text-[#C5E27A]">{label}</span>
        </a>
    );
}

type Neighbour = { id: number; name: string } | null;

// Comparing colleagues means staying on the same section, so the open tab
// travels with the link.
const neighbourHref = (to: Neighbour, tab: TabKey) => route('employees.show', to!.id) + (tab === 'profile' ? '' : `#${tab}`);

/**
 * Links to the previous and next colleague in the list; ← and → keys do the
 * same. The pair stands at the end of the line of tabs, where the design has
 * it: it pages through the staff the way the tabs page through one person's
 * card, so the two belong on one line.
 */
function Neighbours({ prev, next, tab }: { prev: Neighbour; next: Neighbour; tab: TabKey }) {
    const href = (to: Neighbour) => neighbourHref(to, tab);

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.ctrlKey || event.altKey || event.metaKey || event.shiftKey || event.defaultPrevented) return;
            const target = event.target as HTMLElement;
            if (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].includes(target.tagName)) return;
            // An open window — a form, a menu, the photo viewer — owns the arrows:
            // leaving for the next colleague from inside one would throw away
            // whatever was being typed, and the photo viewer pages with them.
            if (document.querySelector('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]')) return;
            const to = event.key === 'ArrowLeft' ? prev : event.key === 'ArrowRight' ? next : null;
            if (to) router.visit(href(to));
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const arrow = (to: Neighbour, label: string, Icon: typeof ChevronLeft) => {
        // The arrow leads the label going back and trails it going forward.
        const back = Icon === ChevronLeft;

        return (
            <Button
                variant="outline"
                // 36px, as every control the page puts in a line of its own is.
                className="h-9 flex-1 sm:flex-none"
                disabled={!to}
                aria-label={to ? `${label}: ${to.name}` : label}
                title={to?.name}
                asChild={!!to}
            >
                {to ? (
                    <Link href={href(to)} prefetch>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </Link>
                ) : (
                    <>
                        {back && <Icon />}
                        {label}
                        {!back && <Icon />}
                    </>
                )}
            </Button>
        );
    };

    return (
        <div className="flex w-full gap-2 max-md:hidden sm:w-auto md:ml-auto">
            {arrow(prev, 'Предыдущий', ChevronLeft)}
            {arrow(next, 'Следующий', ChevronRight)}
        </div>
    );
}

/**
 * The same two links on a phone: two full-width buttons would push the card down
 * by a whole row, so there they are small chevrons in the top corners of the
 * hero (the nearest positioned box), the way a native viewer pages between
 * records. They stay with the hero; only the desktop pair moved to the tabs.
 */
function NeighbourCorners({ prev, next, tab }: { prev: Neighbour; next: Neighbour; tab: TabKey }) {
    const corner = (to: Neighbour, label: string, Icon: typeof ChevronLeft, side: string) =>
        to ? (
            <Button variant="ghost" size="icon" className={cn('absolute top-0 size-9 md:hidden', side)} aria-label={`${label}: ${to.name}`} asChild>
                <Link href={neighbourHref(to, tab)} prefetch>
                    <Icon className="size-5" />
                </Link>
            </Button>
        ) : (
            <Button variant="ghost" size="icon" className={cn('absolute top-0 size-9 md:hidden', side)} aria-label={label} disabled>
                <Icon className="size-5" />
            </Button>
        );

    return (
        <>
            {corner(prev, 'Предыдущий', ChevronLeft, 'left-0')}
            {corner(next, 'Следующий', ChevronRight, 'right-0')}
        </>
    );
}

export default function EmployeeProfile({
    employee,
    neighbours,
    canEdit,
    isSelf,
    canIssue,
    stock,
    equipmentTypes,
    roleLocked,
    positionsLocked,
    picksRole,
    options,
    assigned,
    sheet,
    documents,
    visibleFields,
    editableFields,
}: {
    employee: Employee;
    /** Absent on one’s own profile: a profile has no previous and no next. */
    neighbours: { prev: Neighbour; next: Neighbour } | null;
    /** Whether anything at all is theirs to change; the photograph hangs on this. */
    canEdit: boolean;
    isSelf: boolean;
    /** Whether a unit may be handed over from the equipment section of this card. */
    canIssue: boolean;
    /** What there is to hand over; empty for a viewer who may not hand anything over. */
    stock: StockUnit[];
    /**
     * The categories a unit may be entered under, for the window that puts one
     * on the books from here. Empty for anybody who may not enter a unit.
     */
    equipmentTypes: EquipmentFormProps['options']['types'];
    /** Why the role on this card is nobody's to change, or null when it is somebody's. */
    roleLocked: string | null;
    /** And why the jobs on it are not — a card holding «HR-специалист» is the system administrator's. */
    positionsLocked: string | null;
    /**
     * Whether this viewer picks the role and has the jobs narrow to it, or picks
     * the job and reads off the role it decided.
     */
    picksRole: boolean;
    /** Choices for the edit dialogs; null for viewers who may not edit. */
    options: EditOptions | null;
    assigned: Assigned | null;
    /** How far «Личный листок» has got, over the lines this viewer may read. */
    sheet: SheetProgress;
    /**
     * The papers the company asks everybody for, with this person's answers
     * against them, and whether they are this viewer's to attach and to pass.
     * Null where the documents are not theirs to read.
     */
    documents: EmployeeDocuments | null;

    /** Which lines of this card the viewer reads; everything else is not drawn. */
    visibleFields: string[];
    /** Which of them they may change; the rest are shown without a pencil. */
    editableFields: string[];
}) {
    const [editing, setEditing] = useState<'personal' | 'passport' | 'contacts' | 'languages' | 'employment' | 'family' | null>(null);
    // Whoever leads the departments this person is filed in, each named once:
    // somebody in two units of one department answers to one person, not two.
    const heads = [...new Map((employee.departments ?? []).flatMap((department) => department.heads).map((head) => [head.id, head])).values()];

    // Whether the person reading this is shown their own card and nothing
    // else, which decides what of the card is a record and what is still a
    // sketch — see the blocks below «Основное».
    const { ownCardOnly } = usePage<SharedData>().props.auth;

    const [deletingAvatar, setDeletingAvatar] = useState(false);
    const [issuing, setIssuing] = useState(false);
    const shortName = `${employee.surname} ${employee.name}`;
    const fullName = [employee.surname, employee.name, employee.patronymic].filter(Boolean).join(' ');
    const details = employee.private;

    // A block is editable while one of its lines is: the dialog behind the
    // pencil then has something to save, even if not the whole card.
    const canEditBlock = (block: Block) => BLOCK_FIELDS[block].some((field) => editableFields.includes(field));

    // A section is offered when its own field is readable and there is a card
    // behind it to read.
    const tabs = TABS.filter((tab) => !('field' in tab) || (details !== null && visibleFields.includes(tab.field)));
    const [tab, setTab] = useTab(tabs.map((t) => t.key));

    // One's own card is not a page of the staff list — it is reached from the
    // name in the corner, not by walking through «Сотрудники».
    const breadcrumbs: BreadcrumbItem[] = isSelf
        ? [{ title: 'Профиль', href: '/profile' }]
        : [
              { title: 'Сотрудники', href: '/employees' },
              { title: shortName, href: `/employees/${employee.id}` },
          ];

    return (
        <ProfileFields visible={visibleFields} editable={editableFields}>
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title={shortName} />

                {/* The page scrolls as a whole, header and tabs included. */}
                <PageContent>
                    {/* On a phone the hero is centred, like a contact card: the face,
                    the name under it, then the ways to reach the person. */}
                    <div className="flex flex-col gap-5 px-1 pt-1 max-md:relative max-md:items-center max-md:gap-3 max-md:pb-1 max-md:text-center md:flex-row md:flex-wrap md:items-end">
                        {/* The photograph is a line of the card: closed, it says so, and
                        changing it takes that line rather than the right to change
                        anything at all. */}
                        {visibleFields.includes('avatar') ? (
                            <Avatar employee={employee} canEdit={editableFields.includes('avatar')} onDelete={() => setDeletingAvatar(true)} />
                        ) : (
                            <LockedFace />
                        )}

                        <div className="flex min-w-0 flex-1 flex-col gap-2 max-md:w-full max-md:items-center max-md:gap-2.5 md:min-w-64">
                            <div className="flex flex-wrap items-center gap-3 max-md:justify-center max-md:gap-2">
                                {/* The heading is the bar's now, and the bar has the
                                    short name this person is listed under. This is the
                                    contact card, so here the name stands in full. */}
                                <p className="min-w-0 text-xl font-semibold tracking-tight break-words">{fullName}</p>
                                {employee.status !== 'active' && (
                                    <StatusBadge
                                        tone={employee.status === 'fired' ? 'danger' : 'warning'}
                                        title={employee.status_note ?? undefined}
                                        className={wrappingBadge}
                                    >
                                        {[
                                            employee.status === 'fired'
                                                ? employee.sex === 'female'
                                                    ? 'Уволена'
                                                    : 'Уволен'
                                                : employee.sex === 'female'
                                                  ? 'Переведена'
                                                  : 'Переведён',
                                            formatDate(employee.status_changed_at),
                                            employee.status_note &&
                                                (employee.status === 'transferred' ? `→ ${employee.status_note}` : `· ${employee.status_note}`),
                                        ]
                                            .filter(Boolean)
                                            .join(' ')}
                                    </StatusBadge>
                                )}
                            </div>

                            {/* The position a person holds and the job they do are two different
                                things — "Аналитик" and "Ведущий специалист" — and the header
                                said only the second. Told apart by colour, in the order the
                                card and the table read them. */}
                            {(employee.role || (employee.positions ?? []).length > 0) && (
                                <div className="flex flex-wrap gap-2 max-md:justify-center max-md:gap-1.5">
                                    {employee.role && (
                                        <StatusBadge tone="info" className={wrappingBadge}>
                                            {employee.role}
                                        </StatusBadge>
                                    )}
                                    {(employee.positions ?? []).map((position) => {
                                        const badge = (
                                            <StatusBadge tone="success" className={wrappingBadge}>
                                                <PositionName name={position.name} unit={position.unit} />
                                            </StatusBadge>
                                        );

                                        // The badge is the way to the job for whoever
                                        // may read the jobs, and a word for the rest.
                                        return position.id === null ? (
                                            <span key={position.name}>{badge}</span>
                                        ) : (
                                            <Way
                                                key={position.id}
                                                linked={!ownCardOnly}
                                                href={route('positions.show', position.id)}
                                                title={`Открыть должность: ${positionTitle(position)}`}
                                                className={cn(
                                                    !ownCardOnly && 'rounded-md transition-[filter] hover:brightness-95 dark:hover:brightness-125',
                                                )}
                                            >
                                                {badge}
                                            </Way>
                                        );
                                    })}
                                </div>
                            )}

                            <div className="text-muted-foreground flex flex-wrap gap-x-5 gap-y-1 text-sm max-md:hidden">
                                {employee.email && (
                                    <a href={`mailto:${employee.email}`} className={cn(contactLink, 'max-w-full min-w-0')}>
                                        <Mail className="size-4 shrink-0" />
                                        <span className="truncate">{employee.email}</span>
                                    </a>
                                )}
                                {details?.phone && (
                                    <a href={`tel:${details.phone}`} className={cn(contactLink, 'tabular-nums')}>
                                        <Phone className="size-4" />
                                        {formatPhone(details.phone)}
                                    </a>
                                )}
                            </div>

                            {/* On a phone the same two contacts are round buttons under the
                            name — a tap calls or writes — and only those this viewer reads. */}
                            {(employee.email || details?.phone) && (
                                <div className="mt-1 flex justify-center gap-6 md:hidden">
                                    {details?.phone && (
                                        <QuickAction
                                            href={`tel:${details.phone}`}
                                            label="Позвонить"
                                            title={formatPhone(details.phone)}
                                            Icon={Phone}
                                        />
                                    )}
                                    {employee.email && (
                                        <QuickAction href={`mailto:${employee.email}`} label="Написать" title={employee.email} Icon={Mail} />
                                    )}
                                </div>
                            )}
                        </div>

                        {neighbours !== null && <NeighbourCorners prev={neighbours.prev} next={neighbours.next} tab={tab} />}
                    </div>

                    {/* The tabs and the way out to the cards either side on one
                        line: both are about which card is open rather than
                        about the person in it, and the hero above is about the
                        person. */}
                    <div className="flex items-center justify-between gap-4">
                        <ProfileTabs tabs={tabs} active={tab} onChange={setTab} />
                        {neighbours !== null && <Neighbours prev={neighbours.prev} next={neighbours.next} tab={tab} />}
                    </div>

                    {/* One card per tab. */}
                    {tab !== 'profile' && (
                        <div className="flex flex-col gap-4">
                            {tab === 'sheet' && <SheetTab employee={employee} sheet={sheet} documents={documents} options={options} />}

                            {tab === 'equipment' && details && (
                                // What they hold now on the left, what has been
                                // through their hands on the right.
                                <div className={paneGrid}>
                                    <div className={pane}>
                                        <Panel
                                            title="Текущие оборудования"
                                            action={
                                                canIssue && (
                                                    <button type="button" className={stripAction} onClick={() => setIssuing(true)}>
                                                        <Plus className="size-4" />
                                                        Выдать
                                                    </button>
                                                )
                                            }
                                            // The heads of the list stand in the panel's own line,
                                            // above the body rather than inside it: that way a row
                                            // holds answers only, and the heads are said once for
                                            // the whole list instead of once per row. They line up
                                            // with the columns under them because the info line and
                                            // the body are indented alike.
                                            infoClassName={cn(unitColumns(unitMoves(details.equipment)), 'max-md:hidden')}
                                            info={details.equipment.length > 0 && <EquipmentHeads />}
                                        >
                                            {details.equipment.length === 0 ? (
                                                <p className={emptyNote}>Не выдано</p>
                                            ) : (
                                                <EquipmentList items={details.equipment} />
                                            )}
                                        </Panel>
                                    </div>

                                    <div className={pane}>
                                        <EquipmentJournal events={details.equipment_history.events} names={details.equipment_history.names} />
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'profile' &&
                        (details ? (
                            // Narrow: one column. Wide: the main column and a sidebar.
                            <div className={paneGrid}>
                                <div className={pane}>
                                    {/* The block the card is built around: where somebody stands
                                        and on what terms. */}
                                    <Panel
                                        title="Основное"
                                        action={
                                            canEditBlock('personal') && (
                                                <EditButton label="Редактировать основное" onClick={() => setEditing('personal')} />
                                            )
                                        }
                                    >
                                        <Facts>
                                            {/* What they may do in the system, as against the job they
                                                do in the company. Next to the job because that is the
                                                pair somebody fills in together on «Новый сотрудник». */}
                                            <Fact label="Роль" field="role">
                                                {employee.role}
                                            </Fact>

                                            <Fact label="Отдел" field="departments">
                                                {(employee.departments ?? []).length > 0 && (
                                                    <span className="flex flex-col gap-1">
                                                        {(employee.departments ?? []).map((department) => (
                                                            // As wide as the chain it names, and never
                                                            // wider than the column: a link the width of
                                                            // the column would be a line of empty space
                                                            // to click on, and one the width of its own
                                                            // text alone would run a long name off the
                                                            // card rather than let it wrap.
                                                            <Way
                                                                key={department.id}
                                                                linked={!ownCardOnly}
                                                                href={route('departments.show', department.id)}
                                                                className={cn('w-max max-w-full', !ownCardOnly && 'hover:underline')}
                                                            >
                                                                <DepartmentName name={department.path} full={department.full_path} />
                                                            </Way>
                                                        ))}
                                                    </span>
                                                )}
                                            </Fact>

                                            <Fact label="Должность" field="positions">
                                                {(employee.positions ?? []).length > 0 && (
                                                    <span className="flex flex-col gap-1">
                                                        {(employee.positions ?? []).map((position) =>
                                                            // The way to the job for whoever may read the
                                                            // jobs, and a word for everybody else.
                                                            position.id === null ? (
                                                                <PositionName key={position.name} name={position.name} unit={position.unit} />
                                                            ) : (
                                                                <Way
                                                                    key={position.id}
                                                                    linked={!ownCardOnly}
                                                                    href={route('positions.show', position.id)}
                                                                    className={cn('w-max max-w-full', !ownCardOnly && 'hover:underline')}
                                                                >
                                                                    <PositionName name={position.name} unit={position.unit} />
                                                                </Way>
                                                            ),
                                                        )}
                                                    </span>
                                                )}
                                            </Fact>

                                            <Fact label="Руководитель" field="departments">
                                                {heads.length > 0 && (
                                                    <span className="flex flex-col gap-1">
                                                        {heads.map((head) => (
                                                            <Way
                                                                key={head.id}
                                                                linked={!ownCardOnly}
                                                                href={route('employees.show', head.id)}
                                                                className={cn('w-max max-w-full', !ownCardOnly && 'hover:underline')}
                                                            >
                                                                {head.name}
                                                            </Way>
                                                        ))}
                                                    </span>
                                                )}
                                            </Fact>

                                            <Fact label="Номер сотрудника" field="employee_number">
                                                {employee.employee_number && <span className="tabular-nums">{employee.employee_number}</span>}
                                            </Fact>

                                            <Fact label="Тип занятости" field="employment_type">
                                                {employee.employment_type}
                                            </Fact>

                                            {/* The date, and how long it has been — one line, because
                                                the second is read off the first and not kept anywhere:
                                                a stored length of service is wrong by a day the day
                                                after it is written. Said the way a date of birth used
                                                to say an age beside it. */}
                                            <Fact label="Начало работы" field="hired_at">
                                                {details.hired_at && (
                                                    <span className="tabular-nums">
                                                        {formatDate(details.hired_at)}
                                                        <span className="text-muted-foreground font-normal"> · {tenure(details.hired_at)}</span>
                                                    </span>
                                                )}
                                            </Fact>
                                        </Facts>
                                    </Panel>

                                    {/* The six blocks below are the design of what the
                                        card is to hold: a KPI, a month of attendance, a
                                        year of leave, a plan and two sets of skills. Not
                                        one of them is kept anywhere yet — they are drawn
                                        from figures written into this page — so they are
                                        shown to the people who are building the thing and
                                        not to the person whose card it is. Somebody
                                        reading their own card has no way of telling a
                                        sketch from a record, and «ваш KPI — 97%» is a
                                        sentence nobody should read about themselves until
                                        it is true. */}
                                    {!ownCardOnly && (
                                        <>
                                            <KpiSection />

                                            {/* Two blocks of a size, side by side where there is
                                                room for them and one under the other where there
                                                is not. */}
                                            <div className="grid gap-4 lg:grid-cols-2">
                                                <AttendanceSection />
                                                <LeaveSection />
                                            </div>

                                            <PlanSection />

                                            <div className="grid gap-4 lg:grid-cols-2">
                                                <SkillsSection kind="hard" skills={HARD_SKILLS} />
                                                <SkillsSection kind="soft" skills={SOFT_SKILLS} />
                                            </div>
                                        </>
                                    )}
                                </div>

                                <aside className={sidePane}>
                                    <Panel
                                        fields={['languages']}
                                        title="Знание языков"
                                        action={
                                            canEditBlock('languages') && (
                                                <EditButton label="Редактировать знание языков" onClick={() => setEditing('languages')} />
                                            )
                                        }
                                    >
                                        {(employee.languages ?? []).length === 0 ? (
                                            <p className={emptyNote}>Не указаны</p>
                                        ) : (
                                            <Languages items={employee.languages ?? []} />
                                        )}
                                    </Panel>

                                    <Panel
                                        fields={['email', 'phone', 'sos_phone']}
                                        title="Контакты"
                                        action={
                                            canEditBlock('contacts') && (
                                                <EditButton label="Редактировать контакты" onClick={() => setEditing('contacts')} />
                                            )
                                        }
                                    >
                                        <Fields columns={1}>
                                            <Field label="Электронная почта" field="email">
                                                <a href={`mailto:${employee.email}`} className={contactLink}>
                                                    <Mail className="size-4 shrink-0" />
                                                    <span className="truncate">{employee.email}</span>
                                                </a>
                                            </Field>
                                            <Field label="Телефон" field="phone">
                                                {details.phone && (
                                                    <a href={`tel:${details.phone}`} className={cn(contactLink, 'tabular-nums')}>
                                                        <Phone className="size-4 shrink-0" />
                                                        {formatPhone(details.phone)}
                                                    </a>
                                                )}
                                            </Field>
                                            <Field label="Экстренный контакт" field="sos_phone">
                                                {details.sos_phone && <SosPhone phone={details.sos_phone} contact={details.sos_contact} />}
                                            </Field>
                                        </Fields>
                                    </Panel>

                                    {/* Nobody transfers, fires or deletes themselves, so on one's own
                                    card the row would be three disabled buttons and nothing else. */}
                                    {canEdit && !isSelf && (
                                        <EmployeeActions
                                            variant="group"
                                            isSelf={isSelf}
                                            employee={{
                                                id: employee.id,
                                                name: employee.name,
                                                surname: employee.surname,
                                                status: employee.status,
                                            }}
                                        />
                                    )}
                                </aside>
                            </div>
                        ) : (
                            <div className={paneGrid}>
                                <div className={pane}>
                                    <Card className="text-muted-foreground flex items-start gap-3 rounded-xl px-4 py-5 text-sm max-md:rounded-2xl max-md:border-0 max-md:py-3.5 max-md:text-[15px] max-md:shadow-none md:px-6">
                                        <Lock className="mt-0.5 size-5 shrink-0" />
                                        {/* Who exactly sees them is no longer a sentence to write down:
                                        it follows from the viewer's role, line by line. */}
                                        <p>Личные данные, контакты, паспорт и семья закрыты.</p>
                                    </Card>
                                </div>

                                {/* Hire date, phones and the rest are private, so a colleague's sidebar
                            holds languages alone — those are public. */}
                                <aside className={sidePane}>
                                    <Panel
                                        fields={['languages']}
                                        title="Знание языков"
                                        action={
                                            canEditBlock('languages') && (
                                                <EditButton label="Редактировать знание языков" onClick={() => setEditing('languages')} />
                                            )
                                        }
                                    >
                                        {(employee.languages ?? []).length === 0 ? (
                                            <p className={emptyNote}>Не указаны</p>
                                        ) : (
                                            <Languages items={employee.languages ?? []} />
                                        )}
                                    </Panel>
                                </aside>
                            </div>
                        ))}
                </PageContent>

                {/* Not gated on the private lines: the block may be open to this
                    viewer by its public ones alone. */}
                {editing === 'personal' && options && assigned && (
                    <MainDialog
                        employee={employee}
                        details={details}
                        options={options}
                        assigned={assigned}
                        roleLocked={roleLocked}
                        positionsLocked={positionsLocked}
                        picksRole={picksRole}
                        onClose={() => setEditing(null)}
                    />
                )}

                {editing === 'contacts' && details && <ContactsDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

                {/* Languages are public, so this one needs no private details. */}
                {editing === 'languages' && options && <LanguagesDialog employee={employee} options={options} onClose={() => setEditing(null)} />}

                {deletingAvatar && (
                    <DeleteRecordDialog
                        title="Удалить фотографию?"
                        description="Вместо неё снова будут показаны инициалы. Отменить удаление нельзя."
                        onConfirm={() =>
                            router.delete(route('employees.avatar.destroy', employee.id), {
                                preserveScroll: true,
                                onFinish: () => setDeletingAvatar(false),
                            })
                        }
                        onClose={() => setDeletingAvatar(false)}
                    />
                )}

                {issuing && <IssueEquipmentDialog employee={employee} stock={stock} types={equipmentTypes} onClose={() => setIssuing(false)} />}
            </AppLayout>
        </ProfileFields>
    );
}
