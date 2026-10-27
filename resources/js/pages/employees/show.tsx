import { DatePicker } from '@/components/date-picker';
import { DepartmentName } from '@/components/department-name';
import { EmployeeActions } from '@/components/employee-actions';
import { ChangeLines } from '@/components/equipment-changes';
import { EquipmentForm, type EquipmentFormProps } from '@/components/equipment-form';
import { EquipmentMoveDialog, moveLabel, type AskedMove } from '@/components/equipment-move-dialog';
import InputError from '@/components/input-error';
import { LevelBadge } from '@/components/language-badges';
import { MultiSelect } from '@/components/multi-select';
import { faceEdge, PersonAvatar } from '@/components/person-avatar';
import { SosPhone } from '@/components/phones';
import { Photos, type Photo } from '@/components/photo-viewer';
import { type Placement, type PlacementOptions } from '@/components/placements';
import { PositionName, positionTitle, type PositionRef } from '@/components/position-name';
import { SearchableSelect } from '@/components/searchable-select';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { useCan } from '@/lib/access';
import {
    age,
    formatDate,
    formatPhone,
    languageLevels,
    monthNames,
    monthsSpan,
    tenure,
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
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowDownToLine,
    Camera,
    ChevronLeft,
    ChevronRight,
    LoaderCircle,
    Lock,
    Mail,
    Pencil,
    Phone,
    Plus,
    Trash2,
    Upload,
    type LucideIcon,
} from 'lucide-react';
import { createContext, useContext, useEffect, useRef, useState, type FormEventHandler, type ReactNode } from 'react';

/** Every right, what the positions give and what was decided for this person. */
interface AccessPicture {
    /** An access role: they pass every check whatever the rights below say. */
    everything: boolean;
    sections: { key: string; title: string; rights: { key: string; title: string; hint: string }[] }[];
    rights: { key: string; position: boolean; override: boolean | null }[];
}

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
    /** What the card says about it now, which the write-off form opens on. */
    condition: string | null;
    can_take: boolean;
    can_write_off: boolean;
}

interface ProfilePrivate extends PrivateDetails {
    educations: (Education & { id: number })[];
    /** The latest first. */
    work_experiences: (WorkExperience & { id: number })[];
    /** Grouped by kind, which is resolved to its directory name here. */
    equipment: HeldUnit[];
    /** What has passed through their hands and what happened to it meanwhile. */
    equipment_history: { events: HistoryEvent[]; names: NameLookup };
    birth_place: string | null;
    passport: { series: string | null; number: string | null; issued_at: string | null; issued_by: string | null };
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
    /** Access roles, shown as «Роль». */
    roles?: string[];
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
    branch?: string | null;
    /** Null when the viewer may not see this person's private data. */
    private: ProfilePrivate | null;
}

/** What the card already holds, as the window of «Основное» addresses it. */
interface Assigned {
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
    { key: 'education', title: 'Образование', private: true, field: 'educations' },
    { key: 'experience', title: 'Трудовая деятельность', private: true, field: 'work_experiences' },
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
    branches: string[];
    /** Access roles, chosen by name. */
    roles: { name: string; title: string }[];
    languages: { id: number; name: string }[];
    /** Countries already on file, as suggestions for a previous job. */
    countries: string[];
    equipment_types: { id: number; name: string }[];
}

/**
 * What this colleague may do, and why.
 *
 * Rights come with a position; an exception is for the person whose work does
 * not fit their position — one who needs the journal, one who must not see
 * private data. An exception beats the position either way, which is why it is
 * spelled out here rather than hidden behind a tick.
 */
function AccessSection({ employee, access }: { employee: Employee; access: AccessPicture }) {
    const [open, setOpen] = useState(false);

    const titles = new Map(access.sections.flatMap((section) => section.rights.map((right) => [right.key, `${section.title}: ${right.title}`])));

    // Only the rights this block can name: field, equipment and directory rights are
    // read and kept list by list elsewhere, and here they would be blank lines.
    const titled = access.rights.filter((right) => titles.has(right.key));
    const granted = titled.filter((right) => right.override === true);
    const revoked = titled.filter((right) => right.override === false);
    const effective = titled.filter((right) => right.override ?? right.position);

    // Each tick is saved on its own, so a quick second one neither cancels the
    // first nor the save of a form standing open elsewhere on the page.
    const set = (key: string, allowed: boolean | null) =>
        router.put(route('employees.access', employee.id), { permission: key, allowed }, { preserveScroll: true, preserveState: true, async: true });

    return (
        <>
            <Section title="Доступы" action={!access.everything && <EditButton label="Настроить доступы" onClick={() => setOpen(true)} />}>
                {access.everything ? (
                    <p className={emptyNote}>Все доступы: сотрудник — системный администратор и проходит любую проверку.</p>
                ) : (
                    <Fields columns={1}>
                        <Field label="Открыто">
                            {effective.length > 0 ? (
                                <span className="flex flex-col gap-0.5">
                                    {effective.map((right) => (
                                        <span key={right.key}>{titles.get(right.key)}</span>
                                    ))}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">Ничего</span>
                            )}
                        </Field>
                        {granted.length > 0 && (
                            <Field label="Добавлено лично">
                                <span className="flex flex-col gap-0.5">
                                    {granted.map((right) => (
                                        <span key={right.key}>{titles.get(right.key)}</span>
                                    ))}
                                </span>
                            </Field>
                        )}
                        {revoked.length > 0 && (
                            <Field label="Снято лично">
                                <span className="flex flex-col gap-0.5">
                                    {revoked.map((right) => (
                                        <span key={right.key}>{titles.get(right.key)}</span>
                                    ))}
                                </span>
                            </Field>
                        )}
                    </Fields>
                )}
            </Section>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Доступы сотрудника</DialogTitle>
                        <DialogDescription>
                            По умолчанию доступ берётся из позиции. Здесь его можно выдать этому сотруднику отдельно или снять с него — решение
                            сохраняется сразу.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="scroll-soft -mx-4 max-h-[60vh] overflow-y-auto px-4 sm:-mx-6 sm:px-6">
                        {/* Sections kept list by list (fields, equipment, directories) have no single right to override here. */}
                        {access.sections
                            .filter((section) => section.rights.length > 0)
                            .map((section) => (
                                <div key={section.key} className="border-t py-3 first:border-t-0 first:pt-0">
                                    <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">{section.title}</p>
                                    <ul className="grid gap-2">
                                        {section.rights.map((right) => {
                                            const state = access.rights.find((row) => row.key === right.key);
                                            const value = state?.override === true ? 'yes' : state?.override === false ? 'no' : 'position';

                                            return (
                                                <li key={right.key} className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="min-w-0">
                                                        <span className="text-sm">{right.title}</span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {state?.position ? 'Роль даёт этот доступ' : 'Роль этот доступ не даёт'}
                                                        </span>
                                                    </span>
                                                    <ToggleGroup
                                                        className="w-full sm:w-auto"
                                                        type="single"
                                                        variant="outline"
                                                        size="sm"
                                                        value={value}
                                                        onValueChange={(next) => next && set(right.key, next === 'position' ? null : next === 'yes')}
                                                        aria-label={right.title}
                                                    >
                                                        <ToggleGroupItem value="position" className={toggleItem}>
                                                            По позиции
                                                        </ToggleGroupItem>
                                                        <ToggleGroupItem value="yes" className={toggleItem}>
                                                            Разрешить
                                                        </ToggleGroupItem>
                                                        <ToggleGroupItem value="no" className={toggleItem}>
                                                            Запретить
                                                        </ToggleGroupItem>
                                                    </ToggleGroup>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setOpen(false)}>
                            Готово
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** On a phone the three choices split the row between them; from `sm` they sit at their own width. */
const toggleItem = 'h-auto min-h-8 flex-1 px-1 text-xs whitespace-nowrap sm:flex-none sm:px-2.5 sm:text-sm';

/** The area left under the tabs, split into a main column and a sidebar. */
const paneGrid = 'grid gap-4 max-md:gap-4 lg:grid-cols-[1fr_24rem]';

/** One column of that area. */
const pane = 'flex min-w-0 flex-col gap-4 max-md:gap-4';

/**
 * Contacts are dialled and written to, so they carry the brand colour and an
 * icon rather than looking like the plain text of the fields around them.
 */
const contactLink = 'text-brand-strong flex items-center gap-1.5 hover:underline max-md:justify-end dark:text-[#C5E27A]';

/** A badge that may break onto a second line rather than run off a phone screen. */
const wrappingBadge = 'h-auto min-h-[22px] max-w-full py-0.5 whitespace-normal';

/** The "Контакты" card in a form; the server normalises the phones to E.164. */
function ContactsDialog({ employee, details, onClose }: { employee: Employee; details: ProfilePrivate; onClose: () => void }) {
    const form = useForm({
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
                                <Label htmlFor="contacts-email">Электронная почта</Label>
                                <Input
                                    id="contacts-email"
                                    type="email"
                                    autoComplete="off"
                                    placeholder="name@evolet.tj"
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
                                <Label htmlFor="contacts-sos-phone">Телефон SOS</Label>
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

                    <DialogFooter className="gap-2">
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
        <Section
            title="KPI сотрудника"
            action={
                <Button variant="link" className="text-brand-strong h-auto p-0 text-xs font-normal dark:text-[#C5E27A]">
                    Все периоды
                </Button>
            }
        >
            <div className="grid gap-6 max-md:py-4 md:grid-cols-[minmax(0,14rem)_minmax(0,1fr)] md:gap-8">
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
                <div className="min-w-0 md:border-l md:pl-8">
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
        </Section>
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
        <Section
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
        </Section>
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
        <Section
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
        </Section>
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
        <Section
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
        </Section>
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
        <Section
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
        </Section>
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
    onClose,
}: {
    employee: Employee;
    details: ProfilePrivate | null;
    options: EditOptions;
    assigned: Assigned;
    onClose: () => void;
}) {
    const canEdit = useCanEdit();
    // The jobs are one answer and two lines of the card, so they are offered
    // only where both are this viewer's to change: half a placement would file
    // somebody in a department the save is not allowed to write.
    const places = canEdit('positions') && canEdit('departments');

    const form = useForm({
        placements: assigned.placements,
        employee_number: employee.employee_number ?? '',
        employment_type: employee.employment_type ?? '',
        branch: employee.branch ?? '',
        birth_date: details?.birth_date ?? '',
    });

    // The card is edited in several windows and they share its lines out, so a
    // line this one did not show is one it does not send — and the server, in
    // turn, leaves alone whatever it was not sent.
    const dates = canEdit('birth_date') && details !== null;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...(places ? { placements: data.placements } : {}),
            ...(canEdit('employee_number') ? { employee_number: data.employee_number } : {}),
            ...(canEdit('employment_type') ? { employment_type: data.employment_type } : {}),
            ...(canEdit('branch') ? { branch: data.branch } : {}),
            ...(dates ? { birth_date: data.birth_date } : {}),
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
     * One of the two short lists, as its box offers it: what is on the list,
     * whatever the card already says — an entry since renamed or deleted is
     * still the answer on file — and an empty line, since neither is required.
     */
    const named = (list: string[], value: string) => [
        { value: '', label: 'Не указано' },
        ...[...new Set([...list, ...(value === '' ? [] : [value])])]
            .sort((a, b) => a.localeCompare(b, 'ru'))
            .map((name) => ({ value: name, label: name })),
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
                        {places && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor="main-positions">Должность</Label>
                                <MultiSelect
                                    id="main-positions"
                                    options={options.positions
                                        .filter((post) => post.department_id !== null)
                                        .map((post) => ({ value: post.id, label: post.display_name }))}
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
                                />
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
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    step={1}
                                    value={form.data.employee_number}
                                    onChange={(e) => form.setData('employee_number', e.target.value)}
                                    aria-invalid={!!form.errors.employee_number}
                                />
                                <InputError message={form.errors.employee_number} />
                                <p className="text-muted-foreground text-[13px]">Не меньше пяти цифр; можно оставить пустым.</p>
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

                        {canEdit('branch') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="main-branch">Филиал</Label>
                                <SearchableSelect
                                    id="main-branch"
                                    options={named(options.branches, form.data.branch)}
                                    value={form.data.branch}
                                    onChange={(value) => form.setData('branch', value)}
                                    onCreate={(value) => form.setData('branch', value)}
                                    placeholder="Выберите или впишите"
                                    searchPlaceholder="Филиал"
                                    invalid={!!form.errors.branch}
                                />
                                <InputError message={form.errors.branch} />
                            </div>
                        )}

                        {/* Nothing keeps it yet, so it says the same word every
                            card says and cannot be answered differently. */}
                        <div className="grid content-start gap-2">
                            <Label htmlFor="main-probation">Испытательный срок</Label>
                            <Input id="main-probation" readOnly value="Пройден" className="bg-muted/40" />
                        </div>

                        {dates && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="main-birth-date">Дата рождения</Label>
                                {/* Nobody is born tomorrow. */}
                                <DatePicker
                                    id="main-birth-date"
                                    value={form.data.birth_date}
                                    onChange={(value) => form.setData('birth_date', value)}
                                    toDate={new Date()}
                                    invalid={!!form.errors.birth_date}
                                />
                                <InputError message={form.errors.birth_date} />
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2">
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

/** The hire date on its own; tenure follows from it and is not stored. */
function EmploymentDialog({ employee, details, onClose }: { employee: Employee; details: ProfilePrivate; onClose: () => void }) {
    const form = useForm({ hired_at: details.hired_at ?? '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('employees.employment', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-sm">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Начало работы</DialogTitle>
                        <DialogDescription className="sr-only">Измените дату и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="employment-hired-at">Дата приёма</Label>
                        <Input
                            id="employment-hired-at"
                            type="date"
                            max={new Date().toISOString().slice(0, 10)}
                            value={form.data.hired_at}
                            onChange={(e) => form.setData('hired_at', e.target.value)}
                            aria-invalid={!!form.errors.hired_at}
                        />
                        <InputError message={form.errors.hired_at} />
                        <p className="text-muted-foreground text-[13px]">Стаж считается от неё.</p>
                    </div>

                    <DialogFooter className="gap-2">
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
    const form = useForm({
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

                    <DialogFooter className="gap-2">
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

type EducationRecord = ProfilePrivate['educations'][number];

/** One place of study, added or changed on its own. */
function EducationDialog({ employee, education, onClose }: { employee: Employee; education: EducationRecord | 'new'; onClose: () => void }) {
    const existing = education === 'new' ? null : education;
    const form = useForm({
        institution: existing?.institution ?? '',
        faculty: existing?.faculty ?? '',
        specialty: existing?.specialty ?? '',
        started_year: existing ? String(existing.started_year) : '',
        graduated_year: existing?.graduated_year ? String(existing.graduated_year) : '',
        diploma_number: existing?.diploma_number ?? '',
    });

    const thisYear = new Date().getFullYear();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };

        if (existing) form.put(route('employees.educations.update', [employee.id, existing.id]), options);
        else form.post(route('employees.educations.store', employee.id), options);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? 'Изменить образование' : 'Новое образование'}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="education-institution">Учебное заведение</Label>
                            <Input
                                id="education-institution"
                                autoFocus
                                value={form.data.institution}
                                onChange={(e) => form.setData('institution', e.target.value)}
                                aria-invalid={!!form.errors.institution}
                            />
                            <InputError message={form.errors.institution} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="education-faculty">Факультет</Label>
                            <Input
                                id="education-faculty"
                                value={form.data.faculty}
                                onChange={(e) => form.setData('faculty', e.target.value)}
                                aria-invalid={!!form.errors.faculty}
                            />
                            <InputError message={form.errors.faculty} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="education-specialty">Специальность</Label>
                            <Input
                                id="education-specialty"
                                value={form.data.specialty}
                                onChange={(e) => form.setData('specialty', e.target.value)}
                                aria-invalid={!!form.errors.specialty}
                            />
                            <InputError message={form.errors.specialty} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="education-started">Год поступления</Label>
                            <Input
                                id="education-started"
                                type="number"
                                inputMode="numeric"
                                min={1950}
                                max={thisYear}
                                value={form.data.started_year}
                                onChange={(e) => form.setData('started_year', e.target.value)}
                                aria-invalid={!!form.errors.started_year}
                            />
                            <InputError message={form.errors.started_year} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="education-graduated">Год окончания</Label>
                            <Input
                                id="education-graduated"
                                type="number"
                                inputMode="numeric"
                                min={1950}
                                max={thisYear + 10}
                                placeholder="Пусто — ещё учится"
                                value={form.data.graduated_year}
                                onChange={(e) => form.setData('graduated_year', e.target.value)}
                                aria-invalid={!!form.errors.graduated_year}
                            />
                            <InputError message={form.errors.graduated_year} />
                        </div>

                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="education-diploma">Номер диплома</Label>
                            <Input
                                id="education-diploma"
                                value={form.data.diploma_number}
                                onChange={(e) => form.setData('diploma_number', e.target.value)}
                                aria-invalid={!!form.errors.diploma_number}
                            />
                            <InputError message={form.errors.diploma_number} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
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

type JobRecord = ProfilePrivate['work_experiences'][number];

/** One previous job, added or changed on its own. */
function WorkExperienceDialog({
    employee,
    job,
    options,
    onClose,
}: {
    employee: Employee;
    job: JobRecord | 'new';
    options: EditOptions;
    onClose: () => void;
}) {
    const existing = job === 'new' ? null : job;
    const form = useForm({
        organization: existing?.organization ?? '',
        position: existing?.position ?? '',
        country: existing?.country ?? '',
        started_month: existing ? String(existing.started_month) : '',
        started_year: existing ? String(existing.started_year) : '',
        ended_month: existing?.ended_month ? String(existing.ended_month) : '',
        ended_year: existing?.ended_year ? String(existing.ended_year) : '',
    });

    const thisYear = new Date().getFullYear();
    const countries = [...new Set(['Таджикистан', ...options.countries])];

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        const send = { preserveScroll: true, onSuccess: onClose };

        if (existing) form.put(route('employees.experiences.update', [employee.id, existing.id]), send);
        else form.post(route('employees.experiences.store', employee.id), send);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{existing ? 'Изменить место работы' : 'Новое место работы'}</DialogTitle>
                        <DialogDescription className="sr-only">Заполните поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <datalist id="job-countries">
                        {countries.map((country) => (
                            <option key={country} value={country} />
                        ))}
                    </datalist>

                    <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="job-organization">Организация</Label>
                            <Input
                                id="job-organization"
                                autoFocus
                                value={form.data.organization}
                                onChange={(e) => form.setData('organization', e.target.value)}
                                aria-invalid={!!form.errors.organization}
                            />
                            <InputError message={form.errors.organization} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="job-position">Должность</Label>
                            <Input
                                id="job-position"
                                value={form.data.position}
                                onChange={(e) => form.setData('position', e.target.value)}
                                aria-invalid={!!form.errors.position}
                            />
                            <InputError message={form.errors.position} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="job-country">Страна</Label>
                            <Input
                                id="job-country"
                                list="job-countries"
                                value={form.data.country}
                                onChange={(e) => form.setData('country', e.target.value)}
                                aria-invalid={!!form.errors.country}
                            />
                            <InputError message={form.errors.country} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="job-started-month">Вступление</Label>
                            <div className="grid grid-cols-[1fr_4.5rem] gap-2 sm:grid-cols-[1fr_6rem]">
                                <Select value={form.data.started_month} onValueChange={(value) => form.setData('started_month', value)}>
                                    <SelectTrigger id="job-started-month" aria-label="Месяц вступления">
                                        <SelectValue placeholder="Месяц" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {monthNames.map((month, index) => (
                                            <SelectItem key={month} value={String(index + 1)}>
                                                {month}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    aria-label="Год вступления"
                                    placeholder="Год"
                                    min={1950}
                                    max={thisYear}
                                    value={form.data.started_year}
                                    onChange={(e) => form.setData('started_year', e.target.value)}
                                    aria-invalid={!!form.errors.started_year}
                                />
                            </div>
                            <InputError message={form.errors.started_month ?? form.errors.started_year} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="job-ended-month">Уход</Label>
                            <div className="grid grid-cols-[1fr_4.5rem] gap-2 sm:grid-cols-[1fr_6rem]">
                                {/* Clearing the month clears the year too: half a date means nothing. */}
                                <Select
                                    value={form.data.ended_month || 'none'}
                                    onValueChange={(value) =>
                                        form.setData((data) => ({
                                            ...data,
                                            ended_month: value === 'none' ? '' : value,
                                            ended_year: value === 'none' ? '' : data.ended_year,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="job-ended-month" aria-label="Месяц ухода">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Работает сейчас</SelectItem>
                                        {monthNames.map((month, index) => (
                                            <SelectItem key={month} value={String(index + 1)}>
                                                {month}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Input
                                    type="number"
                                    inputMode="numeric"
                                    aria-label="Год ухода"
                                    placeholder="Год"
                                    min={1950}
                                    max={thisYear}
                                    disabled={!form.data.ended_month}
                                    value={form.data.ended_year}
                                    onChange={(e) => form.setData('ended_year', e.target.value)}
                                    aria-invalid={!!form.errors.ended_year}
                                />
                            </div>
                            <InputError message={form.errors.ended_month ?? form.errors.ended_year} />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
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
                <DialogFooter className="gap-2">
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
function Section({
    title,
    children,
    className,
    action,
    fields,
}: {
    title?: string;
    /** What stands in the block; a block still being designed holds nothing. */
    children?: ReactNode;
    className?: string;
    action?: ReactNode;
    /** The fields this block is made of; without any of them there is no block. */
    fields?: string[];
}) {
    const shows = useShows();

    if (fields !== undefined && !fields.some(shows)) {
        return null;
    }

    // On a phone the block is an inset grouped list: the title and the action
    // sit above the card, as a native settings screen has them, and the card
    // holds nothing but rows. From `md` up the wrapper steps aside (contents)
    // and the card with its header strip is exactly what it was.
    return (
        <div className="flex min-w-0 flex-col md:contents">
            {title && (
                <div className="mb-1.5 flex min-h-6 flex-wrap items-end justify-between gap-x-3 gap-y-1 px-1 md:hidden">
                    <h2 className="text-muted-foreground min-w-0 text-[13px] font-semibold tracking-wide uppercase">{title}</h2>
                    {action}
                </div>
            )}
            <Card
                className={cn(
                    'flex flex-col gap-4 rounded-xl px-4 py-5 max-md:gap-0 max-md:rounded-2xl max-md:border-0 max-md:py-0 max-md:shadow-none md:px-6',
                    className,
                )}
            >
                {title && (
                    // Flush to the card's edges, so the strip reads as its header.
                    <div className="bg-muted/60 -mx-4 -mt-5 flex min-h-11 flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-t-xl border-b px-4 py-2 max-md:hidden md:-mx-6 md:px-6">
                        <h2 className="min-w-0 text-base font-semibold">{title}</h2>
                        {action}
                    </div>
                )}
                {children}
            </Card>
        </div>
    );
}

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

/**
 * "Add a record" under a list. On a phone it is a row of its own in the brand
 * colour across the whole width, as a native list ends with "Add…"; from `md`
 * up it stays an ordinary outlined button.
 */
const addRecord =
    'self-start max-md:h-12 max-md:self-stretch max-md:justify-start max-md:rounded-2xl max-md:border-0 max-md:bg-card max-md:px-4 max-md:text-[15px] max-md:font-normal max-md:text-brand-strong max-md:shadow-none max-md:dark:bg-card max-md:dark:text-[#C5E27A]';

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
 * Which fields of this card the viewer reads. A position is given the lines it
 * needs, so a card is drawn from the answer rather than from one flag: whatever
 * is not on the list is not shown, and a block with nothing left on it is not
 * shown either.
 */
const VisibleFields = createContext<string[] | null>(null);

function useShows(): (field?: string) => boolean {
    const visible = useContext(VisibleFields);

    // No list means nothing to hide — a form that renders outside the card.
    return (field) => field === undefined || visible === null || visible.includes(field);
}

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
    // «Основное»: where somebody stands, the number they are filed under, the
    // terms they work on and when they were born.
    personal: ['positions', 'departments', 'employee_number', 'employment_type', 'branch', 'birth_date'],
    passport: ['passport_number', 'passport_issued_at', 'passport_issued_by'],
    contacts: ['email', 'phone', 'sos_phone'],
    family: ['marital_status', 'spouse', 'children'],
    languages: ['languages'],
    employment: ['hired_at'],
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

/** "2010–2015", or "2019 — учится" for someone still enrolled. */
const studyYears = (education: Education) =>
    education.graduated_year ? `${education.started_year}–${education.graduated_year}` : `${education.started_year} — учится`;

function Educations({
    items,
    canEdit,
    onEdit,
    onDelete,
}: {
    items: ProfilePrivate['educations'];
    canEdit: boolean;
    onEdit: (education: ProfilePrivate['educations'][number]) => void;
    onDelete: (education: ProfilePrivate['educations'][number]) => void;
}) {
    return (
        <ul className="flex flex-col">
            {items.map((education) => (
                <li key={education.id} className={cn(recordRow, 'flex items-start gap-3')}>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5 break-words">
                        <span className="text-sm font-medium max-md:text-[15px]">{education.institution}</span>
                        <span className="text-sm">
                            {education.faculty} · {education.specialty}
                        </span>
                        <span className="text-muted-foreground text-[13px] tabular-nums">
                            {studyYears(education)}
                            {education.diploma_number && ` · диплом № ${education.diploma_number}`}
                        </span>
                    </div>
                    {canEdit && <RowActions onEdit={() => onEdit(education)} onDelete={() => onDelete(education)} what="образование" />}
                </li>
            ))}
        </ul>
    );
}

/** The pencil and bin that sit at the end of one record in a list. */
function RowActions({ onEdit, onDelete, what }: { onEdit: () => void; onDelete: () => void; what: string }) {
    return (
        <div className="flex shrink-0 gap-1">
            <Button variant="ghost" size="icon" className="text-muted-foreground size-9 lg:size-7" aria-label={`Изменить ${what}`} onClick={onEdit}>
                <Pencil className="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="size-9 text-[#B42318] hover:text-[#B42318] lg:size-7 dark:text-[#F7A19A]"
                aria-label={`Удалить ${what}`}
                onClick={onDelete}
            >
                <Trash2 className="size-4" />
            </Button>
        </div>
    );
}

/** "Март 2018 — Июнь 2021 · 3 года 3 мес." */
function workPeriod(job: WorkExperience): string {
    const now = new Date();
    const [endYear, endMonth] = job.ended_year && job.ended_month ? [job.ended_year, job.ended_month] : [now.getFullYear(), now.getMonth() + 1];
    const end = job.ended_year && job.ended_month ? `${monthNames[job.ended_month - 1]} ${job.ended_year}` : 'по настоящее время';

    return `${monthNames[job.started_month - 1]} ${job.started_year} — ${end} · ${monthsSpan(job.started_year, job.started_month, endYear, endMonth)}`;
}

function WorkExperiences({
    items,
    canEdit,
    onEdit,
    onDelete,
}: {
    items: ProfilePrivate['work_experiences'];
    canEdit: boolean;
    onEdit: (job: JobRecord) => void;
    onDelete: (job: JobRecord) => void;
}) {
    return (
        <ul className="flex flex-col">
            {items.map((job) => (
                <li key={job.id} className={cn(recordRow, 'flex items-start gap-3')}>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5 break-words">
                        <span className="text-sm font-medium max-md:text-[15px]">{job.position}</span>
                        <span className="text-sm">
                            {job.organization} · {job.country}
                        </span>
                        <span className="text-muted-foreground text-[13px] tabular-nums">{workPeriod(job)}</span>
                    </div>
                    {canEdit && <RowActions onEdit={() => onEdit(job)} onDelete={() => onDelete(job)} what="место работы" />}
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
        <Section title="Журнал">
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
        </Section>
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
    const form = useForm({ equipment: [] as number[], issued_at: today });
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
                                    placeholder="Ничего не выбрано"
                                    searchPlaceholder="Поиск по названию или номеру"
                                />
                                <InputError message={form.errors.equipment} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="issue-date">Дата выдачи</Label>
                                <Input
                                    id="issue-date"
                                    type="date"
                                    max={today}
                                    value={form.data.issued_at}
                                    onChange={(e) => form.setData('issued_at', e.target.value)}
                                    aria-invalid={!!form.errors.issued_at}
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

                    <DialogFooter className="gap-2">
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
 * What the person holds right now, and what can be done about it from here:
 * this card is where somebody notices that the thing is back on the desk or past
 * saving, so a unit is taken back and struck off from the row itself, through
 * the same forms its own card opens. Each move shows only where it is this
 * viewer's to make on that very unit.
 */
function EquipmentList({ items }: { items: ProfilePrivate['equipment'] }) {
    /** Which unit a move was picked on, and which move, until the form closes. */
    const [asking, setAsking] = useState<{ unit: HeldUnit; kind: AskedMove } | null>(null);

    return (
        <>
            <ul className="flex flex-col">
                {items.map((unit) => (
                    <li key={unit.id} className={cn(recordRow, 'flex items-start gap-3')}>
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5 break-words">
                            {/* The unit's own card is a matter of the fleet, not of this card. */}
                            {unit.open ? (
                                <Link href={route('equipment.show', unit.id)} className="text-sm font-medium hover:underline">
                                    {unit.name}
                                </Link>
                            ) : (
                                <span className="text-sm font-medium">{unit.name}</span>
                            )}
                            <span className="text-muted-foreground text-[13px]">{[unit.type, unit.details].filter(Boolean).join(' · ')}</span>
                            <span className="text-muted-foreground text-[13px] tabular-nums">
                                Инв. № {unit.inventory_number}
                                {unit.issued_at && ` · выдано ${formatDate(unit.issued_at)}`}
                            </span>
                        </div>

                        {/* Never squeezed: a long name wraps in the column beside
                            this one rather than pushing the icons off a phone. */}
                        {(unit.can_take || unit.can_write_off) && (
                            <div className="flex shrink-0 gap-1">
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
        <div className="flex w-full gap-1 max-md:hidden sm:w-auto md:ml-auto">
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
    options,
    assigned,
    access,
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
    /** Why the positions of this card are locked, or null when they are not. */

    /** Choices for the edit dialogs; null for viewers who may not edit. */
    options: EditOptions | null;
    assigned: Assigned | null;

    /** Rights and personal exceptions; only a system administrator is sent these. */
    access: AccessPicture | null;
    /** Which lines of this card the viewer reads; everything else is not drawn. */
    visibleFields: string[];
    /** Which of them they may change; the rest are shown without a pencil. */
    editableFields: string[];
}) {
    const [editing, setEditing] = useState<'personal' | 'passport' | 'contacts' | 'languages' | 'employment' | 'family' | null>(null);
    // Whoever leads the departments this person is filed in, each named once:
    // somebody in two units of one department answers to one person, not two.
    const heads = [...new Map((employee.departments ?? []).flatMap((department) => department.heads).map((head) => [head.id, head])).values()];
    // Education is edited one record at a time, so these hold a record, not a card name.
    const [education, setEducation] = useState<EducationRecord | 'new' | null>(null);
    const [deletingEducation, setDeletingEducation] = useState<EducationRecord | null>(null);
    const [job, setJob] = useState<JobRecord | 'new' | null>(null);
    const [deletingJob, setDeletingJob] = useState<JobRecord | null>(null);
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
                <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:gap-6 md:px-4 md:py-6 lg:px-6">
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
                            {((employee.roles ?? []).length > 0 || (employee.positions ?? []).length > 0) && (
                                <div className="flex flex-wrap gap-2 max-md:justify-center max-md:gap-1.5">
                                    {(employee.roles ?? []).map((title) => (
                                        <StatusBadge key={title} tone="info" className={wrappingBadge}>
                                            {title}
                                        </StatusBadge>
                                    ))}
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
                                            <Link
                                                key={position.id}
                                                href={route('positions.show', position.id)}
                                                title={`Открыть должность: ${positionTitle(position)}`}
                                                className="rounded-md transition-[filter] hover:brightness-95 dark:hover:brightness-125"
                                            >
                                                {badge}
                                            </Link>
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
                        {neighbours !== null && <Neighbours prev={neighbours.prev} next={neighbours.next} tab={tab} />}
                    </div>

                    <ProfileTabs tabs={tabs} active={tab} onChange={setTab} />

                    {/* One card per tab. */}
                    {tab !== 'profile' && (
                        <div className="flex flex-col gap-4">
                            {tab === 'education' && details && (
                                <>
                                    <Section>
                                        {details.educations.length === 0 ? (
                                            <p className={emptyNote}>Не указано</p>
                                        ) : (
                                            <Educations
                                                items={details.educations}
                                                canEdit={canEditBlock('education')}
                                                onEdit={setEducation}
                                                onDelete={setDeletingEducation}
                                            />
                                        )}
                                    </Section>

                                    {/* Outside the card: the card is the list, this adds to it. */}
                                    {canEditBlock('education') && (
                                        <Button type="button" variant="outline" className={addRecord} onClick={() => setEducation('new')}>
                                            <Plus />
                                            Добавить образование
                                        </Button>
                                    )}
                                </>
                            )}

                            {tab === 'experience' && details && (
                                <>
                                    <Section>
                                        {details.work_experiences.length === 0 ? (
                                            <p className={emptyNote}>Не указана</p>
                                        ) : (
                                            <WorkExperiences
                                                items={details.work_experiences}
                                                canEdit={canEditBlock('experience')}
                                                onEdit={setJob}
                                                onDelete={setDeletingJob}
                                            />
                                        )}
                                    </Section>

                                    {canEditBlock('experience') && (
                                        <Button type="button" variant="outline" className={addRecord} onClick={() => setJob('new')}>
                                            <Plus />
                                            Добавить место работы
                                        </Button>
                                    )}
                                </>
                            )}

                            {tab === 'equipment' && details && (
                                // What they hold now on the left, what has been
                                // through their hands on the right.
                                <div className={paneGrid}>
                                    <div className={pane}>
                                        <Section
                                            title="Текущие оборудования"
                                            action={
                                                canIssue && (
                                                    <button type="button" className={stripAction} onClick={() => setIssuing(true)}>
                                                        <Plus className="size-4" />
                                                        Выдать
                                                    </button>
                                                )
                                            }
                                        >
                                            {details.equipment.length === 0 ? (
                                                <p className={emptyNote}>Не выдано</p>
                                            ) : (
                                                <EquipmentList items={details.equipment} />
                                            )}
                                        </Section>
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
                                    <Section
                                        title="Основное"
                                        action={
                                            canEditBlock('personal') && (
                                                <EditButton label="Редактировать основное" onClick={() => setEditing('personal')} />
                                            )
                                        }
                                    >
                                        <Facts>
                                            <Fact label="Отдел" field="departments">
                                                {(employee.departments ?? []).length > 0 && (
                                                    <span className="flex flex-col gap-1">
                                                        {(employee.departments ?? []).map((department) => (
                                                            // As wide as the chain it names: a link the
                                                            // width of the column would be a line of
                                                            // empty space to click on.
                                                            <Link
                                                                key={department.id}
                                                                href={route('departments.show', department.id)}
                                                                className="w-max hover:underline"
                                                            >
                                                                <DepartmentName name={department.path} full={department.full_path} />
                                                            </Link>
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
                                                                <Link
                                                                    key={position.id}
                                                                    href={route('positions.show', position.id)}
                                                                    className="w-max hover:underline"
                                                                >
                                                                    <PositionName name={position.name} unit={position.unit} />
                                                                </Link>
                                                            ),
                                                        )}
                                                    </span>
                                                )}
                                            </Fact>

                                            <Fact label="Руководитель" field="departments">
                                                {heads.length > 0 && (
                                                    <span className="flex flex-col gap-1">
                                                        {heads.map((head) => (
                                                            <Link
                                                                key={head.id}
                                                                href={route('employees.show', head.id)}
                                                                className="w-max hover:underline"
                                                            >
                                                                {head.name}
                                                            </Link>
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

                                            <Fact label="Филиал" field="branch">
                                                {employee.branch}
                                            </Fact>

                                            {/* Nothing keeps it yet: every card says the same word
                                                until there is somewhere to write the answer. */}
                                            <Fact label="Испытательный срок">Пройден</Fact>

                                            <Fact label="Дата рождения" field="birth_date">
                                                {details.birth_date && (
                                                    <span className="tabular-nums">
                                                        {formatDate(details.birth_date)}
                                                        <span className="text-muted-foreground font-normal"> · {age(details.birth_date)}</span>
                                                    </span>
                                                )}
                                            </Fact>
                                        </Facts>
                                    </Section>

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
                                </div>

                                <aside className={pane}>
                                    {/*
                                     * A block like the ones under it: a card with its name on
                                     * the strip and its action at the end of that strip. It
                                     * used to stand bare at the top of this column, the only
                                     * thing here without a frame, which read as a leftover of
                                     * the card above rather than a block of its own.
                                     */}
                                    <Section
                                        fields={['hired_at']}
                                        title="Работа в компании"
                                        action={
                                            canEditBlock('employment') && (
                                                <EditButton label="Редактировать начало работы" onClick={() => setEditing('employment')} />
                                            )
                                        }
                                    >
                                        <Fields columns={2}>
                                            <Field label="Начало работы" field="hired_at">
                                                {formatDate(details.hired_at)}
                                            </Field>
                                            <Field label="Стаж в компании" field="hired_at">
                                                {details.hired_at && (
                                                    <span className="text-brand-strong dark:text-[#C5E27A]">{tenure(details.hired_at)}</span>
                                                )}
                                            </Field>
                                        </Fields>
                                    </Section>

                                    <Section
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
                                    </Section>

                                    <Section
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
                                            <Field label="Телефон SOS" field="sos_phone">
                                                {details.sos_phone && <SosPhone phone={details.sos_phone} contact={details.sos_contact} />}
                                            </Field>
                                        </Fields>
                                    </Section>

                                    {access && <AccessSection employee={employee} access={access} />}

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
                                        it is whatever the access page says, line by line. */}
                                        <p>Личные данные, контакты, паспорт и семья закрыты.</p>
                                    </Card>
                                </div>

                                {/* Hire date, phones and the rest are private, so a colleague's sidebar
                            holds languages alone — those are public. */}
                                <aside className={pane}>
                                    <Section
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
                                    </Section>
                                </aside>
                            </div>
                        ))}
                </div>

                {/* Not gated on the private lines: the block may be open to this
                    viewer by its public ones alone. */}
                {editing === 'personal' && options && assigned && (
                    <MainDialog employee={employee} details={details} options={options} assigned={assigned} onClose={() => setEditing(null)} />
                )}

                {editing === 'contacts' && details && <ContactsDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

                {editing === 'employment' && details && <EmploymentDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

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

                {education && <EducationDialog employee={employee} education={education} onClose={() => setEducation(null)} />}

                {job && options && <WorkExperienceDialog employee={employee} job={job} options={options} onClose={() => setJob(null)} />}

                {deletingJob && (
                    <DeleteRecordDialog
                        title={`Удалить место работы «${deletingJob.organization}»?`}
                        description="Запись исчезнет из профиля. Отменить удаление нельзя."
                        onConfirm={() =>
                            router.delete(route('employees.experiences.destroy', [employee.id, deletingJob.id]), {
                                preserveScroll: true,
                                onFinish: () => setDeletingJob(null),
                            })
                        }
                        onClose={() => setDeletingJob(null)}
                    />
                )}

                {deletingEducation && (
                    <DeleteRecordDialog
                        title={`Удалить образование «${deletingEducation.institution}»?`}
                        description="Запись исчезнет из профиля. Отменить удаление нельзя."
                        onConfirm={() =>
                            router.delete(route('employees.educations.destroy', [employee.id, deletingEducation.id]), {
                                preserveScroll: true,
                                onFinish: () => setDeletingEducation(null),
                            })
                        }
                        onClose={() => setDeletingEducation(null)}
                    />
                )}
            </AppLayout>
        </ProfileFields>
    );
}
