import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DebugConsoleTab } from '@/pages/security-devices/integrations/queclink-hub';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        post: vi.fn(),
        get: vi.fn(),
    },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
        reset: vi.fn(),
    }),
}));

const recentFrame = {
    id: 101,
    imei: '867963069916998',
    direction: 'inbound',
    frame_type: 'RESP',
    command_word: 'GTFRI',
    raw_frame: '+RESP:GTFRI,970204,867963069916998,,0,0,1,,,,,,,,0530,0001,A310,0017E102,19,0,4175,100,1,,,20260518020210,0082$',
    parse_ok: true,
    parse_error: null,
    created_at: '2026-05-18T02:07:01Z',
};

class EventSourceStub {
    static instances: EventSourceStub[] = [];

    onmessage: ((event: MessageEvent) => void) | null = null;
    onerror: (() => void) | null = null;
    close = vi.fn();

    constructor(public url: string) {
        EventSourceStub.instances.push(this);
    }
}

function renderHub() {
    return render(
        <DebugConsoleTab
            devices={[
                {
                    id: 2,
                    imei: '867963069916998',
                    status: 'paired',
                    model_hint: null,
                    protocol_version: '970204',
                    firmware_version: null,
                    connection_state: 'connected',
                    first_seen_at: null,
                    last_seen_at: '2026-05-18T02:07:01Z',
                    last_frame_at: '2026-05-18T02:07:01Z',
                    remote_address: null,
                    assignment: {
                        type: 'client',
                        target_id: 9012,
                        assigned_at: '2026-05-18T02:00:00Z',
                        label: 'Amelia Wilson',
                    },
                },
            ]}
            can={{ manage: true }}
        />,
    );
}

describe('QueclinkHub debug console', () => {
    beforeEach(() => {
        EventSourceStub.instances = [];
        vi.stubGlobal('EventSource', EventSourceStub);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ frames: [recentFrame] }),
            }),
        );
    });

    it('loads recent frames when the debug console opens instead of showing an empty stream', async () => {
        renderHub();

        expect(
            await screen.findByText(recentFrame.raw_frame),
        ).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/frames',
            expect.objectContaining({
                headers: expect.objectContaining({ Accept: 'application/json' }),
            }),
        );
    });
});
