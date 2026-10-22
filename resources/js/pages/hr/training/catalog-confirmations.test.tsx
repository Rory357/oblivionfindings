import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import TrainingHub from './catalog';

// Cancelling a session and waiving training used browser prompt(). Cancelling
// a session now opens a dialog with an optional reason and a destructive
// "Cancel session" button; waiving opens a reason dialog whose reason is
// required. Archiving a course from its menu or the bulk bar used to send
// straight away and now asks first. Nothing is sent until the dialog's button
// is pressed.

type VisitOptions = {
    data?: unknown;
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const router = vi.hoisted(() => ({
    reload: vi.fn(),
    patch: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: {} }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/hr/training-hero', () => ({
    TrainingHero: () => null,
}));
vi.mock('@/components/hr/training/training-wizard-dialog', () => ({
    TrainingWizardDialog: () => null,
}));
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

const sheet = {
    id: 3,
    title: 'First aid level 2',
    code: 'FA2',
    provider: 'St John',
    delivery_method: 'in_person',
    duration_hours: 8,
    cost: 180,
    is_mandatory: true,
    requires_renewal: true,
    validity_period_months: 24,
    metrics: { enrol: 4, completion: 75, expiring: 1 },
    sessions: [
        {
            id: 7,
            session_date: '2026-11-12',
            start_time: '09:00',
            end_time: '16:30',
            location: 'Hamilton office',
            trainer: 'Ana Rewi',
            status: 'scheduled',
            seats: 12,
        },
    ],
    enrollments: [],
};

const renderHub = ({
    isActive = true,
    countsForFirstAid = false,
}: { isActive?: boolean; countsForFirstAid?: boolean } = {}) =>
    render(
        <TrainingHub
            summary={{
                total_courses: 1,
                mandatory_courses: 1,
                total_enrollments: 4,
                completed_enrollments: 3,
                upcoming_sessions: 1,
                overdue_assignments: 0,
                expiring_soon: 1,
                completion_rate: 75,
            }}
            dashboard={{
                mandatoryCurrentPct: 80,
                overdueCount: 0,
                expiringCount: 1,
                spendYtd: 0,
                renewals: [],
                completionBySite: [],
                upcomingSessions: [],
            }}
            courses={[
                {
                    id: 3,
                    title: 'First aid level 2',
                    code: 'FA2',
                    category: null,
                    delivery_method: 'in_person',
                    duration_hours: 8,
                    provider: 'St John',
                    cost: 180,
                    is_mandatory: true,
                    is_active: isActive,
                    counts_for_first_aid: countsForFirstAid,
                    requires_renewal: true,
                    validity_period_months: 24,
                    cpd_points: null,
                    sessions_count: 1,
                    enrol: 4,
                    completion: 75,
                    expiring: 1,
                },
            ]}
            assignments={[
                {
                    id: 55,
                    person: 'Mere Tane',
                    course: 'First aid level 2',
                    source: 'manual',
                    due: '2026-12-01',
                    status: 'assigned',
                    score: null,
                },
            ]}
            categories={[]}
            deliveryMethods={[]}
            lookups={{} as never}
            filters={{ search: '', sort: 'title' }}
            competency={null}
            induction={null}
            can={{
                manage: true,
                enroll: true,
                record: true,
                claim: false,
                competency: false,
                induction: false,
            }}
        />,
    );

beforeEach(() => {
    router.delete.mockReset();
    router.patch.mockReset();
    router.post.mockReset();
    router.reload.mockReset();
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({ ok: true, json: () => Promise.resolve(sheet) }),
        ),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
});

describe('Training › course sheet: cancel a session', () => {
    const cancelDialog = () =>
        screen.getByRole('dialog', { name: 'Cancel this session?' });

    const openCancel = async () => {
        window.history.replaceState({}, '', '/hr/training/catalog?course=3');
        renderHub();
        const row = await screen.findByText(/12 Nov 2026/);
        fireEvent.contextMenu(row);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel session' }));
    };

    it('opens a dialog instead of cancelling straight away', async () => {
        await openCancel();

        expect(router.delete).not.toHaveBeenCalled();
        expect(cancelDialog()).toHaveTextContent(
            'session of First aid level 2 is marked cancelled',
        );
        expect(cancelDialog()).toHaveTextContent('No one is sent a message');
        expect(
            within(cancelDialog()).getByLabelText('Reason (optional)'),
        ).toHaveAttribute('maxLength', '500');
    });

    it('Keep session cancels nothing', async () => {
        await openCancel();

        fireEvent.click(
            within(cancelDialog()).getByRole('button', {
                name: 'Keep session',
            }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Cancel this session?' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('cancels without a reason, because the reason is optional', async () => {
        router.delete.mockImplementation(
            (_url: string, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onSuccess?.();
                options?.onFinish?.();
            },
        );
        await openCancel();

        fireEvent.click(
            within(cancelDialog()).getByRole('button', {
                name: 'Cancel session',
            }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/training/sessions/7',
            expect.objectContaining({
                data: { reason: '' },
                preserveScroll: true,
            }),
        );
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Cancel this session?' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('sends the reason when one is given', async () => {
        await openCancel();

        fireEvent.change(
            within(cancelDialog()).getByLabelText('Reason (optional)'),
            { target: { value: ' Trainer unwell ' } },
        );
        fireEvent.click(
            within(cancelDialog()).getByRole('button', {
                name: 'Cancel session',
            }),
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/hr/training/sessions/7',
            expect.objectContaining({ data: { reason: 'Trainer unwell' } }),
        );
    });
});

describe('Training › assignments: waive training', () => {
    const waiveDialog = () =>
        screen.getByRole('dialog', { name: 'Waive this training?' });

    const openWaive = () => {
        renderHub();
        fireEvent.click(screen.getByRole('tab', { name: /Assignments/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Actions for Mere Tane' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Waive (reason…)' }),
        );
    };

    it('opens a reason dialog instead of waiving straight away', () => {
        openWaive();

        expect(router.patch).not.toHaveBeenCalled();
        expect(waiveDialog()).toHaveTextContent(
            "Mere Tane won't need to complete First aid level 2.",
        );
    });

    it('needs a reason before waiving', () => {
        openWaive();

        const confirm = within(waiveDialog()).getByRole('button', {
            name: 'Waive training',
        });
        expect(confirm).toBeDisabled();
        fireEvent.click(confirm);

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('Cancel waives nothing', async () => {
        openWaive();

        fireEvent.click(
            within(waiveDialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(
                screen.queryByRole('dialog', { name: 'Waive this training?' }),
            ).not.toBeInTheDocument(),
        );
    });

    it('waives with the reason once confirmed', () => {
        openWaive();

        fireEvent.change(within(waiveDialog()).getByLabelText('Reason'), {
            target: { value: 'Holds a current certificate' },
        });
        fireEvent.click(
            within(waiveDialog()).getByRole('button', {
                name: 'Waive training',
            }),
        );

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/hr/training/assignments/55/waive',
            { reason: 'Holds a current certificate' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});

describe('Training › catalogue: archive a course', () => {
    const archiveDialog = () => screen.getByRole('alertdialog');
    const settle = (_url: string, _data: unknown, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    };

    const firstAidWarning =
        "Certificates from this course stop counting towards first-aid cover, so Sites will show first-aid cover as needing action until it's active again.";

    const openCatalog = (isActive = true, countsForFirstAid = false) => {
        renderHub({ isActive, countsForFirstAid });
        fireEvent.click(screen.getByRole('tab', { name: /Catalog/ }));
    };

    const openArchiveFromMenu = (countsForFirstAid = false) => {
        openCatalog(true, countsForFirstAid);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Actions for First aid level 2',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
    };

    it('asks before archiving from the course menu and sends nothing yet', () => {
        openArchiveFromMenu();

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(archiveDialog()).getByText('Archive this course?'),
        ).toBeInTheDocument();
        expect(archiveDialog()).toHaveTextContent(
            'First aid level 2 is marked inactive. It stays in the catalogue',
        );
        expect(archiveDialog()).toHaveTextContent(
            'New starters are no longer enrolled in it during induction.',
        );
        expect(archiveDialog()).not.toHaveTextContent('first-aid cover');
    });

    it('warns that first-aid cover drops when the course proves first aid', () => {
        openArchiveFromMenu(true);

        expect(archiveDialog()).toHaveTextContent(firstAidWarning);
        expect(archiveDialog()).toHaveTextContent(
            'You can activate it again from its menu.',
        );
        expect(router.patch).not.toHaveBeenCalled();
    });

    it('gives the same first-aid warning when archiving it from the bulk bar', () => {
        openCatalog(true, true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Select First aid level 2' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));

        expect(
            within(archiveDialog()).getByText('Archive 1 course?'),
        ).toBeInTheDocument();
        expect(archiveDialog()).toHaveTextContent(firstAidWarning);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('Cancel archives nothing', async () => {
        openArchiveFromMenu();

        fireEvent.click(
            within(archiveDialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('archives the course with the same toggle request once confirmed', async () => {
        router.patch.mockImplementation(settle);
        openArchiveFromMenu();

        fireEvent.click(
            within(archiveDialog()).getByRole('button', {
                name: 'Archive course',
            }),
        );

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/hr/training/courses/3/toggle',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('still activates an inactive course straight away', () => {
        openCatalog(false);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Actions for First aid level 2',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(router.patch).toHaveBeenCalledWith(
            '/hr/training/courses/3/toggle',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    const openBulkArchive = () => {
        openCatalog();
        fireEvent.click(
            screen.getByRole('button', { name: 'Select First aid level 2' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Archive' }));
    };

    it('asks before archiving the selected courses, and Cancel sends nothing', async () => {
        openBulkArchive();

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(archiveDialog()).getByText('Archive 1 course?'),
        ).toBeInTheDocument();
        expect(archiveDialog()).toHaveTextContent(
            'The selected course is marked inactive.',
        );

        fireEvent.click(
            within(archiveDialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByText('1 selected')).toBeInTheDocument();
    });

    it('sends the same bulk archive request once confirmed', async () => {
        router.post.mockImplementation(settle);
        openBulkArchive();

        fireEvent.click(
            within(archiveDialog()).getByRole('button', {
                name: 'Archive course',
            }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/training/courses/bulk-archive',
            { course_ids: [3], active: false },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
