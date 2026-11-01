import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link } from '@inertiajs/react';
import { LogOut } from 'lucide-react';

/**
 * Every address, for the two roles the system is not finished for yet.
 *
 * A head of department and a specialist can sign in, and that is deliberately
 * all they can do: what they should see of their own card, and of anybody
 * else's, has not been decided, and a half-built section leaves somebody
 * guessing which half is which. So App\Http\Middleware\EnsureSiteIsOpen answers
 * every address with this one page.
 *
 * It stands outside the shell, like the maintenance page does: a sidebar whose
 * every entry led back here would be a menu of closed doors. Nothing is wrong
 * and nothing failed, so it is not an error page and does not read as one — it
 * greets the person by name, because somebody who has just typed a password is
 * owed the confirmation that it was accepted, and then says plainly that there
 * is nothing to do here yet.
 */
export default function Closed({ name, role }: { name: string; role: string | null }) {
    return (
        <AuthLayout
            title={`Здравствуйте, ${name}`}
            description="Вы вошли в систему, но раздел для вашей роли ещё готовится — пока здесь нечего делать."
            note="Когда раздел будет готов, он откроется сам."
        >
            <Head title="Раздел ещё готовится" />

            <div className="flex flex-col gap-6">
                {/* Said where the role is known: it is the reason this page is
                    the whole system for now, so it is worth naming. */}
                {role && (
                    <p className="text-muted-foreground text-center text-sm">
                        Ваша роль: <span className="text-foreground font-medium">{role}</span>
                    </p>
                )}

                {/* The one thing there is to do, so it is the one button on the
                    page — a link that posts, the way «Выйти» posts everywhere else. */}
                <Button asChild variant="outline" className="w-full max-md:h-11 max-md:text-[15px]">
                    <Link method="post" href={route('logout')} as="button">
                        <LogOut />
                        Выйти
                    </Link>
                </Button>
            </div>
        </AuthLayout>
    );
}
