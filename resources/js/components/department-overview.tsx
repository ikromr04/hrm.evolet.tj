import { CardSection } from '@/components/card-section';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

/**
 * «Обзор» — a department read at a glance: what its staff is, what is open,
 * what today looks like and what is waiting for somebody.
 *
 * This is the design drawn and nothing more. Every number below is the one in
 * the picture: nothing is read from the database, nothing is counted, and the
 * words that look like links lead nowhere yet. The data each block wants is
 * written down beside it, so giving the page real figures is a matter of
 * handing these blocks props instead of the constants at the top of this file.
 *
 * Desktop only, like the page that holds it: a phone opens a department on its
 * own list of screens and this one is not among them.
 */

/** The row of figures over the page. */
const TILES: { label: string; value: string; note: string; tone?: 'good' | 'warn' }[] = [
    { label: 'Сотрудники', value: '11', note: '8 должностей' },
    { label: 'Укомплектованность', value: '73%', note: '11 из 15 ставок', tone: 'good' },
    { label: 'Открытые вакансии', value: '4', note: '1 без кандидатов', tone: 'warn' },
    { label: 'Средний KPI · 3 кв.', value: '94%', note: 'цель 90%', tone: 'good' },
    { label: 'Сегодня на работе', value: '8 из 11', note: '1 в отпуске, 1 на больничном' },
];

/** Every unit of the department: who leads it and how much of it is filled. */
const UNITS: { code: string; name: string; head: string | null; filled: number; total: number }[] = [
    { code: 'ДАС', name: 'Руководство департамента', head: null, filled: 0, total: 1 },
    { code: 'НО', name: 'Научный отдел', head: null, filled: 2, total: 3 },
    { code: 'ОАП', name: 'Отдел анализа производителей', head: 'Гафуров Санджар', filled: 4, total: 5 },
    { code: 'ОППЛ', name: 'Отдел планирования заявок и логистики', head: 'Кароматов Бахром', filled: 1, total: 1 },
    { code: 'ОРПП', name: 'Отдел развития продуктового портфеля', head: null, filled: 4, total: 5 },
];

/** The stages a candidate walks, in order; the funnel of a vacancy is these five. */
const STAGES: { label: string; fill: string }[] = [
    { label: 'Новые', fill: 'bg-neutral-400 dark:bg-neutral-600' },
    { label: 'Скрининг', fill: 'bg-neutral-300 dark:bg-neutral-400' },
    { label: 'Интервью', fill: 'bg-blue-500' },
    { label: 'Тестовое', fill: 'bg-brand' },
    { label: 'Оффер', fill: 'bg-emerald-500' },
];

/** What is open in the department; `funnel` counts candidates stage by stage. */
const VACANCIES: { title: string; unit: string; candidates: number; funnel: number[]; opened: string }[] = [
    { title: 'Руководитель Отдела (ДАС)', unit: 'ДАС', candidates: 6, funnel: [2, 1, 2, 1, 0], opened: '38 дней' },
    { title: 'Аналитик (ОАП)', unit: 'ОАП', candidates: 9, funnel: [3, 2, 0, 2, 2], opened: '21 день' },
    { title: 'Руководитель Отдела (ОРПП)', unit: 'ОРПП', candidates: 3, funnel: [1, 1, 1, 0, 0], opened: '12 дней' },
    { title: 'Руководитель Отдела (НО)', unit: 'НО', candidates: 0, funnel: [], opened: 'Черновик' },
];

/** What the department holds, in four numbers. */
const EQUIPMENT: { label: string; value: string; tone?: 'bad' }[] = [
    { label: 'Выдано', value: '58' },
    { label: 'Ноутбуки', value: '11' },
    { label: 'Акты ждут подписи', value: '3', tone: 'bad' },
    { label: 'На обслуживании', value: '0' },
];

/** Where everybody is today. */
const TODAY = {
    date: '20 окт.',
    present: 8,
    total: 11,
    rows: [
        { label: 'В офисе', count: 6, fill: 'bg-brand' },
        { label: 'Удалённо', count: 2, fill: 'bg-blue-500' },
        { label: 'Отпуск', count: 1, fill: 'bg-neutral-300 dark:bg-neutral-500' },
        { label: 'Больничный', count: 1, fill: 'bg-red-400' },
        { label: 'Командировка', count: 1, fill: 'bg-neutral-400 dark:bg-neutral-600' },
    ],
};

/** The quarter, unit by unit, against the one target they share. */
const KPI = {
    target: 90,
    rows: [
        { code: 'НО', value: 91 },
        { code: 'ОАП', value: 96 },
        { code: 'ОППЛ', value: 93 },
        { code: 'ОРПП', value: 95 },
    ],
};

/** Who is away next, and for how long. */
const ABSENCES: { initials: string; name: string; kind: string; when: string }[] = [
    { initials: 'МР', name: 'Мирзоев Рустам', kind: 'Отпуск · ежегодный', when: '21 — 27 окт.' },
    { initials: 'ЛД', name: 'Латипова Дилафруз', kind: 'Командировка', when: '23 — 25 окт.' },
    { initials: 'АТ', name: 'Аминов Тимур', kind: 'Отпуск · ежегодный', when: '2 — 15 нояб.' },
];

/** What is waiting for somebody, and the one thing to do about each. */
const ATTENTION: { text: string; action: string }[] = [
    { text: 'Положение: не ознакомлены 2 сотрудника', action: 'Напомнить' },
    { text: 'Профиль должности «Руководитель»: пересмотр до 31 дек.', action: 'Открыть СФД' },
    { text: '3 акта выдачи оборудования ждут подписи', action: 'Открыть' },
    { text: 'У 2 сотрудников не заполнен личный листок', action: 'Запросить' },
];

/** The word at the end of a band, and the word under a line of «Требует внимания». */
function Action({ children }: { children: ReactNode }) {
    return (
        <button type="button" className="text-brand-strong text-[13px] font-medium whitespace-nowrap hover:underline dark:text-[#C5E27A]">
            {children}
        </button>
    );
}

/** One post of a unit: filled, or standing open. */
function Seats({ filled, total }: { filled: number; total: number }) {
    return (
        <span className="flex flex-wrap items-center gap-1" aria-label={`Занято ${filled} из ${total}`}>
            {Array.from({ length: total }, (_, index) => (
                <Seat key={index} filled={index < filled} />
            ))}
        </span>
    );
}

function Seat({ filled }: { filled: boolean }) {
    return <span className={cn('h-2.5 w-6 rounded-[3px]', filled ? 'bg-brand' : 'border border-dashed border-amber-500/70')} aria-hidden="true" />;
}

/** A row of the department's units: the name, who leads it, its posts, its count. */
function UnitRow({ unit }: { unit: (typeof UNITS)[number] }) {
    return (
        <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,13rem)_minmax(0,11rem)_4rem] items-center gap-4 border-b px-4 py-3 last:border-0 md:px-6">
            <span className="flex min-w-0 flex-col">
                <span className="text-sm font-semibold">{unit.code}</span>
                <span className="text-muted-foreground truncate text-[13px]">{unit.name}</span>
            </span>

            {unit.head === null ? (
                <span className="text-[13px] font-medium text-red-600 dark:text-[#F7A19A]">Руководитель вакантен</span>
            ) : (
                <span className="truncate text-sm">{unit.head}</span>
            )}

            <Seats filled={unit.filled} total={unit.total} />

            <span className="text-right text-sm tabular-nums">
                <span className="font-semibold">{unit.filled}</span> <span className="text-muted-foreground">из {unit.total}</span>
            </span>
        </div>
    );
}

/** Where the candidates of one vacancy stand, stage by stage. */
function Funnel({ funnel }: { funnel: number[] }) {
    const total = funnel.reduce((sum, count) => sum + count, 0);

    // A vacancy nobody has answered yet — a draft among them — is an empty
    // track rather than no track at all: the column keeps its line.
    if (total === 0) return <span className="bg-muted block h-1.5 rounded-full" aria-label="Кандидатов нет" />;

    return (
        <span className="flex h-1.5 items-stretch gap-0.5">
            {funnel.map((count, index) =>
                count === 0 ? null : (
                    <span
                        key={index}
                        className={cn('rounded-full', STAGES[index].fill)}
                        style={{ flexGrow: count }}
                        aria-label={`${STAGES[index].label}: ${count}`}
                    />
                ),
            )}
        </span>
    );
}

/** The dot before a word in a legend. */
function Dot({ fill }: { fill: string }) {
    return <span className={cn('size-2 shrink-0 rounded-full', fill)} aria-hidden="true" />;
}

export function DepartmentOverview() {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                {TILES.map((tile) => (
                    <Card key={tile.label} className="flex flex-col gap-1 rounded-xl px-4 py-3.5">
                        <p className="text-muted-foreground text-[13px]">{tile.label}</p>
                        <p
                            className={cn(
                                'text-[28px] leading-9 font-semibold tabular-nums',
                                tile.tone === 'good' && 'text-brand-strong dark:text-[#C5E27A]',
                                tile.tone === 'warn' && 'text-amber-600 dark:text-[#F8C471]',
                            )}
                        >
                            {tile.value}
                        </p>
                        <p className="text-muted-foreground text-xs">{tile.note}</p>
                    </Card>
                ))}
            </div>

            {/* The department on the left, the day and what it asks for on the right. */}
            <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    <CardSection title="Штат по отделам" action={<Action>Штатное расписание</Action>} bodyClassName="-mx-4 -mb-5 md:-mx-6">
                        {UNITS.map((unit) => (
                            <UnitRow key={unit.code} unit={unit} />
                        ))}

                        <div className="text-muted-foreground flex items-center gap-5 px-4 py-3 text-xs md:px-6">
                            <span className="flex items-center gap-2">
                                <Seat filled /> Занятая ставка
                            </span>
                            <span className="flex items-center gap-2">
                                <Seat filled={false} /> Вакантная ставка
                            </span>
                        </div>
                    </CardSection>

                    <CardSection
                        title="Открытые вакансии подразделения"
                        action={<Action>Открыть в рекрутменте</Action>}
                        bodyClassName="-mx-4 -mb-5 md:-mx-6"
                    >
                        <div className="text-muted-foreground grid grid-cols-[minmax(0,1fr)_6rem_minmax(0,16rem)_6rem] gap-4 px-4 pb-2 text-xs md:px-6">
                            <span>Вакансия</span>
                            <span>Кандидатов</span>
                            <span>Воронка</span>
                            <span className="text-right">Открыта</span>
                        </div>

                        {VACANCIES.map((vacancy) => (
                            <div
                                key={vacancy.title}
                                className="grid grid-cols-[minmax(0,1fr)_6rem_minmax(0,16rem)_6rem] items-center gap-4 border-t px-4 py-3 md:px-6"
                            >
                                <span className="flex min-w-0 flex-col">
                                    <span className="text-brand-strong truncate text-sm font-medium dark:text-[#C5E27A]">{vacancy.title}</span>
                                    <span className="text-muted-foreground text-[13px]">{vacancy.unit}</span>
                                </span>
                                <span className="text-sm font-semibold tabular-nums">{vacancy.candidates}</span>
                                <Funnel funnel={vacancy.funnel} />
                                <span className="text-muted-foreground text-right text-[13px] whitespace-nowrap">{vacancy.opened}</span>
                            </div>
                        ))}

                        <div className="text-muted-foreground flex flex-wrap items-center gap-x-5 gap-y-2 border-t px-4 py-3 text-xs md:px-6">
                            {STAGES.map((stage) => (
                                <span key={stage.label} className="flex items-center gap-2">
                                    <Dot fill={stage.fill} /> {stage.label}
                                </span>
                            ))}
                        </div>
                    </CardSection>

                    <CardSection title="Оборудование подразделения" action={<Action>Подробнее</Action>}>
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                            {EQUIPMENT.map((figure) => (
                                <div key={figure.label} className="flex flex-col gap-1">
                                    <span className="text-muted-foreground text-[13px]">{figure.label}</span>
                                    <span
                                        className={cn(
                                            'text-2xl font-semibold tabular-nums',
                                            figure.tone === 'bad' && 'text-red-600 dark:text-[#F7A19A]',
                                        )}
                                    >
                                        {figure.value}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardSection>
                </div>

                <div className="flex min-w-0 flex-col gap-4">
                    <CardSection title={`Сегодня · ${TODAY.date}`} action={<Action>Календарь</Action>}>
                        <p className="flex items-baseline gap-2">
                            <span className="text-[32px] leading-9 font-semibold tabular-nums">{TODAY.present}</span>
                            <span className="text-muted-foreground text-sm">из {TODAY.total} на работе</span>
                        </p>

                        {/* One line for the whole department, in the order of the legend under it. */}
                        <span className="flex h-2 items-stretch gap-0.5">
                            {TODAY.rows.map((row) => (
                                <span
                                    key={row.label}
                                    className={cn('rounded-full', row.fill)}
                                    style={{ flexGrow: row.count }}
                                    aria-label={`${row.label}: ${row.count}`}
                                />
                            ))}
                        </span>

                        <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-[13px]">
                            {TODAY.rows.map((row) => (
                                <div key={row.label} className="flex items-center gap-2">
                                    <Dot fill={row.fill} />
                                    <dt className="text-muted-foreground min-w-0 flex-1 truncate">{row.label}</dt>
                                    <dd className="font-medium tabular-nums">{row.count}</dd>
                                </div>
                            ))}
                        </dl>
                    </CardSection>

                    <CardSection title="KPI по отделам · 3 кв." action={<span className="text-muted-foreground text-xs">цель {KPI.target}%</span>}>
                        <div className="flex flex-col gap-3">
                            {KPI.rows.map((row) => (
                                <div key={row.code} className="grid grid-cols-[3.5rem_minmax(0,1fr)_3rem] items-center gap-3">
                                    <span className="truncate text-[13px] font-medium">{row.code}</span>
                                    {/* The target is a notch in the track, so a unit
                                        is read against it rather than against the end. */}
                                    <span className="bg-muted relative block h-1.5 rounded-full">
                                        <span className="bg-brand absolute inset-y-0 left-0 rounded-full" style={{ width: `${row.value}%` }} />
                                        <span className="bg-foreground/40 absolute inset-y-[-2px] w-px" style={{ left: `${KPI.target}%` }} />
                                    </span>
                                    <span className="text-right text-[13px] font-semibold tabular-nums">{row.value}%</span>
                                </div>
                            ))}
                        </div>
                    </CardSection>

                    <CardSection title="Ближайшие отсутствия" bodyClassName="-mx-4 -mb-5 md:-mx-6">
                        {ABSENCES.map((absence) => (
                            <div key={absence.name} className="flex items-center gap-3 border-b px-4 py-3 last:border-0 md:px-6">
                                <span className="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                                    {absence.initials}
                                </span>
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-sm font-medium">{absence.name}</span>
                                    <span className="text-muted-foreground truncate text-[13px]">{absence.kind}</span>
                                </span>
                                <span className="text-muted-foreground shrink-0 text-[13px] whitespace-nowrap">{absence.when}</span>
                            </div>
                        ))}
                    </CardSection>

                    <CardSection title="Требует внимания">
                        <ul className="flex flex-col gap-3">
                            {ATTENTION.map((item) => (
                                <li key={item.text} className="flex gap-2.5">
                                    <span className="mt-1.5 size-2 shrink-0 rounded-full bg-red-400" aria-hidden="true" />
                                    <span className="flex min-w-0 flex-col items-start gap-0.5">
                                        <span className="text-[13px]">{item.text}</span>
                                        <Action>{item.action}</Action>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardSection>
                </div>
            </div>
        </div>
    );
}
