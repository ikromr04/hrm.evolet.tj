import { DocumentCard } from '@/components/document-card';
import { type Paper } from '@/lib/document';

export interface Regulation extends Paper {
    names: { nominative: string };
    /**
     * What the server answers in a body it reads out, and what each one reads
     * as here.
     *
     * The shared text is written with them — «ПОЛОЖЕНИЕ О РАБОТЕ
     * {подразделение}» is its first line — which is how one document serves
     * twenty-six departments. Nothing here offers them as buttons: a department
     * writing its own words writes the name rather than a token for it, and a
     * token typed by hand still resolves, because the server goes on doing it.
     */
    placeholders: { token: string; title: string; value: string }[];
}

/**
 * «Положение о работе» as a department reads it: one text, and nothing else on
 * the tab.
 *
 * The company writes this document as one file and asked for it to be written
 * the same way here, so it is a document of one section and nothing of ours is
 * drawn around the body of it. The name of the sheet is its first line and the
 * parts of it, ОБЩЕЕ ПОЛОЖЕНИЕ and the three after it, are headings after that,
 * their numbers typed in with them — every one of them written in the one
 * editor this tab opens. «Профиль должности» is the other shape, nine sections
 * the company fills in one at a time under a heading of its own, and it is the
 * same card.
 *
 * The document itself is drawn by {@link DocumentCard}, which that profile draws
 * too; what belongs to this one and not to that one is here: where its
 * addresses are, that it is one text, and that it is written for a department
 * with a name, which the sentences about its own wording say.
 */
export function DepartmentRegulation({ department, regulation }: { department: number; regulation: Regulation }) {
    return (
        <DocumentCard
            owner={department}
            addresses={{
                // No address for the heading: there is no heading apart from
                // the text, so the one that writes the text writes it.
                store: 'departments.regulation.sections.store',
                update: 'departments.regulation.sections.update',
                destroy: 'departments.regulation.sections.destroy',
            }}
            paper={regulation}
            whose={regulation.names.nominative}
            whole={{ title: 'Текст положения', edit: 'Изменить положение о работе' }}
            empty="Текст положения не загрузился. Обновите страницу, а если он не появится — сообщите администратору."
        />
    );
}
