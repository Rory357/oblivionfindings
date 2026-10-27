import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddHubWizard } from './add-hub-wizard';
import { EditHubDialog } from './edit-hub-dialog';

vi.mock('axios');

describe('AddHubWizard and EditHubDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
        });
    });

    afterEach(() => {
        cleanup();
    });

    it('walks through Site hub setup, displays the one-time setup code, and transitions to Connected ✓', async () => {
        const optionsPayload = {
            platform: 'linux' as const,
            profile_configured: true,
            profile_missing_reason: null,
            catalogue_available: true,
            catalogue_expires_at: '2026-11-28T00:00:00Z',
            deployment_url: 'https://oblivionfindings.test',
            server_url: 'https://oblivionfindings.test',
            organisation: 'Oblivion Findings',
            enrolment_minutes: 15,
            accessible_sites: [
                { id: 10, name: 'Kauri House' },
                { id: 11, name: 'Totara Lodge' },
            ],
            downloads: [],
            downloads_by_platform: {
                windows: [
                    {
                        id: 'windows-amd64',
                        title: 'Obli & Site Hub Setup',
                        platform: 'windows' as const,
                        architecture: 'amd64',
                        roles: ['computer', 'site'],
                        version: '0.2.0',
                        filename: 'obli-setup-windows-amd64.exe',
                        size: 4096,
                        size_bytes: 4096,
                        sha256: 'a'.repeat(64),
                        download_url: `/security-devices/agent-downloads/windows-amd64/${'a'.repeat(64)}`,
                    },
                ],
                linux: [
                    {
                        id: 'linux-amd64',
                        title: 'Oblivion Site Hub Service',
                        platform: 'linux' as const,
                        architecture: 'amd64',
                        roles: ['site'],
                        version: '0.2.0',
                        filename: 'oblivion-site-hub-linux-amd64.tar.gz',
                        size: 2048,
                        size_bytes: 2048,
                        sha256: 'b'.repeat(64),
                        download_url: `/security-devices/agent-downloads/linux-amd64/${'b'.repeat(64)}`,
                    },
                ],
            },
        };

        vi.mocked(axios.get).mockImplementation(async (url) => {
            if (String(url).includes('/enrolments/77/status')) {
                return {
                    data: {
                        enrollment_id: 77,
                        state: 'connected',
                        expires_at: '2026-10-28T12:00:00Z',
                        consumed_at: '2026-10-28T10:05:00Z',
                        collector: {
                            id: 301,
                            name: 'Kauri House Hub',
                            status: 'online',
                            software_version: '0.2.0',
                            last_heartbeat_at: '2026-10-28T10:05:10Z',
                            covered_site_ids: [11],
                        },
                    },
                };
            }

            return { data: optionsPayload };
        });

        vi.mocked(axios.post).mockResolvedValueOnce({
            data: {
                enrollment_id: 77,
                expires_at: '2026-10-28T12:00:00Z',
                profile_configured: true,
                profile_missing_reason: null,
                setup_code:
                    'ofh1_eyJ0b2tlbiI6Im9mY19lbnJvbF90ZXN0X3Rva2VuXzc3In0',
                enrolment_token: 'ofc_enrol_test_token_77',
                collector_uuid: '018f0000-0000-7000-8000-000000000301',
                profile_filename:
                    'oblivion-collector-018f0000-0000-7000-8000-000000000301.json',
                linux_command:
                    "sudo bash install.sh --code 'ofh1_eyJ0b2tlbiI6Im9mY19lbnJvbF90ZXN0X3Rva2VuXzc3In0'",
            },
        });

        const onConnected = vi.fn();
        const onClose = vi.fn();

        render(
            <AddHubWizard
                open
                defaultSiteId={10}
                onClose={onClose}
                onConnected={onConnected}
            />,
        );

        await waitFor(() => {
            expect(screen.getByLabelText('Hub name')).toHaveValue(
                'Kauri House Hub',
            );
        });

        fireEvent.click(
            screen.getByRole('button', {
                name: /Linux NUC or Raspberry Pi/i,
            }),
        );
        fireEvent.click(screen.getByText('Totara Lodge'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Create one-time code' }),
        );

        await waitFor(() => {
            expect(
                screen.getByDisplayValue(
                    'ofh1_eyJ0b2tlbiI6Im9mY19lbnJvbF90ZXN0X3Rva2VuXzc3In0',
                ),
            ).toBeVisible();
        });

        expect(axios.post).toHaveBeenCalledWith(
            '/security-devices/discovery/collectors/hub-wizard/issue',
            {
                site_id: 10,
                platform: 'linux',
                name: 'Kauri House Hub',
                covered_site_ids: [11],
            },
            expect.objectContaining({
                headers: { Accept: 'application/json' },
            }),
        );
        expect(
            screen.getByRole('link', { name: 'Download' }),
        ).toHaveAttribute(
            'href',
            `/security-devices/agent-downloads/linux-amd64/${'b'.repeat(64)}`,
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Continue to connection check',
                }),
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Connected ✓')).toBeVisible();
        });
        expect(screen.getByText('0.2.0')).toBeVisible();
        expect(onConnected).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('updates a hub name and SD-WAN covered Sites in EditHubDialog', async () => {
        vi.mocked(axios.patch).mockResolvedValueOnce({ data: {} });
        const onSaved = vi.fn();
        const onOpenChange = vi.fn();

        render(
            <EditHubDialog
                open
                onOpenChange={onOpenChange}
                hub={{
                    id: 301,
                    name: 'Kauri House Hub',
                    site: { id: 10, name: 'Kauri House' },
                    covered_site_ids: [],
                    update_url: '/security-devices/discovery/collectors/301',
                }}
                sites={[
                    { id: 10, name: 'Kauri House' },
                    { id: 11, name: 'Totara Lodge' },
                ]}
                onSaved={onSaved}
            />,
        );

        fireEvent.change(screen.getByLabelText('Hub name'), {
            target: { value: 'Kauri Main Hub' },
        });
        fireEvent.click(screen.getByText('Totara Lodge'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save hub settings' }),
        );

        await waitFor(() => {
            expect(axios.patch).toHaveBeenCalledWith(
                '/security-devices/discovery/collectors/301',
                {
                    name: 'Kauri Main Hub',
                    covered_site_ids: [11],
                },
                expect.objectContaining({
                    headers: { Accept: 'application/json' },
                }),
            );
        });
        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
});
