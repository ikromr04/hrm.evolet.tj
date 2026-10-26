import { DirectoryManager } from '@/components/directory-manager';
import DirectoriesLayout from '@/layouts/directories-layout';

interface BranchItem {
    id: number;
    name: string;
    users_count: number;
}

/** The places the company works from; a person works at one of them. */
export default function Branches({ items, canEdit }: { items: BranchItem[]; canEdit: boolean }) {
    return (
        <DirectoriesLayout title="Филиалы">
            <DirectoryManager
                items={items.map((item) => ({ id: item.id, label: item.name, users_count: item.users_count }))}
                canEdit={canEdit}
                field="name"
                route="directories.branches"
                labels={{ add: 'Добавить филиал', create: 'Новый филиал', edit: 'Изменить филиал', accusative: 'филиал' }}
            />
        </DirectoriesLayout>
    );
}
