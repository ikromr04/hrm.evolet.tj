import { useClearingForm } from '@/hooks/use-clearing-form';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Учётная запись',
        href: '/settings/profile',
    },
];

/**
 * The address a person signs in with, and the only thing about themselves they
 * settle here: the name, the position and the rest of the card are filed by HR.
 *
 * A new address is asked for rather than set. A letter goes to the address
 * asked for, and the account keeps the one it has until that letter is
 * answered — a typo would otherwise lock somebody out of the system.
 */
export default function Profile({ pendingEmail, status }: { pendingEmail: string | null; status?: string }) {
    const { auth } = usePage<SharedData>().props;
    // Everything in the shell is behind the door, so there is somebody here.
    const user = auth.user!;

    const { data, setData, patch, errors, processing } = useClearingForm({
        email: pendingEmail ?? user.email,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Учётная запись" />

            <SettingsLayout>
                <div className="space-y-6">
                    {/* The phone's top bar already says «Учётная запись», so there the heading would only repeat it. */}
                    <div className="max-md:hidden">
                        <HeadingSmall title="Учётная запись" description="Адрес, с которым вы входите в систему" />
                    </div>

                    <form noValidate onSubmit={submit} className="space-y-6 max-md:space-y-4">
                        {/* On a phone the field and the note on it are one grouped card on the grey page. */}
                        <div className="max-md:bg-card grid max-w-md content-start gap-2 max-md:max-w-none max-md:rounded-2xl max-md:p-4">
                            <Label htmlFor="email" required>
                                Электронная почта
                            </Label>

                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                required
                                autoComplete="username"
                                placeholder="name@evolet.co.uk"
                                aria-invalid={!!errors.email}
                                className="max-md:h-11"
                            />

                            <InputError message={errors.email} />

                            <p className="text-muted-foreground text-[13px]">
                                Сейчас вход по адресу <span className="font-medium break-all">{user.email}</span>.
                            </p>
                        </div>

                        {status === 'email-changed' && (
                            <p className="text-brand-strong text-sm font-medium max-md:px-1 dark:text-[#C5E27A]">
                                Адрес подтверждён — теперь входите по нему.
                            </p>
                        )}

                        {pendingEmail !== null && (
                            <div className="max-md:bg-card max-w-md space-y-2 rounded-lg border p-4 max-md:max-w-none max-md:rounded-2xl max-md:border-0">
                                <p className="text-sm">
                                    Ждём подтверждения адреса <span className="font-medium break-all">{pendingEmail}</span>.
                                </p>
                                <p className="text-muted-foreground text-[13px]">
                                    {status === 'email-confirmation-sent'
                                        ? 'Письмо отправлено. Перейдите по ссылке из него — до этого вход по прежнему адресу.'
                                        : 'Перейдите по ссылке из письма — до этого вход по прежнему адресу. Ссылка действует час.'}
                                </p>

                                <div className="flex flex-wrap items-center gap-3 pt-1 max-md:flex-col max-md:items-stretch max-md:gap-1">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        className="max-md:h-11 max-md:text-[15px]"
                                    >
                                        Отправить письмо ещё раз
                                    </Button>
                                    <Link
                                        href={route('email.cancel')}
                                        method="delete"
                                        as="button"
                                        className="text-muted-foreground text-[13px] underline max-md:min-h-10 max-md:text-[15px] max-md:no-underline"
                                    >
                                        Отменить смену
                                    </Link>
                                </div>
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing} className="max-md:h-11 max-md:w-full max-md:text-[15px]">
                                Сохранить
                            </Button>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
