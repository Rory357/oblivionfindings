import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    RosterWorkspaceRail,
    RosterWorkspaceViewSelect,
    type RosterView,
} from './roster-workspace-navigation';

vi.mock('@/components/page', () => ({
    PageHeaderRail: ({ items, value, onSelect }: {
        items: { key: string; label: string }[];
        value: string;
        onSelect: (key: string) => void;
    }) => <div role="tablist">
        {items.map((item) => <button key={item.key} role="tab"
            aria-selected={item.key === value} onClick={() => onSelect(item.key)}>
            {item.label}
        </button>)}
    </div>,
    PageHeaderFilterSelect: ({ label, options, onChange }: {
        label: string;
        options: { value: string; label: string }[];
        onChange: (value: string) => void;
    }) => <div role="group" aria-label={label}>
        {options.map((option) => <button key={option.value}
            onClick={() => onChange(option.value)}>{option.label}</button>)}
    </div>,
}));

afterEach(cleanup);

const views = [
    { key: 'shifts', label: 'Shifts', count: 12 },
    { key: 'calendar', label: 'Calendar' },
    { key: 'open', label: 'Open shifts', count: 3 },
    { key: 'coverage', label: 'Coverage' },
    { key: 'timeoff', label: 'Time off' },
    { key: 'availability', label: 'Availability' },
    { key: 'capacity', label: 'Capacity heatmap' },
    { key: 'analytics', label: 'Analytics' },
    { key: 'templates', label: 'Templates' },
    { key: 'recurring', label: 'Recurring' },
].map((item) => ({ ...item, key: item.key as RosterView, icon: CalendarDays }));

function Workspace({ initial, onChange }: {
    initial: RosterView;
    onChange: (view: RosterView) => void;
}) {
    const [value, setValue] = useState(initial);
    const select = (next: RosterView) => {
        onChange(next);
        setValue(next);
    };
    return <>
        <RosterWorkspaceRail value={value} views={views} onSelect={select} />
        <RosterWorkspaceViewSelect value={value} views={views} onSelect={select} />
    </>;
}

describe('Rostering workspace navigation', () => {
    it('retains a Calendar bookmark as the active Roster view', () => {
        const change = vi.fn();
        render(<Workspace initial="calendar" onChange={change} />);
        expect(screen.getByRole('tab', { name: 'Roster' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('group', { name: 'View: Calendar' })).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: 'Roster' }));
        expect(change).not.toHaveBeenCalled();
    });

    it('keeps the original coverage callback and open-shift count without summing conflicts into it', () => {
        const change = vi.fn();
        render(<Workspace initial="calendar" onChange={change} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Cover & conflicts' }));
        expect(change).toHaveBeenLastCalledWith('open');
        expect(screen.getByRole('button', { name: 'Open shifts · 3' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Coverage' }));
        expect(change).toHaveBeenLastCalledWith('coverage');
        change.mockClear();
        fireEvent.click(screen.getByRole('tab', { name: 'Cover & conflicts' }));
        expect(change).not.toHaveBeenCalled();
    });

    it('returns the original Availability and Time off IDs to the existing read boundary', () => {
        const change = vi.fn();
        render(<Workspace initial="shifts" onChange={change} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Availability & leave' }));
        expect(change).toHaveBeenLastCalledWith('availability');
        fireEvent.click(screen.getByRole('button', { name: 'Time off' }));
        expect(change).toHaveBeenLastCalledWith('timeoff');
    });

    it('retains both template and recurring lazy-read IDs', () => {
        const change = vi.fn();
        render(<Workspace initial="shifts" onChange={change} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Patterns' }));
        expect(change).toHaveBeenLastCalledWith('templates');
        fireEvent.click(screen.getByRole('button', { name: 'Recurring' }));
        expect(change).toHaveBeenLastCalledWith('recurring');
    });

    it('retains capacity and analytics as separate report views', () => {
        const change = vi.fn();
        render(<Workspace initial="shifts" onChange={change} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Reports' }));
        expect(change).toHaveBeenLastCalledWith('capacity');
        fireEvent.click(screen.getByRole('button', { name: 'Analytics' }));
        expect(change).toHaveBeenLastCalledWith('analytics');
    });

    it('does not offer sections whose views were not supplied by the owning page', () => {
        render(<RosterWorkspaceRail value="calendar"
            views={views.filter((item) => item.key === 'calendar')}
            onSelect={vi.fn()} />);
        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(screen.queryByRole('tab', { name: 'Patterns' })).toBeNull();
    });

    it('opens the canonical conflict queue without inventing a new roster view', () => {
        const select = vi.fn();
        const conflicts = vi.fn();
        render(
            <RosterWorkspaceViewSelect
                value="open"
                views={views}
                onSelect={select}
                onOpenConflicts={conflicts}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Conflict queue' }));
        expect(conflicts).toHaveBeenCalledOnce();
        expect(select).not.toHaveBeenCalled();
    });

    it('offers the full list only when the owning page supplies its permitted route callback', () => {
        const select = vi.fn();
        const list = vi.fn();
        const view = render(
            <RosterWorkspaceViewSelect value="shifts" views={views} onSelect={select} />,
        );
        expect(screen.queryByRole('button', { name: 'Full shift list' })).toBeNull();
        view.rerender(
            <RosterWorkspaceViewSelect value="shifts" views={views}
                onSelect={select} onOpenShiftList={list} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Full shift list' }));
        expect(list).toHaveBeenCalledOnce();
        expect(select).not.toHaveBeenCalled();
    });

    it('retains the independent Qualifications route without changing a roster view ID', () => {
        const select = vi.fn();
        const qualifications = vi.fn();
        render(
            <RosterWorkspaceViewSelect value="coverage" views={views}
                onSelect={select} onOpenQualifications={qualifications} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Qualifications' }));
        expect(qualifications).toHaveBeenCalledOnce();
        expect(select).not.toHaveBeenCalled();
    });
});
