import { describe, expect, it } from 'vitest';

import * as clientProfilePage from '@/pages/operations/clients/show';
import {
    CLIENT_TAB_GROUPS,
    resolveVisibleProfileTab,
} from '@/pages/operations/clients/tabs/_groups';

describe('client profile restricted section navigation', () => {
    it('maps every sensitive tab to its profile section authority', () => {
        const profilePage = clientProfilePage as typeof clientProfilePage & {
            clientProfileTabHasSectionAccess?: (
                tab: string,
                access: Record<string, boolean> | null | undefined,
                props: Record<string, unknown>,
            ) => boolean;
        };

        expect(profilePage.clientProfileTabHasSectionAccess).toBeTypeOf(
            'function',
        );
        if (!profilePage.clientProfileTabHasSectionAccess) return;

        const restrictedTabs = {
            onboarding: 'onboarding',
            progress_notes: 'notes',
            communication_notes: 'notes',
            timeline: 'timeline',
            meal_prefs: 'meals',
            rhythms_routines: 'daily_living',
            care_plans: 'care_plans',
            goals_path: 'care_plans',
            assessments: 'assessments',
            observations: 'behaviour',
            medical: 'medical',
            mar: 'medical',
            health_monitoring: 'health',
            finance: 'finance',
            consents: 'consents',
            'consent-requests': 'consents',
            risk_management: 'risks',
            incidents_accidents: 'incidents',
            first_aid: 'first_aid',
            calendar: 'calendar',
            transport: 'transport',
            leave_excursions: 'daily_living',
            personal_assets: 'personal_assets',
            service_agreements: 'agreements',
            documents: 'documents',
            photos: 'photos',
            family_tree: 'portal_access',
            portal: 'portal_access',
            family_notes: 'family_notes',
            actions_reviews: 'actions_reviews',
            location: 'tracking',
            audit_history: 'audit',
            privacy: 'privacy',
            respite: 'respite',
        } as const;

        for (const [tab, allowedSection] of Object.entries(restrictedTabs)) {
            const access = Object.fromEntries(
                Object.values(restrictedTabs).map((section) => [
                    section,
                    section === allowedSection,
                ]),
            );

            expect(
                profilePage.clientProfileTabHasSectionAccess(tab, access, {}),
                `${tab} should use ${allowedSection} access`,
            ).toBe(true);
            expect(
                profilePage.clientProfileTabHasSectionAccess(
                    tab,
                    { ...access, [allowedSection]: false },
                    {},
                ),
                `${tab} should be hidden without ${allowedSection} access`,
            ).toBe(false);
        }
    });

    it('uses sensitive prop presence only when the explicit access flag is absent', () => {
        const profilePage = clientProfilePage as typeof clientProfilePage & {
            clientProfileTabHasSectionAccess?: (
                tab: string,
                access: Record<string, boolean> | null | undefined,
                props: Record<string, unknown>,
            ) => boolean;
        };

        expect(profilePage.clientProfileTabHasSectionAccess).toBeTypeOf(
            'function',
        );
        if (!profilePage.clientProfileTabHasSectionAccess) return;

        expect(
            profilePage.clientProfileTabHasSectionAccess('documents', null, {
                documents: [],
            }),
        ).toBe(true);
        expect(
            profilePage.clientProfileTabHasSectionAccess('documents', null, {}),
        ).toBe(false);
        expect(
            profilePage.clientProfileTabHasSectionAccess(
                'documents',
                { documents: false },
                { documents: [] },
            ),
        ).toBe(false);
        expect(
            profilePage.clientProfileTabHasSectionAccess(
                'documents',
                { documents: true },
                {},
            ),
        ).toBe(true);
        expect(
            profilePage.clientProfileTabHasSectionAccess('profile', {}, {}),
        ).toBe(true);

        const fallbackProps = {
            onboarding: 'onboarding',
            progress_notes: 'client_daily_notes',
            communication_notes: 'communication_notes',
            timeline: 'events',
            meal_prefs: 'meal_logs',
            rhythms_routines: 'client_routines',
            care_plans: 'care_plans_summary',
            goals_path: 'path_plan',
            assessments: 'assessments',
            observations: 'behaviour_patterns',
            medical: 'medical',
            mar: 'emar_summary',
            health_monitoring: 'health_monitoring',
            finance: 'client_finance',
            consents: 'consents',
            'consent-requests': 'consent_request_list',
            risk_management: 'client_risks',
            incidents_accidents: 'client_incidents',
            first_aid: 'first_aid_records',
            calendar: 'calendar_events',
            transport: 'transport',
            leave_excursions: 'leave_excursions',
            personal_assets: 'personal_assets',
            service_agreements: 'client_agreements',
            documents: 'documents',
            photos: 'photos',
            family_tree: 'next_of_kins',
            portal: 'portal_users',
            family_notes: 'family_notes',
            actions_reviews: 'actions_reviews',
            location: 'location',
            audit_history: 'audit_history',
            privacy: 'data_subject_requests',
            respite: 'respite',
        } as const;

        for (const [tab, prop] of Object.entries(fallbackProps)) {
            expect(
                profilePage.clientProfileTabHasSectionAccess(tab, null, {
                    [prop]: null,
                }),
                `${tab} should fall back to ${prop} presence`,
            ).toBe(true);
        }
    });

    it('falls back inside the requested group when a deep-linked section is hidden', () => {
        const profilePage = clientProfilePage as typeof clientProfilePage & {
            clientProfileTabHasSectionAccess?: (
                tab: string,
                access: Record<string, boolean> | null | undefined,
                props: Record<string, unknown>,
            ) => boolean;
        };

        expect(profilePage.clientProfileTabHasSectionAccess).toBeTypeOf(
            'function',
        );
        if (!profilePage.clientProfileTabHasSectionAccess) return;

        const access = { privacy: false, audit: true };
        const groups = CLIENT_TAB_GROUPS.map((group) => ({
            key: group.key,
            tabs: group.tabKeys
                .filter((tab) =>
                    profilePage.clientProfileTabHasSectionAccess?.(
                        tab,
                        access,
                        {},
                    ),
                )
                .map((key) => ({ key })),
        })).filter((group) => group.tabs.length > 0);

        expect(resolveVisibleProfileTab('privacy', groups)).toBe(
            'audit_history',
        );
    });
});
