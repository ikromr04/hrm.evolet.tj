import { DocumentCard, type DocumentFrame } from '@/components/document-card';
import { PaperAction, PaperBand, PaperBandRow } from '@/components/paper-band';
import { PaperApproval, PaperContents } from '@/components/paper-contents';
import { type Paper } from '@/lib/document';
import { type ReactNode } from 'react';

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
 * Everything the design draws around the text and nothing yet knows.
 *
 * Who has read the paper and who has not is kept nowhere today, so it is
 * written down here as the drawing has it and the page wears it; «Напомнить»
 * does nothing for the same reason. The contents is the standard text's four
 * parts — a department that wrote its own would need this read out of the
 * document, which is work of its own.
 *
 * The pencil is the exception: it is the card's own and it writes the document.
 */
const READ = { done: 9, total: 11, missing: 'Назарова П., Одинаева М.' };
const PARTS = [
    { number: '01', title: 'Общее положение' },
    { number: '02', title: 'Права' },
    { number: '03', title: 'Обязанности' },
    { number: '04', title: 'Ответственность' },
];

/** How far the department has got through reading the paper. */
function Read() {
    const share = Math.round((READ.done / READ.total) * 100);

    return (
        <PaperBandRow className="py-2.5">
            <span className="text-muted-foreground text-[13px]">Ознакомление</span>

            <span className="bg-muted block h-1.5 w-[18rem] shrink-0 overflow-hidden rounded-full">
                <span className="bg-brand block h-full rounded-full" style={{ width: `${share}%` }} />
            </span>

            <span className="text-sm font-semibold tabular-nums">
                {READ.done} из {READ.total}
            </span>

            <span className="text-muted-foreground min-w-0 truncate text-[13px]">Не ознакомлены: {READ.missing}</span>

            <span className="ml-auto shrink-0">
                <PaperAction>Напомнить</PaperAction>
            </span>
        </PaperBandRow>
    );
}

const frame: DocumentFrame = {
    header: (pencil: ReactNode | null) => (
        <PaperBand title="Положение о работе" pencil={pencil}>
            <Read />
        </PaperBand>
    ),
    beside: <PaperContents parts={PARTS} />,
    corner: <PaperApproval />,
};

/**
 * «Положение о работе» as a department reads it: the text, and around it what
 * the design asks for — the state of the paper, how far the department has read
 * it, what it is made of and who signs it.
 *
 * The company writes this document as one file and asked for it to be written
 * the same way here, so it is a document of one section. The name of the sheet
 * is its first line and the parts of it, ОБЩЕЕ ПОЛОЖЕНИЕ and the three after
 * it, are headings after that — every one of them written in the one editor
 * this tab opens.
 *
 * The document itself is drawn by {@link DocumentCard}, which every document of
 * this application draws; what belongs to this one is here: where its addresses
 * are, that it is one text, that it is written for a department with a name,
 * and the frame around it.
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
            frame={frame}
        />
    );
}
