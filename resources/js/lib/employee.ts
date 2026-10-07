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
    spouse_birth_date: string | null;
    /** null until anyone filled the card in, false once HR states there are none. */
    has_children: boolean | null;
    hired_at: string | null;
    children: { full_name: string; birth_date: string | null }[];
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
    started_year: number;
    /** Null while still studying. */
    graduated_year: number | null;
    diploma_number: string | null;
}

/** A previous job, known to the month; private. */
export interface WorkExperience {
    organization: string;
    position: string;
    country: string;
    started_month: number;
    started_year: number;
    /** Both null while the person still works there. */
    ended_month: number | null;
    ended_year: number | null;
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

/** A language an employee speaks, public like positions. */
export interface SpokenLanguage {
    id: number;
    name: string;
    level: LanguageLevel;
}
