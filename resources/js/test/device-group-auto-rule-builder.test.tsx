import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type React from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    AutoRuleBuilder,
    type AutoRules,
    normaliseAutoRules,
} from '@/pages/security-devices/device-groups/auto-rule-builder';
import DeviceGroupShow from '@/pages/security-devices/device-groups/show';

vi.mock('axios');

const inertiaMocks = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: inertiaMocks,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/page', () => ({
    PageHero: ({
        title,
        actions,
    }: {
        title: React.ReactNode;
        actions?: React.ReactNode;
    }) => (
        <header>
            {title}
            {actions}
        </header>
    ),
}));

function Harness({ initial = null }: { initial?: AutoRules | null }) {
    const [rules, setRules] = useState<AutoRules | null>(initial);

    return (
        <>
            <AutoRuleBuilder value={rules} onChange={setRules} />
            <output data-testid="rules">{JSON.stringify(rules)}</output>
        </>
    );
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Device Group automatic membership builder', () => {
    it('enables a plain-language condition and normalises comma-separated values', () => {
        render(<Harness />);

        fireEvent.click(
            screen.getByRole('switch', { name: 'Automatic membership' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add condition' }));

        const valueInputs = screen.getAllByLabelText('Value');
        fireEvent.change(valueInputs[1], {
            target: { value: 'camera, nvr, camera' },
        });

        expect(
            normaliseAutoRules({
                match: 'all',
                conditions: [
                    {
                        field: 'category',
                        op: 'in',
                        value: 'camera, nvr, camera',
                    },
                ],
            }),
        ).toEqual({
            match: 'all',
            conditions: [
                { field: 'category', op: 'in', value: ['camera', 'nvr'] },
            ],
        });
    });

    it('previews unsaved rules inline without changing membership', async () => {
        vi.mocked(axios.post).mockResolvedValue({
            data: {
                count: 2,
                sample: [
                    {
                        id: 1,
                        name: 'Lobby camera',
                        device_uid: 'CAM-1',
                        category: 'camera',
                    },
                    {
                        id: 2,
                        name: 'Rear camera',
                        device_uid: 'CAM-2',
                        category: 'camera',
                    },
                ],
            },
        });

        render(
            <Harness
                initial={{
                    match: 'all',
                    conditions: [
                        { field: 'domain', op: 'equals', value: 'security' },
                    ],
                }}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Preview matches' }),
        );

        await waitFor(() => {
            expect(screen.getByText('2 devices match')).toBeInTheDocument();
        });
        expect(screen.getByText('Lobby camera')).toBeInTheDocument();
        expect(axios.post).toHaveBeenCalledWith(
            '/security-devices/device-groups/auto-rules/preview',
            {
                auto_rules: {
                    match: 'all',
                    conditions: [
                        { field: 'domain', op: 'equals', value: 'security' },
                    ],
                },
            },
        );
    });

    it('explains that saving rules does not silently change group membership', () => {
        render(<Harness />);

        expect(
            screen.getByText(/Saving a rule does not add or remove devices/),
        ).toBeInTheDocument();
    });

    it('reviews exact membership changes before applying a saved rule', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                count: 2,
                changes: { added: 1, removed: 1, kept: 1, total: 2 },
                sample: [
                    {
                        id: 1,
                        name: 'Lobby camera',
                        device_uid: 'CAM-1',
                        category: 'camera',
                    },
                ],
            },
        });

        render(
            <DeviceGroupShow
                group={{
                    id: 14,
                    name: 'Security estate',
                    type: 'functional',
                    description: null,
                    created_at: '2026-08-03T00:00:00Z',
                    auto_rules: {
                        match: 'all',
                        conditions: [
                            {
                                field: 'domain',
                                op: 'equals',
                                value: 'security',
                            },
                        ],
                    },
                    auto_rule_condition_count: 1,
                }}
                members={{
                    data: [],
                    links: [],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        total: 0,
                    },
                }}
                availableDevices={[]}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review proposed membership',
            }),
        );

        await waitFor(() => {
            expect(screen.getByText('Final total')).toBeInTheDocument();
        });
        expect(
            screen.getByText(/Applying will remove 1 visible device/),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        );
        expect(inertiaMocks.post).toHaveBeenCalledWith(
            '/security-devices/device-groups/14/auto-rules/sync',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('uses governed dialogs for group deletion and member removal', () => {
        const browserConfirm = vi
            .spyOn(window, 'confirm')
            .mockReturnValue(false);

        render(
            <DeviceGroupShow
                group={{
                    id: 14,
                    name: 'Security estate',
                    type: 'functional',
                    description: null,
                    created_at: '2026-08-03T00:00:00Z',
                    auto_rules: null,
                    auto_rule_condition_count: 0,
                }}
                members={{
                    data: [
                        {
                            id: 9,
                            device_uid: 'CAM-9',
                            name: 'Lobby camera',
                            domain: 'security',
                            category: 'camera',
                            subcategory: null,
                            manufacturer: null,
                            model: null,
                            status: 'active',
                            health_status: 'healthy',
                            provider: 'unifi',
                            last_seen_at: null,
                            battery_level: null,
                            assigned_to: null,
                            assignment_type: null,
                        },
                    ],
                    links: [],
                    meta: { current_page: 1, last_page: 1, total: 1 },
                }}
                availableDevices={[]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(
            screen.getByRole('heading', { name: 'Delete device group?' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Delete group' }));
        expect(inertiaMocks.delete).toHaveBeenCalledWith(
            '/security-devices/device-groups/14',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Remove Lobby camera from group',
            }),
        );
        expect(
            screen.getByRole('heading', { name: 'Remove device from group?' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Remove device' }));
        expect(inertiaMocks.delete).toHaveBeenCalledWith(
            '/security-devices/device-groups/14/members/9',
            { preserveScroll: true },
        );
        expect(browserConfirm).not.toHaveBeenCalled();
    });
});
