import {
    CardFieldsButton,
    CardFieldsDialog,
    readsAnyCardLine,
    RightsButton,
    RightsDialog,
    type CardFieldGroup,
    type CardFieldsMode,
    type PlainRight,
} from '@/components/card-fields';
import { searchBox } from '@/components/directory-manager';
import { EquipmentScopesButton, EquipmentScopesDialog, type EquipmentScope } from '@/components/equipment-scopes';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import DirectoriesLayout from '@/layouts/directories-layout';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, Lock, Search } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

interface Right {
    key: string;
    title: string;
    hint: string;
}

interface Section {
    key: string;
    title: string;
    rights: Right[];
}

interface RoleRow {
    id: number;
    name: string;
    title: string;
    users_count: number;
    /** An access role: it answers yes to everything, whatever the table says. */
    everything: boolean;
    permissions: string[];
}

/** The sections whose columns are not a plain row of checkboxes. */
const EMPLOYEES = 'employees';

/** The same lines of a card, but the ones a person holds over their own. */
const PROFILE = 'profile';

/** Where the view is not one right either, but three parts of the fleet. */
const EQUIPMENT = 'equipment';

/** And where one section is really five lists, each opened and changed on its own. */
const DIRECTORIES = 'directories';

/**
 * Whether the section opens at all. It is never ticked by hand: a position that
 * reads not a single line of a card would find nothing but empty rows there, so
 * the right follows the choice of fields instead of sitting beside it.
 */
const EMPLOYEES_VIEW = 'employees.view';

/** What one does to a colleague rather than to a line of their card. */
const ACTIONS = ['employees.create', 'employees.transfer', 'employees.fire', 'employees.delete'];

/** A column of the table: a right to tick, or a door to a window of rights. */
interface Column {
    key: string;
    title: string;
    hint: string;
    width: string;
}

/**
 * The three columns of «Сотрудники». Two of them are not rights but counters:
 * the lines of a card are four dozen rights and are chosen in a window, with the
 * column showing how much of the card is open. The third holds what one does to
 * the person.
 */
const EMPLOYEE_COLUMNS: Column[] = [
    {
        key: 'view',
        title: 'Просмотр',
        hint: 'Какие строки чужой карточки позиция видит — в карточке сотрудника, в колонках таблицы, в фильтрах и в поиске. Свою карточку открывает раздел «Профиль». Пока не выбрана ни одна строка, раздел «Сотрудники» для позиции закрыт.',
        width: 'w-36',
    },
    {
        key: 'edit',
        title: 'Изменение',
        hint: 'Какие строки чужой карточки позиция может править. Менять можно только то, что видно: строка, закрытая в «Просмотре», недоступна и здесь.',
        width: 'w-36',
    },
    {
        key: 'actions',
        title: 'Действия',
        hint: 'Что делают с самим сотрудником, а не со строкой его карточки: добавление нового сотрудника с заполнением всей карточки, перевод в другой отдел или на другую должность, увольнение и удаление.',
        width: 'w-40',
    },
];

/**
 * The two columns of «Профиль». The same counters, over the same lines, but about
 * one's own card: there is nothing one "does" to oneself, so the section has no
 * third column and no plain rights of its own.
 */
const PROFILE_COLUMNS: Column[] = [
    {
        key: 'view',
        title: 'Просмотр',
        hint: 'Какие строки своей карточки сотрудник видит сам — в разделе «Профиль». Речь только о собственной карточке: чужие открывает раздел «Сотрудники».',
        width: 'w-36',
    },
    {
        key: 'edit',
        title: 'Изменение',
        hint: 'Какие строки своей карточки сотрудник правит сам, без кадровика. Менять можно только видимое: строка, закрытая в «Просмотре», недоступна и здесь.',
        width: 'w-36',
    },
];

/**
 * The three columns of «Оборудование». Not one of them is a box to tick: how
 * much of the fleet is open is three answers rather than one, the card of a unit
 * is edited by whole blocks, and what one does to a unit is half a dozen
 * operations. So all three are counters, each with a window behind it.
 */
const EQUIPMENT_VIEW_COLUMN: Column = {
    key: 'view',
    title: 'Просмотр',
    hint: 'Техника видна по частям: своё, оборудование своего отдела — это для руководителя — и всё сразу. У каждой части отдельно решается журнал операций: одно дело знать, что у тебя на руках, другое — кто держал эту единицу до тебя.',
    width: 'w-36',
};

const EQUIPMENT_COLUMNS: Column[] = [
    EQUIPMENT_VIEW_COLUMN,
    {
        key: 'blocks',
        title: 'Изменение',
        hint: 'Карточка единицы правится блоками: характеристики, комплектация, состояние и инвентаризация. Блок открывают целиком, а не построчно, и править можно только то, что видно в «Просмотре».',
        width: 'w-36',
    },
    {
        key: 'actions',
        title: 'Действия',
        hint: 'Операции над самой единицей, а не строки её карточки: добавление, выдача и возврат, списание, обслуживание и удаление.',
        width: 'w-40',
    },
];

/**
 * The two columns of «Справочники». The section looks like one page, but it is
 * five lists kept by five different people, so «Просмотр» and «Изменение» are
 * counters here too: a column of ten boxes would say nothing about who keeps
 * what.
 */
const DIRECTORY_COLUMNS: Column[] = [
    {
        key: 'lists',
        title: 'Просмотр',
        hint: 'Справочники — это пять отдельных списков, и ведут их разные люди: должности и языки — кадровик, категории техники — тот, кто отвечает за парк. Раздел появляется в меню, если позиция открывает хотя бы один список.',
        width: 'w-36',
    },
    {
        key: 'edits',
        title: 'Изменение',
        hint: 'Что позиция добавляет, переименовывает и удаляет в этих списках. Менять можно только тот список, который открыт в «Просмотре».',
        width: 'w-36',
    },
];

/** What the section spans across the table: its rights, or the counters of the two card sections. */
const columnsOf = (section: Section): Column[] => {
    if (section.key === EMPLOYEES) {
        return EMPLOYEE_COLUMNS;
    }

    if (section.key === PROFILE) {
        return PROFILE_COLUMNS;
    }

    if (section.key === EQUIPMENT) {
        // The section has no plain rights left, but the spread keeps the header,
        // the group span and the row counting the same thing if one comes back.
        return [...EQUIPMENT_COLUMNS, ...section.rights.map((right) => ({ ...right, width: 'w-28' }))];
    }

    if (section.key === DIRECTORIES) {
        // Same story: nothing plain is left here either, and the spread keeps the
        // span of the group and of the empty row counted from one place.
        return [...DIRECTORY_COLUMNS, ...section.rights.map((right) => ({ ...right, width: 'w-28' }))];
    }

    return section.rights.map((right) => ({ ...right, width: 'w-28' }));
};

/**
 * What a window is open over. The mode says which window it is, so the scope it
 * makes sense with comes with it: there is no such thing as the actions of a
 * profile, nor parts of a fleet inside a card. Two sections both call a window
 * "actions" — what one does to a colleague and what one does to a unit are not
 * the same list — so the scope, not the mode alone, decides which one opens.
 */
type Picking =
    | { id: number; scope: typeof EMPLOYEES | typeof PROFILE; mode: CardFieldsMode }
    | { id: number; scope: typeof EMPLOYEES; mode: 'actions' }
    | { id: number; scope: typeof EQUIPMENT; mode: 'scopes' | 'blocks' | 'actions' }
    | { id: number; scope: typeof DIRECTORIES; mode: 'lists' | 'edits' };

/**
 * Who may do what: positions down the side, rights across the top.
 *
 * A tick is saved the moment it is made — a page of three hundred checkboxes
 * with one "Сохранить" at the bottom is a page where half the work is lost to a
 * stray reload. The row is sent whole, so the server never has to guess.
 */
export default function AccessPage({
    sections,
    roles,
    fields,
    profileFields,
    equipmentScopes,
    equipmentBlocks,
    equipmentActions,
    directoryLists,
    directoryEdits,
}: {
    sections: Section[];
    roles: RoleRow[];
    /** The lines of somebody else's card. */
    fields: CardFieldGroup[];
    /** The same lines, carrying the rights one holds over one's own card. */
    profileFields: CardFieldGroup[];
    /** The three parts of the fleet, each with its own view and its own journal. */
    equipmentScopes: EquipmentScope[];
    /** The blocks a unit's card is edited by, as a plain list of rights. */
    equipmentBlocks: PlainRight[];
    /** The operations over a unit, in the same shape. */
    equipmentActions: PlainRight[];
    /** Which of the five reference lists a position opens. */
    directoryLists: PlainRight[];
    /** And which it keeps: each one names the list it cannot be held without. */
    directoryEdits: PlainRight[];
}) {
    const [query, setQuery] = useState('');
    // Which window is open over which position: a half of somebody's card, a half
    // of one's own, the actions, or the parts of the fleet. The position is kept
    // by id rather than as the row it was opened from, so the window shows what
    // has just been saved instead of the copy that is now stale.
    const [picking, setPicking] = useState<Picking | null>(null);
    // What the table shows while a save is in flight, so a tick answers at once.
    const [pending, setPending] = useState<Record<number, string[]>>({});

    const rights = useMemo(() => sections.flatMap((section) => section.rights), [sections]);
    // The actions keep the names and hints the server gives them; only where they
    // sit in the table is decided here.
    const actions = useMemo(
        () => sections.find((section) => section.key === EMPLOYEES)?.rights.filter((right) => ACTIONS.includes(right.key)) ?? [],
        [sections],
    );
    const columnCount = useMemo(() => sections.reduce((total, section) => total + columnsOf(section).length, 0), [sections]);

    const term = query.trim().toLowerCase();
    const visible = term ? roles.filter((role) => role.title.toLowerCase().includes(term)) : roles;

    const held = (role: RoleRow) => pending[role.id] ?? role.permissions;
    const picked = picking ? (roles.find((role) => role.id === picking.id) ?? null) : null;

    const save = (role: RoleRow, permissions: string[]) => {
        setPending((current) => ({ ...current, [role.id]: permissions }));

        router.put(
            route('directories.access.update', role.id),
            { permissions },
            {
                preserveScroll: true,
                // Saved on its own, so ticking a second right does not cancel the
                // first one and the row that was already sent still lands.
                async: true,
                // The row is settled once the page comes back with it.
                onFinish: () => setPending((current) => Object.fromEntries(Object.entries(current).filter(([id]) => Number(id) !== role.id))),
            },
        );
    };

    const toggle = (role: RoleRow, key: string) => {
        const current = held(role);

        save(role, current.includes(key) ? current.filter((name) => name !== key) : [...current, key]);
    };

    /**
     * The view window over somebody else's card also answers whether the section
     * opens: the first line chosen brings "employees.view" with it, the last one
     * taken away removes it again. A position with no readable line has nothing
     * to open.
     */
    const saveFields = (role: RoleRow, scope: typeof EMPLOYEES | typeof PROFILE, mode: CardFieldsMode, permissions: string[]) => {
        // One's own card needs no door: a position always has a profile of its
        // own, so "employees.view" is none of its business.
        if (scope === PROFILE || mode === 'edit') {
            save(role, permissions);

            return;
        }

        const rest = permissions.filter((name) => name !== EMPLOYEES_VIEW);

        save(role, readsAnyCardLine(fields, rest) ? [...rest, EMPLOYEES_VIEW] : rest);
    };

    /**
     * Closing a reference list takes the right to change it away with it. A
     * "directories.edit.roles" left behind without the view beside it answers no
     * to every check anyway, and the counter would go on promising something the
     * position cannot do — the same reason a part of the fleet closes its journal.
     */
    const saveLists = (role: RoleRow, permissions: string[]) =>
        save(
            role,
            permissions.filter(
                (name) =>
                    !directoryEdits.some((right) => right.key === name && right.requires !== undefined && !permissions.includes(right.requires.key)),
            ),
        );

    /**
     * What answers for one position under one column: a counter with a window
     * behind it, or a box to tick. Drawn once here, so the table on a desktop and
     * the cards on a phone are the same controls laid out two ways.
     */
    const cell = (role: RoleRow, section: Section, column: Column): ReactNode => {
        const counter = counterOf(role, section.key, column.key);

        if (counter !== null) {
            return role.everything ? <span className="text-muted-foreground text-[13px]">все</span> : counter;
        }

        return role.everything ? (
            <Check className="text-muted-foreground mx-auto size-4" aria-label={`${role.title}: ${column.title} — есть всегда`} />
        ) : (
            <Checkbox
                checked={held(role).includes(column.key)}
                onCheckedChange={() => toggle(role, column.key)}
                aria-label={`${role.title}: ${section.title} — ${column.title}`}
                className="mx-auto"
            />
        );
    };

    /** The counter under a column, or null where the column is a plain right. */
    const counterOf = (role: RoleRow, section: string, column: string): ReactNode | null => {
        if (section === EMPLOYEES && (column === 'view' || column === 'edit')) {
            return (
                <CardFieldsButton
                    mode={column}
                    groups={fields}
                    held={held(role)}
                    onOpen={() => setPicking({ id: role.id, scope: EMPLOYEES, mode: column })}
                />
            );
        }

        if (section === EMPLOYEES && column === 'actions') {
            // Behind the same counter as the two columns beside it: four loose
            // boxes in a cell read as a different kind of answer, and the row
            // should read as one.
            return (
                <RightsButton
                    chosen={actions.filter((right) => held(role).includes(right.key)).length}
                    total={actions.length}
                    title="Действия с сотрудником"
                    onOpen={() => setPicking({ id: role.id, scope: EMPLOYEES, mode: 'actions' })}
                />
            );
        }

        if (section === PROFILE && (column === 'view' || column === 'edit')) {
            return (
                <CardFieldsButton
                    mode={column}
                    groups={profileFields}
                    held={held(role)}
                    onOpen={() => setPicking({ id: role.id, scope: PROFILE, mode: column })}
                />
            );
        }

        if (section === EQUIPMENT && column === 'view') {
            // Behind a counter like the card sections beside it: what is visible
            // here is three parts of the fleet and a journal for each, not a box
            // one either ticks or not.
            return (
                <EquipmentScopesButton
                    scopes={equipmentScopes}
                    held={held(role)}
                    onOpen={() => setPicking({ id: role.id, scope: EQUIPMENT, mode: 'scopes' })}
                />
            );
        }

        if (section === EQUIPMENT && column === 'blocks') {
            return (
                <RightsButton
                    chosen={equipmentBlocks.filter((right) => held(role).includes(right.key)).length}
                    total={equipmentBlocks.length}
                    title="Блоки карточки единицы"
                    onOpen={() => setPicking({ id: role.id, scope: EQUIPMENT, mode: 'blocks' })}
                />
            );
        }

        if (section === EQUIPMENT && column === 'actions') {
            return (
                <RightsButton
                    chosen={equipmentActions.filter((right) => held(role).includes(right.key)).length}
                    total={equipmentActions.length}
                    title="Операции с единицей"
                    onOpen={() => setPicking({ id: role.id, scope: EQUIPMENT, mode: 'actions' })}
                />
            );
        }

        if (section === DIRECTORIES && column === 'lists') {
            // Two counters instead of the pair of boxes this section used to
            // hold: «Справочники» is five lists, and a position usually keeps one
            // of them and only reads the rest.
            return (
                <RightsButton
                    chosen={directoryLists.filter((right) => held(role).includes(right.key)).length}
                    total={directoryLists.length}
                    title="Какие справочники видно"
                    onOpen={() => setPicking({ id: role.id, scope: DIRECTORIES, mode: 'lists' })}
                />
            );
        }

        if (section === DIRECTORIES && column === 'edits') {
            return (
                <RightsButton
                    chosen={directoryEdits.filter((right) => held(role).includes(right.key)).length}
                    total={directoryEdits.length}
                    title="Какие справочники позиция ведёт"
                    onOpen={() => setPicking({ id: role.id, scope: DIRECTORIES, mode: 'edits' })}
                />
            );
        }

        return null;
    };

    return (
        <DirectoriesLayout title="Доступы">
            <div className="flex flex-wrap items-center gap-2 md:-mb-2">
                <label className={searchBox}>
                    <Search className="size-4 shrink-0" />
                    <span className="sr-only">Поиск</span>
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Поиск по позиции"
                        className="text-foreground min-w-0 flex-1 bg-transparent text-sm outline-hidden max-md:text-base"
                    />
                </label>
                <p className="text-muted-foreground text-sm max-md:px-1 max-md:text-[13px]">
                    {rights.length} {plural(rights.length, ['доступ', 'доступа', 'доступов'])} · изменения сохраняются сразу
                </p>
            </div>

            {/*
             * A phone has no room for a matrix forty columns wide, so each
             * position becomes a card of its own: the sections down the card, the
             * same counters and boxes at the right of each line. A position that
             * passes every check has nothing to choose, and says so in one line
             * instead of a column of «все».
             */}
            <div className="flex flex-col gap-5 md:hidden">
                {visible.map((role) => (
                    <section key={role.id} aria-label={role.title}>
                        <h2 className="text-muted-foreground mb-1.5 flex items-baseline gap-2 px-1 text-[13px] font-semibold tracking-wide uppercase">
                            <span className="min-w-0 break-words">{role.title}</span>
                            {role.everything && <Lock className="size-3.5 shrink-0 self-center" aria-label="Все доступы: изменить нельзя" />}
                            <span className="ml-auto shrink-0 font-normal tracking-normal normal-case tabular-nums">
                                {role.users_count} {plural(role.users_count, ['сотрудник', 'сотрудника', 'сотрудников'])}
                            </span>
                        </h2>
                        <Card className="gap-0 rounded-2xl border-0 px-4 py-0 shadow-none">
                            {role.everything ? (
                                <p className="text-muted-foreground py-3 text-[15px]">Все доступы: позиция проходит любую проверку.</p>
                            ) : (
                                sections.map((section) => {
                                    const columns = columnsOf(section);

                                    if (columns.length === 0) return null;

                                    return (
                                        <div key={section.key} className="border-border/60 border-b pt-3 last:border-0">
                                            <p className="text-muted-foreground text-[13px] font-medium">{section.title}</p>
                                            {columns.map((column) => {
                                                const control = cell(role, section, column);
                                                const plain = counterOf(role, section.key, column.key) === null;
                                                const line = 'flex min-h-12 items-center justify-between gap-4 py-1.5';

                                                // A plain right is ticked from anywhere on its line,
                                                // not only from the small box at the end of it.
                                                return plain ? (
                                                    <label key={column.key} className={line}>
                                                        <span className="min-w-0 text-[15px] break-words">{column.title}</span>
                                                        <span className="flex size-10 shrink-0 items-center justify-center">{control}</span>
                                                    </label>
                                                ) : (
                                                    <div key={column.key} className={line}>
                                                        <span className="min-w-0 text-[15px] break-words">{column.title}</span>
                                                        <span className="shrink-0">{control}</span>
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    );
                                })
                            )}
                        </Card>
                    </section>
                ))}

                {visible.length === 0 && (
                    <Card className="text-muted-foreground rounded-2xl border-0 px-4 py-12 text-center text-[15px] shadow-none">
                        Ничего не найдено.
                    </Card>
                )}
            </div>

            <Card className="flex flex-col gap-0 overflow-hidden rounded-xl p-0 max-md:hidden md:min-h-0 md:flex-1">
                {/*
                 * Below md the page scrolls as a whole, so the table is given a
                 * height of its own: without it the header row would have no box
                 * to stick to, and a phone scrolling down twenty positions would
                 * lose the names of the columns.
                 */}
                <div className="max-h-[75svh] overflow-auto md:max-h-none md:min-h-0 md:flex-1">
                    <table className="w-full border-collapse text-sm">
                        <thead className="bg-sidebar sticky top-0 z-20 shadow-[0_1px_0_var(--border)]">
                            <tr className="text-muted-foreground text-left text-[13px]">
                                <th
                                    scope="col"
                                    rowSpan={2}
                                    // Narrower on a phone: at 320px a column of 224px would leave the
                                    // counters a strip too thin to scroll through.
                                    className="bg-sidebar sticky left-0 z-30 min-w-36 px-3 py-3 font-semibold shadow-[1px_0_0_var(--border)] md:min-w-56 md:px-6"
                                >
                                    Позиция
                                </th>
                                {sections.map((section) => (
                                    <th
                                        key={section.key}
                                        scope="colgroup"
                                        colSpan={columnsOf(section).length}
                                        className="border-l px-4 pt-3 pb-1 text-center font-semibold"
                                    >
                                        {section.title}
                                    </th>
                                ))}
                            </tr>
                            <tr className="text-muted-foreground text-left text-[12px]">
                                {sections.flatMap((section) =>
                                    columnsOf(section).map((column, index) => (
                                        <th
                                            // «Просмотр» and «Изменение» appear under several sections.
                                            key={`${section.key}.${column.key}`}
                                            scope="col"
                                            className={cn('px-2 pb-2.5 align-bottom font-medium', column.width, index === 0 && 'border-l')}
                                        >
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="mx-auto block max-w-24 cursor-help text-center leading-tight">
                                                        {column.title}
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-64">{column.hint}</TooltipContent>
                                            </Tooltip>
                                        </th>
                                    )),
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((role) => (
                                <tr key={role.id} className="hover:bg-muted/40 group border-t">
                                    <th
                                        scope="row"
                                        // The tint of a hovered row has to be opaque here: this cell
                                        // stands still while the table scrolls under it, and a
                                        // see-through background shows the counters passing behind.
                                        className="bg-background sticky left-0 z-10 px-3 py-2.5 text-left font-normal shadow-[1px_0_0_var(--border)] group-hover:bg-[color-mix(in_oklab,var(--muted)_40%,var(--background))] md:px-6"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span>{role.title}</span>
                                            {role.everything && (
                                                <Lock className="text-muted-foreground size-3.5" aria-label="Все доступы: изменить нельзя" />
                                            )}
                                            <span className="text-muted-foreground text-xs tabular-nums">{role.users_count}</span>
                                        </span>
                                    </th>

                                    {sections.map((section) =>
                                        columnsOf(section).map((column, index) => (
                                            <td
                                                // «Просмотр» and «Изменение» appear under several sections.
                                                key={`${section.key}.${column.key}`}
                                                className={cn('px-2 py-2.5 text-center', index === 0 && 'border-l')}
                                            >
                                                {cell(role, section, column)}
                                            </td>
                                        )),
                                    )}
                                </tr>
                            ))}

                            {visible.length === 0 && (
                                <tr className="border-t">
                                    <td
                                        colSpan={columnCount + 1}
                                        // The row spans the whole width of the table, so on a phone a
                                        // centred line would sit somewhere off to the right.
                                        className="text-muted-foreground px-3 py-12 text-left md:px-6 md:text-center"
                                    >
                                        Ничего не найдено.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            <p className="text-muted-foreground text-sm max-md:px-1 max-md:text-[13px]">
                Системный администратор проходит любую проверку, поэтому его строка отмечена целиком. Все остальные позиции, включая «Администратор»,
                получают ровно то, что отмечено в таблице. Отдельному сотруднику доступ можно выдать или снять в его карточке.
            </p>

            {picking && picked && picking.scope === EMPLOYEES && picking.mode === 'actions' && (
                <RightsDialog
                    title={`Действия с сотрудником: ${picked.title}`}
                    description="Что эта позиция делает с самим сотрудником, а не со строкой его карточки. Восстановить уволенного может тот, кто может уволить."
                    rights={actions}
                    held={held(picked)}
                    onChange={(permissions) => save(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope === EQUIPMENT && picking.mode === 'scopes' && (
                <EquipmentScopesDialog
                    subject={picked.title}
                    scopes={equipmentScopes}
                    held={held(picked)}
                    onChange={(permissions) => save(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope === EQUIPMENT && picking.mode === 'blocks' && (
                <RightsDialog
                    title={`Изменение оборудования: ${picked.title}`}
                    description="Карточка единицы правится блоками целиком. Открытый блок позиция меняет у любой единицы, которую видит: закрытая в «Просмотре» техника не правится и здесь."
                    rights={equipmentBlocks}
                    held={held(picked)}
                    onChange={(permissions) => save(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope === EQUIPMENT && picking.mode === 'actions' && (
                <RightsDialog
                    title={`Действия с оборудованием: ${picked.title}`}
                    description="Что эта позиция делает с самой единицей, а не со строкой её карточки. Каждая операция оставляет запись в журнале, так что видно, кто её провёл."
                    rights={equipmentActions}
                    held={held(picked)}
                    onChange={(permissions) => save(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope === DIRECTORIES && picking.mode === 'lists' && (
                <RightsDialog
                    title={`Какие справочники видно: ${picked.title}`}
                    description="Пять списков живут на одной странице, но открываются по одному: у каждого свои хозяева. Раздел «Справочники» появляется в меню, если открыт хотя бы один список, а закрытый список уносит с собой и право его менять."
                    rights={directoryLists}
                    held={held(picked)}
                    onChange={(permissions) => saveLists(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope === DIRECTORIES && picking.mode === 'edits' && (
                <RightsDialog
                    title={`Изменение справочников: ${picked.title}`}
                    description="Что позиция добавляет, переименовывает и удаляет в этих списках. Вести можно только то, что видно: список, закрытый в «Просмотре», недоступен и здесь."
                    rights={directoryEdits}
                    held={held(picked)}
                    onChange={(permissions) => save(picked, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}

            {picking && picked && picking.scope !== EQUIPMENT && picking.scope !== DIRECTORIES && picking.mode !== 'actions' && (
                // The two windows hold the same list of lines, so the heading has
                // to say whose card it is about, or the wrong half gets ticked.
                <CardFieldsDialog
                    mode={picking.mode}
                    subject={picking.scope === PROFILE ? `свой профиль — ${picked.title}` : picked.title}
                    groups={picking.scope === PROFILE ? profileFields : fields}
                    held={held(picked)}
                    onChange={(permissions) => saveFields(picked, picking.scope, picking.mode, permissions)}
                    onClose={() => setPicking(null)}
                />
            )}
        </DirectoriesLayout>
    );
}
