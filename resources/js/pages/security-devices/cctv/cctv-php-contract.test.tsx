import { cleanup, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { sourceSetupReply, sourceWriteReply } from './_source-contract';
import CctvLive, { type CctvLiveProps } from './live';
import { cctvSessionReply } from './live-contract';
import CctvSource from './source';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: () => () => undefined },
}));
afterEach(cleanup);
const path = process.env.OBLI_CCTV_EXPORT;
const read = () => JSON.parse(readFileSync(path!, 'utf8'));

describe('actual PHP CCTV producer', () => {
    it.skipIf(!path)(
        'accepts unchanged source, session progression and exact original answer bytes',
        () => {
            const fixture = read(),
                actor = fixture.started.actor_id,
                camera = fixture.started.camera_id;
            const setup = sourceSetupReply(fixture.setup, actor, camera);
            expect(
                sourceWriteReply(fixture.source_write, actor, camera),
            ).toEqual(setup.source);
            const requested = cctvSessionReply(fixture.started, actor, camera);
            const negotiating = cctvSessionReply(
                fixture.offered,
                actor,
                camera,
                requested,
            );
            const ready = cctvSessionReply(
                fixture.ready,
                actor,
                camera,
                negotiating,
            );
            expect(ready.answer?.sdp).toBe(
                fixture.result_input.results[0].answer.sdp,
            );
            expect(ready.answer?.sdp.endsWith('\r\n')).toBe(true);
            expect(ready.authority_until).toBe(requested.authority_until);
            expect(() =>
                cctvSessionReply(fixture.ready, actor + 1, camera),
            ).toThrow();
            expect(() =>
                sourceSetupReply(fixture.setup, actor, camera + 1),
            ).toThrow();
        },
    );
    it.skipIf(!path)(
        'renders source verification separately from actual video and opens a reviewed edit',
        () => {
            const fixture = read(),
                setup = sourceSetupReply(
                    fixture.setup,
                    fixture.setup.actor_id,
                    fixture.setup.camera_id,
                );
            render(<CctvSource {...setup} />);
            expect(
                screen.getByRole('button', { name: 'Edit connection' }),
            ).toBeInTheDocument();
            expect(
                screen.getByText(setup.source!.recorder_name),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('Live', { exact: true }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Camera details' }),
            ).toHaveAttribute(
                'href',
                '/security-devices/devices/' + setup.camera_id,
            );
            cleanup();
            render(<CctvLive {...(fixture.page as CctvLiveProps)} />);
            expect(
                screen.getByRole('button', { name: 'Start live view' }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Camera setup' }),
            ).toHaveAttribute(
                'href',
                '/security-devices/devices/' + setup.camera_id + '/live/source',
            );
            expect(
                screen.queryByText('Live', { exact: true }),
            ).not.toBeInTheDocument();
            expect(
                document.querySelector('video')?.srcObject ?? null,
            ).toBeNull();
        },
    );
});
