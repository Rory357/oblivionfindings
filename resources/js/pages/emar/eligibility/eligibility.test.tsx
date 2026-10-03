import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StaffEligibility from '../StaffEligibility';
import { AssessmentWizard } from './_assessment';
import {
    areaAbility,
    eligLine,
    givenAbility,
    type AreaMeta,
    type EligPerson,
    type EligPolicy,
} from './_model';
import { MyEligibility, myMeter } from './_my-eligibility';

const routerMock = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: routerMock,
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ url: '/emar/safety/eligibility', props: {} }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

const AREA_KEYS: [string, string, boolean, AreaMeta['rule']][] = [
    ['medication_knowledge', 'Medication knowledge', true, null],
    ['five_rights', 'The five rights', true, null],
    ['safety_checks', 'Safety checks', true, null],
    ['documentation', 'Documentation', true, null],
    ['controlled_drugs', 'Controlled drugs', false, 'area'],
    ['prn_assessment', 'As-needed (PRN) assessment', false, null],
    ['insulin_competent', 'Insulin', false, 'notyet'],
    ['inhaler_competent', 'Inhaler technique', false, null],
    ['topical_competent', 'Topical medicines', false, null],
    ['covert_admin_knowledge', 'Covert administration', false, 'area'],
    ['error_reporting', 'Error reporting', true, null],
    ['allergy_awareness', 'Allergy awareness', true, null],
];
const areas: AreaMeta[] = AREA_KEYS.map(([key, label, core, rule]) => ({
    key,
    label,
    core,
    rule,
    description: null,
}));
const policy: EligPolicy = {
    pass_mark: 10,
    core_must_pass: false,
    observed_minimum: null,
    validity_months: 12,
    renewal_days: 30,
    longest_exemption_days: 30,
    longest_exemption_reviewed: false,
    validity_reviewed: false,
    restricted_mode: 'cosigner',
    area_mode: 'failed',
};
const allYes = Object.fromEntries(areas.map((a) => [a.key, 'yes' as const]));
const person = (over: Partial<EligPerson> = {}): EligPerson => ({
    id: 7,
    name: 'Priya Shah',
    role: 'Support worker',
    house_id: 3,
    house: 'Kōwhai House',
    started: '2026-01-10',
    records_doses: true,
    st: 'current',
    status: 'current',
    until: '2027-03-14',
    days: 160,
    prev_valid: null,
    pin: 'set',
    exemption: null,
    assessment: {
        id: 70,
        type: 'annual',
        type_label: 'Renewal',
        status: 'passed',
        assessed: '2026-03-14',
        until: '2027-03-14',
        assessor: 'Hana Kereama',
        assessor_id: 2,
        declared_at: '2026-03-14',
        acknowledged_at: '2026-03-15',
        res: { ...allYes, controlled_drugs: 'no' },
        passed: 11,
        pass_threshold: 10,
        restricted: false,
        restriction_notes: null,
        can_witness: false,
        unsupervised: false,
        observed: [],
        strengths: null,
        to_work_on: null,
        action_plan: null,
        comments: null,
    },
    can: { assess: true, exempt: false, reset_pin: true },
    ...over,
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    // The page keeps its sub-view in the address; start each test afresh.
    window.history.replaceState(null, '', '/emar/safety/eligibility');
});

describe('Staff eligibility wording', () => {
    it('words what someone can do from the server’s state and today’s rules', () => {
        expect(givenAbility(person(), policy).v).toBe('yes');
        expect(
            givenAbility(
                person({ st: 'restricted', status: 'restricted' }),
                policy,
            ),
        ).toMatchObject({ v: 'part' });
        expect(
            givenAbility(person({ st: 'restricted', status: 'restricted' }), {
                ...policy,
                restricted_mode: 'block',
            }).v,
        ).toBe('no');
        expect(
            givenAbility(
                person({ st: 'ack', status: 'ack', prev_valid: '2026-10-20' }),
                policy,
            ).t,
        ).toBe(
            'Records given doses on the previous assessment (until 20 Oct 2026)',
        );
        const cd = areas.find((a) => a.key === 'controlled_drugs')!;
        expect(areaAbility(person(), policy, cd).v).toBe('no');
        // Under an exemption nothing reads an assessment, so the area rule doesn't apply.
        expect(
            areaAbility(
                person({
                    st: 'expired',
                    status: 'exempt',
                    exemption: {
                        id: 1,
                        house: 'Kōwhai House',
                        from: '2026-10-01',
                        until: '2026-10-05',
                        by: 'Hana Kereama',
                        reason: 'Renewal booked',
                    },
                }),
                policy,
                cd,
            ),
        ).toMatchObject({ v: 'na' });
        expect(eligLine(person({ status: 'due', days: 12 }), areas)).toBe(
            'Ends 14 Mar 2027 · in 12 days',
        );
    });
});

const pageProps = {
    people: [
        person(),
        person({
            id: 8,
            name: 'Ben Carter',
            st: 'none',
            status: 'none',
            until: null,
            days: null,
            assessment: null,
            pin: 'not_set',
            can: { assess: true, exempt: true, reset_pin: false },
        }),
        person({
            id: 9,
            name: 'Aroha Ngata',
            status: 'due',
            days: 9,
            until: '2026-10-11',
        }),
    ],
    exemptions: [],
    houses: [{ id: 3, name: 'Kōwhai House' }],
    policy,
    areas,
    can: { assess: true, exempt: true, reset_pins: true },
    clients: [],
    me: { id: 2, name: 'Hana Kereama' },
    loaded_at: '2026-10-02T09:12:00+13:00',
};

describe('Safety & oversight › Staff eligibility', () => {
    it('shows the meters, the sub-views as header chips, and no witness view', () => {
        render(<StaffEligibility {...pageProps} />);
        expect(screen.getByText('Safety & oversight')).toBeInTheDocument();
        // The Safety & oversight hub rail, this page as the active tab.
        expect(
            screen
                .getByRole('tab', { name: /^Staff eligibility/ })
                .getAttribute('aria-selected'),
        ).toBe('true');
        expect(screen.getByText('Competency register')).toBeInTheDocument();
        expect(screen.getByText(/2 of 3/)).toBeInTheDocument();
        expect(
            screen.getByText('First: Aroha, 11 Oct 2026'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Can witness/)).toBeNull();
        expect(screen.queryByText(/Witness & PINs/)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /^Renewals/ }));
        expect(
            screen.getByRole('heading', { name: 'Given competency not met' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Due within 30 days')).toBeInTheDocument();
    });

    it('keeps every action the old page had on each row', () => {
        render(<StaffEligibility {...pageProps} />);
        const row = screen.getByText('Priya Shah').closest('[role="row"]')!;
        fireEvent.contextMenu(row);
        for (const label of [
            'View assessment',
            'Renew or reassess',
            'Edit this assessment',
            'View staff member',
            'Reset witness PIN',
            'Delete this assessment',
        ])
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
    });
});

describe('Assessment wizard', () => {
    it('asks for every area, then records it the way the server stores it', () => {
        const ben = pageProps.people[1];
        render(
            <AssessmentWizard
                people={pageProps.people}
                clients={[]}
                areas={areas}
                policy={policy}
                assessor="Hana Kereama"
                who={ben.id}
                mode="new"
                onClose={vi.fn()}
                onView={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(
            screen.getByText(
                'Choose a result for every area — 12 still need one.',
            ),
        ).toBeInTheDocument();
    });
});

describe('My eligibility', () => {
    it('turns the meter to Acknowledge and opens the dialog when an assessment waits', () => {
        const data = {
            person: person({ st: 'ack', status: 'ack' }),
            policy,
            areas,
            pending: {
                id: 42,
                assessed_on: '2026-09-28',
                assessor: 'Hana Kereama',
                ends_on: '2027-09-28',
                passed_areas: 11,
                not_passed: ['Insulin'],
                not_assessed: [],
                restriction: null,
                to_work_on: null,
                can_give_now: false,
            },
            checked_at: '2026-10-02T09:12:00+13:00',
        };
        expect(myMeter(data)).toMatchObject({
            big: 'Acknowledge',
            tone: 'critical',
        });
        render(
            <MyEligibility data={data} name="Priya Shah" onClose={vi.fn()} />,
        );
        expect(
            screen.getByText('Your new assessment is waiting for you'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Read and acknowledge/ }),
        );
        expect(
            screen.getByText('Acknowledge your assessment'),
        ).toBeInTheDocument();
    });

    it('says so when the role doesn’t record doses', () => {
        const data = {
            person: person({ records_doses: false }),
            policy,
            areas,
            pending: null,
            checked_at: '2026-10-02T09:12:00+13:00',
        };
        expect(myMeter(data).big).toBe('Not needed');
        render(
            <MyEligibility data={data} name="Priya Shah" onClose={vi.fn()} />,
        );
        expect(
            screen.getByText(/Your role doesn’t record doses/),
        ).toBeInTheDocument();
    });
});
