import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type ConfirmPasswordForm = {
    password: string;
};

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm<ConfirmPasswordForm>({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Подтвердите пароль" description="Это защищённый раздел. Чтобы продолжить, введите свой пароль ещё раз">
            <Head title="Подтверждение пароля" />

            <form noValidate className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid content-start gap-2">
                    <Label htmlFor="password">Пароль</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autoFocus
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password}
                    />
                    <InputError message={errors.password} />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Подтвердить
                </Button>
            </form>
        </AuthLayout>
    );
}
