import { DocumentCard } from '@/components/document-card';
import { type Paper } from '@/lib/document';

export interface Regulation extends Paper {
    names: { nominative: string };
    /**
     * What the server still answers in a body it reads out. Nothing here offers
     * them any more: the one shared text they were for is gone, and a department
     * writing its own words writes the name rather than a token for it. A token
     * somebody types by hand still resolves, because the server goes on doing it.
     */
    placeholders: { token: string; title: string; value: string }[];
}

/**
 * «Положение о работе» as a department reads it.
 *
 * The document itself is drawn by {@link DocumentCard}, which a position's
 * «Профиль должности» draws too; what belongs to this one and not to that one is
 * here: where its five addresses are, and that its sections are written for a
 * department with a name, which the sentences about its own wording say.
 */
export function DepartmentRegulation({ department, regulation }: { department: number; regulation: Regulation }) {
    return (
        <DocumentCard
            owner={department}
            addresses={{
                title: 'departments.regulation.title',
                store: 'departments.regulation.sections.store',
                update: 'departments.regulation.sections.update',
                destroy: 'departments.regulation.sections.destroy',
                order: 'departments.regulation.order',
            }}
            paper={regulation}
            whose={regulation.names.nominative}
            empty="Разделы положения не загрузились. Обновите страницу, а если они не появятся — сообщите администратору."
        />
    );
}
