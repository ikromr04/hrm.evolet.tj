import { formatPhone } from '@/lib/employee';
import { cn } from '@/lib/utils';

/** A phone number and, below it, the SOS number highlighted in red. */
export function Phones({
    phone,
    sos,
    sosContact,
    className,
}: {
    phone: string | null;
    sos: string | null;
    sosContact?: string | null;
    className?: string;
}) {
    if (!phone && !sos) return <span className="text-muted-foreground">—</span>;

    return (
        <div className={cn('flex flex-col gap-1', className)}>
            {phone && (
                <a href={`tel:${phone}`} className="text-foreground tabular-nums hover:underline">
                    {formatPhone(phone)}
                </a>
            )}
            {sos && <SosPhone phone={sos} contact={sosContact} />}
        </div>
    );
}

/** The SOS number and, when known, whose it is: "Мама — Дилором". */
export function SosPhone({ phone, contact }: { phone: string; contact?: string | null }) {
    return (
        <span className="flex flex-col gap-0.5">
            <a
                href={`tel:${phone}`}
                className="flex items-center gap-1.5 tabular-nums hover:underline"
                aria-label={contact ? `Экстренный контакт (${contact}): ${phone}` : `Экстренный контакт: ${phone}`}
            >
                <span className="rounded bg-[#FDE8E6] px-1 text-[10px] leading-4 font-bold tracking-wide text-[#B42318] dark:bg-[#EF4444]/15 dark:text-[#F7A19A]">
                    SOS
                </span>
                <span className="text-[#B42318] dark:text-[#F7A19A]">{formatPhone(phone)}</span>
            </a>
            {contact && <span className="text-muted-foreground text-xs">{contact}</span>}
        </span>
    );
}
