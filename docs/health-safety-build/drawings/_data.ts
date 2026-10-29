/**
 * Example data for the Part 2 drawings (H&S ease-of-use, 29–30 Oct 2026). Made-up but realistic: NZ names, places
 * and dates. Drawings only — nothing here is imported by the app.
 */
export const SITES = ['Kauri House', 'Harbour Respite', 'Rimu House', 'Pōneke Community', 'Tōtara House'];

export const TODAY = 'Thu 29 Oct 2026';

export const ACCIDENT = {
    title: 'Fall in the lounge',
    person: 'Aroha Kingi',
    site: 'Kauri House',
    when: 'Mon 26 Oct 2026, 7:40 pm',
    reportedBy: 'Mere Tane (support worker)',
    owner: 'Jordan Peri (health and safety lead)',
    teamLead: 'Rāwiri Ngata (team lead, Kauri House)',
    ref: 'INC-2026-0392',
};

export const ROLE_LABEL = {
    support_worker: 'Support worker — Mere Tane',
    team_lead: 'Team lead — Rāwiri Ngata, Kauri House',
    hs_manager: 'Health and safety lead — Jordan Peri',
} as const;

export type Role = keyof typeof ROLE_LABEL;
