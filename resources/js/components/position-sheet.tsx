import { EditPencil } from '@/components/edit-pencil';
import { PaperAction, PaperBand, PaperBandRow } from '@/components/paper-band';
import { PaperApproval, PaperContents } from '@/components/paper-contents';
import { PositionName, type PositionUnit } from '@/components/position-name';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Info, Plus } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * «СФД» — the profile of a job, as the design draws it on a department's page:
 * the department's jobs in a strip, and the sheet of the one that is open.
 *
 * Everything under the strip is the drawing written down. The company keeps
 * this sheet as one text today, and the design takes it apart into fields,
 * tables, skills with a level each and a table of KPI — none of which is
 * recorded anywhere yet. So the sheet below is the picture: the same words, in
 * the same places, for whichever department it is opened on. The strip is the
 * one thing that is real, because the page already knows the jobs; choosing one
 * changes nothing yet.
 *
 * Nothing here writes anything. The pencil, «Версии», «Скачать .docx»,
 * «Добавить файл» and the rest wait for what is behind them.
 */
const PARTS = [
    { number: '01', title: 'Общая информация' },
    { number: '02', title: 'Должностные обязанности' },
    { number: '03', title: 'Образование и опыт' },
    { number: '04', title: 'Знание языков' },
    { number: '05', title: 'Жёсткие навыки' },
    { number: '06', title: 'Мягкие навыки' },
    { number: '07', title: 'Критерии оценки KPI' },
    { number: '08', title: 'Ответственность за файлы' },
    { number: '09', title: 'Ознакомление' },
];

const GENERAL = [
    { label: 'Департамент', value: 'Аналитика и Статистика' },
    { label: 'Должность', value: 'Руководитель' },
    { label: 'Категория', value: 'Менеджер' },
    { label: 'Грейд', value: 'Менеджер 1' },
];

const SUBORDINATES = [
    'Руководитель Научного отдела',
    'Руководитель отдела развития продуктового портфеля',
    'Руководитель отдела анализа производителей',
    'Руководитель отдела планирования заявок и логистики',
    'Руководитель отдела статистики и мониторинга',
];

const MANAGEMENT = [
    'Участие в стратегическом управлении Представительства.',
    'Участие в мероприятиях по организационному развитию Представительства.',
    'Поддержка корпоративной HR-стратегии и кадровой политики Представительства.',
    'Разработка ключевых бизнес-процессов департамента и отделов.',
    'Постоянное развитие сотрудников и процессов департамента и отделов.',
    'Управленческие задачи: планирование, организация, мотивация, контроль и координация деятельности.',
];

const PROCESSES = [
    'Участвовать в решении сложных вопросов (недопонимание между отделом регистрации и отделом контрактного производства по регистрации продуктов).',
    'Решить вопрос по дополнительным инвестициям на продукт при подготовке досье, при запросе образцов и стандартов, при регистрации.',
    'Тесное сотрудничество с руководителями с целью улучшения работы и выполнения плана Компании.',
    'Подготовить данные для встречи и участвовать на встречах с топ-производителями продуктов с целью улучшить отношения.',
];

const INTERACTIONS = [
    { name: 'Департамент Регистрации и Документации', text: 'Сотрудничество в решении сложных вопросов по регистрации продуктов.' },
    {
        name: 'Департамент Контрактного Производства',
        text: 'Сотрудничество в решении сложных вопросов по процессам поиска продуктов, подготовка данных для встреч.',
    },
    { name: 'ДРЭ', text: 'Обсуждение новых проектов, выбор портфеля для новых проектов, оценка и стратегия входа в рынок.' },
    { name: 'ДЗЛ', text: 'Получение информации о статусе производства и логистике продукта.' },
    { name: 'Отдел Цифрового маркетинга', text: 'Предоставление информации о продукте по их запросу.' },
    { name: 'Отдел Дизайна', text: 'Обсуждение новых продуктов для подготовки дизайна.' },
    {
        name: 'Промо Компании',
        text: 'Обсуждение с главой промо компаний развития портфеля и увеличения продаж в стране, выбор правильного продукта для портфеля.',
    },
];

const RESPONSIBILITY = [
    'Выполнение поставленных рабочих задач в сроки и с заданным качеством.',
    'Предложение эффективных и безопасных продуктов в портфель Компании.',
];

const AUTHORITY = [
    'Принимать решения по конкретным рабочим задачам в пределах компетентности.',
    'Инициировать изменения в работе подразделения.',
    'Инициировать изменения на уровне всей компании.',
];

const EDUCATION = [
    { label: 'Образование', value: 'Высшее образование' },
    { label: 'Профиль образования', value: 'Менеджмент и Маркетинг' },
    { label: 'Опыт работы', value: 'От 1 года, желательно' },
];

const LANGUAGES: { name: string; need: string; tone: 'success' | 'neutral' }[] = [
    { name: 'Русский', need: 'Обязательно', tone: 'success' },
    { name: 'Английский', need: 'Обязательно', tone: 'success' },
    { name: 'Другие языки', need: 'Приветствуются', tone: 'neutral' },
];

const HARD = [
    { name: 'Бизнес-аналитика', level: 4, text: 'Применение знаний о регионе и рынке для достижения целей организации.' },
    { name: 'Компьютерные навыки', level: 3, text: 'Умение работать в Excel, Word, PowerPoint, Internet Explorer.' },
    {
        name: 'Управление продуктовым портфелем',
        level: 4,
        text: 'Умение систематизировать препараты компании как портфель и классифицировать их по нужным классификациям (ATX, нозология).',
    },
    { name: 'Работа с базой данных', level: 4, text: 'Умение превращать данные в информацию, анализировать данные и давать решения и рекомендации.' },
    { name: 'Управление проектами', level: 4, text: 'Умение планировать, организовывать, координировать, контролировать проекты.' },
    { name: 'Развитие бизнеса', level: 4, text: 'Умение развивать компанию, оценивая её эффективность и находя области для улучшения.' },
    { name: 'Маркетинг', level: 4, text: 'Понимание основных методик маркетинга, современных моделей развития и управления брендом.' },
    { name: 'Менеджмент', level: 4, text: 'Умение управлять процессами, проектами и сотрудниками департамента.' },
];

const SOFT = [
    {
        title: 'Достойное и честное поведение',
        items: [
            { number: 22, name: 'Этика и моральные ценности' },
            { number: 29, name: 'Честность и доверие' },
        ],
    },
    {
        title: 'Забота об окружающих',
        items: [
            { number: 7, name: 'Забота о подчинённых' },
            { number: 10, name: 'Сочувствие' },
        ],
    },
    {
        title: 'Навыки поведения в организации',
        items: [
            { number: 38, name: 'Способность ориентироваться внутри организации' },
            { number: 48, name: 'Дипломатичность' },
        ],
    },
    {
        title: 'Навыки построения отношений',
        items: [
            { number: 3, name: 'Доступность для окружающих' },
            { number: 31, name: 'Построение межличностных отношений' },
        ],
    },
    {
        title: 'Нацеленность на достижение результата',
        items: [
            { number: 1, name: 'Стремление к действию' },
            { number: 43, name: 'Упорство' },
            { number: 53, name: 'Нацеленность на результат' },
        ],
    },
    {
        title: 'Организованность',
        items: [
            { number: 39, name: 'Организаторские способности' },
            { number: 47, name: 'Планирование' },
            { number: 62, name: 'Управление временем' },
        ],
    },
    {
        title: 'Отношения с вышестоящими сотрудниками',
        items: [
            { number: 6, name: 'Стремление к карьерному росту' },
            { number: 8, name: 'Уверенность при общении с руководством' },
        ],
    },
    {
        title: 'Понимание бизнеса',
        items: [{ number: 5, name: 'Деловая хватка' }],
    },
];

const KPI = [
    {
        task: 'Проводить систематический научный анализ фармпродуктов',
        product: 'Количество продуктов, которые изучили научные данные для предложения Промо Компании',
        steps: '6 шагов',
        period: '—',
    },
    {
        task: 'Подготовка презентации по Промо Компаниям',
        product: 'Количество продуктов, по которым подготовлены презентации для врачей',
        steps: '4 шага',
        period: 'Каждый месяц',
    },
    {
        task: 'Ответы и решения на запросы других отделов',
        product: 'Количество предоставленных ответов и решений',
        steps: '6 шагов',
        period: '—',
    },
    {
        task: 'Продукты, отработанные Промо Компаниям как предложения для Портфеля',
        product: 'Количество предложенных и согласованных для Портфеля продуктов',
        steps: '3 шага',
        period: '—',
    },
    {
        task: 'Продукты, добавленные в Портфель',
        product: 'Количество добавленных в Портфель продуктов',
        steps: '2 шага',
        period: '—',
    },
];

/** One part of the sheet, under a number and a rule. */
function Part({ number, title, action, children }: { number: string; title: string; action?: ReactNode; children: ReactNode }) {
    return (
        <section className="flex min-w-0 flex-col gap-3">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-b pb-2">
                <span className="text-brand-strong text-[13px] font-semibold tabular-nums dark:text-[#C5E27A]">{number}</span>
                <h3 className="text-[13px] font-semibold tracking-wide uppercase">{title}</h3>
                {action && <span className="ml-auto">{action}</span>}
            </div>
            {children}
        </section>
    );
}

/** A fact of the sheet: what it is called, and what it says. */
function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="text-muted-foreground text-[11px]">{label}</span>
            <span className="text-[13px] font-semibold">{value}</span>
        </div>
    );
}

/** A list the sheet numbers itself, rather than leaving to the browser. */
function Numbered({ items }: { items: string[] }) {
    return (
        <ol className="flex flex-col gap-2">
            {items.map((item, index) => (
                <li key={item} className="flex gap-2.5 text-[13px] leading-relaxed">
                    <span className="text-muted-foreground shrink-0 tabular-nums">{index + 1}</span>
                    <span className="min-w-0">{item}</span>
                </li>
            ))}
        </ol>
    );
}

/** The duties of one group, under the name of it. */
function Duties({ title, items, more }: { title: string; items: string[]; more?: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <p className="text-[13px] font-semibold">{title}</p>
            <ul className="flex flex-col gap-1.5">
                {items.map((item) => (
                    <li key={item} className="text-muted-foreground flex gap-2.5 text-[13px] leading-relaxed">
                        <span className="bg-muted-foreground/60 mt-[0.5rem] size-1 shrink-0 rounded-full" aria-hidden="true" />
                        <span className="min-w-0">{item}</span>
                    </li>
                ))}
            </ul>
            {more}
        </div>
    );
}

/** How much of a skill the job asks for, out of five. */
function Level({ level }: { level: number }) {
    return (
        <span className="flex shrink-0 items-center gap-1" aria-label={`Требуемый уровень: ${level} из 5`}>
            {Array.from({ length: 5 }, (_, index) => (
                <span key={index} className={cn('h-1.5 w-4 rounded-[2px]', index < level ? 'bg-brand' : 'bg-muted')} aria-hidden="true" />
            ))}
        </span>
    );
}

export function PositionSheet({ positions }: { positions: { id: number; name: string; unit: PositionUnit | null }[] }) {
    return (
        <div className="flex min-w-0 flex-col px-4 py-4 md:px-6 md:py-5">
            <PaperBand title="Профиль должности" pencil={<EditPencil label="Изменить профиль должности" onClick={() => {}} />}>
                {/* The jobs of the department, the first of them open. A strip
                    rather than a list: there are as many as the department has,
                    and the sheet under it is what the page is for. */}
                {positions.length > 0 && (
                    <PaperBandRow className="flex-nowrap overflow-x-auto py-2.5 [scrollbar-width:thin]">
                        {positions.map((position, index) => (
                            <span
                                key={position.id}
                                className={cn(
                                    'flex h-7 shrink-0 items-center rounded-md px-2.5 text-[13px] whitespace-nowrap',
                                    index === 0 ? 'bg-brand-soft font-semibold dark:bg-white/10' : 'text-muted-foreground bg-muted/60',
                                )}
                            >
                                <PositionName name={position.name} unit={position.unit} />
                            </span>
                        ))}
                    </PaperBandRow>
                )}
            </PaperBand>

            <div className="mt-4 lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-6">
                <div className="max-lg:hidden">
                    <PaperContents parts={PARTS} />
                </div>

                <div className="relative flex min-w-0 flex-col gap-6">
                    <div className="absolute top-0 right-0 w-[24rem] max-lg:hidden">
                        <PaperApproval />
                    </div>

                    <h2 className="text-[15px] leading-snug font-semibold uppercase lg:min-h-[7.5rem] lg:pr-[25.5rem]">
                        Профиль должности руководителя Департамента Аналитики и Статистики
                    </h2>

                    {/* Why the sheet is worth keeping right: it is read twice —
                        here, and on the card of whoever holds the job. */}
                    <p className="bg-muted/40 text-muted-foreground flex gap-2.5 rounded-lg border px-3 py-2.5 text-[13px] leading-relaxed">
                        <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>
                            Жёсткие и мягкие навыки, языки и KPI из этого профиля подтягиваются в профиль сотрудника на этой должности. Там они
                            сравниваются с фактическим уровнем и целями ПИР.
                        </span>
                    </p>

                    <Part number="01" title="Общая информация">
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                            {GENERAL.map((fact) => (
                                <Fact key={fact.label} {...fact} />
                            ))}
                        </div>

                        <Fact label="Подчинение" value="Руководителю Представительства" />

                        <div className="flex flex-col gap-2">
                            <span className="text-muted-foreground text-[11px]">Подчинённые</span>
                            <div className="flex flex-wrap gap-2">
                                {SUBORDINATES.map((name, index) => (
                                    <span key={name} className="bg-muted/60 flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-[13px]">
                                        <span className="text-muted-foreground tabular-nums">{index + 1}</span>
                                        {name}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </Part>

                    <Part number="02" title="Должностные обязанности">
                        <Duties title="Функциональные обязанности · Менеджмент" items={MANAGEMENT} />

                        <Duties title="Функциональные обязанности · Процессы" items={PROCESSES} more={<PaperAction>Показать ещё 14</PaperAction>} />

                        <div className="flex flex-col gap-2">
                            <p className="text-[13px] font-semibold">Взаимодействие с другими компаниями, департаментами, отделами</p>
                            <div className="rounded-lg border">
                                {INTERACTIONS.map((row, index) => (
                                    <div
                                        key={row.name}
                                        className="grid grid-cols-[2rem_minmax(0,14rem)_minmax(0,1fr)] gap-3 border-b px-3 py-2.5 text-[13px] last:border-0"
                                    >
                                        <span className="text-muted-foreground tabular-nums">{index + 1}</span>
                                        <span className="font-semibold">{row.name}</span>
                                        <span className="text-muted-foreground leading-relaxed">{row.text}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="flex flex-col gap-2 rounded-lg border px-4 py-3">
                                <p className="text-[13px] font-semibold">Сфера ответственности</p>
                                <Numbered items={RESPONSIBILITY} />
                            </div>
                            <div className="flex flex-col gap-2 rounded-lg border px-4 py-3">
                                <p className="text-[13px] font-semibold">Сфера полномочий</p>
                                <Numbered items={AUTHORITY} />
                            </div>
                        </div>
                    </Part>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Part number="03" title="Образование и опыт">
                            <div className="flex flex-col gap-3">
                                {EDUCATION.map((fact) => (
                                    <Fact key={fact.label} {...fact} />
                                ))}
                            </div>
                        </Part>

                        <Part number="04" title="Знание языков">
                            <div className="flex flex-col gap-2">
                                {LANGUAGES.map((language) => (
                                    <div key={language.name} className="flex items-center justify-between gap-3">
                                        <span className="text-[13px] font-semibold">{language.name}</span>
                                        <StatusBadge tone={language.tone}>{language.need}</StatusBadge>
                                    </div>
                                ))}
                            </div>
                        </Part>
                    </div>

                    <Part
                        number="05"
                        title="Жёсткие навыки"
                        action={<span className="text-muted-foreground text-[11px]">Требуемый уровень из 5</span>}
                    >
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {HARD.map((skill) => (
                                <div key={skill.name} className="flex flex-col gap-2 rounded-lg border px-4 py-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <span className="text-[13px] font-semibold">{skill.name}</span>
                                        <Level level={skill.level} />
                                    </div>
                                    <p className="text-muted-foreground text-[13px] leading-relaxed">{skill.text}</p>
                                </div>
                            ))}
                        </div>
                    </Part>

                    <Part number="06" title="Мягкие навыки">
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {SOFT.map((group) => (
                                <div key={group.title} className="flex flex-col gap-2 rounded-lg border px-4 py-3">
                                    <p className="text-muted-foreground text-[11px] font-semibold tracking-wide uppercase">{group.title}</p>
                                    <ul className="flex flex-col gap-1.5">
                                        {group.items.map((item) => (
                                            <li key={item.number} className="flex items-start gap-2 text-[13px]">
                                                <span className="bg-muted text-muted-foreground rounded px-1.5 py-0.5 text-[11px] tabular-nums">
                                                    {item.number}
                                                </span>
                                                <span className="min-w-0 leading-relaxed">{item.name}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </Part>

                    <Part number="07" title="Критерии оценки эффективности (KPI)" action={<PaperAction>Связанные сотрудники</PaperAction>}>
                        <div className="rounded-lg border">
                            <div className="text-muted-foreground grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_9rem] gap-3 border-b px-3 py-2 text-[11px]">
                                <span>Задача</span>
                                <span>Ценный конечный продукт</span>
                                <span>Шагов</span>
                                <span>Периодичность</span>
                            </div>
                            {KPI.map((row) => (
                                <div
                                    key={row.task}
                                    className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_6rem_9rem] gap-3 border-b px-3 py-3 text-[13px] last:border-0"
                                >
                                    <span className="font-semibold">{row.task}</span>
                                    <span className="text-muted-foreground leading-relaxed">{row.product}</span>
                                    <span className="text-muted-foreground">{row.steps}</span>
                                    <span className="text-muted-foreground">{row.period}</span>
                                </div>
                            ))}
                        </div>
                    </Part>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <Part number="08" title="Ответственность за файлы">
                            <div className="flex flex-col items-center gap-3 rounded-lg border px-4 py-6 text-center">
                                <p className="text-muted-foreground max-w-[42ch] text-[13px] leading-relaxed">
                                    Раздел пока пуст. Добавьте документы и файлы, за которые отвечает должность.
                                </p>
                                <Button type="button" variant="outline" size="sm">
                                    <Plus />
                                    Добавить файл
                                </Button>
                            </div>
                        </Part>

                        <Part number="09" title="Ознакомление">
                            <div className="flex flex-col items-start gap-2 rounded-lg border px-4 py-3">
                                <StatusBadge tone="danger">Должность вакантна</StatusBadge>
                                <p className="text-muted-foreground text-[13px] leading-relaxed">
                                    Ознакомление запросится у сотрудника при приёме на работу.
                                </p>
                                <PaperAction>Открыть вакансию</PaperAction>
                            </div>
                        </Part>
                    </div>
                </div>
            </div>
        </div>
    );
}
