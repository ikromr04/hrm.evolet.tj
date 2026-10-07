import { Pickable } from '@/components/pickable';
import { StatusBadge } from '@/components/status-badge';

/**
 * A person's citizenships, one chip per country, the way the languages beside
 * them read: a second passport shows at a glance instead of hiding at the end of
 * a line of commas. A chip narrows the staff list to that country — on the spot
 * with `onPick`, or as a link with `href`.
 */
export function CitizenshipBadges({
    countries,
    onPick,
    isPicked,
    href,
}: {
    countries: string[];
    onPick?: (country: string) => void;
    /** Lights up the countries the list is already filtered by. */
    isPicked?: (country: string) => boolean;
    href?: (country: string) => string;
}) {
    return (
        <div className="flex flex-wrap gap-1 whitespace-normal">
            {countries.map((country) => (
                <Pickable
                    key={country}
                    label={`Сотрудники с гражданством: ${country}`}
                    onPick={onPick && (() => onPick(country))}
                    picked={isPicked?.(country)}
                    href={href?.(country)}
                >
                    <StatusBadge tone={isPicked?.(country) ? 'success' : 'neutral'}>{country}</StatusBadge>
                </Pickable>
            ))}
        </div>
    );
}
