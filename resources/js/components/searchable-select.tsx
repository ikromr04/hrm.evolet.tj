import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Check, ChevronDown, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

export interface SearchableOption {
    value: string;
    label: string;
    /** A second line under the label, such as a department or an inventory number. */
    hint?: string;
}

interface SearchableSelectProps {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    options: SearchableOption[];
    placeholder?: string;
    searchPlaceholder?: string;
    /** Shown when nothing matches what was typed. */
    empty?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
    /**
     * When given, what is typed and matches no option can be added as it is: the
     * page decides what a new value becomes (a language missing from the list).
     */
    onCreate?: (label: string) => void;
}

/**
 * A select you can type into: the same box as the plain one, but the list
 * narrows as you search. Long lists of colleagues are unusable without it.
 */
export function SearchableSelect({
    id,
    value,
    onChange,
    options,
    placeholder = 'Выберите',
    searchPlaceholder = 'Поиск',
    empty = 'Ничего не найдено',
    invalid,
    disabled,
    className,
    onCreate,
}: SearchableSelectProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [highlighted, setHighlighted] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);

    const chosen = options.find((option) => option.value === value);

    const matches = useMemo(() => {
        const term = query.trim().toLocaleLowerCase();
        if (term === '') return options;

        return options.filter((option) => `${option.label} ${option.hint ?? ''}`.toLocaleLowerCase().includes(term));
    }, [options, query]);

    const typed = query.trim();
    // Offered only for something new: an option already on the list is picked instead.
    const canCreate =
        onCreate !== undefined && typed !== '' && !options.some((option) => option.label.toLocaleLowerCase() === typed.toLocaleLowerCase());
    const create = () => {
        onCreate?.(typed);
        setOpen(false);
    };

    // A fresh search starts at the top; the highlight never points past the list.
    useEffect(() => setHighlighted(0), [query]);

    // Opening it puts the cursor on whatever is chosen, not on the first name.
    useEffect(() => {
        if (!open) return;

        setQuery('');
        const index = options.findIndex((option) => option.value === value);
        setHighlighted(index < 0 ? 0 : index);
    }, [open, options, value]);

    useEffect(() => {
        listRef.current?.querySelector('[data-highlighted="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [highlighted, matches]);

    const pick = (option: SearchableOption) => {
        onChange(option.value);
        setOpen(false);
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setHighlighted((current) => (matches.length === 0 ? 0 : (current + step + matches.length) % matches.length));
        }

        if (event.key === 'Enter' && matches[highlighted]) {
            event.preventDefault();
            pick(matches[highlighted]);
        } else if (event.key === 'Enter' && canCreate) {
            event.preventDefault();
            create();
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    id={id}
                    type="button"
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={invalid}
                    disabled={disabled}
                    className={cn(
                        'border-input bg-background ring-offset-background focus:ring-ring flex h-10 w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
                        'aria-invalid:border-red-600',
                        className,
                    )}
                >
                    <span className={cn('truncate', !chosen && 'text-placeholder')}>{chosen?.label ?? placeholder}</span>
                    <ChevronDown className="size-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>

            <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] p-0" onKeyDown={onKeyDown}>
                {/* A search row, not a field inside a field: no border, no ring of its own. */}
                <div className="flex h-10 items-center gap-2 border-b px-3">
                    <Search className="text-muted-foreground size-4 shrink-0" />
                    <input
                        autoFocus
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={searchPlaceholder}
                        className="placeholder:text-placeholder min-w-0 flex-1 bg-transparent text-sm outline-hidden"
                    />
                </div>

                <div ref={listRef} className="scroll-soft max-h-64 overflow-y-auto p-1" role="listbox">
                    {matches.length === 0 && !canCreate && <p className="text-muted-foreground px-2 py-6 text-center text-sm">{empty}</p>}

                    {matches.map((option, index) => (
                        <button
                            key={option.value}
                            type="button"
                            role="option"
                            aria-selected={option.value === value}
                            data-highlighted={index === highlighted}
                            onMouseEnter={() => setHighlighted(index)}
                            onClick={() => pick(option)}
                            className={cn(
                                'flex w-full items-center gap-2 rounded-md px-2 py-2.5 text-left text-sm lg:py-1.5',
                                index === highlighted && 'bg-accent text-accent-foreground',
                            )}
                        >
                            <Check className={cn('size-4 shrink-0', option.value === value ? 'opacity-100' : 'opacity-0')} />
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate">{option.label}</span>
                                {option.hint && <span className="text-muted-foreground truncate text-xs">{option.hint}</span>}
                            </span>
                        </button>
                    ))}

                    {canCreate && (
                        <button
                            type="button"
                            onClick={create}
                            className="hover:bg-accent flex w-full items-center gap-2 rounded-md px-2 py-2.5 text-left text-sm lg:py-1.5"
                        >
                            <Plus className="size-4 shrink-0" />
                            <span className="truncate">Добавить «{typed}»</span>
                        </button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
