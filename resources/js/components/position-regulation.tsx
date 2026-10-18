import { type Regulation } from '@/components/department-regulation';
import { DocumentCard } from '@/components/document-card';

/**
 * «Положение о работе» as a position keeps it: one text, and nothing else on
 * the tab.
 *
 * A job has one of its own. It used to read the department's here, read-only,
 * under a line saying where to go and change it; the company asked for a job to
 * keep its own, so this tab is the document and the pencil, exactly as the
 * «СФД» tab beside it is.
 *
 * The same shared standard text as a department's, from the same template, and
 * the same card draws it — what differs is the four addresses and the line the
 * sheet opens with, which the server answers «ПОЛОЖЕНИЕ О РАБОТЕ Аналитик
 * (ОАП)» here and «ПОЛОЖЕНИЕ О РАБОТЕ Отдел дизайна» there.
 *
 * The company's wording is about a подразделение — «{подразделение} является
 * самостоятельным структурным подразделением…» — and a job filed in a unit
 * reads it about that unit until somebody writes the job its own. That text is
 * the company's to rewrite, not this page's to paraphrase.
 */
export function PositionRegulation({ position, regulation }: { position: number; regulation: Regulation }) {
    return (
        <DocumentCard
            owner={position}
            addresses={{
                // No address for the heading: there is no heading apart from
                // the text, so the one that writes the text writes it.
                store: 'positions.regulation.sections.store',
                update: 'positions.regulation.sections.update',
                destroy: 'positions.regulation.sections.destroy',
            }}
            paper={regulation}
            whose={regulation.names.nominative}
            whole={{ title: 'Текст положения', edit: 'Изменить положение о работе' }}
            empty="Текст положения не загрузился. Обновите страницу, а если он не появится — сообщите администратору."
        />
    );
}
