import { describe, expect, it } from 'vitest';
import {
    matchesRecordingRequest,
    recordingDetail,
    recordingListHref,
    recordingPage,
} from './_recording-contract';
import { recordingWindow } from './_recording-dialog';
import {
    recordingDetailFixture,
    recordingPageFixture,
} from './recording-test-fixtures';
describe('Recording reference contracts', () => {
    it('keeps reference-only metadata separate from changed current source and another authorised reader', () => {
        const detail = recordingDetailFixture();
        detail.reference.created_by = { id: 18, name: 'Original reviewer' };
        detail.current_source = {
            state: 'changed',
            reason_code: 'source_changed',
            revision: 3,
            source_verified_at: '2026-10-08T07:30:00Z',
        };
        expect(recordingDetail(detail, 7, 42).reference.created_by.id).toBe(18);
        expect(recordingDetail(detail, 7, 42).reference.preserved).toBe(false);
        expect(() => recordingDetail(detail, 8, 42)).toThrow();
        expect(() => recordingDetail(detail, 7, 41)).toThrow();
    });
    it('rejects fabricated footage claims, mixed sites, provider URLs and extra secrets', () => {
        for (const change of [
            { preserved: true },
            { recording_verified_at: '2026-10-08T08:00:00Z' },
            { site_id: 10 },
            { reader_href: 'https://provider.example/clip' },
            { source_uri: 'rtsps://private.invalid/' },
        ]) {
            const detail = recordingDetailFixture();
            expect(() =>
                recordingDetail(
                    {
                        ...detail,
                        reference: { ...detail.reference, ...change },
                    },
                    7,
                    42,
                ),
            ).toThrow();
        }
    });
    it('binds list cursor destinations to the camera and preserves permission denial', () => {
        const page = recordingPageFixture();
        page.permissions.can_create = false;
        page.create_href = null;
        expect(recordingPage(page, 7, 42).permissions.can_create).toBe(false);
        expect(
            recordingListHref(
                '/security-devices/devices/42/recording-references?cursor=Y3Vyc29y',
                42,
            ),
        ).toBe(true);
        for (const path of [
            '/security-devices/devices/43/recording-references?cursor=YQ',
            '/security-devices/devices/42/recording-references?cursor=YQ&other=1',
            '//provider.example/',
            '/security-devices/devices/42/recording-references?cursor=YQ#secret',
        ])
            expect(recordingListHref(path, 42)).toBe(false);
    });
    it('accepts an ongoing window but rejects future starts, reversals and excessive duration', () => {
        const now = Date.parse('2026-10-08T08:00:00Z');
        expect(
            recordingWindow(
                '2026-10-08T20:55',
                '',
                '2026-10-08T21:15',
                '',
                now,
                3600,
            ),
        ).toEqual({
            requested_start_at: '2026-10-08T07:55:00Z',
            requested_end_at: '2026-10-08T08:15:00Z',
        });
        expect(() =>
            recordingWindow(
                '2026-10-08T21:05',
                '',
                '2026-10-08T21:15',
                '',
                now,
                3600,
            ),
        ).toThrow();
        expect(() =>
            recordingWindow(
                '2026-10-08T20:55',
                '',
                '2026-10-08T20:45',
                '',
                now,
                3600,
            ),
        ).toThrow();
        expect(() =>
            recordingWindow(
                '2026-10-08T20:00',
                '',
                '2026-10-08T21:15',
                '',
                now,
                3600,
            ),
        ).toThrow();
    });
    it('requires an explicit repeated-clock choice and rejects missing daylight-saving time', () => {
        const now = Date.parse('2026-10-08T08:00:00Z');
        expect(() =>
            recordingWindow(
                '2026-04-05T02:30',
                '',
                '2026-04-05T03:15',
                '',
                now,
                7200,
            ),
        ).toThrow();
        expect(
            recordingWindow(
                '2026-04-05T02:30',
                '+13:00',
                '2026-04-05T03:15',
                '',
                now,
                7200,
            ).requested_start_at,
        ).toBe('2026-04-04T13:30:00Z');
        expect(
            recordingWindow(
                '2026-04-05T02:30',
                '+12:00',
                '2026-04-05T03:15',
                '',
                now,
                7200,
            ).requested_start_at,
        ).toBe('2026-04-04T14:30:00Z');
        expect(() =>
            recordingWindow(
                '2026-09-27T02:30',
                '',
                '2026-09-27T03:15',
                '',
                now,
                3600,
            ),
        ).toThrow();
    });
    it('does not accept a different receipt as recovery of the reviewed request', () => {
        const detail = recordingDetailFixture(),
            r = detail.reference,
            request = {
                expected_actor_id: 7,
                request_key: '22222222-2222-4222-8222-222222222222',
                expected_source_revision: 2,
                requested_start_at: r.requested_start_at,
                requested_end_at: r.requested_end_at,
                purpose: r.purpose,
            };
        expect(matchesRecordingRequest(detail, request, 43)).toBe(true);
        expect(
            matchesRecordingRequest(
                detail,
                { ...request, purpose: 'A different investigation purpose.' },
                43,
            ),
        ).toBe(false);
        detail.reference.created_by.id = 18;
        expect(matchesRecordingRequest(detail, request, 43)).toBe(false);
    });
});
