import { CitizenshipBadges } from '@/components/citizenship-badges';
import { EmployeeActions } from '@/components/employee-actions';
import { ChangeLines } from '@/components/equipment-changes';
import InputError from '@/components/input-error';
import { LevelBadge } from '@/components/language-badges';
import { MultiSelect } from '@/components/multi-select';
import { PersonAvatar } from '@/components/person-avatar';
import { SosPhone } from '@/components/phones';
import { Photos, type Photo } from '@/components/photo-viewer';
import { SearchableSelect } from '@/components/searchable-select';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { seesEquipment, useCan } from '@/lib/access';
import {
    age,
    capitalize,
    formatDate,
    formatPhone,
    languageLevels,
    maritalLabels,
    monthNames,
    monthsSpan,
    sexLabels,
    tenure,
    type Education,
    type Equipment,
    type LanguageLevel,
    type Marital,
    type PrivateDetails,
    type Sex,
    type SpokenLanguage,
    type WorkExperience,
} from '@/lib/employee';
import { eventLabel, eventTone, type EventChanges, type EventKind, type NameLookup } from '@/lib/equipment';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Camera, ChevronLeft, ChevronRight, Laptop, LoaderCircle, Lock, Mail, Pencil, Phone, Plus, Trash2, Upload } from 'lucide-react';
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

interface ProfilePrivate extends PrivateDetails {
    educations: (Education & { id: number })[];
    /** The latest first. */
    work_experiences: (WorkExperience & { id: number })[];
    /** Grouped by kind, which is resolved to its directory name here. */
    equipment: (Equipment & { id: number })[];
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
    /** Access roles, shown as "Позиция". */
    roles?: string[];
    /** Positions, shown as "Должность"; an employee can hold several. */
    positions?: string[];
    departments?: { id: number; name: string; path: string; is_head: boolean }[];
    /** The best known first. */
    languages?: SpokenLanguage[];
    /** Null when the viewer may not see this person's private data. */
    private: ProfilePrivate | null;
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
            window.history.replaceState(null, '', key === 'profile' ? window.location.pathname : `#${key}`);
        },
    ];
}

function Tabs({ tabs, active, onChange }: { tabs: readonly { key: TabKey; title: string }[]; active: TabKey; onChange: (key: TabKey) => void }) {
    return (
        // One strip that scrolls sideways on a phone: wrapped, the underline of the
        // open tab would sit between two rows and read as belonging to neither.
        // On a phone the strip also stays under the top bar while the card scrolls,
        // on a frosted band that runs from edge to edge of the screen.
        <nav
            aria-label="Разделы профиля"
            className="max-md:bg-background/90 mt-1 flex gap-x-6 overflow-x-auto [scrollbar-width:none] max-md:sticky max-md:top-12 max-md:z-20 max-md:-mx-3 max-md:mt-0 max-md:gap-x-5 max-md:border-b max-md:px-4 max-md:backdrop-blur-xl lg:mt-3 [&::-webkit-scrollbar]:hidden"
        >
            {tabs.map((tab) => (
                <button
                    key={tab.key}
                    type="button"
                    onClick={() => onChange(tab.key)}
                    aria-current={tab.key === active ? 'page' : undefined}
                    className={cn(
                        'shrink-0 border-b-2 px-1 pt-2 pb-2.5 text-sm whitespace-nowrap transition-colors max-md:pt-3 lg:pt-0',
                        tab.key === active
                            ? 'border-brand text-foreground font-semibold'
                            : 'text-muted-foreground hover:text-foreground border-transparent font-medium',
                    )}
                >
                    {tab.title}
                </button>
            ))}
        </nav>
    );
}

interface EditOptions {
    nationalities: string[];
    citizenships: string[];
    /** Access roles, chosen by name. */
    roles: { name: string; title: string }[];
    positions: { id: number; name: string }[];
    /** Flattened tree; `depth` indents the children. */
    departments: { id: number; name: string; depth: number }[];
    languages: { id: number; name: string }[];
    /** Countries already on file, as suggestions for a previous job. */
    countries: string[];
    equipment_types: { id: number; name: string }[];
}

/** What the employee currently holds, as the dialog addresses it. */
interface Assigned {
    roles: string[];
    positions: number[];
    departments: number[];
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
                                                            {state?.position ? 'Позиция даёт этот доступ' : 'Позиция этот доступ не даёт'}
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

/** The "Основные данные" card in a form; sex sits on the user, the rest on the details. */
function PersonalDialog({
    rolesLocked,
    employee,
    details,
    options,
    assigned,
    onClose,
}: {
    employee: Employee;
    /**
     * Null when no private line of this card is open to the viewer — the form is
     * still worth opening, because the public lines of the block may be theirs to
     * change and the dialog shows only what they may save anyway.
     */
    details: ProfilePrivate | null;
    options: EditOptions;
    assigned: Assigned;
    /** Why the positions are not this viewer's to change, or null when they are. */
    rolesLocked: string | null;
    onClose: () => void;
}) {
    // This card is made of lines that are allowed one by one, so the form shows
    // only the ones this viewer may actually save.
    const canEdit = useCanEdit();

    const form = useForm({
        surname: employee.surname,
        name: employee.name,
        patronymic: employee.patronymic ?? '',
        sex: employee.sex,
        birth_date: details?.birth_date ?? '',
        birth_place: details?.birth_place ?? '',
        citizenship: details?.citizenship ?? [],
        nationality: details?.nationality ?? '',
        home_address: details?.home_address ?? '',
        roles: assigned.roles,
        positions: assigned.positions,
        departments: assigned.departments,
    });

    /** The first error for a list and its items ("roles", "roles.0", ...). */
    const listError = (key: string) => {
        const errors = form.errors as Record<string, string | undefined>;

        return errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('employees.personal', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: the browser's own bubbles would pre-empt the server, whose
                    rules are the real ones; its messages show under each field instead. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Основные данные</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <datalist id="personal-nationalities">
                        {options.nationalities.map((value) => (
                            <option key={value} value={value} />
                        ))}
                    </datalist>

                    <div className="grid gap-4">
                        {/* Everybody reads these two; retyping them is a right like any
                        other, and the server drops them from a save without it. */}
                        {canEdit('surname') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-surname">Фамилия</Label>
                                <Input
                                    id="personal-surname"
                                    required
                                    value={form.data.surname}
                                    onChange={(e) => form.setData('surname', e.target.value)}
                                    aria-invalid={!!form.errors.surname}
                                />
                                <InputError message={form.errors.surname} />
                            </div>
                        )}

                        {canEdit('name') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-name">Имя</Label>
                                <Input
                                    id="personal-name"
                                    required
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    aria-invalid={!!form.errors.name}
                                />
                                <InputError message={form.errors.name} />
                            </div>
                        )}

                        {canEdit('patronymic') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-patronymic">Отчество</Label>
                                <Input
                                    id="personal-patronymic"
                                    value={form.data.patronymic}
                                    onChange={(e) => form.setData('patronymic', e.target.value)}
                                    aria-invalid={!!form.errors.patronymic}
                                />
                                <InputError message={form.errors.patronymic} />
                            </div>
                        )}

                        {canEdit('birth_date') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-birth-date">Дата рождения</Label>
                                <Input
                                    id="personal-birth-date"
                                    type="date"
                                    max={new Date().toISOString().slice(0, 10)}
                                    value={form.data.birth_date}
                                    onChange={(e) => form.setData('birth_date', e.target.value)}
                                    aria-invalid={!!form.errors.birth_date}
                                />
                                <InputError message={form.errors.birth_date} />
                            </div>
                        )}

                        {canEdit('sex') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-sex">Пол</Label>
                                <Select value={form.data.sex} onValueChange={(value) => form.setData('sex', value as Sex)}>
                                    <SelectTrigger id="personal-sex">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="male">{sexLabels.male}</SelectItem>
                                        <SelectItem value="female">{sexLabels.female}</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.sex} />
                            </div>
                        )}

                        {canEdit('birth_place') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-birth-place">Место рождения</Label>
                                <Input
                                    id="personal-birth-place"
                                    value={form.data.birth_place}
                                    onChange={(e) => form.setData('birth_place', e.target.value)}
                                    aria-invalid={!!form.errors.birth_place}
                                />
                                <InputError message={form.errors.birth_place} />
                            </div>
                        )}

                        {canEdit('nationality') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-nationality">Национальность</Label>
                                <Input
                                    id="personal-nationality"
                                    list="personal-nationalities"
                                    value={form.data.nationality}
                                    onChange={(e) => form.setData('nationality', e.target.value)}
                                    aria-invalid={!!form.errors.nationality}
                                />
                                <InputError message={form.errors.nationality} />
                            </div>
                        )}

                        {canEdit('citizenship') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-citizenship">Гражданство</Label>
                                <MultiSelect
                                    id="personal-citizenship"
                                    creatable
                                    options={options.citizenships.map((value) => ({ value, label: value }))}
                                    value={form.data.citizenship}
                                    onChange={(value) => form.setData('citizenship', value)}
                                    placeholder="Выберите или впишите страну"
                                    searchPlaceholder="Страна"
                                />
                                {/* A wrong country is reported on its own line ("citizenship.1"). */}
                                <InputError
                                    message={
                                        form.errors.citizenship ?? Object.entries(form.errors).find(([key]) => key.startsWith('citizenship.'))?.[1]
                                    }
                                />
                            </div>
                        )}

                        {canEdit('home_address') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-home-address">Домашний адрес</Label>
                                <Input
                                    id="personal-home-address"
                                    value={form.data.home_address}
                                    onChange={(e) => form.setData('home_address', e.target.value)}
                                    aria-invalid={!!form.errors.home_address}
                                />
                                <InputError message={form.errors.home_address} />
                            </div>
                        )}

                        {canEdit('roles') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-roles">Позиция</Label>
                                <MultiSelect
                                    id="personal-roles"
                                    // A locked field still shows what the person holds, so the
                                    // positions stay readable even where they are untouchable.
                                    options={options.roles.map((role) => ({
                                        value: role.name,
                                        label: role.title,
                                    }))}
                                    value={form.data.roles}
                                    onChange={(value) => form.setData('roles', value)}
                                    disabled={rolesLocked !== null}
                                />
                                {rolesLocked !== null && <p className="text-muted-foreground text-sm">{rolesLocked}</p>}
                                <InputError message={listError('roles')} />
                            </div>
                        )}

                        {canEdit('positions') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-positions">Должность</Label>
                                <MultiSelect
                                    id="personal-positions"
                                    options={options.positions.map((position) => ({ value: position.id, label: position.name }))}
                                    value={form.data.positions}
                                    onChange={(value) => form.setData('positions', value)}
                                />
                                <InputError message={listError('positions')} />
                            </div>
                        )}

                        {canEdit('departments') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor="personal-departments">Отдел</Label>
                                <MultiSelect
                                    id="personal-departments"
                                    options={options.departments.map((department) => ({
                                        value: department.id,
                                        label: department.name,
                                        depth: department.depth,
                                    }))}
                                    value={form.data.departments}
                                    onChange={(value) => form.setData('departments', value)}
                                />
                                <InputError message={listError('departments')} />
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

/** The spouse of a man is "Супруга", of a woman "Супруг". */
const spouseLabel = (sex: Sex) => (sex === 'male' ? 'Супруга' : 'Супруг');

/** The "Паспорт" card in a form; every field may stay empty. */
function PassportDialog({ employee, details, onClose }: { employee: Employee; details: ProfilePrivate; onClose: () => void }) {
    const form = useForm({
        passport_series: details.passport.series ?? '',
        passport_number: details.passport.number ?? '',
        passport_issued_at: details.passport.issued_at ?? '',
        passport_issued_by: details.passport.issued_by ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('employees.passport', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Паспорт</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="passport-series">Серия</Label>
                            <Input
                                id="passport-series"
                                value={form.data.passport_series}
                                onChange={(e) => form.setData('passport_series', e.target.value)}
                                aria-invalid={!!form.errors.passport_series}
                            />
                            <InputError message={form.errors.passport_series} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="passport-number">Номер</Label>
                            <Input
                                id="passport-number"
                                value={form.data.passport_number}
                                onChange={(e) => form.setData('passport_number', e.target.value)}
                                aria-invalid={!!form.errors.passport_number}
                            />
                            <InputError message={form.errors.passport_number} />
                        </div>

                        <div className="grid content-start gap-2">
                            <Label htmlFor="passport-issued-at">Дата выдачи</Label>
                            <Input
                                id="passport-issued-at"
                                type="date"
                                max={new Date().toISOString().slice(0, 10)}
                                value={form.data.passport_issued_at}
                                onChange={(e) => form.setData('passport_issued_at', e.target.value)}
                                aria-invalid={!!form.errors.passport_issued_at}
                            />
                            <InputError message={form.errors.passport_issued_at} />
                        </div>

                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="passport-issued-by">Кем выдан</Label>
                            <Input
                                id="passport-issued-by"
                                value={form.data.passport_issued_by}
                                onChange={(e) => form.setData('passport_issued_by', e.target.value)}
                                aria-invalid={!!form.errors.passport_issued_by}
                            />
                            <InputError message={form.errors.passport_issued_by} />
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

/** The "Семья" card in a form: marital status, the spouse, and any number of children. */
function FamilyDialog({ employee, details, onClose }: { employee: Employee; details: ProfilePrivate; onClose: () => void }) {
    const form = useForm({
        marital_status: details.marital_status ?? '',
        spouse_name: details.spouse_name ?? '',
        spouse_birth_date: details.spouse_birth_date ?? '',
        has_children: details.has_children,
        // The block opens for whoever may change any of its lines, and the payload
        // leaves out the lines they may not read — so the children may be absent
        // even here. The server drops what they may not save in any case.
        children: (details.children ?? []).map((child) => ({ full_name: child.full_name, birth_date: child.birth_date ?? '' })),
    });

    // The three lines are allowed one by one. Only the ones this viewer may change
    // are shown and sent: a line left out is kept on file as it is, while an empty
    // one would read as "clear it" — the server ignores it anyway, but the form
    // should not ask in the first place.
    const canEdit = useCanEdit();
    const editsMarital = canEdit('marital_status');
    const editsSpouse = canEdit('spouse');
    const editsChildren = canEdit('children');

    form.transform((data) => ({
        ...(editsMarital ? { marital_status: data.marital_status } : {}),
        ...(editsSpouse ? { spouse_name: data.spouse_name, spouse_birth_date: data.spouse_birth_date } : {}),
        ...(editsChildren ? { has_children: data.has_children, children: data.children } : {}),
    }));

    /** Ticked, the card states there are none; unticked with no rows, it stays unanswered. */
    const noChildren = form.data.has_children === false;

    const today = new Date().toISOString().slice(0, 10);
    const errors = form.errors as Record<string, string | undefined>;

    const setChild = (index: number, patch: Partial<{ full_name: string; birth_date: string }>) =>
        form.setData(
            'children',
            form.data.children.map((child, i) => (i === index ? { ...child, ...patch } : child)),
        );

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.put(route('employees.family', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="overflow-y-auto sm:max-h-[90vh] sm:max-w-lg">
                {/* noValidate: see PersonalDialog — the server's rules are the real ones. */}
                <form onSubmit={submit} noValidate className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Семья</DialogTitle>
                        <DialogDescription className="sr-only">Измените поля и сохраните.</DialogDescription>
                    </DialogHeader>

                    {(editsMarital || editsSpouse) && (
                        <div className="grid gap-x-4 gap-y-4 sm:grid-cols-2">
                            {editsMarital && (
                                <div className="grid content-start gap-2 sm:col-span-2">
                                    <Label htmlFor="family-marital">Семейное положение</Label>
                                    <Select
                                        value={form.data.marital_status || 'none'}
                                        onValueChange={(value) => form.setData('marital_status', value === 'none' ? '' : (value as Marital))}
                                    >
                                        <SelectTrigger id="family-marital">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">Не указано</SelectItem>
                                            <SelectItem value="single">{maritalLabels[employee.sex ?? 'male'].single}</SelectItem>
                                            <SelectItem value="married">{maritalLabels[employee.sex ?? 'male'].married}</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError message={form.errors.marital_status} />
                                </div>
                            )}

                            {editsSpouse && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="family-spouse-name">{spouseLabel(employee.sex ?? 'male')}</Label>
                                    <Input
                                        id="family-spouse-name"
                                        placeholder="ФИО"
                                        value={form.data.spouse_name}
                                        onChange={(e) => form.setData('spouse_name', e.target.value)}
                                        aria-invalid={!!form.errors.spouse_name}
                                    />
                                    <InputError message={form.errors.spouse_name} />
                                </div>
                            )}

                            {editsSpouse && (
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="family-spouse-birth-date">Дата рождения</Label>
                                    <Input
                                        id="family-spouse-birth-date"
                                        type="date"
                                        max={today}
                                        value={form.data.spouse_birth_date}
                                        onChange={(e) => form.setData('spouse_birth_date', e.target.value)}
                                        aria-invalid={!!form.errors.spouse_birth_date}
                                    />
                                    <InputError message={form.errors.spouse_birth_date} />
                                </div>
                            )}
                        </div>
                    )}

                    {editsChildren && (
                        // The rule above the children only separates them from the lines before.
                        <div className={cn('flex flex-col gap-3', (editsMarital || editsSpouse) && 'border-t pt-4')}>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="text-sm font-semibold">Дети</h3>
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="family-no-children"
                                        checked={noChildren}
                                        // Ticking it drops any rows, so the flag and the list never contradict each other.
                                        onCheckedChange={(checked) =>
                                            form.setData((data) => ({
                                                ...data,
                                                has_children: checked === true ? false : null,
                                                children: checked === true ? [] : data.children,
                                            }))
                                        }
                                    />
                                    <Label htmlFor="family-no-children" className="font-normal">
                                        Детей нет
                                    </Label>
                                </div>
                            </div>

                            {!noChildren && form.data.children.length === 0 && <p className="text-muted-foreground text-sm">Не указаны</p>}

                            {!noChildren &&
                                form.data.children.map((child, index) => (
                                    <div key={index} className="flex items-start gap-2">
                                        <div className="grid min-w-0 flex-1 gap-x-4 gap-y-2 sm:grid-cols-[1fr_10rem]">
                                            <div className="grid content-start gap-2">
                                                <Label htmlFor={`family-child-${index}`} className="sr-only">
                                                    ФИО ребёнка
                                                </Label>
                                                <Input
                                                    id={`family-child-${index}`}
                                                    placeholder="ФИО"
                                                    value={child.full_name}
                                                    onChange={(e) => setChild(index, { full_name: e.target.value })}
                                                    aria-invalid={!!errors[`children.${index}.full_name`]}
                                                />
                                                <InputError message={errors[`children.${index}.full_name`]} />
                                            </div>
                                            <div className="grid content-start gap-2">
                                                <Label htmlFor={`family-child-${index}-birth`} className="sr-only">
                                                    Дата рождения ребёнка
                                                </Label>
                                                <Input
                                                    id={`family-child-${index}-birth`}
                                                    type="date"
                                                    max={today}
                                                    value={child.birth_date}
                                                    onChange={(e) => setChild(index, { birth_date: e.target.value })}
                                                    aria-invalid={!!errors[`children.${index}.birth_date`]}
                                                />
                                                <InputError message={errors[`children.${index}.birth_date`]} />
                                            </div>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="text-muted-foreground shrink-0"
                                            aria-label="Убрать ребёнка"
                                            onClick={() =>
                                                form.setData(
                                                    'children',
                                                    form.data.children.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}

                            {!noChildren && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="self-start"
                                    onClick={() => form.setData('children', [...form.data.children, { full_name: '', birth_date: '' }])}
                                >
                                    <Plus />
                                    Добавить ребёнка
                                </Button>
                            )}
                        </div>
                    )}

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
    children: ReactNode;
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
 * The action of a block: a pencil in the desktop header strip, the word
 * «Изменить» in the brand colour above the card on a phone, where an icon on
 * its own would be a small target with no word to say what it does.
 */
function EditButton({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <Button
            variant="ghost"
            size="icon"
            className="text-muted-foreground max-md:text-brand-strong max-md:hover:text-brand-strong -my-1 -mr-2.5 size-9 max-md:-my-2.5 max-md:mr-0 max-md:h-10 max-md:w-auto max-md:px-1 max-md:text-[15px] max-md:font-medium max-md:hover:bg-transparent max-md:active:opacity-60 lg:my-0 lg:-mr-2 lg:size-7 max-md:dark:text-[#C5E27A] max-md:dark:hover:text-[#C5E27A]"
            aria-label={label}
            onClick={onClick}
        >
            <Pencil className="size-4 max-md:hidden" />
            <span className="md:hidden">Изменить</span>
        </Button>
    );
}

/** A note in place of an empty list; on a phone it is padded like a row of the card it sits in. */
const emptyNote = 'text-muted-foreground text-sm max-md:py-3.5 max-md:text-[15px]';

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
    personal: [
        'surname',
        'name',
        'patronymic',
        'sex',
        'birth_date',
        'birth_place',
        'citizenship',
        'nationality',
        'home_address',
        'roles',
        'positions',
        'departments',
    ],
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

function Departments({ items }: { items: NonNullable<Employee['departments']> }) {
    return (
        <ul className="flex flex-col gap-1">
            {items.map((department) => (
                <li key={department.id}>
                    <Link href={route('departments.show', department.id)} className="hover:underline">
                        {department.path}
                    </Link>
                    {department.is_head && <span className="text-brand-strong font-semibold dark:text-[#C5E27A]"> · руководитель</span>}
                </li>
            ))}
        </ul>
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
 * What the person holds right now, for reading only: a unit is handed out and
 * taken back in the equipment section, so its status has a single home.
 */
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

function EquipmentList({ items }: { items: ProfilePrivate['equipment'] }) {
    return (
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
                </li>
            ))}
        </ul>
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
            className="bg-muted text-muted-foreground flex size-[72px] shrink-0 items-center justify-center rounded-full md:size-28"
            title="Фотография закрыта"
            aria-label="Фотография закрыта"
        >
            <Lock className="size-7 md:size-8" />
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
            className="size-[72px] rounded-full object-cover md:size-28"
        />
    ) : (
        <PersonAvatar name={`${employee.name} ${employee.surname}`} className="size-[72px] text-2xl md:size-28 md:text-4xl" />
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

/** Links to the previous and next colleague in the list; ← and → keys do the same. */
function Neighbours({ prev, next, tab }: { prev: Neighbour; next: Neighbour; tab: TabKey }) {
    // Comparing colleagues means staying on the same section, so the open tab
    // travels with the link.
    const href = (to: Neighbour) => route('employees.show', to!.id) + (tab === 'profile' ? '' : `#${tab}`);

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
                className="flex-1 px-3 sm:flex-none sm:px-4"
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

    // On a phone two full-width buttons would push the card down by a whole row,
    // so there the same links are small chevrons in the top corners of the hero
    // (the nearest positioned box), the way a native viewer pages between records.
    const corner = (to: Neighbour, label: string, Icon: typeof ChevronLeft, side: string) =>
        to ? (
            <Button variant="ghost" size="icon" className={cn('absolute top-0 size-9 md:hidden', side)} aria-label={`${label}: ${to.name}`} asChild>
                <Link href={href(to)} prefetch>
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
            <div className="flex w-full gap-1 max-md:hidden sm:w-auto">
                {arrow(prev, 'Предыдущий', ChevronLeft)}
                {arrow(next, 'Следующий', ChevronRight)}
            </div>
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
    rolesLocked,
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
    /** Why the positions of this card are locked, or null when they are not. */
    rolesLocked: string | null;
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
    const can = useCan();
    const [editing, setEditing] = useState<'personal' | 'passport' | 'contacts' | 'languages' | 'employment' | 'family' | null>(null);
    // Education is edited one record at a time, so these hold a record, not a card name.
    const [education, setEducation] = useState<EducationRecord | 'new' | null>(null);
    const [deletingEducation, setDeletingEducation] = useState<EducationRecord | null>(null);
    const [job, setJob] = useState<JobRecord | 'new' | null>(null);
    const [deletingJob, setDeletingJob] = useState<JobRecord | null>(null);
    const [deletingAvatar, setDeletingAvatar] = useState(false);
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
                <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:px-5 md:py-4">
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
                                <h1 className="min-w-0 text-xl font-semibold tracking-tight break-words">{fullName}</h1>
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
                                    {(employee.positions ?? []).map((title) => (
                                        <StatusBadge key={title} tone="success" className={wrappingBadge}>
                                            {title}
                                        </StatusBadge>
                                    ))}
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

                        {neighbours !== null && (
                            <div className="flex items-center gap-2 self-stretch max-md:contents md:self-end">
                                <Neighbours prev={neighbours.prev} next={neighbours.next} tab={tab} />
                            </div>
                        )}
                    </div>

                    <Tabs tabs={tabs} active={tab} onChange={setTab} />

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
                                                // The list it opens is the fleet's, so it takes seeing the fleet.
                                                seesEquipment(can) && (
                                                    // A text link rather than a button: the strip keeps the
                                                    // height it has without one.
                                                    <Link
                                                        // "У кого" is a name search now, so the link passes the name.
                                                        href={route('equipment.index', { holder: `${employee.surname} ${employee.name}` })}
                                                        className="text-brand-strong flex shrink-0 items-center gap-1.5 text-[13px] font-medium hover:underline dark:text-[#C5E27A]"
                                                    >
                                                        <Laptop className="size-4" />
                                                        Открыть в разделе оборудования
                                                    </Link>
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
                                    <Section
                                        title="Основные данные"
                                        action={
                                            canEditBlock('personal') && (
                                                <EditButton label="Редактировать основные данные" onClick={() => setEditing('personal')} />
                                            )
                                        }
                                    >
                                        <Fields>
                                            <Field label="Фамилия" field="surname">
                                                {employee.surname}
                                            </Field>
                                            <Field label="Имя" field="name">
                                                {employee.name}
                                            </Field>
                                            <Field label="Отчество" field="patronymic">
                                                {employee.patronymic}
                                            </Field>
                                            <Field label="Пол" field="sex">
                                                {employee.sex && sexLabels[employee.sex]}
                                            </Field>
                                            <Field label="Дата рождения" field="birth_date">
                                                {details.birth_date && (
                                                    <>
                                                        {formatDate(details.birth_date)}
                                                        <span className="text-muted-foreground font-normal"> · {age(details.birth_date)}</span>
                                                    </>
                                                )}
                                            </Field>
                                            <Field label="Место рождения" field="birth_place">
                                                {details.birth_place}
                                            </Field>
                                            <Field label="Гражданство" field="citizenship">
                                                {details.citizenship?.length ? (
                                                    <CitizenshipBadges
                                                        countries={details.citizenship}
                                                        href={
                                                            can('employees.view') && can('employees.field.citizenship')
                                                                ? (country) => route('employees.index', { citizenship: [country] })
                                                                : undefined
                                                        }
                                                    />
                                                ) : null}
                                            </Field>
                                            <Field label="Национальность" field="nationality">
                                                {details.nationality && capitalize(details.nationality)}
                                            </Field>
                                            <Field label="Домашний адрес" field="home_address">
                                                {details.home_address}
                                            </Field>
                                            <Field label={(employee.roles ?? []).length > 1 ? 'Позиции' : 'Позиция'} field="roles">
                                                {employee.roles?.length ? employee.roles.join(', ') : null}
                                            </Field>
                                            <Field label={(employee.positions ?? []).length > 1 ? 'Должности' : 'Должность'} field="positions">
                                                {employee.positions?.length ? employee.positions.join(', ') : null}
                                            </Field>
                                            <Field label={(employee.departments ?? []).length > 1 ? 'Отделы' : 'Отдел'} field="departments">
                                                {employee.departments?.length ? <Departments items={employee.departments} /> : null}
                                            </Field>
                                        </Fields>
                                    </Section>

                                    <Section
                                        fields={['passport_number', 'passport_issued_at', 'passport_issued_by']}
                                        title="Паспорт"
                                        action={
                                            canEditBlock('passport') && (
                                                <EditButton label="Редактировать паспорт" onClick={() => setEditing('passport')} />
                                            )
                                        }
                                    >
                                        <Fields>
                                            <Field label="Серия и номер" field="passport_number">
                                                {(details.passport.series || details.passport.number) && (
                                                    <span className="tabular-nums">
                                                        {[details.passport.series, details.passport.number].filter(Boolean).join(' ')}
                                                    </span>
                                                )}
                                            </Field>
                                            <Field label="Дата выдачи" field="passport_issued_at">
                                                {formatDate(details.passport.issued_at)}
                                            </Field>
                                            <Field label="Кем выдан" field="passport_issued_by">
                                                {details.passport.issued_by}
                                            </Field>
                                        </Fields>
                                    </Section>

                                    <Section
                                        fields={['marital_status', 'spouse', 'children']}
                                        title="Семья"
                                        action={
                                            canEditBlock('family') && <EditButton label="Редактировать семью" onClick={() => setEditing('family')} />
                                        }
                                    >
                                        <Fields>
                                            <Field label="Семейное положение" field="marital_status">
                                                {details.marital_status && maritalLabels[employee.sex ?? 'male'][details.marital_status]}
                                            </Field>
                                            <Field label={spouseLabel(employee.sex ?? 'male')} field="spouse">
                                                {details.spouse_name && (
                                                    <>
                                                        {details.spouse_name}
                                                        {details.spouse_birth_date && (
                                                            <span className="text-muted-foreground font-normal">
                                                                {' · '}
                                                                {formatDate(details.spouse_birth_date)} · {age(details.spouse_birth_date)}
                                                            </span>
                                                        )}
                                                    </>
                                                )}
                                            </Field>
                                            {/* On a phone the children are one more row of the list, the
                                            names stacked on the right; the heading and list below are
                                            the desktop's. The Field asks about the same line. */}
                                            <Field label="Дети" field="children" className="md:hidden">
                                                {details.children.length === 0 ? (
                                                    details.has_children === false ? (
                                                        'Нет'
                                                    ) : null
                                                ) : (
                                                    <span className="flex flex-col gap-1.5">
                                                        {details.children.map((child) => (
                                                            <span key={child.full_name + child.birth_date} className="flex flex-col">
                                                                <span>{child.full_name}</span>
                                                                {child.birth_date && (
                                                                    <span className="text-muted-foreground text-[13px]">
                                                                        {formatDate(child.birth_date)} · {age(child.birth_date)}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        ))}
                                                    </span>
                                                )}
                                            </Field>
                                        </Fields>

                                        {/* Not a Field but a heading and a list, so it has to ask about
                                        its line itself: the payload leaves out what the viewer may
                                        not read, and the block above it may well be readable. */}
                                        {visibleFields.includes('children') && (
                                            <div className="max-md:hidden md:contents">
                                                <h3 className="text-muted-foreground text-[13px]">Дети</h3>
                                                {details.children.length === 0 ? (
                                                    // A dash while nobody has filled this in; "Нет" only once HR says so.
                                                    <p className="text-muted-foreground text-sm">{details.has_children === false ? 'Нет' : '—'}</p>
                                                ) : (
                                                    <ul className="flex flex-col">
                                                        {details.children.map((child) => (
                                                            <li
                                                                key={child.full_name + child.birth_date}
                                                                className="flex flex-col gap-0.5 border-t py-3 first:border-t-0 first:pt-0 last:pb-0"
                                                            >
                                                                <span className="text-sm font-medium">{child.full_name}</span>
                                                                {child.birth_date && (
                                                                    <span className="text-muted-foreground text-[13px]">
                                                                        {formatDate(child.birth_date)} · {age(child.birth_date)}
                                                                    </span>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        )}
                                    </Section>
                                </div>

                                <aside className={pane}>
                                    {/* On a phone the two facts get a heading like every other block,
                                    so the way to change them reads the same: «Изменить» above the card. */}
                                    {visibleFields.includes('hired_at') && (
                                        <div className="-mb-2.5 flex min-h-6 items-end justify-between gap-3 px-1 md:hidden">
                                            <h2 className="text-muted-foreground text-[13px] font-semibold tracking-wide uppercase">
                                                Работа в компании
                                            </h2>
                                            {canEditBlock('employment') && (
                                                <EditButton label="Редактировать начало работы" onClick={() => setEditing('employment')} />
                                            )}
                                        </div>
                                    )}
                                    {/* Bare, without a card of its own: two facts that need no heading. */}
                                    <div
                                        className={cn(
                                            'px-1',
                                            visibleFields.includes('hired_at') ? 'max-md:bg-card max-md:rounded-2xl max-md:px-4' : 'max-md:hidden',
                                        )}
                                    >
                                        <Fields columns={2}>
                                            <Field label="Начало работы" field="hired_at">
                                                {/* The pencil sits by the value it edits, not by the block. */}
                                                <span className="flex items-center gap-1 max-md:justify-end">
                                                    {formatDate(details.hired_at) ?? <span className="text-muted-foreground font-normal">—</span>}
                                                    {canEditBlock('employment') && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground -my-2.5 size-9 shrink-0 max-md:hidden lg:-my-1 lg:size-6"
                                                            aria-label="Редактировать начало работы"
                                                            onClick={() => setEditing('employment')}
                                                        >
                                                            <Pencil className="size-3.5" />
                                                        </Button>
                                                    )}
                                                </span>
                                            </Field>
                                            <Field label="Стаж в компании" field="hired_at">
                                                {details.hired_at && tenure(details.hired_at)}
                                            </Field>
                                        </Fields>
                                    </div>

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
                                    <Section
                                        // The same block under the same name: what is in it depends
                                        // on what this viewer may read, which is not a different
                                        // block and should not read as one.
                                        title="Основные данные"
                                        // The block is short here, but a line of it may still be
                                        // this viewer's to change — their own «Пол», say — and a
                                        // right that shows up nowhere is a right nobody has.
                                        action={
                                            canEditBlock('personal') && (
                                                <EditButton label="Редактировать основные данные" onClick={() => setEditing('personal')} />
                                            )
                                        }
                                    >
                                        <Fields>
                                            {/* Read by everybody, so they open the block here as they do
                                            on a card whose private lines are open too. */}
                                            <Field label="Фамилия" field="surname">
                                                {employee.surname}
                                            </Field>
                                            <Field label="Имя" field="name">
                                                {employee.name}
                                            </Field>
                                            <Field label="Отчество" field="patronymic">
                                                {employee.patronymic}
                                            </Field>
                                            <Field label={(employee.roles ?? []).length > 1 ? 'Позиции' : 'Позиция'} field="roles">
                                                {employee.roles?.length ? employee.roles.join(', ') : null}
                                            </Field>
                                            <Field label={(employee.positions ?? []).length > 1 ? 'Должности' : 'Должность'} field="positions">
                                                {employee.positions?.length ? employee.positions.join(', ') : null}
                                            </Field>
                                            <Field label={(employee.departments ?? []).length > 1 ? 'Отделы' : 'Отдел'} field="departments">
                                                {employee.departments?.length ? <Departments items={employee.departments} /> : null}
                                            </Field>
                                            <Field label="Пол" field="sex">
                                                {employee.sex && sexLabels[employee.sex]}
                                            </Field>
                                        </Fields>
                                    </Section>

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

                {/* Not gated on the private lines: the block may be open to this viewer
                by its public ones alone. */}
                {editing === 'personal' && options && assigned && (
                    <PersonalDialog
                        employee={employee}
                        details={details}
                        options={options}
                        assigned={assigned}
                        rolesLocked={rolesLocked}
                        onClose={() => setEditing(null)}
                    />
                )}

                {editing === 'passport' && details && <PassportDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

                {editing === 'contacts' && details && <ContactsDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

                {editing === 'employment' && details && <EmploymentDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

                {/* Languages are public, so this one needs no private details. */}
                {editing === 'languages' && options && <LanguagesDialog employee={employee} options={options} onClose={() => setEditing(null)} />}

                {editing === 'family' && details && <FamilyDialog employee={employee} details={details} onClose={() => setEditing(null)} />}

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
