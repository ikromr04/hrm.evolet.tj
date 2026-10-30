import { useClearingForm } from '@/hooks/use-clearing-form';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle, MailCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

/** What a field says of itself before the form is sent anywhere. */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** The address, as this page asks for it; null where there is nothing to say. */
function emailProblem(email: string): string | null {
    if (email.trim() === '') return 'Укажите рабочую почту.';

    return EMAIL.test(email.trim()) ? null : 'Укажите адрес полностью, например name@evolet.co.uk';
}

type ForgotPasswordForm = {
    email: string;
};

/** Under the card on both screens: the way back, wherever the form has got to. */
const backToLogin = (
    <Link href={route('login')} className="hover:text-foreground inline-flex items-center justify-center gap-1.5 transition-colors">
        <ArrowLeft className="size-4" aria-hidden="true" />
        Вернуться ко входу
    </Link>
);

/**
 * «Восстановление пароля»: ask for the address, then say that the letter is on
 * its way.
 *
 * An address nobody works under never reaches the second screen: the server
 * answers it under the field, so a mistyped address is corrected here rather
 * than waited on.
 */
export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, setError, clearErrors } = useClearingForm<ForgotPasswordForm>({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const problem = emailProblem(data.email);

        clearErrors();

        if (problem !== null) {
            setError('email', problem);

            return;
        }

        post(route('password.email'));
    };

    // The letter is away. The server says so with a status, and the address is
    // the one still in the field — the page was answered, not reloaded.
    if (status) {
        return (
            <AuthLayout note={backToLogin}>
                <Head title="Проверьте почту" />

                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-4 text-center">
                        <span className="bg-brand-soft text-brand-strong dark:bg-accent dark:text-brand flex size-12 items-center justify-center rounded-full">
                            <MailCheck className="size-[22px]" aria-hidden="true" />
                        </span>

                        <div className="flex flex-col gap-1.5">
                            <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em]">Проверьте почту</h1>
                            <p role="status" className="text-muted-foreground text-sm">
                                {data.email ? (
                                    <>
                                        Мы отправили ссылку для сброса пароля на <b className="text-foreground font-medium">{data.email}</b>.
                                    </>
                                ) : (
                                    'Мы отправили ссылку для сброса пароля на указанный адрес.'
                                )}{' '}
                                Она действует 60 минут.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col gap-2">
                        <Button asChild className="w-full">
                            <Link href={route('login')}>Вернуться ко входу</Link>
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            disabled={processing}
                            onClick={() => post(route('password.email'))}
                        >
                            {processing && <LoaderCircle className="animate-spin" />}
                            Не пришло письмо? Отправить ещё раз
                        </Button>
                    </div>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Восстановление пароля" description="Введите рабочую почту — мы пришлём ссылку для сброса пароля" note={backToLogin}>
            <Head title="Восстановление пароля" />

            {/* noValidate: the field says what is wrong under itself, in the
                page's own words. */}
            <form className="flex flex-col gap-5" noValidate onSubmit={submit}>
                <div className="flex flex-col gap-2">
                    <Label htmlFor="email" required>
                        Электронная почта
                    </Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => {
                            setData('email', e.target.value);
                            clearErrors('email');
                        }}
                        placeholder="name@evolet.co.uk"
                        aria-invalid={!!errors.email}
                    />
                    <InputError message={errors.email} />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    {processing ? 'Отправляем…' : 'Отправить ссылку'}
                </Button>
            </form>
        </AuthLayout>
    );
}
