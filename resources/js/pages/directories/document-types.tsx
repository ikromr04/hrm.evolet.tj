import { DirectoryManager } from '@/components/directory-manager';
import DirectoriesLayout from '@/layouts/directories-layout';

interface DocumentTypeItem {
    id: number;
    name: string;
    hint: string | null;
    min_files: number;
    /** Null where no number of files is too many. */
    max_files: number | null;
    accepts_files: boolean;
    accepts_camera: boolean;
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
 * how its answer may arrive — how many files, chosen from a disk or
 * photographed — and whether it is wanted from everybody; a card reads the list
 * and shows one person's answers against it.
 */
export default function DocumentTypes({ items, canEdit }: { items: DocumentTypeItem[]; canEdit: boolean }) {
    return (
        <DirectoriesLayout title="Документы (Чек-лист)">
            <DirectoryManager
                items={items.map((item) => ({
                    id: item.id,
                    label: item.name,
                    hint: item.hint,
                    min_files: item.min_files,
                    max_files: item.max_files,
                    accepts_files: item.accepts_files,
                    accepts_camera: item.accepts_camera,
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
