import { EquipmentForm, type EquipmentFormProps } from '@/components/equipment-form';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { INVENTORY_PLACES, type InventoryPlace } from '@/lib/inventory';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    options: {
        /** Each category with what its units are described by, and whether they come with anything. */
        types: EquipmentFormProps['options']['types'];
        holders: { id: number; name: string }[];
    };
    /** Which part of the stock the unit joins; null where the form asks. */
    section: string | null;
    /** The parts there are, for the field that asks which of them it is. */
    sections: { value: string; label: string }[];
    /**
     * The colleague this unit is being bought for, when the page was opened
     * from their card, and null on the ordinary add page. Who gets it is then
     * already decided, so the form does not ask again.
     */
    forEmployee: EquipmentFormProps['forEmployee'];
}

/** The page this form belongs to: the part it adds to, or «Обзор» for any of them. */
const placeOf = (section: string | null): InventoryPlace =>
    (Object.keys(INVENTORY_PLACES) as InventoryPlace[]).find((key) => INVENTORY_PLACES[key].section === section) ?? 'inventory';

/**
 * Opened from a card, the trail leads back through the colleague it is for;
 * opened from a page of «Инвентаризация», through that page.
 */
const trail = (forEmployee: Props['forEmployee'], place: InventoryPlace): BreadcrumbItem[] => {
    const here = INVENTORY_PLACES[place];

    return forEmployee
        ? [
              { title: 'Сотрудники', href: '/employees' },
              { title: forEmployee.name, href: route('employees.show', forEmployee.id) },
              { title: here.add.title, href: route('equipment.create', { for: forEmployee.id }) },
          ]
        : [...here.crumbs, { title: here.add.title, href: here.add.href }];
};

export default function CreateEquipment({ options, section, sections, forEmployee }: Props) {
    const place = placeOf(section);

    return (
        <AppLayout breadcrumbs={trail(forEmployee, place)}>
            <Head title={INVENTORY_PLACES[place].add.title} />

            {/*
             * Nothing above the form but the form: the bar names the page, the
             * form says in its own words who the unit goes to, and it carries
             * «Отмена» at the foot beside «Сохранить», where the choice is made.
             */}
            <div className="flex flex-1 flex-col gap-5 p-3 max-md:gap-3 md:gap-6 md:px-4 md:py-6 lg:px-6">
                <h1 className="text-xl leading-7 font-semibold tracking-[-0.01em] max-md:hidden">{INVENTORY_PLACES[place].add.title}</h1>

                <Card className="w-full rounded-xl p-4 max-md:rounded-2xl max-md:border-0 max-md:p-4! max-md:shadow-none sm:p-6">
                    <EquipmentForm options={{ ...options, sections }} section={section} forEmployee={forEmployee} layout="page" />
                </Card>
            </div>
        </AppLayout>
    );
}
