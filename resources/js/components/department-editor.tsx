import { DirectoryEditor } from '@/components/directory-editor';

/**
 * A department as it is written down: the two names and where it sits. That is
 * the whole of it — the same record «Подразделения» keeps in its list, and the
 * same one the department's own page sends now that it opens the same form.
 *
 * Who leads a department is nowhere here. It follows from which of the
 * department's positions are marked leading, it is marked where a position is
 * kept, and the only place it is read is the department's own page.
 */
export interface DepartmentRecord {
    id: number;
    name: string;
    abbreviation: string | null;
    parent_id: number | null;
}

/**
 * What the two places that edit a department agree on: the address they save
 * through, the field the name travels in, and the words the window and the
 * buttons are written with.
 *
 * «Подразделения» spreads this over its list, and the department's own page
 * reads it for the window alone. Both of them are editing the same thing, so
 * neither spells out what it is called or where it is kept.
 */
export const departmentDirectory = {
    field: 'name',
    route: 'departments',
    labels: { add: 'Добавить отдел', create: 'Новый отдел', edit: 'Изменить отдел', accusative: 'отдел' },
    abbreviation: true,
    tree: true,
} as const;

/**
 * «Изменить отдел» for one department: the window the list opens, opened from
 * the department's own page.
 *
 * Nothing is re-implemented here. It is {@link DirectoryEditor} with the
 * settings of the departments list and this department as its record, so the
 * form and the save are the ones «Подразделения» has.
 */
export function DepartmentEditor({
    department,
    departments,
    onSaved,
    onClose,
}: {
    department: DepartmentRecord;
    /** Every department, for the placement the window offers. */
    departments: DepartmentRecord[];
    onSaved?: () => void;
    onClose: () => void;
}) {
    const record = (unit: DepartmentRecord) => ({ ...unit, label: unit.name });

    return (
        <DirectoryEditor
            item={record(department)}
            items={departments.map(record)}
            field={departmentDirectory.field}
            routeName={departmentDirectory.route}
            labels={departmentDirectory.labels}
            abbreviated={departmentDirectory.abbreviation}
            tree={departmentDirectory.tree}
            onSaved={onSaved}
            onClose={onClose}
        />
    );
}
