import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ChecklistConfigProvider,
    type ChecklistConfig,
} from '@/components/checklists/context';
import { RunModal } from '@/components/checklists/run-modal';
import type { RunDetail } from '@/components/checklists/types';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    reload: vi.fn(),
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: mocks.post,
        reload: mocks.reload,
    },
    usePage: () => ({ props: mocks.props }),
}));

const config: ChecklistConfig = {
    categories: [],
    categoryMap: {},
    freqLabels: { daily: 'Daily' },
    typeLabels: {},
    today: '2026-08-14',
    can: {
        view: true,
        manageTemplates: false,
        schedule: false,
        run: true,
    },
    scope: { mode: 'org' },
    assignableUsers: [],
    openRun: vi.fn(),
    openBuilder: vi.fn(),
};

function runDetail(signatureFlag: boolean): RunDetail {
    return {
        id: 77,
        status: 'in_progress',
        can_run: true,
        scheduled_date: '2026-08-14',
        completion_percentage: 100,
        overall_notes: null,
        site: { id: 4, name: 'Matai House', type: 'house' },
        template: {
            id: 8,
            name: 'Daily checks',
            frequency: 'daily',
            category: 'safety',
            flags: {
                hazard: false,
                photo: false,
                sign: signatureFlag,
            },
        },
        items: [
            {
                id: 12,
                question: 'Are exits clear?',
                response_type: 'yes_no',
                response_config: null,
                is_required: true,
                guidance: null,
                failure_creates_hazard: false,
                failure_creates_damage: false,
                failure_risk_level: 'ordinary',
            },
        ],
        responses: [
            {
                template_item_id: 12,
                response_value: 'yes',
                notes: null,
                photo_path: null,
                is_failed: false,
            },
        ],
    };
}

describe('RunModal sign-off', () => {
    beforeEach(() => {
        mocks.post.mockReset();
        mocks.reload.mockReset();
    });

    it.each([false, true])(
        'requires a visible explicit attestation when template signature flag is %s',
        async (signatureFlag) => {
            mocks.props = {
                auth: { user: { name: 'Hidden Authenticated Name' } },
                runDetail: runDetail(signatureFlag),
            };

            render(
                <ChecklistConfigProvider value={config}>
                    <RunModal runId={77} onClose={vi.fn()} />
                </ChecklistConfigProvider>,
            );

            const signOff = screen.getByPlaceholderText('Your name');
            expect(screen.getByText('Sign-off')).toBeVisible();
            expect(signOff).toHaveValue('');
            expect(signOff.parentElement).toHaveClass(
                'flex-col',
                'sm:flex-row',
            );
            expect(screen.getByText('Complete run').parentElement).toHaveClass(
                'grid',
                'sm:flex',
            );
            expect(
                screen.queryByDisplayValue('Hidden Authenticated Name'),
            ).not.toBeInTheDocument();

            await waitFor(() =>
                expect(screen.getByText('Complete run')).toBeDisabled(),
            );
            fireEvent.change(signOff, {
                target: { value: 'Explicit Typed Attestation' },
            });

            await waitFor(() =>
                expect(screen.getByText('Complete run')).toBeEnabled(),
            );
        },
    );

    it('makes the required H&S hand-off visible for a critical failed item', async () => {
        const detail = runDetail(false);
        detail.items[0].failure_creates_damage = true;
        detail.items[0].failure_risk_level = 'critical';
        detail.responses[0].response_value = 'no';
        detail.responses[0].is_failed = true;
        mocks.props = { runDetail: detail };

        render(
            <ChecklistConfigProvider value={config}>
                <RunModal runId={77} onClose={vi.fn()} />
            </ChecklistConfigProvider>,
        );

        await waitFor(() =>
            expect(
                screen.getByText(
                    '1 critical failed check will require H&S escalation and a corrective action',
                ),
            ).toBeVisible(),
        );
    });
});
