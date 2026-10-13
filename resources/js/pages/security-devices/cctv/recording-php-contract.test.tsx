import { cleanup, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { recordingDetail, recordingPage } from './_recording-contract';
import RecordingReferencePage from './recording-reference';
import RecordingReferencesPage from './recording-references';
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
const path = process.env.OBLI_CCTV_REFERENCE_EXPORT;
const read = (suffix = '') => JSON.parse(readFileSync(path! + suffix, 'utf8'));
describe('actual PHP recording-reference projections', () => {
    it.skipIf(!path)(
        'accepts original creation, private recovery and metadata-only list without rewriting the receipt',
        () => {
            const f = read(),
                actor = f.page.actor_id,
                camera = f.page.camera.id;
            for (const raw of [f.empty, f.page, f.listed])
                expect(recordingPage(raw, actor, camera)).toEqual(raw);
            for (const raw of [f.created, f.detail, f.recovered]) {
                const detail = recordingDetail(
                    raw,
                    actor,
                    camera,
                    f.created.reference.reference_uuid,
                );
                expect(detail.reference).toEqual(f.created.reference);
                expect(detail.reference.provider_recorder_id).toBe(
                    'nvr-verified-one',
                );
                expect(detail.reference.preserved).toBe(false);
                expect(detail.reference.recording_verified_at).toBeNull();
            }
            expect(
                Date.parse(f.created.reference.requested_end_at),
            ).toBeGreaterThan(Date.parse(f.created.server_now));
            expect(() =>
                recordingDetail(f.detail, actor + 1, camera),
            ).toThrow();
            expect(() =>
                recordingDetail(f.detail, actor, camera + 1),
            ).toThrow();
            expect(() =>
                recordingDetail(
                    {
                        ...f.detail,
                        reference: { ...f.detail.reference, preserved: true },
                    },
                    actor,
                    camera,
                ),
            ).toThrow();
        },
    );
    it.skipIf(!path)(
        'renders original create and reader pages while keeping source withdrawal separate from the saved receipt',
        () => {
            const f = read();
            render(<RecordingReferencesPage {...f.page} />);
            expect(
                screen.getByRole('button', {
                    name: 'Save recording reference',
                }),
            ).toBeInTheDocument();
            cleanup();
            render(<RecordingReferencePage {...f.detail} />);
            expect(
                screen.getByText('Footage not verified or preserved'),
            ).toBeInTheDocument();
            expect(
                screen.getByText(f.detail.reference.purpose),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /play|preserve|export/i }),
            ).not.toBeInTheDocument();
            cleanup();
            const w = read('.withdrawn'),
                page = recordingPage(w.page, w.page.actor_id, w.page.camera.id);
            const detail = recordingDetail(
                w.detail,
                w.detail.actor_id,
                w.detail.camera.id,
            );
            expect(detail.current_source.state).toBe('withdrawn');
            expect(detail.reference.preserved).toBe(false);
            render(<RecordingReferencesPage {...page} />);
            expect(
                screen.queryByRole('button', {
                    name: 'Save recording reference',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByText(/Viewing has been turned off/),
            ).toBeInTheDocument();
            expect(
                screen.getAllByRole('link', { name: 'Open reference' })[0],
            ).toHaveAttribute('href', detail.reference.reader_href);
        },
    );
    it.skipIf(!path)(
        'accepts original bounded cursor pages and exposes only the exact camera paging links',
        () => {
            const f = read('.pages'),
                first = recordingPage(
                    f.first,
                    f.first.actor_id,
                    f.first.camera.id,
                ),
                second = recordingPage(
                    f.second,
                    f.second.actor_id,
                    f.second.camera.id,
                );
            expect(first.references.items).toHaveLength(20);
            expect(second.references.items).toHaveLength(3);
            expect(second.references.next_href).toBeNull();
            expect(
                new Set(
                    [...first.references.items, ...second.references.items].map(
                        (r) => r.reference_uuid,
                    ),
                ).size,
            ).toBe(23);
            render(<RecordingReferencesPage {...first} />);
            expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
                'href',
                first.references.next_href,
            );
            cleanup();
            render(<RecordingReferencesPage {...second} />);
            expect(
                screen.getByRole('link', { name: 'Previous' }),
            ).toHaveAttribute('href', second.references.previous_href);
            expect(
                screen.queryByRole('link', { name: 'Next' }),
            ).not.toBeInTheDocument();
        },
    );
});
