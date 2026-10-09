import { EquipmentForm, equipmentFormBack, type EquipmentFormProps } from '@/components/equipment-form';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Props {
    options: {
        /** Each category with what its units are described by, and whether they come with anything. */
        types: EquipmentFormProps['options']['types'];
        holders: { id: number; name: string }[];
    };
    /**
     * The colleague this unit is being bought for, when the page was opened
     * from their card, and null on the ordinary add page. Who gets it is then
     * already decided, so the form does not ask again.
     */
    forEmployee: EquipmentFormProps['forEmployee'];
}

/** Opened from a card, the trail leads back through the colleague it is for. */
const trail = (forEmployee: Props['forEmployee']): BreadcrumbItem[] =>
    forEmployee
        ? [
              { title: 'Сотрудники', href: '/employees' },
              { title: forEmployee.name, href: route('employees.show', forEmployee.id) },
              { title: 'Новое оборудование', href: route('equipment.create', { for: forEmployee.id }) },
          ]
        : [
              { title: 'Оборудование', href: '/equipment' },
              { title: 'Новое оборудование', href: '/equipment/create' },
          ];

export default function CreateEquipment({ options, forEmployee }: Props) {
    // «Отмена» up here leads back where the page was opened from, the same place
    // the form's own «Отмена» and a finished save go.
    const back = equipmentFormBack(forEmployee);

    return (
        <AppLayout breadcrumbs={trail(forEmployee)}>
            <Head title="Новое оборудование" />

            <div className="flex flex-1 flex-col gap-5 p-3 max-md:gap-3 md:px-5 md:py-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        {/* The phone's top bar already carries the page's name. */}
                        <h1 className="text-xl font-semibold tracking-tight max-md:sr-only">Новое оборудование</h1>
                        <p className="text-muted-foreground text-sm">
                            {forEmployee
                                ? `Единица встаёт на баланс — её сразу получит ${forEmployee.name}.`
                                : 'Единица встаёт на баланс — её можно сразу выдать сотруднику.'}
                        </p>
                    </div>

                    <Button variant="ghost" className="max-md:hidden" asChild>
                        <Link href={back}>Отмена</Link>
                    </Button>
                </div>

                <Card className="w-full rounded-xl p-4 max-md:rounded-2xl max-md:border-0 max-md:p-4! max-md:shadow-none sm:p-6">
                    <EquipmentForm options={options} forEmployee={forEmployee} layout="page" />
                </Card>
            </div>
        </AppLayout>
    );
}
