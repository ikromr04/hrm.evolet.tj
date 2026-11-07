import { plural } from '@/lib/plural';
import { differenceInMonths, differenceInYears, parseISO } from 'date-fns';

export type Sex = 'male' | 'female';
export type Marital = 'single' | 'married';

/** Private data, sent only to viewers allowed to see it. */
export interface PrivateDetails {
    birth_date: string | null;
    nationality: string | null;
    /** Every country the person is a citizen of; there may be several. */
    citizenship: string[] | null;
    home_address: string | null;
    phone: string | null;
    sos_phone: string | null;
    /** Whose number the SOS phone is: "Мама — Дилором". */
    sos_contact: string | null;
    marital_status: Marital | null;
    /** At most one, so it sits here rather than in a list like the children. */
    spouse_name: string | null;
    /**
     * «1971-03»: the month, as every date of birth the family is asked for is
     * kept. A personnel department is told the month somebody was born and
     * seldom the day, and a day nobody was asked for would print as a fact.
     */
    spouse_birth_date: string | null;
    /** Where they work or study, written out as one line, as a relative's is. */
    spouse_occupation: string | null;
    /** null until anyone filled the card in, false once HR states there are none. */
    has_children: boolean | null;
    hired_at: string | null;
    /** The same three answers the rest of the family is named by; the month again. */
    children: { full_name: string; birth_date: string | null; occupation: string | null }[];
    /** The rest of the family the card names: a line apiece, as the sheet prints them. */
    relatives: { relation: string; full_name: string; birth_date: string | null; occupation: string | null }[];
}

export const sexLabels: Record<Sex, string> = { male: 'Мужской', female: 'Женский' };

export const maritalLabels: Record<Sex, Record<Marital, string>> = {
    male: { single: 'Не женат', married: 'Женат' },
    female: { single: 'Не замужем', married: 'Замужем' },
};

/**
 * Every month cut to three letters and a full stop, so that a column of dates
 * lines up: "нояб." beside "мая" would not.
 */
export const shortMonths = ['янв.', 'фев.', 'мар.', 'апр.', 'мая.', 'июн.', 'июл.', 'авг.', 'сен.', 'окт.', 'ноя.', 'дек.'];

/** "2026-11-05" -> "05 ноя. 2026": the day is padded so a column of dates is one width. */
export function formatDate(value: string | null): string | null {
    if (!value) return null;

    const date = parseISO(value);

    return `${String(date.getDate()).padStart(2, '0')} ${shortMonths[date.getMonth()]} ${date.getFullYear()}`;
}

/**
 * "2024-10" -> "10.2024": one end of a span, in figures.
 *
 * For the months a period is counted between — «10.2024 — 01.2025» — where the
 * two ends are read against each other rather than as dates in their own right,
 * and figures of one width line up where names of five letters and three would
 * not.
 *
 * Read off the written month rather than through a calendar: a month turned
 * into a local moment to be asked its name can come back the month before it
 * either side of midnight.
 */
export function formatPeriod(value: string | null): string | null {
    const [year, month] = (value ?? '').split('-');

    return year && month ? `${month.padStart(2, '0')}.${year}` : null;
}

/**
 * "1971-03" -> "мар. 1971": a date the card knows only to the month.
 *
 * Said the way every other date here is said, with the name of the month, and
 * not in figures: a date of birth in «Состав семьи» answers the same question
 * as the one at the head of the sheet, and «03.1971» standing under «13 янв.
 * 2005» would be one question written two ways. A period of work is the other
 * thing and keeps its figures — see formatPeriod.
 */
export function formatMonth(value: string | null): string | null {
    const [year, month] = (value ?? '').split('-');
    // The months above are cut for standing after a day — «05 мая 2026» — and
    // one of them is a whole word in that case rather than a short form. On its
    // own a month is named and not counted from, so May is «май» here.
    const name = Number(month) === 5 ? 'май' : shortMonths[Number(month) - 1];

    return year && name ? `${name} ${year}` : null;
}

export const capitalize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

/** +992901243299 -> +992 90 124 32 99 */
export const formatPhone = (phone: string) => phone.replace(/^\+992(\d{2})(\d{3})(\d{2})(\d{2})$/, '+992 $1 $2 $3 $4');

/** "34 года" */
export function age(birthDate: string): string {
    const years = differenceInYears(new Date(), parseISO(birthDate));

    return `${years} ${plural(years, ['год', 'года', 'лет'])}`;
}

/** Time since a date: "5 лет 6 мес.", "3 мес." */
export function tenure(since: string): string {
    const months = differenceInMonths(new Date(), parseISO(since));
    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years > 0 && `${years} ${plural(years, ['год', 'года', 'лет'])}`, (rest > 0 || years === 0) && `${rest} мес.`].filter(Boolean).join(' ');
}

/** "5 сотрудников" */
export const peopleLabel = (count: number) => `${count} ${plural(count, ['сотрудник', 'сотрудника', 'сотрудников'])}`;

/** One place of study; private. */
export interface Education {
    institution: string;
    faculty: string;
    specialty: string;
    /** What the diploma made of them; null while they are still studying. */
    qualification: string | null;
    started_year: number;
    /** Null while still studying. */
    graduated_year: number | null;
    /**
     * The diploma, where one was attached: what it was called and where to ask
     * for it. A path on the disk is never sent — the address answers only
     * somebody who may read this card.
     */
    document: { name: string | null; url: string } | null;
}

/**
 * The document behind a record of the card: what it is called and where to ask
 * for it.
 *
 * The name is the one the file arrived under, which is the name of the paper —
 * a paper is a PDF here, and it is renamed before it is sent rather than
 * labelled afterwards, so nobody retypes it. A path on the disk is never sent:
 * the address answers only somebody who may read this card.
 */
export interface CardDocument {
    name: string;
    url: string;
}

/**
 * One record of the three parts that are a line of text and a paper apiece —
 * «Учёная степень, звание», «Государственные награды, поощрения», «Отношение к
 * воинской обязанности».
 *
 * All three are the same two answers, so all three arrive in one shape; what
 * the line says is the record's own business and the card prints it as it
 * stands. The id is here because the sheet's form corrects a record where it
 * stands, which is what leaves its paper clipped to it.
 */
export interface PaperRecord {
    id: number;
    name: string;
    document: CardDocument | null;
}

/** A previous job, known to the month; private. */
export interface WorkExperience {
    organization: string;
    position: string;
    started_month: number;
    started_year: number;
    /** All three null while the person still works there. */
    ended_month: number | null;
    ended_year: number | null;
    /** Why it ended, in the words of the labour book it was copied from. */
    reason: string | null;
}

/**
 * One unit of company hardware the employee holds. It belongs to the company,
 * so the profile only reads it: handing out and taking back live in the
 * equipment section.
 */
export interface Equipment {
    id: number;
    name: string;
    /** The first couple of things its category asks about, as it answered them. */
    details: string | null;
    inventory_number: string;
    /** Category from the directory, e.g. "Ноутбуки". */
    type: string | null;
    issued_at: string | null;
    /** Whether its own card is this viewer's to open; absent where nobody asked. */
    open?: boolean;
}

export const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

/** "3 года 4 мес." between two months, the end month not counted. */
export function monthsSpan(fromYear: number, fromMonth: number, toYear: number, toMonth: number): string {
    const months = Math.max(1, toYear * 12 + toMonth - (fromYear * 12 + fromMonth));
    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years > 0 && `${years} ${plural(years, ['год', 'года', 'лет'])}`, rest > 0 && `${rest} мес.`].filter(Boolean).join(' ');
}

export type LanguageLevel = 'beginner' | 'intermediate' | 'advanced';

/** From least to most fluent. */
export const languageLevels: LanguageLevel[] = ['beginner', 'intermediate', 'advanced'];

export const languageLevelLabels: Record<LanguageLevel, string> = {
    beginner: 'Начальный',
    intermediate: 'Средний',
    advanced: 'Продвинутый',
};

/**
 * A language an employee speaks, public like positions.
 *
 * Known by its name: a language is a word written on this card rather than an
 * entry of a list the company keeps, and the staff list narrows by that word.
 */
export interface SpokenLanguage {
    name: string;
    level: LanguageLevel;
}
