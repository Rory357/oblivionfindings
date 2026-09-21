import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { ZoneDraft } from './types';
import ZoneDraftDialog from './zone-draft-dialog';

vi.mock('./client-location-map', () => ({
    default: () => <div>Interactive map</div>,
}));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const draft: ZoneDraft = {
    id: 4,
    revision: 2,
    status: 'draft',
    name: 'Library',
    purpose: 'Agreed visits',
    classification: 'agreed',
    geometry_source: 'custom',
    geometry: {
        type: 'circle',
        center: { lat: -36.85, lng: 174.76 },
        radius_m: 80,
    },
    schedule: {
        timezone: 'Pacific/Auckland',
        weekdays: [1, 2],
        start: '09:00',
        end: '16:00',
        following_day: false,
        first_date: '2026-09-21',
        last_date: '2026-10-20',
        exception_dates: [],
    },
    response_proposal: null,
    saved_at: '2026-09-21T00:00:00Z',
};
const props = {
    center: { lat: -36.85, lng: 174.76 },
    draft,
    boundaries: [],
    url: '/zones',
    fingerprint: 'current',
    onClose: vi.fn(),
    onSaved: vi.fn(),
    onAccessEnded: vi.fn(),
};

it('preserves a failed save and reuses the same idempotency key on retry', async () => {
    const fetchMock = vi
        .fn()
        .mockRejectedValueOnce(new Error('Offline'))
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ zone: draft }),
        });
    vi.stubGlobal('fetch', fetchMock);
    const saved = vi.fn();
    render(<ZoneDraftDialog {...props} onSaved={saved} />);
    fireEvent.click(screen.getByRole('button', { name: /Review & save/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Offline');
    expect(screen.getByText('Library')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    await waitFor(() => expect(saved).toHaveBeenCalledWith(draft));
    expect(JSON.parse(fetchMock.mock.calls[0][1].body).idempotency_key).toBe(
        JSON.parse(fetchMock.mock.calls[1][1].body).idempotency_key,
    );
    expect(JSON.parse(fetchMock.mock.calls[1][1].body).expected_revision).toBe(
        2,
    );
});

it('requires a boundary and supports keyboard-friendly rectangle undo and redo', () => {
    render(<ZoneDraftDialog {...props} draft={null} />);
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Draw a boundary');
    fireEvent.click(screen.getByRole('button', { name: 'Start rectangle' }));
    expect(screen.getByText(/4 corners/)).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'Undo boundary change' }),
    );
    expect(screen.queryByText(/4 corners/)).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Redo boundary change' }),
    );
    expect(screen.getByText(/4 corners/)).toBeVisible();
});

it('keeps dirty edits until the user explicitly discards them', () => {
    const close = vi.fn();
    render(<ZoneDraftDialog {...props} onClose={close} />);
    fireEvent.change(screen.getByLabelText('Radius (metres)'), {
        target: { value: '120' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('alertdialog')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(close).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Radius (metres)')).toHaveValue(120);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(close).toHaveBeenCalledOnce();
});

it.each(['changed', 'unavailable'])(
    'blocks implicit conversion of a %s linked source',
    async (state) => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ zone: draft }),
        });
        vi.stubGlobal('fetch', fetchMock);
        render(
            <ZoneDraftDialog
                {...props}
                draft={{
                    ...draft,
                    geometry_source: 'canonical',
                    canonical_geofence_id: 9,
                    canonical_geometry_hash: 'old-hash',
                }}
                boundaries={
                    state === 'changed'
                        ? [
                              {
                                  id: 9,
                                  name: 'Changed site boundary',
                                  hash: 'new-hash',
                                  geometry: {
                                      type: 'circle',
                                      center: { lat: -36.85, lng: 174.76 },
                                      radius_m: 120,
                                  },
                              },
                          ]
                        : []
                }
            />,
        );
        expect(screen.getByText('Linked boundary needs review')).toBeVisible();
        expect(screen.getByLabelText('Radius (metres)')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: /Review & save/ }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Review the changed or unavailable linked boundary',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use saved shape as a custom draft',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: /Review & save/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({
            geometry_source: 'custom',
            source_change_reviewed: true,
            geometry: draft.geometry,
        });
    },
);

it('requires an explicit selection to use a revised canonical geometry', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ zone: draft }),
    });
    vi.stubGlobal('fetch', fetchMock);
    render(
        <ZoneDraftDialog
            {...props}
            draft={{
                ...draft,
                geometry_source: 'canonical',
                canonical_geofence_id: 9,
                canonical_geometry_hash: 'old-hash',
            }}
            boundaries={[
                {
                    id: 9,
                    name: 'Changed site boundary',
                    hash: 'new-hash',
                    geometry: draft.geometry,
                },
            ]}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review current site boundary' }),
    );
    expect(
        screen.getByRole('button', { name: 'Linked: Changed site boundary' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: /Review & save/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({
        geometry_source: 'canonical',
        canonical_geofence_id: 9,
        canonical_geometry_hash: 'new-hash',
        source_change_reviewed: true,
    });
});
