import { useClearingForm } from '@/hooks/use-clearing-form';
import { Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    token: string;
    email: string;
}

// A type alias rather than an interface: useForm needs the implicit index signature only aliases get.
type ResetPasswordForm = {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useClearingForm<ResetPasswordForm>({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Новый пароль" description="Придумайте пароль не короче 12 символов, с буквами и цифрами">
            <Head title="Сброс пароля" />

            <form noValidate className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid content-start gap-2">
                    <Label htmlFor="email">Электронная почта</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="email"
                        value={data.email}
                        readOnly
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={!!errors.email}
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid content-start gap-2">
                    <Label htmlFor="password" required>
                        Новый пароль
                    </Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autoFocus
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password}
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="grid content-start gap-2">
                    <Label htmlFor="password_confirmation" required>
                        Повторите пароль
                    </Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        required
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        aria-invalid={!!errors.password_confirmation}
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Сохранить пароль
                </Button>
            </form>
        </AuthLayout>
    );
}
