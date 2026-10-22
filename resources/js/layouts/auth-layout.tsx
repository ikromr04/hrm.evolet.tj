import AuthLayoutTemplate from '@/layouts/auth/auth-evolet-layout';
import { type ReactNode } from 'react';

export default function AuthLayout({
    children,
    title,
    description,
    note,
    ...props
}: {
    children: ReactNode;
    /** Left out where the card writes its own head, as «Проверьте почту» does. */
    title?: string;
    description?: string;
    /** A line under the card, outside it. */
    note?: ReactNode;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description} note={note} {...props}>
            {children}
        </AuthLayoutTemplate>
    );
}
