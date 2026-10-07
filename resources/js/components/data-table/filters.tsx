import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { ListFilter } from 'lucide-react';
import { useState } from 'react';
import { HeaderHint } from './hint';
import { type ColumnDef, type FilterDef, type FilterValues } from './types';

/** Whether a filter narrows anything, which is what lights its icon up. */
export function isFilterActive(filter: FilterDef, filters: FilterValues): boolean {
    switch (filter.type) {
        case 'text':
            return filters[filter.param] !== '' && filters[filter.param] != null;
        case 'select':
            return filters[filter.param] != null;
        case 'multi':
            return ((filters[filter.param] as unknown[]) ?? []).length > 0;
        case 'dates':
            return filters[filter.from] != null || filters[filter.to] != null;
    }
}

/** The changes that switch a filter off again. */
export function clearedFilter(filter: FilterDef): FilterValues {
    switch (filter.type) {
        case 'text':
            return { [filter.param]: '' };
        case 'select':
            return { [filter.param]: null };
        case 'multi':
            return { [filter.param]: [] };
        case 'dates':
            return { [filter.from]: null, [filter.to]: null };
    }
}

export function FilterBody({ filter, filters, onApply }: { filter: FilterDef; filters: FilterValues; onApply: (changes: FilterValues) => void }) {
    const [text, setText] = useState(filter.type === 'text' ? ((filters[filter.param] as string) ?? '') : '');
    const [from, setFrom] = useState(filter.type === 'dates' ? ((filters[filter.from] as string) ?? '') : '');
    const [to, setTo] = useState(filter.type === 'dates' ? ((filters[filter.to] as string) ?? '') : '');

    if (filter.type === 'text') {
        return (
            <form
                className="flex flex-col gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    onApply({ [filter.param]: text.trim() });
                }}
            >
                <Input autoFocus value={text} onChange={(event) => setText(event.target.value)} placeholder={filter.placeholder} className="h-9" />
                <Button type="submit" size="sm">
                    Применить
                </Button>
            </form>
        );
    }

    if (filter.type === 'select') {
        const value = filters[filter.param];

        return (
            <div className="flex flex-col gap-1" role="radiogroup">
                {[{ value: null, label: 'Все' }, ...filter.options].map((option) => (
                    <button
                        key={option.label}
                        type="button"
                        role="radio"
                        aria-checked={value === option.value}
                        onClick={() => onApply({ [filter.param]: option.value })}
                        className={cn('hover:bg-accent rounded-md px-2 py-1.5 text-left text-sm', value === option.value && 'bg-accent font-medium')}
                    >
                        {option.label}
                    </button>
                ))}
            </div>
        );
    }

    if (filter.type === 'multi') {
        const selected = (filters[filter.param] as (string | number)[]) ?? [];
        const toggle = (value: string | number, on: boolean) =>
            onApply({ [filter.param]: on ? [...selected, value] : selected.filter((item) => item !== value) });

        return (
            <div className="flex max-h-72 flex-col gap-0.5 overflow-y-auto">
                {filter.options.length === 0 && <p className="text-muted-foreground px-2 py-1.5 text-sm">Нет значений</p>}
                {filter.options.map((option) => {
                    const id = `filter-${filter.param}-${option.value}`;

                    return (
                        <div
                            key={id}
                            className="hover:bg-accent flex items-center gap-2 rounded-md px-2 py-1.5"
                            style={option.depth ? { paddingLeft: 8 + option.depth * 20 } : undefined}
                        >
                            <Checkbox id={id} checked={selected.includes(option.value)} onCheckedChange={(on) => toggle(option.value, on === true)} />
                            <Label htmlFor={id} className="flex-1 cursor-pointer font-normal">
                                {option.label}
                            </Label>
                        </div>
                    );
                })}
            </div>
        );
    }

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                onApply({ [filter.from]: from || null, [filter.to]: to || null });
            }}
        >
            <Label className="flex flex-col gap-1.5 text-xs">
                С
                <Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} className="h-9" />
            </Label>
            <Label className="flex flex-col gap-1.5 text-xs">
                По
                <Input type="date" value={to} onChange={(event) => setTo(event.target.value)} className="h-9" />
            </Label>
            <Button type="submit" size="sm">
                Применить
            </Button>
        </form>
    );
}

/** The funnel in a column header, and the popover it opens. */
export function ColumnFilter({
    column,
    filters,
    onApply,
    wide,
}: {
    column: ColumnDef;
    filters: FilterValues;
    onApply: (changes: FilterValues) => void;
    /** A tree of options needs more room than a short list. */
    wide?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const filter = column.filter!;
    const active = isFilterActive(filter, filters);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <HeaderHint label={active ? 'Фильтр включён' : 'Фильтр'}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        aria-label={`Фильтр: ${column.label}`}
                        className={cn(
                            'hover:bg-accent hover:text-foreground relative rounded p-1',
                            active ? 'text-brand-strong dark:text-[#C5E27A]' : 'opacity-50 hover:opacity-100',
                        )}
                    >
                        <ListFilter className="size-3.5" />
                        {active && <span className="bg-brand absolute top-0.5 right-0.5 size-1.5 rounded-full" />}
                    </button>
                </PopoverTrigger>
            </HeaderHint>
            <PopoverContent align="start" className={cn('max-w-[calc(100vw-2rem)] p-3', wide ? 'w-96' : 'w-64')}>
                <div className="mb-2 flex items-center justify-between">
                    <span className="text-sm font-semibold">{column.label}</span>
                    {active && (
                        <button
                            type="button"
                            onClick={() => {
                                onApply(clearedFilter(filter));
                                setOpen(false);
                            }}
                            className="text-muted-foreground hover:text-foreground text-xs"
                        >
                            Сбросить
                        </button>
                    )}
                </div>
                <FilterBody
                    filter={filter}
                    filters={filters}
                    onApply={(changes) => {
                        onApply(changes);
                        // A list of checkboxes stays open for the next tick.
                        if (filter.type !== 'multi') setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
