import { type CardFieldGroup, type PlainRight } from '@/components/card-fields';
import { DirectoryManager } from '@/components/directory-manager';
import { type EquipmentScope } from '@/components/equipment-scopes';
import DirectoriesLayout from '@/layouts/directories-layout';
import { type AccessRight, type AccessSection } from '@/lib/access';

interface RoleItem {
    id: number;
    name: string;
    title: string;
    users_count: number;
    protected: boolean;
    everything: boolean;
    /** What the position opens, by right key. */
    permissions: string[];
}

/** Access roles, shown in the UI as «Роль». */
export default function Roles({
    items,
    sections,
    fields,
    profileFields,
    equipmentScopes,
    equipmentBlocks,
    equipmentActions,
    directoryLists,
    directoryEdits,
    defaults,
    canManageAccess,
    canEdit,
}: {
    items: RoleItem[];
    sections: AccessSection[];
    /** The lines of an employee card, read and changed, chosen in the same dialog. */
    fields: CardFieldGroup[];
    /** The same lines as the rights to one's own card, asked separately. */
    profileFields: CardFieldGroup[];
    /** How much of the fleet the position sees, part by part, journals included. */
    equipmentScopes: EquipmentScope[];
    /** The blocks of a unit's card the position may change. */
    equipmentBlocks: AccessRight[];
    /** What the position does with a unit: balance, handover, write-off. */
    equipmentActions: AccessRight[];
    /** Which of the five reference lists the position opens, one right each. */
    directoryLists: PlainRight[];
    /** Which of the open ones it may also change; each depends on its viewing right. */
    directoryEdits: PlainRight[];
    /** What a new position starts with, as the server defines it. */
    defaults: string[];
    canManageAccess: boolean;
    /** Whether this list of positions is this person's to change. */
    canEdit: boolean;
}) {
    const byId = new Map(items.map((item) => [item.id, item]));

    return (
        <DirectoriesLayout title="Роли доступа">
            <DirectoryManager
                items={items.map((item) => ({
                    id: item.id,
                    label: item.title,
                    users_count: item.users_count,
                    protected: item.protected,
                    everything: item.everything,
                    permissions: item.permissions,
                }))}
                canEdit={canEdit}
                field="title"
                route="directories.roles"
                labels={{ add: 'Добавить позицию', create: 'Новая позиция', edit: 'Изменить позицию', accusative: 'позицию' }}
                employeesUrl={(item) => route('employees.index', { role: [byId.get(item.id)!.name] })}
                employeesField="employees.field.roles"
                // A position is no use until somebody says what it opens, so the
                // rights are ticked in the same dialog — for whoever may decide.
                rights={canManageAccess ? sections : undefined}
                cardFields={canManageAccess ? fields : undefined}
                profileFields={canManageAccess ? profileFields : undefined}
                equipmentScopes={canManageAccess ? equipmentScopes : undefined}
                equipmentBlocks={canManageAccess ? equipmentBlocks : undefined}
                equipmentActions={canManageAccess ? equipmentActions : undefined}
                directoryLists={canManageAccess ? directoryLists : undefined}
                directoryEdits={canManageAccess ? directoryEdits : undefined}
                defaultRights={defaults}
            />
        </DirectoriesLayout>
    );
}
