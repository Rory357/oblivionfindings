/**
 * Report-menu items that open their own drawn report page instead of an in-place wizard.
 * These records are created by keyed commands (one request key, exact times with their
 * offset) that only their report pages send, so a generic wizard post would be refused.
 */
const REPORT_PAGES: Record<string, string> = {
    first_aid: '/health-safety/first-aid/report?return_to=%2Fhealth-safety',
    substance: '/health-safety/substances/create',
    restraint: '/health-safety/restraints/report?return_to=%2Fhealth-safety',
    drill: '/health-safety/drills/create?return_to=%2Fhealth-safety',
    rtw: '/health-safety/injuries/create?return_to=%2Fhealth-safety',
    lone: '/health-safety/lone-workers?start=1',
    // An exposure is recorded from its chemical: the register is where the person picks it.
    exposure: '/health-safety/substances?tab=active',
    safeguarding: '/safeguarding/create',
};

/** The report page for a Report-menu item, or null when it opens in place. */
export function reportPageFor(key: string): string | null {
    return Object.prototype.hasOwnProperty.call(REPORT_PAGES, key)
        ? REPORT_PAGES[key]
        : null;
}
