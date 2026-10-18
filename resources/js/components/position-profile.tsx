import { type Filing } from '@/components/department-name';
import { DocumentCard } from '@/components/document-card';
import { type Paper } from '@/lib/document';

/**
 * Where the position sits and what it is called, read out of the structure.
 *
 * This was «01 ОБЩАЯ ИНФОРМАЦИЯ», the one section of the sheet that was data
 * rather than text. It is not a section any more: the facts are drawn in the
 * column beside the document, where the page was already saying most of them,
 * and a table repeating them inside the sheet would have been the aside printed
 * twice.
 *
 * «Категория» and «Грайд» are not here because the company does not keep them.
 * «Подчинение» and «Подчинённые» are not here either, and that is the change:
 * the application records which department a position is filed in and nothing
 * about one position answering to another, so on a printed sheet those two rows
 * stood empty with a line saying why. A column of facts is not a printed sheet,
 * and two permanently empty lines in it read as a page that forgot something.
 * If a hierarchy of positions is ever recorded, they come back then.
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

/**
 * «Профиль должности» as a position reads it: one text, and nothing of ours
 * drawn around it.
 *
 * The company writes this sheet as one file and asked for it to be written the
 * same way here, so the name of the sheet is its first line and the parts of it
 * are headings after that — all of them written in the one editor this tab
 * opens, under the pencil in the corner of the card.
 *
 * The document itself is drawn by {@link DocumentCard}, which every document of
 * this application draws. What belongs to this one is here: its three
 * addresses, and that it is one text.
 *
 * Nothing names an owner in these sentences: the sheet names itself in its
 * first line, and «написан для «Ведущий специалист отдела HR»» would read as a
 * mistake rather than as information.
 */
export function PositionProfile({ position, profile }: { position: number; profile: Profile }) {
    return (
        <DocumentCard
            owner={position}
            addresses={{
                store: 'positions.profile.sections.store',
                update: 'positions.profile.sections.update',
                destroy: 'positions.profile.sections.destroy',
            }}
            paper={profile}
            whole={{ title: 'Текст профиля', edit: 'Изменить профиль должности' }}
            empty="Текст профиля не загрузился. Обновите страницу, а если он не появится — сообщите администратору."
        />
    );
}
