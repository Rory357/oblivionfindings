import type {
    ConsultationRow,
    MeetingRow,
    RepRow,
} from '@/components/worker-participation/shared';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { Eye, GraduationCap, Upload } from 'lucide-react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ParticipationCards,
    ParticipationRowMenu,
    participationMenu,
} from './participation-cards';

vi.mock('@inertiajs/react', () => ({
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
afterEach(cleanup);
const rep: RepRow = {
    id: 11,
    user: { id: 4, name: 'Alex Representative' },
    site: { id: 3, name: 'House Three' },
    work_group: 'Night team',
    election_method: 'elected',
    elected_at: '2026-10-01',
    training_days_completed: 1,
    status: 'active',
};
const meeting: MeetingRow = {
    id: 22,
    committee: { id: 2, name: 'Safety committee' },
    scheduled_at: '2026-10-12T12:30:00.000000Z',
    status: 'completed',
    attendees_count: 3,
    minutes_document_path: null,
    actions_due_count: 2,
};
const consultation: ConsultationRow = {
    id: 33,
    title: 'Safer transport',
    consultation_type: 'policy',
    consultation_date: '2026-10-03',
    status: 'feedback_received',
    site: { id: 3, name: 'House Three' },
    document_path: 'private/consultation.pdf',
    outcome_document_path: null,
    worker_feedback_summary: 'Private feedback body',
    outcome: 'Private outcome body',
};

describe('Participation mobile cards and shared row menus', () => {
    it('flags missing Site ownership without asserting that the record is intentionally organisation-wide', () => {
        render(
            <ParticipationCards
                kind="meeting"
                rows={[{ ...meeting, site_ownership: 'unconfirmed' }]}
                actionsFor={() => []}
                onOpen={vi.fn()}
                onContextMenu={vi.fn()}
            />,
        );
        expect(
            screen.getByText('Site ownership not confirmed'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Organisation-wide committee'),
        ).not.toBeInTheDocument();
    });
    it('preserves representative facts and uses an independent touch-sized View action', () => {
        const open = vi.fn();
        render(
            <ParticipationCards
                kind="representative"
                rows={[rep]}
                actionsFor={() => []}
                onOpen={open}
                onContextMenu={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('list', { name: 'Representative records' }),
        ).toHaveClass('md:hidden');
        for (const text of [
            'Alex Representative',
            'House Three',
            'Night team',
            'Elected',
            'Elected 1 Oct 2026',
            '1 training day recorded',
        ])
            expect(screen.getByText(text)).toBeInTheDocument();
        fireEvent.click(screen.getByText('Night team'));
        expect(open).not.toHaveBeenCalled();
        const button = screen.getByRole('button', {
            name: 'View representative: Alex Representative',
        });
        expect(button).toHaveClass('frontline-tap', 'frontline-focus');
        fireEvent.click(button);
        expect(open).toHaveBeenCalledExactlyOnceWith(11);
        expect(
            screen.queryByText(/compliant|training complete/i),
        ).not.toBeInTheDocument();
    });
    it('shows scheduled NZ time, listed attendees, unfinished actions and missing minutes without overclaims', () => {
        render(
            <ParticipationCards
                kind="meeting"
                rows={[meeting]}
                actionsFor={() => []}
                onOpen={vi.fn()}
                onContextMenu={vi.fn()}
            />,
        );
        for (const text of [
            '13 October 2026, 1:30 am',
            '3 attendees listed',
            '2 actions still to do',
            'No minutes uploaded',
        ])
            expect(screen.getByText(text)).toBeInTheDocument();
        expect(
            screen.queryByText(/confirmed|overdue|due now/i),
        ).not.toBeInTheDocument();
    });
    it('keeps consultation stage and document presence visible without adding private body fields', () => {
        render(
            <ParticipationCards
                kind="consultation"
                rows={[consultation]}
                actionsFor={() => []}
                onOpen={vi.fn()}
                onContextMenu={vi.fn()}
            />,
        );
        for (const text of [
            'Safer transport',
            'Stage 2 of 4',
            'Consultation document added',
            'Outcome document not added yet',
            '3 Oct 2026',
        ])
            expect(screen.getByText(text)).toBeInTheDocument();
        expect(
            screen.queryByText('Private feedback body'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private outcome body'),
        ).not.toBeInTheDocument();
    });
    it('does not present an unrecognised consultation status as Open or as stage one', () => {
        render(
            <ParticipationCards
                kind="consultation"
                rows={[{ ...consultation, status: 'awaiting_external_review' }]}
                actionsFor={() => []}
                onOpen={vi.fn()}
                onContextMenu={vi.fn()}
            />,
        );
        expect(
            screen.getByText('Awaiting external review'),
        ).toBeInTheDocument();
        expect(screen.getByText('Stage not known')).toBeInTheDocument();
        expect(screen.queryByText('Stage 1 of 4')).not.toBeInTheDocument();
        expect(screen.queryByText('Open')).not.toBeInTheDocument();
    });
    it('routes right-click to the same original record and does not open its detail', () => {
        const context = vi.fn();
        const open = vi.fn();
        render(
            <ParticipationCards
                kind="meeting"
                rows={[meeting]}
                actionsFor={() => []}
                onOpen={open}
                onContextMenu={context}
            />,
        );
        fireEvent.contextMenu(screen.getByText('Safety committee'), {
            clientX: 20,
            clientY: 30,
        });
        expect(context).toHaveBeenCalledOnce();
        expect(context.mock.calls[0].slice(1)).toEqual([
            'meeting',
            meeting,
            'Held',
            'Safety committee',
        ]);
        expect(open).not.toHaveBeenCalled();
    });
    it('opens the actual keyboard menu and invokes the original training callback only once', () => {
        const open = vi.fn();
        const training = vi.fn();
        render(
            <ParticipationCards
                kind="representative"
                rows={[rep]}
                onOpen={open}
                onContextMenu={vi.fn()}
                actionsFor={() => [
                    {
                        icon: <Eye />,
                        label: 'View representative',
                        onClick: open,
                    },
                    { sep: true },
                    {
                        icon: <GraduationCap />,
                        label: 'Record training',
                        onClick: training,
                    },
                ]}
            />,
        );
        const trigger = screen.getByRole('button', {
            name: 'Actions for Alex Representative',
        });
        expect(trigger).toHaveClass('frontline-tap');
        fireEvent.keyDown(trigger, { key: 'Enter' });
        const item = within(screen.getByRole('menu')).getByRole('menuitem', {
            name: 'Record training',
        });
        expect(item).toHaveClass('frontline-tap');
        fireEvent.click(item);
        expect(training).toHaveBeenCalledOnce();
        expect(open).not.toHaveBeenCalled();
    });
    it('uses supplied read-only actions in the desktop menu and preserves dangerous callbacks through the shared adapter', () => {
        const viewed = vi.fn();
        const closed = vi.fn();
        render(
            <ParticipationRowMenu
                kind="consultation"
                row={consultation}
                name={consultation.title}
                actionsFor={() => [
                    {
                        icon: <Eye />,
                        label: 'View consultation',
                        onClick: viewed,
                    },
                ]}
            />,
        );
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for Safer transport' }),
            { key: 'Enter' },
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Upload document' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View consultation' }),
        );
        expect(viewed).toHaveBeenCalledOnce();
        const actions = participationMenu([
            { sep: true },
            {
                icon: <Upload />,
                label: 'Close consultation',
                tone: 'critical',
                onClick: closed,
            },
            { sep: true },
        ]);
        expect(actions).toHaveLength(1);
        expect(actions[0].danger).toBe(true);
        actions[0].onClick?.();
        expect(closed).toHaveBeenCalledOnce();
    });
});
