import { DirectoryManager } from '@/components/directory-manager';
import DirectoriesLayout from '@/layouts/directories-layout';

interface DocumentTypeItem {
    id: number;
    name: string;
    hint: string | null;
    /** Whether the original is handed over and kept in a safe rather than sent. */
    on_paper: boolean;
    is_required: boolean;
    /** Working employees who have handed this one in. */
    documents_count: number;
}

/**
 * The papers the company asks everybody for: «Копия паспорта», «ИНН», «Копия
 * диплома».
 *
 * A list rather than a set of columns on a card, because what a personnel
 * office asks for changes with the rules it works under. What a record says is
 * what the paper is called, whether its original is handed over instead of
 * sent, and whether it is wanted from everybody; a card reads the list and
 * shows one person's answers against it.
 */
export default function DocumentTypes({ items, canEdit }: { items: DocumentTypeItem[]; canEdit: boolean }) {
    return (
        <DirectoriesLayout title="Документы (Чек-лист)">
            <DirectoryManager
                items={items.map((item) => ({
                    id: item.id,
                    label: item.name,
                    hint: item.hint,
                    on_paper: item.on_paper,
                    is_required: item.is_required,
                    // The column counts the people who have handed this paper
                    // in, which is what a list of papers is read for: not how
                    // many there are, but how far the asking has got.
                    users_count: item.documents_count,
                }))}
                canEdit={canEdit}
                field="name"
                route="directories.document_types"
                documents
                orderable
                countLabel="Приложили"
                labels={{
                    add: 'Добавить документ',
                    create: 'Новый документ',
                    edit: 'Изменить документ',
                    accusative: 'документ',
                }}
            />
        </DirectoriesLayout>
    );
}
