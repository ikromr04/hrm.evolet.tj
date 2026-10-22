import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/lib/access';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowRightLeft, Ellipsis, LoaderCircle, RotateCcw, Trash2, UserX, type LucideIcon } from 'lucide-react';
import { Fragment, useState, type FormEventHandler } from 'react';

export type EmploymentStatus = 'active' | 'transferred' | 'fired';

export interface ActionTarget {
    id: number;
    name: string;
    surname: string;
    status: EmploymentStatus;
}

type OpenDialog = 'transfer' | 'fire' | 'delete' | null;

/** Red, for the one action that cannot be undone. */
const dangerItem = 'text-[#B42318] focus:text-[#B42318] dark:text-[#F7A19A] [&_svg]:text-current!';

/**
 * Transfer, fire, restore and delete for one employee, either behind a "⋯"
 * menu (the list) or as a segmented group of buttons (the profile sidebar).
 * Both share the same dialogs, so the confirmations stay in one place.
 */
export function EmployeeActions({ employee, isSelf, variant = 'menu' }: { employee: ActionTarget; isSelf: boolean; variant?: 'menu' | 'group' }) {
    const can = useCan();
    const [dialog, setDialog] = useState<OpenDialog>(null);
    const name = `${employee.surname} ${employee.name}`;

    const restore = () => router.post(route('employees.restore', employee.id), {}, { preserveScroll: true });

    // A working employee can leave; one who already left can only come back.
    // `asks` marks the ones that open a dialog, which the menu spells with "…".
    const actions = [
        // Moving somebody about and ending an employment are asked separately;
        // taking somebody back is the counterpart of the latter.
        ...(employee.status === 'active'
            ? [
                  ...(can('employees.transfer')
                      ? [
                            {
                                key: 'transfer',
                                label: 'Перевести',
                                Icon: ArrowRightLeft,
                                disabled: isSelf,
                                asks: true,
                                run: () => setDialog('transfer'),
                            },
                        ]
                      : []),
                  ...(can('employees.fire')
                      ? [{ key: 'fire', label: 'Уволить', Icon: UserX, disabled: isSelf, asks: true, run: () => setDialog('fire') }]
                      : []),
              ]
            : can('employees.fire')
              ? [{ key: 'restore', label: 'Восстановить', Icon: RotateCcw, disabled: false, asks: false, run: restore }]
              : []),
        ...(can('employees.delete')
            ? [{ key: 'delete', label: 'Удалить', Icon: Trash2, disabled: isSelf, asks: true, run: () => setDialog('delete') }]
            : []),
    ];

    // Nothing this viewer may do to this card: no menu, no empty button group.
    if (actions.length === 0) {
        return null;
    }

    return (
        <>
            {variant === 'menu' ? (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="text-muted-foreground size-9 lg:size-8" aria-label={`Действия: ${name}`}>
                            <Ellipsis className="size-5!" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-48">
                        {actions.map(({ key, label, Icon, disabled, asks, run }) => (
                            <Fragment key={key}>
                                {key === 'delete' && <DropdownMenuSeparator />}
                                <DropdownMenuItem disabled={disabled} onSelect={run} className={cn(key === 'delete' && dangerItem)}>
                                    <Icon />
                                    {asks ? `${label}…` : label}
                                </DropdownMenuItem>
                            </Fragment>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : (
                <>
                    {/* On a phone the actions are a grouped list of their own, one
                    full-width row each, as a native contact card ends with them. */}
                    <div className="bg-card divide-border/60 flex flex-col divide-y overflow-hidden rounded-2xl md:hidden">
                        {actions.map(({ key, label, Icon, disabled, run }) => (
                            <button
                                key={key}
                                type="button"
                                disabled={disabled}
                                onClick={run}
                                className={cn(
                                    'active:bg-accent/60 flex min-h-12 w-full items-center gap-3 px-4 text-left text-[15px] transition-colors disabled:pointer-events-none disabled:opacity-50',
                                    key === 'delete' ? 'text-[#B42318] dark:text-[#F7A19A]' : 'text-foreground',
                                )}
                            >
                                <Icon
                                    className={cn('size-5 shrink-0', key === 'delete' ? 'text-current' : 'text-brand-strong dark:text-[#C5E27A]')}
                                />
                                {label}
                            </button>
                        ))}
                    </div>
                    <SegmentedActions actions={actions} />
                </>
            )}

            {(dialog === 'transfer' || dialog === 'fire') && (
                <LeaveDialog kind={dialog} employee={employee} name={name} onClose={() => setDialog(null)} />
            )}
            <DeleteDialog open={dialog === 'delete'} employee={employee} name={name} onClose={() => setDialog(null)} />
        </>
    );
}

interface Action {
    key: string;
    label: string;
    Icon: LucideIcon;
    disabled: boolean;
    run: () => void;
}

/** The profile sidebar's bar of actions, from `md` up. */
function SegmentedActions({ actions }: { actions: Action[] }) {
    return (
        // One segmented control: shared borders, rounded only at the ends. The
        // labels drop their "…" here, where every pixel of width counts.
        <div className="bg-background flex w-full items-stretch overflow-hidden rounded-md border max-md:hidden">
            {actions.map(({ key, label, Icon, disabled, run }, index) => (
                <button
                    key={key}
                    type="button"
                    disabled={disabled}
                    onClick={run}
                    className={cn(
                        'hover:bg-accent focus-visible:ring-ring flex min-w-0 flex-1 items-center justify-center gap-1.5 px-2 py-2.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-hidden disabled:pointer-events-none disabled:opacity-50 max-sm:flex-col max-sm:gap-1 max-sm:px-1 max-sm:text-xs lg:py-2',
                        index > 0 && 'border-l',
                        key === 'delete' && 'text-[#B42318] dark:text-[#F7A19A]',
                    )}
                >
                    <Icon className="size-4 shrink-0" />
                    <span className="truncate">{label}</span>
                </button>
            ))}
        </div>
    );
}

function LeaveDialog({ kind, employee, name, onClose }: { kind: 'transfer' | 'fire'; employee: ActionTarget; name: string; onClose: () => void }) {
    const transfer = kind === 'transfer';
    const form = useForm({ date: format(new Date(), 'yyyy-MM-dd'), note: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route(transfer ? 'employees.transfer' : 'employees.fire', employee.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form noValidate onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{transfer ? `Перевести: ${name}` : `Уволить: ${name}`}</DialogTitle>
                        <DialogDescription>
                            Сотрудник перейдёт в список «{transfer ? 'Переведённые' : 'Уволенные'}» и больше не сможет войти в систему. Вернуть его
                            можно командой «Восстановить».
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="leave-date">{transfer ? 'Дата перевода' : 'Дата увольнения'}</Label>
                        <Input
                            id="leave-date"
                            type="date"
                            required
                            value={form.data.date}
                            onChange={(event) => form.setData('date', event.target.value)}
                        />
                        <InputError message={form.errors.date} />
                    </div>

                    <div className="grid content-start gap-2">
                        <Label htmlFor="leave-note">{transfer ? 'Куда переведён' : 'Причина (необязательно)'}</Label>
                        <Input
                            id="leave-note"
                            autoFocus
                            required={transfer}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                            placeholder={transfer ? 'Например, Эволет Европа' : 'Например, по собственному желанию'}
                        />
                        <InputError message={form.errors.note} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Отмена
                        </Button>
                        <Button type="submit" variant={transfer ? 'default' : 'destructive'} disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            {transfer ? 'Перевести' : 'Уволить'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({ open, employee, name, onClose }: { open: boolean; employee: ActionTarget; name: string; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);

    const confirm = () =>
        router.delete(route('employees.destroy', employee.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: onClose,
        });

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Удалить сотрудника {name}?</DialogTitle>
                    <DialogDescription asChild>
                        <div className="flex flex-col gap-1.5">
                            <p>Удалятся учётная запись и все данные: личные данные, паспорт, семья, позиции, должности и отделы.</p>
                            <p>Оборудование, выданное сотруднику, вернётся на баланс — это будет записано в журнал.</p>
                            <p>
                                Отменить удаление нельзя. Если человек просто ушёл, лучше <b>уволить</b> или <b>перевести</b> его: запись сохранится.
                            </p>
                        </div>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Отмена
                    </Button>
                    <Button type="button" variant="destructive" onClick={confirm} disabled={processing}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Удалить навсегда
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
