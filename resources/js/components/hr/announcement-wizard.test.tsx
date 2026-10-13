import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { AnnouncementWizard } from './announcement-wizard';
const { post, transformed } = vi.hoisted(() => ({
    post: vi.fn(),
    transformed: { value: {} as Record<string, unknown> },
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((old) => ({ ...old, [key]: value })),
                transform: (
                    callback: (
                        data: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformed.value = callback(data);
                },
                post,
                reset: () => setData(initial),
                clearErrors: vi.fn(),
            };
        },
    };
});
vi.mock('@/lib/confetti', () => ({ fireConfetti: vi.fn() }));
beforeEach(() => {
    post.mockReset();
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => ({ count: 1 }) }),
    );
});
afterEach(() => vi.unstubAllGlobals());
const props = {
    open: true,
    onClose: vi.fn(),
    segments: { all_count: 1, sites: [], departments: [], roles: [] },
    initial: {
        title: 'Private update',
        content: 'A draft for review',
        status: 'draft',
        targets: [{ type: 'all' as const, value: null }],
    },
};
it('saving a new draft confirms privacy instead of claiming it was delivered', () => {
    render(<AnnouncementWizard {...props} />);
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(transformed.value.intent).toBe('draft');
    act(() => post.mock.calls[0][1].onSuccess());
    expect(
        screen.getByRole('heading', { name: 'Draft saved' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/It has not been sent/)).toBeInTheDocument();
    expect(screen.queryByText(/Delivered to/)).not.toBeInTheDocument();
});
it('editing a private draft retains a save draft action and labels publishing explicitly', () => {
    render(<AnnouncementWizard {...props} announcementId={4} />);
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    expect(screen.getByRole('button', { name: 'Publish' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(transformed.value).toMatchObject({
        intent: 'draft',
        _method: 'put',
    });
    expect(post.mock.calls[0][0]).toBe('/hr/announcements/4');
});

it('preserves individually selected colleagues and the exact schedule when editing', () => {
    render(
        <AnnouncementWizard
            {...props}
            announcementId={4}
            segments={{
                ...props.segments,
                people: [{ key: '12', label: 'Aroha', count: 1 }],
            }}
            initial={{
                ...props.initial,
                targets: [{ type: 'user', value: '12' }],
                status: 'scheduled',
                published_at: '2099-01-19T21:00:45Z',
                expires_at: '2099-01-20T10:59:59Z',
            }}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /Audience.*Who receives/ }),
    );
    expect(
        screen.getByRole('button', { name: 'Remove Aroha from recipients' }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    fireEvent.click(screen.getByRole('button', { name: /^Schedule$/ }));
    expect(transformed.value).toMatchObject({
        targets: [{ type: 'user', value: '12' }],
        published_at: '2099-01-19T21:00:45Z',
        expires_at: '2099-01-20T10:59:59Z',
    });
});

it('allows an editor to select one named colleague without widening to all staff', () => {
    render(
        <AnnouncementWizard
            {...props}
            segments={{
                ...props.segments,
                people: [
                    { key: '12', label: 'Aroha', count: 1 },
                    { key: '13', label: 'Noah', count: 1 },
                ],
            }}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /Audience.*Who receives/ }),
    );
    fireEvent.click(screen.getByRole('button', { name: /All staff/ }));
    fireEvent.change(
        screen.getByRole('textbox', { name: 'Search individual recipients' }),
        { target: { value: 'Aroha' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add Aroha' }));
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    expect(screen.getByText('Aroha')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(transformed.value.targets).toEqual([{ type: 'user', value: '12' }]);
});

it('never submits a past or missing scheduled time as an immediate publication', () => {
    render(
        <AnnouncementWizard
            {...props}
            initial={{
                ...props.initial,
                status: 'scheduled',
                published_at: '2020-01-01T00:00:00Z',
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    fireEvent.click(screen.getByRole('button', { name: /^Schedule$/ }));
    expect(post).not.toHaveBeenCalled();
    expect(
        screen.getByText(
            'Choose a future publication time, or choose Publish now.',
        ),
    ).toBeInTheDocument();
});

it('reopens a planned draft in scheduled mode and retains an explicit bell opt out', () => {
    render(
        <AnnouncementWizard
            {...props}
            announcementId={4}
            initial={{
                ...props.initial,
                published_at: '2030-10-20T21:00:00Z',
                priority: 'urgent',
                push_to_bell: false,
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    expect(
        screen.getByRole('button', { name: 'Schedule' }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(transformed.value).toMatchObject({
        intent: 'draft',
        published_at: '2030-10-20T21:00:00Z',
        push_to_bell: false,
    });
});

it('keeps the publication receipt correct when reloaded props become published', () => {
    const view = render(<AnnouncementWizard {...props} announcementId={4} />);
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Publish' }));
    view.rerender(
        <AnnouncementWizard
            {...props}
            announcementId={4}
            initial={{ ...props.initial, status: 'published' }}
        />,
    );
    act(() => post.mock.calls[0][1].onSuccess());
    expect(
        screen.getByRole('heading', { name: 'Announcement published' }),
    ).toBeInTheDocument();
});

it('lets an editor stop repeats on a published notice and makes the effect explicit in Review', () => {
    render(
        <AnnouncementWizard
            {...props}
            announcementId={4}
            initial={{
                ...props.initial,
                status: 'published',
                recurrence: 'weekly',
                recurrence_ends_at: '2030-10-20T10:59:59Z',
                published_at: '2026-10-01T21:00:00Z',
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: /Delivery.*Schedule/ }));
    fireEvent.change(screen.getByRole('combobox', { name: 'Repeats' }), {
        target: { value: '' },
    });
    fireEvent.click(screen.getByRole('button', { name: /Review.*Confirm/ }));
    expect(screen.getByText('Stops upcoming repeats')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(transformed.value).toMatchObject({
        recurrence: null,
        recurrence_ends_at: null,
    });
});
