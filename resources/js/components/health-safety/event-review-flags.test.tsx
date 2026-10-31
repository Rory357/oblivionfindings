import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), data: { note: '' } as { note: string }, setData: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    useForm: () => ({
        data: inertia.data,
        setData: (key: 'note', value: string) => { inertia.data[key] = value; inertia.setData(key, value); },
        post: inertia.post, processing: false, errors: {}, reset: inertia.reset, clearErrors: inertia.clearErrors,
    }),
}));
import { EventReviewFlags, type EventReviewFlag } from './event-review-flags';

const flag = (over: Partial<EventReviewFlag> = {}): EventReviewFlag => ({ id: 5, reason: 'worksafe_review', source_revision: 2,
    from_severity: 'medium', to_severity: 'high', raised_at: '2026-10-12T01:00:00Z', can_resolve: true, ...over });
afterEach(() => { cleanup(); inertia.post.mockReset(); inertia.data.note = ''; });

it('says the WorkSafe decision was not changed and offers a new decision only when one can be recorded', () => {
    const { rerender } = render(<EventReviewFlags eventId={9} flags={[flag()]} canDecideWorksafe />);
    expect(screen.getByText('Check the WorkSafe decision again')).toBeInTheDocument();
    expect(screen.getByText(/The decision hasn’t been changed\. Check it still stands, or record a new decision\./)).toBeInTheDocument();
    expect(screen.getByText(/Its severity went from medium to high\./)).toBeInTheDocument();
    rerender(<EventReviewFlags eventId={9} flags={[flag()]} canDecideWorksafe={false} />);
    expect(screen.queryByText(/record a new decision/)).not.toBeInTheDocument();
});

it('keeps a closed event closed and asks for what was checked', () => {
    render(<EventReviewFlags eventId={9} flags={[flag({ reason: 'escalated_after_close', from_severity: 'low' })]} canDecideWorksafe={false} />);
    expect(screen.getByText('Severity rose after this event was closed')).toBeInTheDocument();
    expect(screen.getByText(/The event stays closed\. Record what you checked and what happens next\./)).toBeInTheDocument();
});

it('marks a flag reviewed with a note, and shows no action to people who cannot', () => {
    const { rerender } = render(<EventReviewFlags eventId={9} flags={[flag()]} canDecideWorksafe />);
    fireEvent.click(screen.getByRole('button', { name: 'Mark reviewed' }));
    fireEvent.change(screen.getByLabelText(/What did you check and decide\?/), { target: { value: 'Checked: the decision still stands.' } });
    rerender(<EventReviewFlags eventId={9} flags={[flag()]} canDecideWorksafe />);
    fireEvent.click(screen.getAllByRole('button', { name: 'Mark reviewed' }).at(-1)!);
    expect(inertia.post).toHaveBeenCalledWith('/health-safety/events/9/review-flags/5/resolve', expect.objectContaining({ preserveScroll: true }));
    cleanup();
    render(<EventReviewFlags eventId={9} flags={[flag({ can_resolve: false })]} canDecideWorksafe />);
    expect(screen.queryByRole('button', { name: 'Mark reviewed' })).not.toBeInTheDocument();
});

it('renders nothing without open flags', () => {
    const { container } = render(<EventReviewFlags eventId={9} flags={[]} canDecideWorksafe />);
    expect(container).toBeEmptyDOMElement();
});
