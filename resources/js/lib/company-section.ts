import { companySections, useCan, type CompanySection } from '@/lib/access';

/**
 * The pages of «Структура компании» this viewer may open, in the order they
 * stand in. A tab that led to a refusal would be worse than no tab, so a right
 * is asked for where a list has one; the chart has none, being open to
 * everybody who signs in.
 *
 * The section used to remember which tab you were last on and send a bare visit
 * there, because the menu had one door for all three. The menu has three doors
 * now, each with an address of its own, so a visit is always asked for by name
 * and there is nothing left to remember.
 */
export function useCompanyTabs(): CompanySection[] {
    const can = useCan();

    return companySections.filter((section) => !section.view || can(section.view));
}
