import type { AccessConsoleInfo, PersonRow, StaffOption } from '@/pages/security-devices/access/_types';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddCard, AddVisitorPass, ChangeDoors, GiveAccess, MatchPeople, POLL_MS, TurnOffCard } from './access-popups';
import { RollCall } from './access-roll-call';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, ...inertia } };
});
const toast = vi.hoisted(() => ({ success: vi.fn(), info: vi.fn(), warning: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const UUID = '0b9f3c1e-8a52-4d2c-9a0e-6f1d2b3c4d5e';

const house: AccessConsoleInfo = {
    id: 3,
    site_id: 4,
    site: 'Synthetic House',
    state: 'ok',
    synced_at: null,
    doors: [
        { id: 'door-front', name: 'Synthetic front door', readers: [{ id: 'reader-front', name: 'Front reader' }] },
        { id: 'door-back', name: 'Synthetic back door', readers: [{ id: 'reader-back', name: 'Back reader' }] },
    ],
    policies: [{ id: 'policy-staff', name: 'House staff', doors: 'Synthetic front door, Synthetic back door', people: 3 }],
};

const worker: PersonRow = {
    id: 11,
    console_id: 3,
    site_id: 4,
    site: 'Synthetic House',
    name: 'Test Worker',
    sub: 'Support worker · Synthetic House',
    kind: 'staff',
    status: 'active',
    employee_number: 'E100',
    user_id: 70,
    suggestion: null,
    cards: [{ id: 91, display: '••4821' }],
    gets_in: 'Card ••4821',
    doors: 'House staff',
    policy_ids: ['policy-staff'],
    rank: 4,
    badge: { tone: 'success', text: 'Active' },
    note: null,
    last_used: { text: 'Not used yet', at: null },
    can_log: true,
};

const staff: Record<string, StaffOption[]> = {
    '4': [{ id: 70, name: 'Test Starter', sub: 'Support worker · E104', employee_number: 'E104', in_access: false }],
};

/** The hub's answers to "how is it going?", in order. */
function jobAnswers(...answers: { state: string; message: string | null }[]) {
    const get = vi.spyOn(axios, 'get');
    for (const answer of answers) get.mockResolvedValueOnce({ data: answer });
    return get;
}

async function tick(ms = POLL_MS + 10) {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('Turn off a card (sdr-83)', () => {
    it('asks what happened, sends it, waits for the hub and says how it ended', async () => {
        const remove = vi.spyOn(axios, 'delete').mockResolvedValue({ data: { job: UUID, message: 'Turning the card off in UniFi Access…' } });
        const get = jobAnswers({ state: 'sent', message: null }, { state: 'succeeded', message: 'Done in UniFi Access.' });
        const onClose = vi.fn();
        const onAddCardNext = vi.fn();
        render(<TurnOffCard open person={worker} card={worker.cards[0]} canAddCard onClose={onClose} onAddCardNext={onAddCardNext} />);

        const confirm = screen.getByRole('button', { name: 'Turn off card ••4821 now' });
        expect(confirm).toBeDisabled();
        fireEvent.click(screen.getByRole('radio', { name: 'Lost' }));
        fireEvent.click(confirm);
        await tick(0);

        expect(remove).toHaveBeenCalledWith('/security-devices/access/cards/91', expect.objectContaining({ data: { reason: 'Lost' } }));
        await tick();
        await tick();
        expect(get).toHaveBeenCalledWith(`/security-devices/access/jobs/${UUID}`, expect.anything());
        expect(toast.success).toHaveBeenCalledWith('Done in UniFi Access.');
        expect(onClose).toHaveBeenCalled();
        expect(onAddCardNext).toHaveBeenCalledWith(worker);
    });

    it('shows the hub’s reason when it can’t take the change', async () => {
        vi.spyOn(axios, 'delete').mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { errors: { access: ['This Site’s hub needs an update before it can change UniFi Access.'] } } } });
        render(<TurnOffCard open person={worker} card={worker.cards[0]} canAddCard={false} onClose={vi.fn()} onAddCardNext={vi.fn()} />);

        fireEvent.click(screen.getByRole('radio', { name: 'Stolen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Turn off card ••4821 now' }));
        await tick(0);

        expect(screen.getByText('This Site’s hub needs an update before it can change UniFi Access.')).toBeInTheDocument();
    });
});

describe('Add a card at a reader (sdr-82)', () => {
    it('asks the reader to listen, says to tap the card, then shows the card added', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { job: UUID, message: 'Tap the new card on the reader within a minute.' } });
        jobAnswers({ state: 'sent', message: null }, { state: 'succeeded', message: 'Card added (100245).' });
        render(<AddCard open target={{ person: worker, console: house }} onClose={vi.fn()} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'Reader' }));
        fireEvent.click(await screen.findByRole('option', { name: /Back reader/ }));
        fireEvent.click(screen.getByRole('button', { name: /Start listening/ }));
        await tick(0);
        expect(post).toHaveBeenCalledWith('/security-devices/access/people/11/cards', { reader_id: 'reader-back' }, expect.anything());

        await tick();
        expect(screen.getByText(/Tap the new card on the Back reader now/)).toBeInTheDocument();
        await tick();
        expect(screen.getByText('Card added (100245).')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Done' })).toBeInTheDocument();
    });

    it('says when the card wasn’t added, and offers to try again', async () => {
        vi.spyOn(axios, 'post').mockResolvedValue({ data: { job: UUID, message: 'Tap the new card on the reader within a minute.' } });
        jobAnswers({ state: 'failed', message: 'That card is already someone else’s. Tap a different card.' });
        render(<AddCard open target={{ person: worker, console: house, readerId: 'reader-front' }} onClose={vi.fn()} />);

        await tick(0);
        await tick();
        expect(screen.getByText('That card is already someone else’s. Tap a different card.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Try again/ })).toBeInTheDocument();
    });
});

describe('Give someone access (sdr-81)', () => {
    it('adds a staff member from HR to a door group, waits for the hub and offers their card next', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { job: UUID, message: 'Adding them in UniFi Access…' } });
        const get = vi.spyOn(axios, 'get').mockImplementation(async (url: string) =>
            url.endsWith('/person') ? { data: { person_id: 55 } } : { data: { state: 'succeeded', message: 'Added in UniFi Access.' } },
        );
        const onAddCard = vi.fn();
        render(<GiveAccess open consoles={[house]} staff={staff} initialSiteId={4} onClose={vi.fn()} onVisitorPass={vi.fn()} onAddCard={onAddCard} onSeePeople={vi.fn()} />);

        fireEvent.click(screen.getByRole('combobox', { name: 'Staff member' }));
        fireEvent.click(await screen.findByRole('option', { name: /Test Starter/ }));
        expect(screen.getByText(/E104 · from HR, goes into UniFi/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'House staff' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('combobox', { name: 'Reader' }));
        fireEvent.click(await screen.findByRole('option', { name: /Front reader/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Give access' }));
        await tick(0);

        expect(post).toHaveBeenCalledWith('/security-devices/access/consoles/3/people', { user_id: 70, policy_ids: ['policy-staff'] }, expect.anything());
        await tick();
        await tick(0);
        expect(get).toHaveBeenCalledWith(`/security-devices/access/jobs/${UUID}/person`, expect.anything());
        expect(screen.getByText('Test Starter can get in at Synthetic House')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Add their card now/ }));
        expect(onAddCard).toHaveBeenCalledWith({ personId: 55, name: 'Test Starter', consoleId: 3, readerId: 'reader-front' });
    });

    it('sends someone visiting to a visitor pass instead', () => {
        const onVisitorPass = vi.fn();
        render(<GiveAccess open consoles={[house]} staff={staff} initialSiteId={4} onClose={vi.fn()} onVisitorPass={onVisitorPass} onAddCard={vi.fn()} onSeePeople={vi.fn()} />);

        fireEvent.click(screen.getByRole('button', { name: /Someone visiting/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Add visitor pass' }));

        expect(onVisitorPass).toHaveBeenCalled();
    });
});

describe('Add visitor pass (sdr-84)', () => {
    it('shows the plain reason when the hub can’t make the pass', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { errors: { access: ['No hub covers this Site, so UniFi Access can’t be changed from Oblivion.'] } } } });
        render(<AddVisitorPass open consoles={[house]} initialSiteId={4} onClose={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Visitor’s name/), { target: { value: 'Test Contractor' } });
        fireEvent.click(screen.getByRole('button', { name: 'Synthetic front door' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add visitor pass' }));
        await tick(0);

        expect(screen.getByText('No hub covers this Site, so UniFi Access can’t be changed from Oblivion.')).toBeInTheDocument();
    });

    it('needs a door before it sends anything', () => {
        const post = vi.spyOn(axios, 'post');
        render(<AddVisitorPass open consoles={[house]} initialSiteId={4} onClose={vi.fn()} />);

        fireEvent.change(screen.getByLabelText(/Visitor’s name/), { target: { value: 'Test Contractor' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add visitor pass' }));

        expect(screen.getByText('Choose at least one door.')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });
});

describe('Change doors', () => {
    it('sends the door groups and waits for the hub', async () => {
        const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { job: UUID, message: 'Changing their doors in UniFi Access…' } });
        jobAnswers({ state: 'succeeded', message: 'Done in UniFi Access.' });
        const onClose = vi.fn();
        render(<ChangeDoors open person={worker} console={house} onClose={onClose} />);

        fireEvent.click(screen.getByRole('checkbox', { name: 'House staff' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save doors' }));
        await tick(0);
        expect(put).toHaveBeenCalledWith('/security-devices/access/people/11/doors', { policy_ids: [] }, expect.anything());
        await tick();
        expect(onClose).toHaveBeenCalled();
    });
});

describe('Match UniFi people to staff (sdr-90)', () => {
    it('matches each person to the staff member chosen (the suggestion first)', async () => {
        const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { message: 'Matched.' } });
        const unmatched: PersonRow = { ...worker, id: 12, name: 'M Ross', kind: 'unmatched', user_id: null, employee_number: '1027', suggestion: { user_id: 70, name: 'Test Starter', sub: 'Finance · E1027' }, can_log: false };
        const onClose = vi.fn();
        render(<MatchPeople open people={[unmatched]} staff={staff} onClose={onClose} />);

        expect(screen.getByText('“M Ross” in UniFi')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Match 1 person' }));
        await tick(0);

        expect(put).toHaveBeenCalledWith('/security-devices/access/people/12/match', { user_id: 70 }, expect.anything());
        expect(toast.success).toHaveBeenCalledWith('Matched 1 person.');
        expect(onClose).toHaveBeenCalled();
    });
});

describe('Fire roll call (sdr-87)', () => {
    it('lists who may be there, from where, and counts down as people are ticked (not saved)', async () => {
        vi.spyOn(axios, 'get').mockResolvedValue({
            data: {
                site: { id: 4, name: 'Synthetic House' },
                emergency: 'normal',
                doors: 2,
                unlocked: 0,
                as_of: new Date().toISOString(),
                sections: [
                    { key: 'staff', title: 'Staff on shift', icon: 'id-card', people: [{ key: 'staff-70', name: 'Test Worker', hint: { text: 'Rostered {from} – {until}', times: { from: '2026-11-01T18:00:00Z', until: '2026-11-02T02:00:00Z' } } }] },
                    { key: 'came_in', title: 'Came in today by badge, not on shift', icon: 'door-open', people: [{ key: 'badge-9', name: 'Someone not matched to staff', hint: { text: 'Badged in {badge} at Synthetic front door · may have left', times: { badge: '2026-11-01T20:00:00Z' } } }] },
                    { key: 'visitors', title: 'Visitors with a pass now', icon: 'hard-hat', people: [] },
                ],
            },
        });
        render(<RollCall open sites={[{ value: '4', label: 'Synthetic House' }]} initialSiteId="4" onClose={vi.fn()} />);
        await tick(0);

        const panel = screen.getByTestId('sd-access-roll-call');
        expect(within(panel).getByText('2 not ticked yet')).toBeInTheDocument();
        expect(within(panel).getByText(/Leaving isn’t recorded/)).toBeInTheDocument();
        expect(within(panel).getByText(/aren’t saved/)).toBeInTheDocument();
        expect(within(panel).getByText('Someone not matched to staff')).toBeInTheDocument();
        fireEvent.click(within(panel).getByRole('checkbox', { name: 'Test Worker' }));
        expect(within(panel).getByText('1 not ticked yet')).toBeInTheDocument();
        expect(within(panel).queryByRole('button', { name: /save/i })).not.toBeInTheDocument();
    });
});
