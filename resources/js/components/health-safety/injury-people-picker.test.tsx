import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { InjuryPeoplePicker } from './injury-people-picker';

// Readiness audit F1 (6 Nov 2026): a Site with no staff profiles gave an empty list and no reason.
const io = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get } }));
afterEach(() => { cleanup(); io.get.mockReset(); });

const empty = (search = '') => ({ data: { workers: [], worker_picker: { page: 1, search, per_page: 100, has_more: false, next_page: null } } });

it('says why the injured-worker list is empty', async () => {
    io.get.mockResolvedValue(empty());
    render(<InjuryPeoplePicker id="w" label="Injured worker" endpoint="/x" group="workers" value="" onChange={vi.fn()} siteId="4" />);

    expect(await screen.findByText(/No staff are listed at this Site\. Ask HR to add this Site/, {}, { timeout: 2000 })).toBeInTheDocument();
});

it('shows nothing extra while there is no Site yet', async () => {
    render(<InjuryPeoplePicker id="w" label="Injured worker" endpoint="/x" group="workers" value="" onChange={vi.fn()} siteId="" />);
    await new Promise((resolve) => setTimeout(resolve, 300));

    expect(io.get).not.toHaveBeenCalled();
    expect(screen.queryByText(/No staff are listed/)).not.toBeInTheDocument();
});
