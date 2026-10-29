import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Пароль',
        href: '/settings/password',
    },
];

export default function Password() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Пароль" />

            <SettingsLayout>
                <div className="space-y-6">
                    {/* The phone's top bar already names the page; the advice moves under the fields there, as a note. */}
                    <div className="max-md:hidden">
                        <HeadingSmall title="Смена пароля" description="Не менее 12 символов, с буквами и цифрами, и больше нигде не используется" />
                    </div>

                    <form noValidate onSubmit={updatePassword} className="space-y-6 max-md:space-y-4">
                        {/* On a phone the three fields are one grouped card on the grey page. */}
                        <div className="max-md:bg-card space-y-6 max-md:space-y-4 max-md:rounded-2xl max-md:p-4">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="current_password">Текущий пароль</Label>

                                <Input
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    type="password"
                                    className="mt-1 block w-full max-md:h-11"
                                    autoComplete="current-password"
                                    placeholder="Текущий пароль"
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="password">Новый пароль</Label>

                                <Input
                                    id="password"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    type="password"
                                    className="mt-1 block w-full max-md:h-11"
                                    autoComplete="new-password"
                                    placeholder="Новый пароль"
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid content-start gap-2">
                                <Label htmlFor="password_confirmation">Повторите пароль</Label>

                                <Input
                                    id="password_confirmation"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    type="password"
                                    className="mt-1 block w-full max-md:h-11"
                                    autoComplete="new-password"
                                    placeholder="Повторите пароль"
                                />

                                <InputError message={errors.password_confirmation} />
                            </div>
                        </div>

                        <p className="text-muted-foreground -mt-2 px-1 text-[13px] md:hidden">
                            Не менее 12 символов, с буквами и цифрами, и больше нигде не используется.
                        </p>

                        <div className="flex items-center gap-4 max-md:flex-col max-md:items-stretch max-md:gap-2">
                            <Button disabled={processing} className="max-md:h-11 max-md:w-full max-md:text-[15px]">
                                Сохранить пароль
                            </Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-muted-foreground text-sm max-md:text-center">Сохранено</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
