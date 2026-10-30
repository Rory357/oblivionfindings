import type { DeviceProfile } from '@/pages/security-devices/devices/device-profile';
import { DeviceManagementSection } from '@/pages/security-devices/devices/device-profile-sections';
import { cleanup, render, screen } from '@testing-library/react';
import { existsSync, readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { restartPolicyReply } from './restart-policy';
import {
    RestartRequestState,
    type RestartRequest,
} from './restart-request-state';
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));
afterEach(cleanup);
const path = process.env.OBLI_RESTART_UI_FIXTURE;
const contractPath = process.env.OBLI_RESTART_CONTRACT_FIXTURE;
describe('Actual PHP restart presentation', () => {
    it.skipIf(!path || !existsSync(path))(
        'validates the actual policy and renders cancellation/boot states without implying completion',
        () => {
            const fixture = JSON.parse(readFileSync(path!, 'utf8'));
            const policy = restartPolicyReply(
                fixture.policy,
                fixture.policy.actor_id,
                fixture.policy.device_id,
            );
            expect(
                policy.policy.minimum_warning_seconds,
            ).toBeGreaterThanOrEqual(60);
            expect(fixture.capability.key).toBe('endpoint.computer.restart');
            expect(fixture.capability.parameters).toEqual({
                execute_at: { type: 'date_time' },
                warning_seconds: { type: 'integer', min: 60, max: 3600 },
            });
            for (const state of [
                'scheduled',
                'cancel_requested',
                'awaiting_boot',
            ] as const) {
                const restart = fixture.projections[state] as RestartRequest;
                const ui = render(
                    <RestartRequestState
                        restart={restart}
                        actorId={policy.actor_id}
                        deviceId={policy.device_id}
                        commandId={12}
                    />,
                );
                expect(
                    screen.queryByText('Restart confirmed'),
                ).not.toBeInTheDocument();
                if (state === 'scheduled')
                    expect(
                        screen.getByRole('button', {
                            name: 'Cancel restart request',
                        }),
                    ).toBeInTheDocument();
                else
                    expect(
                        screen.queryByRole('button', {
                            name: 'Cancel restart request',
                        }),
                    ).not.toBeInTheDocument();
                ui.unmount();
            }
        },
    );
});

describe('Actual PHP scheduled-restart lifecycle presentation', () => {
    it.skipIf(!contractPath)(
        'renders the unchanged canonical management action and independently confirmed history',
        () => {
            const fixture = JSON.parse(readFileSync(contractPath!, 'utf8'));
            const before =
                fixture.management_before as DeviceProfile['management'];
            const confirmed =
                fixture.management_confirmed as DeviceProfile['management'];
            const header = fixture.header as DeviceProfile['header'];
            const action = before.actions.find(
                (item) => item.key === 'endpoint.computer.restart',
            );
            expect(action).toMatchObject({
                executionMode: 'endpoint_runtime',
                requiresApproval: true,
                allowsBreakGlass: false,
            });
            expect(action?.parameters).toEqual([
                expect.objectContaining({
                    name: 'execute_at',
                    type: 'date_time',
                }),
                expect.objectContaining({
                    name: 'warning_seconds',
                    type: 'integer',
                    min: 60,
                    max: 3600,
                }),
            ]);
            expect(before.actorId).toBeGreaterThan(0);
            const profile = { header, management: before } as DeviceProfile;
            const ui = render(
                <DeviceManagementSection
                    deviceId={header.identity.id}
                    profile={profile}
                />,
            );
            expect(
                screen.getByText('Restart time requested'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('Restart confirmed'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText(/Ends 5 seconds after the scheduled time/),
            ).toBeInTheDocument();
            ui.rerender(
                <DeviceManagementSection
                    deviceId={header.identity.id}
                    profile={{ ...profile, management: confirmed }}
                />,
            );
            expect(screen.getByText('Restart confirmed')).toBeInTheDocument();
            expect(
                screen.getByText('Technical details').closest('details'),
            ).not.toHaveAttribute('open');
            expect(
                screen.queryByText(/Execution attempt 1/),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText(
                    'This computer checked in after the approved restart.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'Cancel restart request',
                }),
            ).not.toBeInTheDocument();
        },
    );
});
