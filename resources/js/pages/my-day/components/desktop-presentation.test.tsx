import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { TooltipProvider } from '@/components/ui/tooltip';

import { DigestPanel } from './digest-panel';
import { MyDayHeader, type MyDayView, type WorkFilter } from './my-day-header';
import { RecordCareActions } from './record-care-actions';
import { ShiftSummary } from './shift-summary';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { visit: vi.fn() },
}));
vi.mock('@/hooks/use-my-day-labels', () => ({
    useMyDayLabels: () => (key: string) => key,
}));

const people = ['Mere Wilson', 'James Lee', 'Casey Taylor'].map(
    (name, index) => ({
        id: index + 1,
        name,
        first_name: name.split(' ')[0],
        initials: name[0],
        hue: 277,
        photo_url: null,
    }),
);

function Header({ hasShift = true }: { hasShift?: boolean }) {
    const [person, setPerson] = useState<'all' | number>('all');
    const [workFilter, setWorkFilter] = useState<WorkFilter>('all');
    const [view, setView] = useState<MyDayView>('today');
    return (
        <TooltipProvider>
            <MyDayHeader
                dateLabel="Sunday 13 September"
                siteName="Rimu House"
                shiftLabel="7:00 am – 3:00 pm"
                clockedIn
                hasShift={hasShift}
                onBreak={false}
                residents={hasShift ? people : []}
                person={person}
                onPerson={setPerson}
                search=""
                onSearch={vi.fn()}
                workFilter={workFilter}
                onWorkFilter={setWorkFilter}
                view={view}
                onView={setView}
                taskTotal={3}
                taskDone={1}
                medTotal={0}
                medRecorded={0}
                attention={0}
                unreadHandover={false}
                canAdd={hasShift}
                onAdd={vi.fn()}
            />
        </TooltipProvider>
    );
}

describe('My Day desktop presentation', () => {
    it('lets a worker choose a named person and work type directly, with the selection visible', () => {
        render(<Header />);
        const forPeople = within(
            screen.getByRole('group', { name: 'People to show' }),
        );
        fireEvent.click(forPeople.getByRole('button', { name: 'James Lee' }));
        expect(
            forPeople.getByRole('button', { name: 'James Lee' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            forPeople.getByRole('button', { name: 'Everyone' }),
        ).toHaveAttribute('aria-pressed', 'false');
        const work = within(
            screen.getByRole('group', { name: 'Work to show' }),
        );
        fireEvent.click(work.getByRole('button', { name: 'Support tasks' }));
        expect(
            work.getByRole('button', { name: 'Support tasks' }),
        ).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(screen.getByRole('tab', { name: 'Handover' }));
        expect(
            screen.queryByRole('group', { name: 'People to show' }),
        ).not.toBeInTheDocument();
    });

    it('explains a clock-in without a current rostered shift and hides unusable filters', () => {
        render(<Header hasShift={false} />);
        expect(screen.getByText('Clocked in')).toBeVisible();
        expect(screen.queryByText('On shift')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('group', { name: 'People to show' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Your own shifts and care work appear here when you are rostered.',
            ),
        ).toBeVisible();
    });

    it('shows incoming notes and the read action without a second set of tabs', () => {
        const read = vi.fn();
        render(
            <DigestPanel
                handover={{
                    id: 9,
                    unread: true,
                    summary: 'Transport arrives at 11:30 am.',
                    follow_ups: [
                        {
                            key: 'a',
                            label: 'Prepare the bag',
                            task_id: 21,
                            is_completed: false,
                            can_add: false,
                        },
                    ],
                }}
                alertTasks={[]}
                incidents={[]}
                notifications={[]}
                onConfirmHandoverRead={read}
            />,
        );
        expect(
            screen.getByText('Transport arrives at 11:30 am.'),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Open task' })).toBeVisible();
        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'I’ve read this handover' }),
        );
        expect(read).toHaveBeenCalledOnce();
    });

    it('asks who a daily note is for before opening a person-specific record', () => {
        const note = vi.fn();
        render(
            <RecordCareActions
                people={people}
                selectedPerson="all"
                canRecordObservation
                onNote={note}
                onMeal={vi.fn()}
                onObservation={vi.fn()}
                onIncident={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Daily note' }));
        expect(note).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Casey Taylor',
            }),
        );
        expect(note).toHaveBeenCalledWith(3);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps break, finish and timesheet actions connected in the shift card', () => {
        const pause = vi.fn(),
            finish = vi.fn(),
            review = vi.fn();
        render(
            <ShiftSummary
                clockedIn
                onBreak={false}
                hasShift={false}
                elapsed="1h 20m"
                canClock
                canReviewTime
                onClock={finish}
                onToggleBreak={pause}
                onReviewTime={review}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Take a break' }));
        fireEvent.click(screen.getByRole('button', { name: 'Finish shift' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Review my timesheet' }),
        );
        expect(pause).toHaveBeenCalledOnce();
        expect(finish).toHaveBeenCalledOnce();
        expect(review).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('link', { name: 'View my roster' }),
        ).toHaveAttribute('href', '/my-calendar');
    });
});
