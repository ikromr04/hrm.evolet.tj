import { DocumentCard } from '@/components/document-card';
import { Blank, GeneralTable, Where, type Filing } from '@/components/general-table';
import { PersonLink } from '@/components/person-link';
import { type DocumentSection, type Paper } from '@/lib/document';
import { peopleLabel } from '@/lib/employee';
import { type ReactNode } from 'react';

/**
 * «01 ОБЩАЯ ИНФОРМАЦИЯ» of a department's sheet, as data.
 *
 * Five lines, every one of them read out of the structure: a department moved,
 * renamed or given a new head is a department whose sheet is already right.
 */
export interface General {
    /** The department itself where it is one of a department's units; null at the top. */
    unit: Filing | null;
    /** The one above it, or itself where there is nothing above. */
    department: Filing | null;
    /** «Фамилия Имя» each; empty where nobody is appointed. */
    heads: { id: number; name: string }[];
    /** How many work here, and how many in the whole branch under it. */
    staff: { own: number; total: number };
    children: Filing[];
}

export interface Profile extends Paper {
    general: General;
}

/**
 * How many people the sheet counts.
 *
 * Two numbers that are the same number for most departments: a unit with
 * nothing under it employs everybody it employs. So one number is printed where
 * the two agree, and both only where a branch makes them differ — «3 в
 * подразделении, 12 вместе с подотделами» says what a bare pair of numbers
 * would leave a reader to work out.
 */
function Staff({ own, total }: { own: number; total: number }) {
    if (own === total) return <>{peopleLabel(own)}</>;

    return (
        <>
            {own} в подразделении, {total} вместе с подотделами
        </>
    );
}

/**
 * Who leads it, by name, each leading to their card for whoever may open one.
 *
 * Nobody appointed is a fact about the department rather than a line somebody
 * forgot: it is said in the words the rest of the page uses for it, not as an
 * empty cell.
 */
function Heads({ heads }: { heads: General['heads'] }) {
    if (heads.length === 0) return <Blank>Не назначен</Blank>;

    return (
        <>
            {heads.map((head, index) => (
                <span key={head.id}>
                    {index > 0 && ', '}
                    <PersonLink id={head.id} className="text-brand-strong hover:underline dark:text-[#C5E27A]">
                        {head.name}
                    </PersonLink>
                </span>
            ))}
        </>
    );
}

function lines(general: General): { label: string; value: ReactNode }[] {
    return [
        { label: 'ОТДЕЛ', value: <Where filing={general.unit} /> },
        { label: 'ДЕПАРТАМЕНТ', value: <Where filing={general.department} /> },
        { label: 'РУКОВОДИТЕЛЬ', value: <Heads heads={general.heads} /> },
        { label: 'ЧИСЛЕННОСТЬ', value: <Staff own={general.staff.own} total={general.staff.total} /> },
        {
            label: 'ОТДЕЛЫ В СОСТАВЕ',
            value:
                general.children.length > 0 ? (
                    general.children.map((child, index) => (
                        <span key={child.id}>
                            {index > 0 && ', '}
                            <Where filing={child} />
                        </span>
                    ))
                ) : (
                    <Blank>Подотделов нет</Blank>
                ),
        },
    ];
}

/**
 * «Профиль подразделения» as a department reads it — the same nine sections a
 * position's sheet has, kept here and written here.
 *
 * Unlike «Положение о работе» on a position's page, this document belongs to the
 * page it is read on, so it is editable: the pencils, the heading and the way
 * back to the standard text are all where the document is.
 */
export function DepartmentProfile({ department, profile }: { department: number; profile: Profile }) {
    return (
        <DocumentCard
            owner={department}
            addresses={{
                title: 'departments.profile.title',
                store: 'departments.profile.sections.store',
                update: 'departments.profile.sections.update',
                destroy: 'departments.profile.sections.destroy',
            }}
            paper={profile}
            empty="Разделы профиля не загрузились. Обновите страницу, а если они не появятся — сообщите администратору."
            fixed={(section: DocumentSection) => (section.computed ? <GeneralTable lines={lines(profile.general)} /> : null)}
        />
    );
}
