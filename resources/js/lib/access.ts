import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Who may do what.
 *
 * Rights travel with a position and can be given to, or taken from, one person
 * on their card. The list below mirrors App\Support\Access, which is where
 * rights are actually declared; keeping the keys here as a type means a page
 * that asks for a right that no longer exists stops compiling.
 */
export type Permission =
    | 'employees.view'
    // The lines of a card are read from the list a page is sent, not asked for one
    // by one — except these, which are asked far from any card: the photograph
    // turns up beside a name everywhere, and the other five decide whether a link
    // into the staff list filtered by them would lead anywhere but a refusal.
    | 'employees.field.avatar'
    | 'profile.field.avatar'
    | 'employees.field.departments'
    | 'employees.field.positions'
    | 'employees.field.roles'
    | 'employees.field.languages'
    | 'employees.field.citizenship'
    | 'employees.create'
    | 'employees.transfer'
    | 'employees.fire'
    | 'employees.delete'
    | 'equipment.view.own'
    | 'equipment.view.department'
    | 'equipment.view.all'
    | 'equipment.journal.own'
    | 'equipment.journal.department'
    | 'equipment.journal.all'
    | 'equipment.edit.specs'
    | 'equipment.edit.accessories'
    | 'equipment.edit.state'
    | 'equipment.create'
    | 'equipment.issue'
    | 'equipment.take'
    | 'equipment.write_off'
    | 'equipment.service'
    | 'equipment.delete'
    | 'directories.view.roles'
    | 'directories.view.positions'
    | 'directories.view.departments'
    | 'directories.view.languages'
    | 'directories.view.citizenships'
    | 'directories.view.equipment'
    | 'directories.edit.roles'
    | 'directories.edit.positions'
    | 'directories.edit.departments'
    | 'directories.edit.languages'
    | 'directories.edit.citizenships'
    | 'directories.edit.equipment'
    | 'directories.view.access'
    | 'directories.edit.access';

/** A right as the server describes it: what it is called and what it opens. */
export interface AccessRight {
    key: string;
    title: string;
    hint: string;
}

/** The rights of one section, the way the access table groups them. */
export interface AccessSection {
    key: string;
    title: string;
    rights: AccessRight[];
}

/** Whether the viewer holds a right, as the shared props report it. */
export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage<SharedData>().props;

    return (permission) => auth.can[permission] === true;
}

/**
 * Equipment is not seen whole or not at all: a person may see their own, their
 * department's or the entire fleet, and each part has a journal of its own. A
 * door into the section opens on any one of them, so the pages ask these two
 * questions rather than naming every key they would otherwise have to list.
 */

/**
 * What the viewer may change on a unit they already see, as the pages of the
 * section are handed it. Each key is one right of App\Support\EquipmentAccess:
 * the three blocks of a card, then the moves one makes with a unit. The server
 * checked the rights and the unit both, so a page only has to ask which of
 * these to offer — and offering nothing else is the point of splitting them.
 */
export interface EquipmentRights {
    specs: boolean;
    accessories: boolean;
    state: boolean;
    create: boolean;
    issue: boolean;
    take: boolean;
    write_off: boolean;
    service: boolean;
    delete: boolean;
}

/** Whether any part of the fleet is open to the viewer. */
export const seesEquipment = (can: (permission: Permission) => boolean): boolean =>
    can('equipment.view.own') || can('equipment.view.department') || can('equipment.view.all');

/**
 * Whether the journal is open for any part of it. A journal comes with the part
 * of the fleet it is about, so the pair is asked for together — the server reads
 * it the same way, and a link that leads to a refusal is worse than no link.
 */
export const readsEquipmentJournal = (can: (permission: Permission) => boolean): boolean =>
    (can('equipment.view.own') && can('equipment.journal.own')) ||
    (can('equipment.view.department') && can('equipment.journal.department')) ||
    (can('equipment.view.all') && can('equipment.journal.all'));

/**
 * "Справочники" is not one section but five lists that share a page, and they are
 * kept by different hands — an HR officer renames a department, whoever looks
 * after the fleet adds a category of monitors — so each list is a right of its
 * own. Job titles were one of them until they grew duties and a page of their
 * own; the right that opens them is still directories.view.positions.
 */

/** One of the reference lists: what it is called and what right opens it. */
export interface DirectoryList {
    key: string;
    title: string;
    view: Permission;
}

/**
 * The lists in the order their tabs stand in, mirroring App\Support\Directories:
 * the section lands on the first one its viewer may open, so a tab strip built
 * from this array agrees with where "Справочники" takes them.
 */
export const directoryLists: DirectoryList[] = [
    { key: 'roles', title: 'Позиции', view: 'directories.view.roles' },
    { key: 'departments', title: 'Отделы', view: 'directories.view.departments' },
    { key: 'languages', title: 'Языки', view: 'directories.view.languages' },
    { key: 'citizenships', title: 'Гражданства', view: 'directories.view.citizenships' },
    { key: 'equipment', title: 'Категории техники', view: 'directories.view.equipment' },
    // Who may do what: a list of the section like the others, last because it is
    // read after one knows what there is to hand out.
    { key: 'access', title: 'Доступы', view: 'directories.view.access' },
];

/** Whether at least one list is open, which is what opens the section itself. */
export const seesDirectories = (can: (permission: Permission) => boolean): boolean => directoryLists.some((list) => can(list.view));

/*
 * Which positions a form may offer is not decided here: the server sends the list
 * it is willing to accept, without the single system administrator, and a card
 * whose positions are not this viewer's to change arrives with the reason why.
 */
