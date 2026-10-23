import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { Search, X } from 'lucide-react';

/**
 * The box a list is looked through from: the glass at one end, and once there
 * is something in it, the cross to empty it at the other.
 *
 * The cross is ours rather than the browser's. Every browser draws its own over
 * a search box — a different shape in each, grey in both themes, and in Safari
 * none at all — so the native one is hidden in the stylesheet and this one
 * stands in its place, the same button the rest of the app is built from.
 */
export function SearchInput({
    value,
    onChange,
    placeholder = 'Поиск',
    label,
    id,
    className,
    inputClassName,
    autoFocus,
}: {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    /** What the box is for, for a screen reader; the placeholder by default. */
    label?: string;
    id?: string;
    className?: string;
    inputClassName?: string;
    autoFocus?: boolean;
}) {
    const said = label ?? placeholder;

    return (
        <div className={cn('relative', className)}>
            {id && (
                <Label htmlFor={id} className="sr-only">
                    {said}
                </Label>
            )}

            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />

            <Input
                id={id}
                type="search"
                autoFocus={autoFocus}
                value={value}
                aria-label={id ? undefined : said}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className={cn('pr-9 pl-9', inputClassName)}
            />

            {value !== '' && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Очистить"
                    title="Очистить"
                    // Over the box's own right edge, the height of its text
                    // rather than of the box, so the two do not look nested.
                    className="text-muted-foreground hover:text-foreground absolute top-1/2 right-1 size-7 -translate-y-1/2"
                    onClick={() => onChange('')}
                >
                    <X />
                </Button>
            )}
        </div>
    );
}
