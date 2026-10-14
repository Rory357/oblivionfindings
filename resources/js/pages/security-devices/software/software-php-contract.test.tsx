import { softwarePolicyReply } from '@/pages/it/remote-support/software-policy';
import type { DeviceProfile } from '@/pages/security-devices/devices/device-profile';
import { DeviceManagementSection } from '@/pages/security-devices/devices/device-profile-sections';
import { cleanup, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { softwareIndexReply, softwareWriteReply } from './_contracts';
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { post: vi.fn(), reload: vi.fn(), on: () => () => undefined },
}));
afterEach(cleanup);
const path = process.env.OBLI_SOFTWARE_EXPORT;
function read(suffix = '') {
    return JSON.parse(readFileSync(path + suffix, 'utf8'));
}
describe('actual PHP approved software producer', () => {
    it.skipIf(!path)(
        'accepts unchanged actor-bound catalogue, cursor, policy and upload output',
        () => {
            const states = read('.catalogue'),
                pages = read('.pages'),
                policy = read('.policy'),
                upload = read('.upload');
            for (const page of [
                states.pending,
                ...Object.values(pages),
            ] as Array<{
                actor_id: number;
                query: { device_id: number | null };
            }>)
                expect(
                    softwareIndexReply(
                        page,
                        page.actor_id,
                        page.query.device_id,
                    ).actor_id,
                ).toBe(page.actor_id);
            for (const status of ['published', 'withdrawn']) {
                expect(
                    softwareWriteReply(states[status], states[status].actor_id)
                        .status,
                ).toBe(status);
            }
            for (const p of Object.values(policy) as Array<{
                actor_id: number;
                device_id: number;
            }>)
                expect(
                    softwarePolicyReply(p, p.actor_id, p.device_id).device_id,
                ).toBe(p.device_id);
            expect(softwareWriteReply(upload, upload.actor_id).status).toBe(
                'uploaded',
            );
        },
    );
    it.skipIf(!path)(
        'renders actual canonical requested and independently confirmed history without raw package identifiers',
        () => {
            const fixture = read(),
                header = fixture.header as DeviceProfile['header'],
                before =
                    fixture.management_before as DeviceProfile['management'],
                confirmed =
                    fixture.management_confirmed as DeviceProfile['management'];
            const action = before.actions.find(
                (item) => item.key === 'endpoint.software.install',
            );
            expect(action).toMatchObject({
                executionMode: 'endpoint_runtime',
                requiresApproval: true,
                allowsBreakGlass: false,
            });
            expect(action?.parameters.map((p) => p.name)).toEqual([
                'package_uuid',
            ]);
            const profile = { header, management: before } as DeviceProfile;
            const ui = render(
                <DeviceManagementSection
                    deviceId={header.identity.id}
                    profile={profile}
                />,
            );
            expect(
                screen.getByText('Installation requested'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('Installed version verified'),
            ).not.toBeInTheDocument();
            ui.rerender(
                <DeviceManagementSection
                    deviceId={header.identity.id}
                    profile={{ ...profile, management: confirmed }}
                />,
            );
            expect(
                screen.getByText('Installed version verified'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('Technical details').closest('details'),
            ).not.toHaveAttribute('open');
            expect(
                screen.queryByText(/Execution attempt 1/),
            ).not.toBeInTheDocument();
        },
    );
    it.skipIf(!path)(
        'retains actual pending restart evidence after product verification',
        () => {
            const fixture = read('.reboot'),
                profile = {
                    header: fixture.header,
                    management: fixture.management_reboot_needed,
                } as DeviceProfile;
            render(
                <DeviceManagementSection
                    deviceId={fixture.header.identity.id}
                    profile={profile}
                />,
            );
            expect(
                screen.getByText('Installed · restart needed'),
            ).toBeInTheDocument();
            expect(
                screen.getByText(/separate approved restart workflow/),
            ).toBeInTheDocument();
        },
    );
});
