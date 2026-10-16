import { DocumentCard } from '@/components/document-card';
import { Blank, GeneralTable, Where, type Filing } from '@/components/general-table';
import { type DocumentSection, type Paper } from '@/lib/document';
import { type ReactNode } from 'react';

/**
 * «01 ОБЩАЯ ИНФОРМАЦИЯ» of a position's sheet, as data.
 *
 * Where the position sits and what it is called — all of it read out of the
 * structure. «Категория» and «Грайд» are not here because the company does not
 * keep them, and they are no more a row on this sheet than a field anywhere
 * else.
 */
export interface General {
    /** Null where the position is filed on a department that has no parent. */
    unit: Filing | null;
    department: Filing | null;
    position: string;
    /** Which position this one answers to. Always empty: the application does not record it. */
    reports_to: string | null;
    /** Which positions answer to this one. Always empty, for the same reason. */
    subordinates: string[];
}

export interface Profile extends Paper {
    general: General;
}

const UNKEPT = 'приложение пока не ведёт';

/**
 * «Подчинение» and «Подчинённые» are two lines the application cannot fill: it
 * records which department a position is filed in and nothing about one position
 * answering to another. They stay in the table because the document has them,
 * and they say plainly that nothing is kept there — inventing a chain of command
 * out of the department tree would be a guess printed on a signed sheet.
 */
function lines(general: General): { label: string; value: ReactNode }[] {
    return [
        { label: 'ОТДЕЛ', value: <Where filing={general.unit} /> },
        { label: 'ДЕПАРТАМЕНТ', value: <Where filing={general.department} /> },
        { label: 'ДОЛЖНОСТЬ', value: general.position },
        { label: 'ПОДЧИНЕНИЕ', value: general.reports_to ?? <Blank>Не указано — {UNKEPT}</Blank> },
        {
            label: 'ПОДЧИНЕННЫЕ',
            value: general.subordinates.length > 0 ? general.subordinates.join(', ') : <Blank>Не указаны — {UNKEPT}</Blank>,
        },
    ];
}

/**
 * «Профиль должности» as a position reads it.
 *
 * The document itself is drawn by {@link DocumentCard}, which the other three
 * documents of this application draw too. What belongs to this one is here: its
 * four addresses, and its first section — the one filled in out of the
 * structure, which is why that one carries no pencil.
 *
 * Nothing names an owner in these sentences: the heading of the sheet already
 * says whose it is, and «написан для «Ведущий специалист отдела HR»» would read
 * as a mistake rather than as information.
 */
export function PositionProfile({ position, profile }: { position: number; profile: Profile }) {
    return (
        <DocumentCard
            owner={position}
            addresses={{
                title: 'positions.profile.title',
                store: 'positions.profile.sections.store',
                update: 'positions.profile.sections.update',
                destroy: 'positions.profile.sections.destroy',
            }}
            paper={profile}
            empty="Разделы профиля не загрузились. Обновите страницу, а если они не появятся — сообщите администратору."
            fixed={(section: DocumentSection) => (section.computed ? <GeneralTable lines={lines(profile.general)} /> : null)}
        />
    );
}
