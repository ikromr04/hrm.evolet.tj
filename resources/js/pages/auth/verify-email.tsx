import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthLayout title="Подтвердите почту" description="Мы отправили вам письмо со ссылкой — перейдите по ней, чтобы подтвердить адрес">
            <Head title="Подтверждение почты" />

            {status === 'verification-link-sent' && (
                <div className="bg-brand-soft text-brand-strong rounded-md px-3 py-2 text-center text-sm font-medium">
                    Мы отправили новую ссылку на вашу почту.
                </div>
            )}

            <form noValidate className="flex flex-col gap-6" onSubmit={submit}>
                <Button type="submit" variant="secondary" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Отправить письмо ещё раз
                </Button>

                <TextLink href={route('logout')} method="post" className="mx-auto block text-sm">
                    Выйти
                </TextLink>
            </form>
        </AuthLayout>
    );
}
