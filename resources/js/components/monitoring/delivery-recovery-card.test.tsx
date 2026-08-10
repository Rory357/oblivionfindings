import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import {
    DeliveryRecoveryCard,
    type DeliveryRecovery,
} from './delivery-recovery-card';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn() },
}));

const row = (
    id: number,
    overrides: Partial<DeliveryRecovery['dead_letters']['rows'][number]> = {},
): DeliveryRecovery['dead_letters']['rows'][number] => ({
    id,
    reason_code: 'handler_failed',
    reason_message: 'The bounded runtime handler failed.',
    consumer: 'observation-projector',
    message_reference: `msg-${id}`,
    site: null,
    replay_count: 0,
    created_at: '2026-08-04T01:00:00Z',
    schema_version: 2,
    payload_version: 2,
    message_type: 'observation',
    can_replay: true,
    can_discard: false,
    pending_replay: false,
    operator_note: 'Review the safe evidence before acting.',
    ...overrides,
});

const delivery: DeliveryRecovery = {
    contracts: {
        envelope_current: 2,
        envelope_accepted: [1, 2],
        payloads: { observation: { current: 2, accepted: [1, 2] } },
        commands: {},
    },
    dead_letters: {
        visible: true,
        total: 2,
        shown: 2,
        truncated: false,
        note: 'Safe recovery metadata.',
        rows: [
            row(1),
            row(2, {
                reason_code: 'invalid_envelope',
                can_replay: false,
                can_discard: true,
            }),
        ],
    },
};

describe('DeliveryRecoveryCard', () => {
    it('clears an operational reason before another dead-letter action opens', () => {
        render(<DeliveryRecoveryCard delivery={delivery} />);

        fireEvent.click(screen.getByRole('button', { name: 'Replay' }));
        fireEvent.change(screen.getByLabelText('Operational reason'), {
            target: { value: 'Reason for the first signed message only.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        fireEvent.click(screen.getByRole('button', { name: 'Discard' }));

        expect(screen.getByLabelText('Operational reason')).toHaveValue('');
    });
});
