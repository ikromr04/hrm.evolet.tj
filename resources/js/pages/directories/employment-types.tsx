import { DirectoryManager } from '@/components/directory-manager';
import DirectoriesLayout from '@/layouts/directories-layout';

interface EmploymentTypeItem {
    id: number;
    name: string;
    users_count: number;
}

/** The kinds of work people are taken on for; a person is taken on under one. */
export default function EmploymentTypes({ items, canEdit }: { items: EmploymentTypeItem[]; canEdit: boolean }) {
    return (
        <DirectoriesLayout title="Типы занятости">
            <DirectoryManager
                items={items.map((item) => ({ id: item.id, label: item.name, users_count: item.users_count }))}
                canEdit={canEdit}
                field="name"
                route="directories.employment_types"
                labels={{
                    add: 'Добавить тип занятости',
                    create: 'Новый тип занятости',
                    edit: 'Изменить тип занятости',
                    accusative: 'тип занятости',
                }}
            />
        </DirectoriesLayout>
    );
}
