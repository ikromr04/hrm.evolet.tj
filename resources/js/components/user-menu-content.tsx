import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { Link } from '@inertiajs/react';
import { CircleUser, LogOut, Settings } from 'lucide-react';

export function UserMenuContent() {
    const cleanup = useMobileNavigation();

    return (
        <>
            {/* The three things one does with one's own account, each named in a
                word: the card, the settings behind it, and the way out.

                The menu used to open with the name and the address again, which
                is what the button it hangs off already says — and that block was
                the only way to one's own card, so the way in was a picture of
                oneself rather than a word. «Профиль» is where the card lives
                for everybody now, whether or not the menu beside it holds the
                rest of the company. */}
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href={route('profile')} prefetch onClick={cleanup}>
                        <CircleUser className="mr-2" />
                        Профиль
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link className="block w-full" href={route('profile.edit')} as="button" prefetch onClick={cleanup}>
                        <Settings className="mr-2" />
                        Настройки
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link className="block w-full" method="post" href={route('logout')} as="button" onClick={cleanup}>
                    <LogOut className="mr-2" />
                    Выйти
                </Link>
            </DropdownMenuItem>
        </>
    );
}
