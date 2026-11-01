import { ErrorPage } from '@/components/error-page';
import { Lock } from 'lucide-react';

/**
 * The page exists; it is simply not this person's.
 *
 * Which is not a failure and not a mistake, so it does not read as one — and it
 * says where the answer comes from, since a right is something somebody can hand
 * out rather than something to puzzle over.
 */
export default function Forbidden() {
    return (
        <ErrorPage
            code={403}
            icon={Lock}
            title="Действие не авторизовано"
            description="Эта страница открыта не всем: у вашей позиции нет доступа к ней."
            note={<p className="text-muted-foreground text-[13px]">Если доступ нужен для работы, обратитесь к системному администратору.</p>}
        />
    );
}
