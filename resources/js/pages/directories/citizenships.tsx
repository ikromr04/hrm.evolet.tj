import { DirectoryManager } from '@/components/directory-manager';
import DirectoriesLayout from '@/layouts/directories-layout';

interface CitizenshipItem {
    id: number;
    name: string;
    users_count: number;
}

/** Countries employees are citizens of; one may hold several. */
export default function Citizenships({ items, canEdit }: { items: CitizenshipItem[]; canEdit: boolean }) {
    return (
        <DirectoriesLayout title="Гражданства">
            <DirectoryManager
                items={items.map((item) => ({ id: item.id, label: item.name, users_count: item.users_count }))}
                canEdit={canEdit}
                field="name"
                route="directories.citizenships"
                labels={{ add: 'Добавить гражданство', create: 'Новое гражданство', edit: 'Изменить гражданство', accusative: 'гражданство' }}
                employeesUrl={(item) => route('employees.index', { citizenship: [item.label] })}
                employeesField="employees.field.citizenship"
            />
        </DirectoriesLayout>
    );
}
