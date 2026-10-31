import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { InjuryPeoplePicker } from './injury-people-picker';

const io = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get } }));
vi.mock('@/components/health-safety/hazard-staff-picker', () => ({ HazardStaffPicker: (props: {
    id: string; value: string; onChange: (value: string) => void; disabled: boolean;
    options: { value: string; label: string }[]; listLabel: string;
}) => <select id={props.id} aria-label={props.listLabel} value={props.value} disabled={props.disabled} onChange={(event) => props.onChange(event.target.value)}>
    <option value="">Choose a person</option>{props.options.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
</select> }));
const reply = (page = 1, hasMore = true) => ({ data: { injury_id: 9, expected_version: 4,
    coordinators: [{ id: page === 1 ? 101 : 202, name: page === 1 ? 'First page owner' : 'Later page owner' }],
    coordinator_picker: { search: '', page, per_page: 100, has_more: hasMore, next_page: hasMore ? page + 1 : null } } });
const tick = async () => act(async () => { await vi.advanceTimersByTimeAsync(250); });
beforeEach(() => { vi.clearAllMocks(); vi.useFakeTimers(); });
afterEach(() => { cleanup(); vi.useRealTimers(); });

it('reaches a later eligible owner page through the real server boundary and returns the selected person label', async () => {
    io.get.mockResolvedValueOnce(reply()).mockResolvedValueOnce(reply(2, false));
    const changed = vi.fn(); const selected = vi.fn();
    render(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="" onChange={changed} onSelection={selected} injuryId={9} version={4} />);
    await tick(); fireEvent.click(screen.getByRole('button', { name: 'More choices' })); await tick();
    expect(io.get.mock.calls[1][1].params).toEqual({ search: '', page: 2 });
    fireEvent.change(screen.getByRole('combobox', { name: 'Incident owner' }), { target: { value: '202' } });
    expect(changed).toHaveBeenCalledWith('202'); expect(selected).toHaveBeenCalledWith({ id: 202, name: 'Later page owner' });
    expect(screen.getByRole('button', { name: 'More choices' })).toBeDisabled();
});

it('ignores a late search response after the user changes the search instead of replacing current authorised choices', async () => {
    let resolveOld: (value: unknown) => void = () => {};
    io.get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; })).mockResolvedValueOnce({ data: { ...reply().data,
        coordinators: [{ id: 203, name: 'Current matching owner' }], coordinator_picker: { ...reply().data.coordinator_picker, search: 'Current' } } });
    render(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="" onChange={vi.fn()} injuryId={9} version={4} />);
    await tick(); fireEvent.change(screen.getByRole('textbox', { name: 'Search incident owner' }), { target: { value: 'Current' } }); await tick();
    await act(async () => resolveOld(reply()));
    expect(screen.getByRole('option', { name: 'Current matching owner' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'First page owner' })).not.toBeInTheDocument();
});

it('rejects contradictory pagination and changed source versions without clearing the parent selection or enabling saving choices', async () => {
    io.get.mockResolvedValueOnce({ data: { ...reply().data, coordinator_picker: { ...reply().data.coordinator_picker, next_page: null } } });
    const changed = vi.fn(); const { rerender } = render(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="101" onChange={changed} injuryId={9} version={4} />);
    await tick(); expect(screen.getByRole('alert')).toHaveTextContent('couldn’t load the list'); expect(changed).not.toHaveBeenCalled();
    io.get.mockResolvedValueOnce({ data: { ...reply().data, expected_version: 6 } });
    fireEvent.click(screen.getByRole('button', { name: 'Search again' })); await tick();
    expect(screen.getByRole('combobox', { name: 'Incident owner' })).toBeDisabled(); expect(changed).not.toHaveBeenCalled();
    rerender(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="101" onChange={changed} injuryId={9} version={6} />);
    io.get.mockResolvedValueOnce({ data: { ...reply().data, expected_version: 6 } }); await tick();
    expect(screen.getByRole('combobox', { name: 'Incident owner' })).not.toBeDisabled();
});

it('waits for an actual Site before searching capture choices', async () => {
    render(<InjuryPeoplePicker id="worker" label="Injured worker" endpoint="/health-safety/injuries/capture-options" group="workers" value="" onChange={vi.fn()} siteId="" />);
    await tick(); expect(io.get).not.toHaveBeenCalled(); expect(screen.getByRole('combobox', { name: 'Injured worker' })).toBeDisabled();
});

it('filters correction choices by the selected Site while checking the unchanged canonical injury identity', async () => {
    io.get.mockResolvedValueOnce({ data: { ...reply().data, canonical_site_id: 4, site_id: 8 } });
    const changed = vi.fn();
    const { rerender } = render(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="" onChange={changed} injuryId={9} version={4} siteId="8" canonicalSiteId={4} />);
    await tick(); expect(io.get.mock.calls[0][1].params).toEqual({ search: '', page: 1, site_id: '8' });
    expect(screen.getByRole('combobox', { name: 'Incident owner' })).not.toBeDisabled();
    io.get.mockResolvedValueOnce({ data: { ...reply().data, canonical_site_id: 8, site_id: 10 } });
    rerender(<InjuryPeoplePicker id="owner" label="Incident owner" endpoint="/health-safety/injuries/9/command-options" group="coordinators" value="101" onChange={changed} injuryId={9} version={4} siteId="10" canonicalSiteId={4} />);
    await tick(); expect(screen.getByRole('alert')).toHaveTextContent('couldn’t load the list');
    expect(changed).not.toHaveBeenCalled(); expect(screen.getByRole('combobox', { name: 'Incident owner' })).toBeDisabled();
});
