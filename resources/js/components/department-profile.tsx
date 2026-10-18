import { type Filing } from '@/components/department-name';
import { DocumentCard } from '@/components/document-card';
import { type Paper } from '@/lib/document';

/**
 * What the application knows about the unit the sheet is written for, read out
 * of the structure: a department moved, renamed or given a new head is a
 * department whose page is already right.
 *
 * This was «01 ОБЩАЯ ИНФОРМАЦИЯ», the one section of the sheet that was data
 * rather than text. It is not a section any more — and on this page it was the
 * column beside it written out a second time: who leads the unit, what is under
 * it and how many work there are three cards of that column. So the facts stay
 * where the page already says them, and the sheet starts at what it has to say
 * for itself.
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
 * «Профиль подразделения» as a department reads it: the same one text a
 * position's sheet is, written about the unit and kept here.
 *
 * Unlike «Положение о работе» on a position's page, this document belongs to the
 * page it is read on, so it is written here — the pencil in the corner of the
 * card opens the whole of it.
 */
export function DepartmentProfile({ department, profile }: { department: number; profile: Profile }) {
    return (
        <DocumentCard
            owner={department}
            addresses={{
                store: 'departments.profile.sections.store',
                update: 'departments.profile.sections.update',
                destroy: 'departments.profile.sections.destroy',
            }}
            paper={profile}
            whole={{ title: 'Текст профиля', edit: 'Изменить профиль подразделения' }}
            empty="Текст профиля не загрузился. Обновите страницу, а если он не появится — сообщите администратору."
        />
    );
}
