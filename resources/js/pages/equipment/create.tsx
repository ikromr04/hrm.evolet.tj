import { EquipmentForm, type EquipmentFormProps } from '@/components/equipment-form';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

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
    return (
        <AppLayout breadcrumbs={trail(forEmployee)}>
            <Head title="Новое оборудование" />

            {/*
             * Nothing above the form but the form: the bar names the page, the
             * form says in its own words who the unit goes to, and it carries
             * «Отмена» at the foot beside «Сохранить», where the choice is made.
             */}
            <div className="flex flex-1 flex-col gap-5 p-3 max-md:gap-3 md:p-4">
                <Card className="w-full rounded-xl p-4 max-md:rounded-2xl max-md:border-0 max-md:p-4! max-md:shadow-none sm:p-6">
                    <EquipmentForm options={options} forEmployee={forEmployee} layout="page" />
                </Card>
            </div>
        </AppLayout>
    );
}
