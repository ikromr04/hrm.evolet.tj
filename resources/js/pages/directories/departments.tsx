import { DirectoryManager } from '@/components/directory-manager';
import { type PickablePerson } from '@/components/person-picker';
import CompanyStructureLayout from '@/layouts/company-structure-layout';

interface DepartmentItem {
    id: number;
    name: string;
    abbreviation: string | null;
    parent_id: number | null;
    users_count: number;
    total_count: number;
    heads: { id: number; name: string }[];
    member_ids: number[];
}

/**
 * "Подразделения": the tree of departments, written out as a list and kept
 * there. It is read beside the chart it draws rather than among the reference
 * lists, which is why it wears the structure's tabs — the page itself, and the
 * pair of rights behind it, are the ones it has always had.
 */
export default function Departments({ items, employees, canEdit }: { items: DepartmentItem[]; employees: PickablePerson[]; canEdit: boolean }) {
    return (
        <CompanyStructureLayout>
            <DirectoryManager
                items={items.map((item) => ({
                    id: item.id,
                    // The full name and the short one both: this is the list
                    // the short one is written in, and the only one that still
                    // spells a department out.
                    label: item.name,
                    abbreviation: item.abbreviation,
                    users_count: item.users_count,
                    total_count: item.total_count,
                    parent_id: item.parent_id,
                    heads: item.heads,
                    member_ids: item.member_ids,
                }))}
                canEdit={canEdit}
                field="name"
                route="departments.units"
                labels={{ add: 'Добавить отдел', create: 'Новый отдел', edit: 'Изменить отдел', accusative: 'отдел' }}
                employeesUrl={(item) => route('employees.index', { department: [item.id] })}
                employeesField="employees.field.departments"
                // Every department in this list has a page of its own, at both
                // levels of the tree, and until now there was no way from the
                // list to it. The page is open to everybody who signs in — who
                // works where is nobody's secret — so the name leads there
                // whatever this person may read of the staff.
                recordUrl={(item) => route('departments.show', item.id)}
                abbreviation
                tree
                people={employees}
            />
        </CompanyStructureLayout>
    );
}
