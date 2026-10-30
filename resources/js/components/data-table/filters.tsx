import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { ListFilter, Search } from 'lucide-react';
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
    /* What is being looked for in a long list of boxes to tick. */
    const [lookup, setLookup] = useState('');

    if (filter.type === 'text') {
        return (
            <form
                noValidate
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

        // A list is looked through rather than scrolled: every funnel with boxes
        // to tick opens on a line to type in, whether it holds the department
        // tree or five languages, so the way to find a value is the same one
        // everywhere. The needle is matched against what the line says — its
        // label and the full name beside it.
        const needle = lookup.trim().toLowerCase();
        const shown =
            needle === '' ? filter.options : filter.options.filter((option) => `${option.label} ${option.hint ?? ''}`.toLowerCase().includes(needle));

        return (
            <div className="flex flex-col gap-2">
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                    <Input
                        autoFocus
                        value={lookup}
                        onChange={(event) => setLookup(event.target.value)}
                        placeholder="Поиск"
                        aria-label="Поиск по списку"
                        className="h-9 pl-8"
                    />
                </div>

                <div className="flex max-h-72 flex-col gap-0.5 overflow-y-auto">
                    {filter.options.length === 0 && <p className="text-muted-foreground px-2 py-1.5 text-sm">Нет значений</p>}
                    {filter.options.length > 0 && shown.length === 0 && (
                        <p className="text-muted-foreground px-2 py-1.5 text-sm">Ничего не найдено</p>
                    )}
                    {shown.map((option) => {
                        const id = `filter-${filter.param}-${option.value}`;

                        return (
                            <div
                                key={id}
                                className="hover:bg-accent flex items-center gap-2 rounded-md px-2 py-1.5"
                                // A tree is indented by where a line sits in it,
                                // but not while the list is being looked through:
                                // what is found stands on its own.
                                style={option.depth && needle === '' ? { paddingLeft: 8 + option.depth * 20 } : undefined}
                            >
                                <Checkbox
                                    id={id}
                                    checked={selected.includes(option.value)}
                                    onCheckedChange={(on) => toggle(option.value, on === true)}
                                />
                                <Label htmlFor={id} className="flex-1 cursor-pointer font-normal">
                                    {option.label}
                                    {option.hint && <span className="text-muted-foreground ml-1.5 text-xs">{option.hint}</span>}
                                </Label>
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    }

    return (
        <form
            noValidate
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                onApply({ [filter.from]: from || null, [filter.to]: to || null });
            }}
        >
            <Label className="flex flex-col gap-1.5 text-xs">
                С
                <DatePicker value={from} onChange={setFrom} className="h-9" />
            </Label>
            <Label className="flex flex-col gap-1.5 text-xs">
                По
                <DatePicker value={to} onChange={setTo} className="h-9" />
            </Label>
            <Button type="submit" size="sm">
                Применить
            </Button>
        </form>
    );
}

/**
 * The columns whose funnel is drawn wider wherever it is opened: the structure
 * is a list of names with the full one written beside each, and the staff list,
 * the equipment and the directories all ask about it the same way.
 */
const WIDE = ['department', 'unit'];

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
    /** A tree of options needs more room than a short list; said where the column alone does not say it. */
    wide?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const filter = column.filter!;
    const active = isFilterActive(filter, filters);
    const roomy = wide ?? (WIDE.includes(column.key) || ('param' in filter && WIDE.includes(filter.param)));

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
            <PopoverContent align="start" className={cn('max-w-[calc(100vw-2rem)] p-3', roomy ? 'w-96' : 'w-64')}>
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
