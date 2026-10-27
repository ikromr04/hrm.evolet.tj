import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Bookmark, Building2, ChevronDown, Pencil, Plus, RotateCcw, Save, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { type TableMemory } from './view';

/** Which view the name is being asked for: a new one, or one being renamed. */
type Naming = { kind: 'new' } | { kind: 'rename'; id: string };

/**
 * Everything about how a table looks, under one button.
 *
 * The arrangements it can stand in — the one the page draws and every one this
 * viewer has kept under a name of its own — with what can be done about them,
 * and under those the columns the page offers to show or hide, which are the
 * page's own business and arrive as children.
 *
 * Without those columns the button keeps to itself until there is something to
 * say: a table nobody has touched, with nothing kept for it, has no view to
 * choose between.
 */
export function TableMemoryButton({
    memory,
    className,
    children,
    modes,
    mode,
    onMode,
}: {
    memory: TableMemory;
    className?: string;
    children?: ReactNode;
    /**
     * The ways this page can draw its list at all — not arrangements of one
     * table but different rows in it, so they stand above the views rather
     * than among them.
     */
    modes?: { value: string; label: string }[];
    mode?: string;
    onMode?: (value: string) => void;
}) {
    const [naming, setNaming] = useState<Naming | null>(null);
    const [name, setName] = useState('');

    if (!memory.offered && !children) return null;

    const active = memory.views.find((one) => one.id === memory.activeId) ?? null;

    const ask = (what: Naming) => {
        setName(what.kind === 'new' ? memory.suggestedName : (memory.views.find((one) => one.id === what.id)?.name ?? ''));
        setNaming(what);
    };

    const confirm = () => {
        const given = name.trim();
        if (given === '' || naming === null) return;

        if (naming.kind === 'new') memory.save(given);
        else memory.rename(naming.id, given);

        setNaming(null);
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" className={cn('h-9 font-normal', className)}>
                        <Bookmark />
                        {active ? active.name : 'Вид'}
                        {/* An arrangement that belongs to no kept view is marked as its own. */}
                        {memory.current === 'custom' && <span className="bg-brand size-1.5 rounded-full" aria-label="изменён" />}
                        <ChevronDown className="text-muted-foreground" />
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent
                    align="end"
                    // As wide as its longest line rather than a width picked in
                    // advance: the items name views somebody else wrote and say
                    // what can be done to them. Never narrower than it was, and
                    // never wider than the screen it drops onto.
                    // Flush to the sides: the menu's own padding was a strip
                    // along each edge that reads as part of the row under the
                    // cursor and answers to nothing, so the rows take the whole
                    // width and a press anywhere on one lands on it.
                    className="max-h-[32rem] w-max max-w-[min(26rem,calc(100vw-2rem))] min-w-72 overflow-y-auto px-0"
                    // A menu closing hands the focus back to its button, which
                    // would take it out of the box the name is typed into.
                    onCloseAutoFocus={(event) => naming !== null && event.preventDefault()}
                >
                    {modes && modes.length > 1 && (
                        <>
                            <DropdownMenuLabel>Таблица</DropdownMenuLabel>
                            <DropdownMenuRadioGroup value={mode} onValueChange={(next) => onMode?.(next)}>
                                {modes.map((one) => (
                                    <DropdownMenuRadioItem key={one.value} value={one.value}>
                                        {one.label}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                            <DropdownMenuSeparator />
                        </>
                    )}

                    <DropdownMenuLabel>Вид таблицы</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={memory.current === 'custom' ? 'custom' : (memory.activeId ?? 'default')}
                        onValueChange={(next) => memory.choose(next === 'default' ? null : next)}
                    >
                        <DropdownMenuRadioItem value="default">По умолчанию</DropdownMenuRadioItem>
                        {memory.views.map((one) => (
                            <DropdownMenuRadioItem key={one.id} value={one.id}>
                                <span className="truncate">{one.name}</span>
                            </DropdownMenuRadioItem>
                        ))}
                        {/* What is on screen, which is none of them until it is kept. */}
                        {memory.current === 'custom' && (
                            <DropdownMenuRadioItem value="custom" disabled>
                                {active ? `${active.name} (изменён)` : 'Текущий (не сохранён)'}
                            </DropdownMenuRadioItem>
                        )}
                    </DropdownMenuRadioGroup>

                    <DropdownMenuSeparator />

                    {memory.current === 'custom' && active && (
                        <DropdownMenuItem onSelect={() => memory.overwrite(active.id)}>
                            <Save />
                            Сохранить в «{active.name}»
                        </DropdownMenuItem>
                    )}
                    {memory.current !== 'default' && (
                        <DropdownMenuItem onSelect={() => ask({ kind: 'new' })}>
                            <Plus />
                            Сохранить как новый вид
                        </DropdownMenuItem>
                    )}
                    {active && (
                        <>
                            <DropdownMenuItem onSelect={() => ask({ kind: 'rename', id: active.id })}>
                                <Pencil />
                                Переименовать «{active.name}»…
                            </DropdownMenuItem>
                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={() => memory.remove(active.id)}>
                                <Trash2 />
                                Удалить «{active.name}»
                            </DropdownMenuItem>
                        </>
                    )}

                    {/*
                     * How the table opens for everybody who has not arranged it
                     * themselves — which is the company's business rather than
                     * any one reader's, so only the one account outside the list
                     * of rights is offered it. Nobody's own views are touched by
                     * it: those are kept in their own browsers and outrank this.
                     */}
                    {memory.canPublish && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onSelect={() => memory.publish()}>
                                <Building2 />
                                Сделать видом по умолчанию для всех
                            </DropdownMenuItem>
                            {memory.published && (
                                <DropdownMenuItem onSelect={() => memory.unpublish()}>
                                    <RotateCcw />
                                    Вернуть вид по умолчанию
                                </DropdownMenuItem>
                            )}
                        </>
                    )}

                    {children && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Показывать колонки</DropdownMenuLabel>
                            {children}
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={naming !== null} onOpenChange={(open) => !open && setNaming(null)}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{naming?.kind === 'rename' ? 'Переименовать вид' : 'Новый вид'}</DialogTitle>
                        <DialogDescription>Колонки, сортировка и фильтры сохранятся под этим названием.</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="table-view-name">Название</Label>
                        <Input
                            id="table-view-name"
                            autoFocus
                            value={name}
                            maxLength={40}
                            onChange={(event) => setName(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key !== 'Enter') return;
                                event.preventDefault();
                                confirm();
                            }}
                        />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setNaming(null)}>
                            Отмена
                        </Button>
                        <Button onClick={confirm} disabled={name.trim() === ''}>
                            Сохранить
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
