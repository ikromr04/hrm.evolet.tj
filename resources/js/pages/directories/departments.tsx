import { departmentDirectory } from '@/components/department-editor';
import { type DirectoryItem } from '@/components/directory-editor';
import { DirectoryManager } from '@/components/directory-manager';
import AppLayout from '@/layouts/app-layout';
import { plural } from '@/lib/plural';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface DepartmentItem {
    id: number;
    name: string;
    abbreviation: string | null;
    parent_id: number | null;
    users_count: number;
    total_count: number;
    positions_count: number;
    /** The jobs filed here; the number travels only where the viewer may open one. */
    positions: { id: number | null; name: string; unit: { name: string; full_name: string } | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Подразделения', href: '/departments' }];

/**
 * "Подразделения": the tree of departments, written out as a list and kept
 * there. It stands on its own page, headed the way the chart beside it is —
 * the page's own name, and at the end of that line whatever adds to it.
 */
export default function Departments({ items, canEdit }: { items: DepartmentItem[]; canEdit: boolean }) {
    /**
     * What is on screen, counted the way the columns read it: the branches,
     * what hangs inside them, and the jobs filed in both. The list hands over
     * the rows it is showing, so narrowing it narrows the tally with it.
     */
    const above = new Map(items.map((item) => [item.id, item.parent_id]));

    /** The department a row belongs to: itself, where it hangs under nothing. */
    const branchOf = (id: number) => {
        let held = id;

        for (let parent = above.get(held); parent != null; parent = above.get(held)) {
            if (parent === held) break;
            held = parent;
        }

        return held;
    };

    const summary = (shown: DirectoryItem[]) => {
        // The branches the rows stand in, each counted once however many of its
        // units are on screen.
        const departments = new Set(shown.map((row) => branchOf(row.id))).size;
        const units = shown.filter((row) => row.parent_id).length;
        const jobs = shown.reduce((total, row) => total + (row.count_items?.length ?? 0), 0);

        return [
            `${departments} ${plural(departments, ['департамент', 'департамента', 'департаментов'])}`,
            `${units} ${plural(units, ['отдел', 'отдела', 'отделов'])}`,
            `${jobs} ${plural(jobs, ['должность', 'должности', 'должностей'])}`,
        ].join(' · ');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Подразделения" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">Подразделения</h1>

                <DirectoryManager
                    items={items.map((item) => ({
                        id: item.id,
                        // The full name and the short one both: this is the list
                        // the short one is written in, and the only one that still
                        // spells a department out.
                        label: item.name,
                        abbreviation: item.abbreviation,
                        // The column names the jobs filed here; the number
                        // behind it is what the list is sorted by.
                        users_count: item.positions_count,
                        count_items: item.positions.map((position) => ({
                            id: position.id,
                            name: position.name,
                            unit: position.unit,
                            url: position.id === null ? '' : route('positions.show', position.id),
                        })),
                        parent_id: item.parent_id,
                    }))}
                    canEdit={canEdit}
                    // What this list is called and where it saves, which the
                    // department's own page now reads as well: the same window is
                    // opened from there, so the wording and the address are shared
                    // rather than written out twice.
                    {...departmentDirectory}
                    countLabel="Должности"
                    countWords={['должность', 'должности', 'должностей']}
                    // Every department in this list has a page of its own, at both
                    // levels of the tree, and until now there was no way from the
                    // list to it. The page is open to everybody who signs in — who
                    // works where is nobody's secret — so the name leads there
                    // whatever this person may read of the staff.
                    recordUrl={(item) => route('departments.show', item.id)}
                    addIn="toolbar"
                    // The structure reads down two columns here, as it does in the
                    // staff list: the department, and the unit inside it.
                    structure
                    summary={summary}
                />
            </div>
        </AppLayout>
    );
}
