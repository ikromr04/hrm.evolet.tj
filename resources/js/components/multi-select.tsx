import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronsUpDown, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';

export interface MultiSelectOption<T extends string | number> {
    value: T;
    label: string;
    /** Indent level, for trees such as departments. */
    depth?: number;
    /**
     * The short label spelled out beside itself. A department is named by its
     * abbreviation everywhere, and here is where it says what that stands for:
     * a list one picks from is not a link one can follow to find out.
     */
    hint?: string;
}

/** Choose any number of options from a searchable list; the choice shows as removable chips. */
export function MultiSelect<T extends string | number>({
    id,
    options,
    value,
    onChange,
    placeholder = 'Не выбрано',
    searchPlaceholder = 'Поиск',
    chipClassName,
    disabled = false,
    creatable = false,
}: {
    id?: string;
    options: MultiSelectOption<T>[];
    value: T[];
    onChange: (value: T[]) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    chipClassName?: string;
    /** Read-only: what is chosen still shows, but nothing can be added or taken off. */
    disabled?: boolean;
    /**
     * The options are suggestions, not the whole list: what is typed can be added
     * as it is (a country nobody has been entered with yet). Text values only.
     */
    creatable?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    // A value typed in has no option of its own, so it is shown as itself.
    const selected = creatable
        ? value.map((v) => options.find((option) => option.value === v) ?? { value: v, label: String(v) })
        : options.filter((option) => value.includes(option.value));

    const typed = query.trim();
    const term = typed.toLowerCase();
    // While searching, the tree is flattened: indentation would mislead.
    const matches = term ? options.filter((option) => option.label.toLowerCase().includes(term)) : options;
    // Offered only when it is something new: an existing option is picked instead.
    const canCreate = creatable && typed !== '' && ![...options, ...selected].some((option) => option.label.toLowerCase() === term);

    const toggle = (next: T) => onChange(value.includes(next) ? value.filter((v) => v !== next) : [...value, next]);
    const create = () => {
        onChange([...value, typed as T]);
        setQuery('');
    };

    return (
        <div className="flex flex-col gap-2">
            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-1.5">
                    {selected.map((option) => (
                        <li
                            key={option.value}
                            className={cn(
                                'bg-muted flex max-w-full min-w-0 items-center gap-1 rounded-md py-0.5 pl-2 text-sm',
                                disabled ? 'pr-2' : 'pr-0.5',
                                chipClassName,
                            )}
                        >
                            <span className="min-w-0 break-words">{option.label}</span>
                            {!disabled && (
                                <button
                                    type="button"
                                    onClick={() => toggle(option.value)}
                                    aria-label={`Убрать: ${option.label}`}
                                    className="shrink-0 rounded-sm p-1.5 opacity-60 hover:bg-black/10 hover:opacity-100 lg:p-0.5 dark:hover:bg-white/10"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) setQuery('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        disabled={disabled}
                        className="w-full justify-between font-normal"
                    >
                        <span className="text-muted-foreground">{selected.length ? 'Добавить ещё…' : placeholder}</span>
                        <ChevronsUpDown className="text-muted-foreground" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-(--radix-popover-trigger-width) p-0">
                    <label className="flex items-center gap-2 border-b px-3">
                        <Search className="text-muted-foreground size-4 shrink-0" />
                        <span className="sr-only">{searchPlaceholder}</span>
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key !== 'Enter' || !creatable) return;
                                // Enter adds what was typed rather than submitting the form around it.
                                event.preventDefault();
                                if (canCreate) create();
                                else if (matches.length > 0 && !value.includes(matches[0].value)) toggle(matches[0].value);
                            }}
                            placeholder={searchPlaceholder}
                            className="h-10 min-w-0 flex-1 bg-transparent text-sm outline-hidden lg:h-9"
                        />
                    </label>
                    <ul role="listbox" aria-multiselectable="true" className="max-h-64 overflow-y-auto p-1">
                        {matches.map((option) => (
                            <li key={option.value}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={value.includes(option.value)}
                                    onClick={() => toggle(option.value)}
                                    className="hover:bg-accent flex w-full items-center gap-2 rounded-sm px-2 py-2.5 text-left text-sm lg:py-1.5"
                                >
                                    <Check className={cn('size-4 shrink-0', value.includes(option.value) ? 'opacity-100' : 'opacity-0')} />
                                    <span className="truncate" style={term ? undefined : { paddingLeft: (option.depth ?? 0) * 16 }}>
                                        {option.label}
                                        {option.hint && <span className="text-muted-foreground ml-1.5 text-xs">{option.hint}</span>}
                                    </span>
                                </button>
                            </li>
                        ))}
                        {canCreate && (
                            <li>
                                <button
                                    type="button"
                                    onClick={create}
                                    className="hover:bg-accent flex w-full items-center gap-2 rounded-sm px-2 py-2.5 text-left text-sm lg:py-1.5"
                                >
                                    <Plus className="size-4 shrink-0" />
                                    <span className="truncate">Добавить «{typed}»</span>
                                </button>
                            </li>
                        )}
                        {matches.length === 0 && !canCreate && (
                            <li className="text-muted-foreground px-2 py-3 text-center text-sm">Ничего не нашлось</li>
                        )}
                    </ul>
                </PopoverContent>
            </Popover>
        </div>
    );
}
