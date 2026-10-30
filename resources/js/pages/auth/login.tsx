import { useClearingForm } from '@/hooks/use-clearing-form';
import { Head } from '@inertiajs/react';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const [showPassword, setShowPassword] = useState(false);
    const { data, setData, post, processing, errors, reset, setError, clearErrors } = useClearingForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const email = emailProblem(data.email);
        const password = data.password === '' ? 'Введите пароль.' : null;

        clearErrors();

        if (email !== null || password !== null) {
            setError({ ...(email !== null && { email }), ...(password !== null && { password }) });

            return;
        }

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Вход в аккаунт" description="Введите рабочую почту и пароль" note="Нет доступа? Обратитесь в HR-отдел.">
            <Head title="Вход" />

            {/* noValidate: the form says what is wrong in its own words, under
                the field it is about, rather than in the browser's bubble. */}
            <form className="flex flex-col gap-5" noValidate onSubmit={submit}>
                {status && <p className="bg-brand-soft text-brand-strong rounded-md px-3 py-2 text-center text-sm font-medium">{status}</p>}

                <div className="flex flex-col gap-2">
                    {/* The label turns with the field: a form answered wrong says so
                        in the line that names what was wrong, not only under it. */}
                    <Label htmlFor="email" required>
                        Электронная почта
                    </Label>
                    <Input
                        id="email"
                        type="email"
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

                <div className="flex flex-col gap-2">
                    <Label htmlFor="password" required>
                        Пароль
                    </Label>
                    <div className="relative">
                        <Input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            required
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => {
                                setData('password', e.target.value);
                                clearErrors('password');
                            }}
                            className="pr-10"
                            aria-invalid={!!errors.password}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => setShowPassword((show) => !show)}
                            aria-label={showPassword ? 'Скрыть пароль' : 'Показать пароль'}
                            className="text-muted-foreground hover:text-foreground absolute top-1/2 right-1 size-8 -translate-y-1/2"
                        >
                            {showPassword ? <EyeOff /> : <Eye />}
                        </Button>
                    </div>
                    <InputError message={errors.password} />
                </div>

                {/* One line for the two things done beside the password: keeping
                    the session, and the way out when it is forgotten. */}
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                        <Label htmlFor="remember">Запомнить меня</Label>
                    </div>

                    {canResetPassword && (
                        <TextLink href={route('password.request')} className="text-sm">
                            Забыли пароль?
                        </TextLink>
                    )}
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="animate-spin" />}
                    {processing ? 'Входим…' : 'Войти'}
                </Button>
            </form>
        </AuthLayout>
    );
}
