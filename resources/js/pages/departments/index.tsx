import { MobileRow } from '@/components/data-table';
import { groupByParent, OrgChart, type OrgDepartment, PhoneViewSwitch } from '@/components/org-chart';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { peopleLabel } from '@/lib/employee';
import { plural } from '@/lib/plural';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Building2, CornerDownRight, Users } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Оргструктура', href: '/org-chart' }];

type View = 'list' | 'chart';

const VIEW_KEY = 'departments.phone.view';

/** Only a phone reads this: from `md` up the page always shows the chart. */
function savedView(): View {
    try {
        return localStorage.getItem(VIEW_KEY) === 'chart' ? 'chart' : 'list';
    } catch {
        return 'list';
    }
}

/** A branch of the tree laid flat, each department with how deep it sits under the top one. */
function flatten(
    department: OrgDepartment,
    byParent: Map<number | null, OrgDepartment[]>,
    depth = 0,
): { department: OrgDepartment; depth: number }[] {
    return [{ department, depth }, ...(byParent.get(department.id) ?? []).flatMap((child) => flatten(child, byParent, depth + 1))];
}

function Headcount({ value }: { value: number }) {
    return (
        <span className="flex items-center gap-1 text-[13px] tabular-nums" aria-label={peopleLabel(value)} title={peopleLabel(value)}>
            <Users className="size-3.5" aria-hidden="true" />
            {value}
        </span>
    );
}

/**
 * The structure as a phone reads it: every top department in a card of its own,
 * its units listed under it and stepped in by depth. The step stops after three
 * levels, or a deep unit would have no width left for its name at 320px.
 */
function DepartmentList({ departments }: { departments: OrgDepartment[] }) {
    const byParent = groupByParent(departments);

    return (
        <div className="flex flex-col gap-3 md:hidden">
            {(byParent.get(null) ?? []).map((top) => (
                <Card key={top.id} className="gap-0 overflow-hidden rounded-2xl border-0 py-0 shadow-none">
                    <ul className="divide-border/70 divide-y">
                        {flatten(top, byParent).map(({ department, depth }) => (
                            <MobileRow
                                key={department.id}
                                href={route('departments.show', department.id)}
                                leading={
                                    depth === 0 ? (
                                        <span className="bg-brand/15 text-brand-strong flex size-10 items-center justify-center rounded-[10px] dark:text-[#C5E27A]">
                                            <Building2 className="size-5" aria-hidden="true" />
                                        </span>
                                    ) : (
                                        <span className="flex items-center" style={{ paddingLeft: (Math.min(depth, 3) - 1) * 14 }}>
                                            <span className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-[10px]">
                                                <CornerDownRight className="size-4" aria-hidden="true" />
                                            </span>
                                        </span>
                                    )
                                }
                                // A department's name is the whole point of the row, so it wraps
                                // rather than being cut — and a phone has no hover to keep the
                                // full name behind, so it stands under the abbreviation here as
                                // it does in the chart.
                                title={
                                    <span className="flex flex-col gap-0.5">
                                        <span className={cn('whitespace-normal', depth === 0 && 'font-semibold')}>{department.name}</span>
                                        {department.full_name !== department.name && (
                                            <span className="text-muted-foreground text-[12px] leading-tight font-normal whitespace-normal">
                                                {department.full_name}
                                            </span>
                                        )}
                                    </span>
                                }
                                subtitle={
                                    department.heads.length > 0 ? department.heads.map((head) => head.name).join(', ') : 'Руководитель не назначен'
                                }
                                meta={<Headcount value={department.total_count} />}
                            />
                        ))}
                    </ul>
                </Card>
            ))}
        </div>
    );
}

export default function Departments({ departments, employees_count }: { departments: OrgDepartment[]; employees_count: number }) {
    const [view, setView] = useState<View>(savedView);

    const summary = `${departments.length} ${plural(departments.length, ['подразделение', 'подразделения', 'подразделений'])} · ${peopleLabel(employees_count)} в компании`;

    const changeView = (next: View) => {
        setView(next);
        try {
            localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Blocked storage: the choice just is not remembered.
        }
    };

    return (
        // The chart scrolls inside its own frame, and the frame fills the window.
        <AppLayout breadcrumbs={breadcrumbs} fitViewport>
            <Head title="Оргструктура" />

            <div className="flex flex-1 flex-col gap-4 p-3 max-md:gap-3 md:min-h-0 md:gap-6 md:px-4 md:py-6 lg:px-6">
                {/* The page writes its own name, and what the chart adds up to
                   stands at the end of that line. */}
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">Оргструктура</h1>
                    <p className="text-muted-foreground text-sm max-md:px-1 max-md:text-[13px]">{summary}</p>
                </div>

                {departments.length === 0 ? (
                    <Card className="text-muted-foreground rounded-xl p-12 text-center text-sm max-md:rounded-2xl max-md:border-0 max-md:p-8 max-md:shadow-none">
                        Отделов пока нет.
                    </Card>
                ) : (
                    <>
                        <PhoneViewSwitch
                            value={view}
                            onChange={changeView}
                            options={[
                                { value: 'list', label: 'Список' },
                                { value: 'chart', label: 'Схема' },
                            ]}
                        />

                        {view === 'list' && <DepartmentList departments={departments} />}

                        {/* From `md` up the chart is the page, whatever a phone last chose. */}
                        <Card
                            className={cn(
                                'bg-sidebar flex flex-col gap-0 overflow-hidden rounded-xl p-0 max-md:rounded-2xl max-md:border-0 max-md:shadow-none md:min-h-0 md:flex-1',
                                view === 'list' && 'max-md:hidden',
                            )}
                        >
                            <OrgChart departments={departments} employeesCount={employees_count} />
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
