import { DepartmentName } from '@/components/department-name';
import { EvoletMark } from '@/components/evolet-logo';
import { PersonFace } from '@/components/person-face';
import { PersonLink } from '@/components/person-link';
import { Button } from '@/components/ui/button';
import { peopleLabel } from '@/lib/employee';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronsDownUp, ChevronsUpDown, Crown, Expand, Minus, Plus, Scan, Shrink, Users } from 'lucide-react';
import { createContext, type PointerEvent, useCallback, useContext, useEffect, useLayoutEffect, useRef, useState } from 'react';

export interface OrgPerson {
    id: number;
    /** "Фамилия Имя" */
    name: string;
    avatar: string | null;
}

export interface OrgDepartment {
    id: number;
    /** The abbreviation, or the full name when there is none. */
    name: string;
    full_name: string;
    parent_id: number | null;
    /** Working people here and in sub-departments, heads included. */
    total_count: number;
    heads: OrgPerson[];
    /** Everyone else in this department itself. */
    members: OrgPerson[];
}

/** Departments grouped by parent; a parent that is gone puts the branch at the top. */
export function groupByParent(departments: OrgDepartment[]): Map<number | null, OrgDepartment[]> {
    const ids = new Set(departments.map((d) => d.id));
    const map = new Map<number | null, OrgDepartment[]>();

    for (const department of departments) {
        const key = department.parent_id !== null && ids.has(department.parent_id) ? department.parent_id : null;
        map.set(key, [...(map.get(key) ?? []), department]);
    }

    return map;
}

function Count({ value }: { value: number }) {
    return (
        <span className="text-muted-foreground flex shrink-0 items-center gap-1 text-xs tabular-nums" title={peopleLabel(value)}>
            <Users className="size-3.5" />
            {value}
        </span>
    );
}

/** A person in a box, linking to their profile. */
function PersonLine({ person, head }: { person: OrgPerson; head?: boolean }) {
    return (
        <li className="flex min-w-0 items-center gap-1.5">
            <PersonFace id={person.id} name={person.name} avatar={person.avatar} className="size-5 text-[8px]" />
            <PersonLink id={person.id} className={cn('truncate text-xs hover:underline', head ? 'font-medium' : 'text-muted-foreground')}>
                {person.name}
            </PersonLink>
            {head && <Crown className="size-2.5 shrink-0 text-[#9A4A06] dark:text-[#F8C471]" aria-label="Руководитель" />}
        </li>
    );
}

/** Which boxes show their staff list; shared by every box of the chart. */
const StaffListContext = createContext<{ isOpen: (id: number) => boolean; toggle: (id: number) => void }>({
    isOpen: () => false,
    toggle: () => {},
});

/** One box of the chart: name, head count, heads and the rest of the staff, folded by default. */
function Node({ department, top }: { department: OrgDepartment; top?: boolean }) {
    const staff = useContext(StaffListContext);
    const open = staff.isOpen(department.id);

    return (
        <div
            className={cn(
                'bg-card relative flex w-60 flex-col gap-2 rounded-lg border px-3 py-2.5 shadow-xs',
                top && 'border-t-brand border-t-[3px] pt-2',
            )}
        >
            <div className="flex items-start gap-2">
                {/* A box is small and its link already owns the hover, so the
                    full name goes on the link rather than into a tooltip
                    nested inside it. */}
                <Link
                    href={route('departments.show', department.id)}
                    title={department.full_name === department.name ? undefined : department.full_name}
                    className="flex-1 text-[13px] leading-snug font-semibold hover:underline"
                >
                    <DepartmentName name={department.name} full={department.full_name} tooltip={false} />
                </Link>
                <Count value={department.total_count} />
            </div>

            {department.heads.length > 0 ? (
                <ul aria-label="Руководители" className="flex flex-col gap-1">
                    {department.heads.map((head) => (
                        <PersonLine key={head.id} person={head} head />
                    ))}
                </ul>
            ) : (
                <span className="text-muted-foreground/70 text-xs">Руководитель не назначен</span>
            )}

            {department.members.length > 0 && (
                <div className="flex flex-col gap-1.5 border-t pt-1.5">
                    <button
                        type="button"
                        onClick={() => staff.toggle(department.id)}
                        aria-expanded={open}
                        className="text-muted-foreground hover:bg-muted hover:text-foreground -mx-1 flex items-center gap-1 self-start rounded px-1 py-0.5 text-xs"
                    >
                        <ChevronDown className={cn('size-3.5 transition-transform', !open && '-rotate-90')} />
                        {peopleLabel(department.members.length)}
                    </button>
                    {open && (
                        <ul aria-label="Сотрудники" className="flex flex-col gap-1">
                            {department.members.map((member) => (
                                <PersonLine key={member.id} person={member} />
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Sub-departments hang below their parent in a column, joined by an elbow
 * line, so a deep tree grows down instead of sideways.
 */
function Column({ parentId, byParent }: { parentId: number; byParent: Map<number | null, OrgDepartment[]> }) {
    const children = byParent.get(parentId) ?? [];
    if (children.length === 0) return null;

    return (
        <ul className="ml-5 flex flex-col">
            {children.map((department) => (
                <li
                    key={department.id}
                    className={cn(
                        'relative pt-3 pl-5',
                        // Trunk down the left, cut at this box's elbow for the last child.
                        'after:bg-border after:absolute after:top-0 after:left-0 after:h-full after:w-px last:after:h-[31px]',
                        // Elbow into the box.
                        'before:bg-border before:absolute before:top-[31px] before:left-0 before:h-px before:w-5',
                    )}
                >
                    <Node department={department} />
                    <Column parentId={department.id} byParent={byParent} />
                </li>
            ))}
        </ul>
    );
}

const MIN_SCALE = 0.3;
const MAX_SCALE = 2;
const SCALE_KEY = 'departments.chart.scale';

const clampScale = (value: number) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, Math.round(value * 100) / 100));

function savedScale(): number {
    try {
        const value = Number(localStorage.getItem(SCALE_KEY));
        return value ? clampScale(value) : 1;
    } catch {
        return 1;
    }
}

function ZoomControls({
    allOpen,
    onToggleAll,
    scale,
    onZoom,
    onReset,
    onFit,
    fullscreen,
    onFullscreen,
}: {
    allOpen: boolean;
    onToggleAll: () => void;
    scale: number;
    onZoom: (factor: number) => void;
    onReset: () => void;
    onFit: () => void;
    fullscreen: boolean;
    /** Absent when the browser cannot go fullscreen. */
    onFullscreen?: () => void;
}) {
    return (
        // On a phone the bar would be wider than the screen, so it stands on end at the
        // right edge instead: a slim floating column with + above −. The percentage is
        // left out there; the steps are all a finger needs.
        <div className="bg-card absolute right-3 bottom-3 flex items-center gap-0.5 rounded-lg border p-0.5 shadow-sm max-md:right-2 max-md:bottom-2 max-md:flex-col-reverse max-md:rounded-xl max-md:shadow-md">
            <Button
                variant="ghost"
                size="icon"
                className="size-9 max-md:size-10 lg:size-8"
                onClick={onToggleAll}
                aria-label={allOpen ? 'Скрыть сотрудников во всех отделах' : 'Показать сотрудников во всех отделах'}
                title={allOpen ? 'Скрыть всех сотрудников' : 'Показать всех сотрудников'}
            >
                {allOpen ? <ChevronsDownUp /> : <ChevronsUpDown />}
            </Button>
            <span aria-hidden="true" className="bg-border mx-0.5 h-5 w-px max-md:mx-0 max-md:my-0.5 max-md:h-px max-md:w-6" />
            <Button
                variant="ghost"
                size="icon"
                className="size-9 max-md:size-10 lg:size-8"
                onClick={() => onZoom(1 / 1.2)}
                disabled={scale <= MIN_SCALE}
                aria-label="Отдалить"
            >
                <Minus />
            </Button>
            <Button variant="ghost" className="h-9 w-14 px-0 text-xs tabular-nums max-md:hidden lg:h-8" onClick={onReset} title="Сбросить до 100%">
                {Math.round(scale * 100)}%
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="size-9 max-md:size-10 lg:size-8"
                onClick={() => onZoom(1.2)}
                disabled={scale >= MAX_SCALE}
                aria-label="Приблизить"
            >
                <Plus />
            </Button>
            <span aria-hidden="true" className="bg-border mx-0.5 h-5 w-px max-md:mx-0 max-md:my-0.5 max-md:h-px max-md:w-6" />
            <Button
                variant="ghost"
                size="icon"
                className="size-9 max-md:size-10 lg:size-8"
                onClick={onFit}
                aria-label="Уместить схему целиком"
                title="Уместить целиком"
            >
                <Scan />
            </Button>
            {onFullscreen && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9 max-md:size-10 lg:size-8"
                    onClick={onFullscreen}
                    aria-label={fullscreen ? 'Выйти из полноэкранного режима' : 'На весь экран'}
                    title={fullscreen ? 'Выйти из полноэкранного режима (F или Esc)' : 'На весь экран (F)'}
                >
                    {fullscreen ? <Shrink /> : <Expand />}
                </Button>
            )}
        </div>
    );
}

/**
 * «Список / Схема» on a phone, drawn as an iOS segmented control: one grey
 * track with the chosen half raised. A tree of boxes is hard to read on a small
 * screen, so phone pages open on the list and keep the chart one tap away.
 */
export function PhoneViewSwitch<T extends string>({
    value,
    options,
    onChange,
    className,
}: {
    value: T;
    options: { value: T; label: string }[];
    onChange: (value: T) => void;
    className?: string;
}) {
    return (
        <div role="group" aria-label="Вид" className={cn('bg-muted grid auto-cols-fr grid-flow-col rounded-[10px] p-0.5 md:hidden', className)}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    onClick={() => onChange(option.value)}
                    aria-pressed={value === option.value}
                    className={cn(
                        'h-9 rounded-lg text-[14px] font-medium transition-colors',
                        value === option.value ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/**
 * The company as a chart: Evolet on top, departments in a row, units below
 * each. With `rootId`, one department's branch instead: that department on
 * top, its sub-departments below. Drag to move around; zoom with the buttons
 * or Ctrl + wheel / pinch; F or the button shows it fullscreen.
 */
export function OrgChart({
    departments,
    employeesCount = 0,
    rootId,
}: {
    departments: OrgDepartment[];
    /** Shown on the Evolet box of the company chart. */
    employeesCount?: number;
    rootId?: number;
}) {
    const byParent = groupByParent(departments);
    const root = rootId === undefined ? undefined : departments.find((d) => d.id === rootId);
    const top = byParent.get(root ? root.id : null) ?? [];

    const scroller = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(savedScale);

    // Staff lists start folded; open one box, or all of them from the toolbar.
    const withStaff = departments.filter((d) => d.members.length > 0).map((d) => d.id);
    const [openIds, setOpenIds] = useState<Set<number>>(() => new Set());
    const allOpen = withStaff.length > 0 && withStaff.every((id) => openIds.has(id));
    const staffList = {
        isOpen: (id: number) => openIds.has(id),
        toggle: (id: number) =>
            setOpenIds((current) => {
                const next = new Set(current);
                if (!next.delete(id)) next.add(id);
                return next;
            }),
    };
    // Natural (unscaled) size of the chart; the frame takes scale × this, so scrolling covers it exactly.
    const [size, setSize] = useState({ width: 0, height: 0 });
    // Chart point to keep under the cursor (or the centre) while the scale changes.
    const anchor = useRef<{ x: number; y: number; px: number; py: number } | null>(null);

    useLayoutEffect(() => {
        const el = content.current;
        if (!el) return;
        const measure = () => setSize({ width: el.offsetWidth, height: el.offsetHeight });
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(el);
        return () => observer.disconnect();
    }, []);

    // The frame is centred while it is smaller than the viewport.
    const frameOffset = useCallback(
        (s: number) => {
            const el = scroller.current!;
            return { x: Math.max(0, (el.clientWidth - size.width * s) / 2), y: Math.max(0, (el.clientHeight - size.height * s) / 2) };
        },
        [size],
    );

    const zoomTo = useCallback(
        (next: number, point?: { x: number; y: number }) => {
            const el = scroller.current;
            if (!el) return;
            next = clampScale(next);
            const rect = el.getBoundingClientRect();
            const px = point ? point.x - rect.left : el.clientWidth / 2;
            const py = point ? point.y - rect.top : el.clientHeight / 2;
            const offset = frameOffset(scale);
            anchor.current = { x: (el.scrollLeft + px - offset.x) / scale, y: (el.scrollTop + py - offset.y) / scale, px, py };
            setScale(next);
            try {
                localStorage.setItem(SCALE_KEY, String(next));
            } catch {
                // Blocked storage: the zoom just is not remembered.
            }
        },
        [scale, frameOffset],
    );

    // After a zoom, scroll so the anchored point is back where it was.
    useLayoutEffect(() => {
        const el = scroller.current;
        const point = anchor.current;
        if (!el || !point) return;
        anchor.current = null;
        const offset = frameOffset(scale);
        el.scrollLeft = point.x * scale + offset.x - point.px;
        el.scrollTop = point.y * scale + offset.y - point.py;
    }, [scale, frameOffset]);

    // Ctrl + wheel, and pinch on a touchpad, zoom around the cursor instead of zooming the page.
    useEffect(() => {
        const el = scroller.current;
        if (!el) return;
        const onWheel = (event: WheelEvent) => {
            if (!event.ctrlKey && !event.metaKey) return;
            event.preventDefault();
            zoomTo(scale * Math.exp(-event.deltaY * 0.002), { x: event.clientX, y: event.clientY });
        };
        el.addEventListener('wheel', onWheel, { passive: false });
        return () => el.removeEventListener('wheel', onWheel);
    }, [scale, zoomTo]);

    const fit = () => {
        const el = scroller.current;
        if (!el || !size.width) return;
        zoomTo(Math.min(1, el.clientWidth / size.width, el.clientHeight / size.height));
        // Everything fits, so start from the top-left rather than the old anchor.
        anchor.current = null;
        el.scrollTo({ left: 0, top: 0 });
    };

    // Fullscreen shows just the chart; the F key toggles it.
    const frame = useRef<HTMLDivElement>(null);
    const [fullscreen, setFullscreen] = useState(false);
    const canFullscreen = typeof document !== 'undefined' && document.fullscreenEnabled;

    const toggleFullscreen = useCallback(() => {
        if (document.fullscreenElement) void document.exitFullscreen();
        else void frame.current?.requestFullscreen();
    }, []);

    useEffect(() => {
        const onChange = () => setFullscreen(document.fullscreenElement === frame.current);
        const onKeyDown = (event: KeyboardEvent) => {
            // The physical F key, so it works in the Russian layout too; never while typing.
            const target = event.target as HTMLElement;
            const typing = target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
            if (
                event.code !== 'KeyF' ||
                typing ||
                event.repeat ||
                event.ctrlKey ||
                event.altKey ||
                event.metaKey ||
                event.shiftKey ||
                !document.fullscreenEnabled
            )
                return;
            event.preventDefault();
            toggleFullscreen();
        };
        document.addEventListener('fullscreenchange', onChange);
        window.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('fullscreenchange', onChange);
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [toggleFullscreen]);

    // Drag the chart around with the mouse.
    const drag = useRef<{ x: number; y: number; left: number; top: number } | null>(null);

    const onPointerDown = (event: PointerEvent<HTMLDivElement>) => {
        if (event.pointerType !== 'mouse' || event.button !== 0 || (event.target as HTMLElement).closest('a, button')) return;
        const el = scroller.current!;
        drag.current = { x: event.clientX, y: event.clientY, left: el.scrollLeft, top: el.scrollTop };
        el.setPointerCapture(event.pointerId);
    };
    const onPointerMove = (event: PointerEvent<HTMLDivElement>) => {
        if (!drag.current) return;
        const el = scroller.current!;
        el.scrollLeft = drag.current.left - (event.clientX - drag.current.x);
        el.scrollTop = drag.current.top - (event.clientY - drag.current.y);
    };
    const onPointerUp = () => (drag.current = null);

    // Below md the page is not held to the viewport, so the frame gets a height of
    // its own: left to grow with the chart, it would lay a tall tree out on the
    // page, push the zoom controls below the fold and give «Уместить» no height to
    // fit into. A phone pans the chart inside the frame with a finger instead.
    return (
        <div ref={frame} className="bg-sidebar relative flex h-[70svh] min-h-[24rem] flex-col md:h-auto md:min-h-0 md:flex-1">
            <div
                ref={scroller}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                className="flex flex-1 cursor-grab overflow-auto select-none [scrollbar-width:none] active:cursor-grabbing [&::-webkit-scrollbar]:hidden"
            >
                <div className="relative m-auto shrink-0" style={{ width: size.width * scale, height: size.height * scale }}>
                    <StaffListContext.Provider value={staffList}>
                        <div
                            ref={content}
                            className="absolute top-0 left-0 flex w-max origin-top-left flex-col items-center p-6"
                            style={{ transform: `scale(${scale})` }}
                        >
                            {root ? (
                                <Node department={root} top />
                            ) : (
                                <div className="bg-card flex items-center gap-3 rounded-xl border px-4 py-3 shadow-xs">
                                    <EvoletMark className="h-7 w-auto" />
                                    <div className="flex flex-col">
                                        <span className="text-sm font-semibold">Evolet</span>
                                        <span className="text-muted-foreground text-xs">{peopleLabel(employeesCount)}</span>
                                    </div>
                                </div>
                            )}

                            {top.length > 0 && (
                                <>
                                    <div className="bg-border h-6 w-px" />
                                    <ul className="flex">
                                        {top.map((department) => (
                                            <li
                                                key={department.id}
                                                className={cn(
                                                    'relative flex flex-col items-start px-2 pt-6',
                                                    // Rail across the top, split at the box centre (8px padding + half of w-60): left part to the previous box, right part to the next.
                                                    'before:bg-border before:absolute before:top-0 before:left-0 before:h-px before:w-[128px] first:before:hidden',
                                                    'after:bg-border after:absolute after:top-0 after:right-0 after:left-[128px] after:h-px last:after:hidden',
                                                )}
                                            >
                                                {/* Drop from the rail into the box. */}
                                                <span aria-hidden="true" className="bg-border absolute top-0 left-[128px] h-6 w-px" />
                                                <Node department={department} top={!root} />
                                                <Column parentId={department.id} byParent={byParent} />
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            )}
                        </div>
                    </StaffListContext.Provider>
                </div>
            </div>

            <ZoomControls
                allOpen={allOpen}
                onToggleAll={() => setOpenIds(allOpen ? new Set() : new Set(withStaff))}
                scale={scale}
                onZoom={(factor) => zoomTo(scale * factor)}
                onReset={() => zoomTo(1)}
                onFit={fit}
                fullscreen={fullscreen}
                onFullscreen={canFullscreen ? toggleFullscreen : undefined}
            />
        </div>
    );
}
