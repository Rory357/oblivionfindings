import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { HubRequestError } from './_api';
import { BirthdaySettingsDialog } from './_birthday-settings';

const { request, actor, before, visit } = vi.hoisted(() => ({
    request: vi.fn(),
    actor: { id: 11 },
    before: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('./_api', async (original) => ({
    ...(await original<typeof import('./_api')>()),
    hubRequest: request,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: actor } } }),
    router: { visit, on: before },
}));
const people = [
    { id: 21, name: 'Aroha Smith', enabled: false, version: 1 },
    { id: 22, name: 'Jamie Rivers', enabled: false, version: 1 },
];
const choice = () =>
    screen.getByRole('switch', { name: 'Share birthday with staff' });
const receipt = (id: number, enabled: boolean, account = 11) => ({
    saved: true,
    ready: true,
    actor_id: account,
    person: { ...people.find((p) => p.id === id), enabled, version: 2 },
});
beforeEach(() => {
    actor.id = 11;
    visit.mockReset();
    before.mockReset().mockReturnValue(vi.fn());
    request
        .mockReset()
        .mockImplementation((_url, method) =>
            method === 'GET'
                ? Promise.resolve({ ready: true, actor_id: actor.id, people })
                : Promise.resolve(receipt(21, true)),
        );
});
async function open(close = vi.fn()) {
    const view = render(
        <BirthdaySettingsDialog open close={close} people={people} />,
    );
    await waitFor(() => expect(choice()).toBeEnabled());
    return view;
}
it('stages individual choices and accepts only the exact actor/person/version acknowledgement', async () => {
    await open();
    fireEvent.click(choice());
    fireEvent.click(
        screen.getByRole('button', { name: 'Jamie Rivers: Birthday hidden' }),
    );
    fireEvent.click(choice());
    expect(request).toHaveBeenCalledTimes(1);
    let finish!: (value: unknown) => void;
    request.mockReturnValueOnce(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/celebrations/22',
        'PUT',
        { actor_id: 11, version: 1, birthday_enabled: true },
    );
    expect(choice()).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Done' })).toBeDisabled();
    await act(async () => finish(receipt(22, true)));
    expect(screen.getByRole('status')).toHaveTextContent('enabled');
    fireEvent.click(
        screen.getByRole('button', { name: 'Aroha Smith: Unsaved choice' }),
    );
    expect(choice()).toBeChecked();
    expect(
        screen.getByRole('button', { name: 'Save preference' }),
    ).toBeEnabled();
});
it('requires an authoritative recheck after a conflict and keeps the proposed choice', async () => {
    await open();
    fireEvent.click(choice());
    request.mockRejectedValueOnce(
        new HubRequestError('Another editor changed this preference.', 409),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Another editor',
    );
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Save preference' }),
    ).toBeDisabled();
    request.mockResolvedValueOnce({
        ready: true,
        actor_id: 11,
        people: people.map((p) => ({ ...p, version: 3 })),
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Check saved preferences' }),
    );
    await waitFor(() => expect(choice()).toBeEnabled());
    expect(choice()).toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    expect(request).toHaveBeenLastCalledWith(
        '/company-hub/celebrations/21',
        'PUT',
        { actor_id: 11, version: 3, birthday_enabled: true },
    );
});
it('rejects a different account receipt and ignores late writes after account change', async () => {
    const view = await open();
    fireEvent.click(choice());
    let finish!: (value: unknown) => void;
    request.mockReturnValueOnce(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    actor.id = 99;
    view.rerender(
        <BirthdaySettingsDialog open close={vi.fn()} people={people} />,
    );
    await waitFor(() => expect(choice()).toBeEnabled());
    await act(async () => finish(receipt(21, true, 11)));
    expect(choice()).not.toBeChecked();
    expect(
        screen.queryByText('Birthday sharing enabled for this person.'),
    ).not.toBeInTheDocument();
    fireEvent.click(choice());
    request.mockResolvedValueOnce(receipt(21, true, 11));
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
});
it('protects local writing on close and in-app navigation', async () => {
    const close = vi.fn();
    await open(close);
    fireEvent.click(choice());
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Cancel',
        }),
    );
    expect(choice()).toBeChecked();
    const handler = before.mock.calls
        .filter(([name]) => name === 'before')
        .at(-1)![1];
    const event = {
        preventDefault: vi.fn(),
        detail: { visit: { method: 'get', url: '/company-hub?view=news' } },
    };
    act(() => handler(event));
    expect(event.preventDefault).toHaveBeenCalledOnce();
    fireEvent.click(
        screen.getByRole('button', { name: 'Discard and continue' }),
    );
    expect(close).toHaveBeenCalledOnce();
    expect(visit).toHaveBeenCalledOnce();
});
it('keeps settings unavailable until schema and current access are confirmed', async () => {
    request.mockResolvedValueOnce({
        ready: false,
        actor_id: 11,
        people: people.map((p) => ({ ...p, version: null })),
    });
    render(<BirthdaySettingsDialog open close={vi.fn()} people={people} />);
    await screen.findByText(/awaiting an application update/);
    expect(choice()).toBeDisabled();
    request.mockRejectedValueOnce(
        new HubRequestError('You no longer have access.', 403),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Check saved preferences' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'no longer have access',
    );
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    expect(screen.queryByText('Aroha Smith')).not.toBeInTheDocument();
});
it('still protects retained choices after current permission is withdrawn and names are hidden', async () => {
    const close = vi.fn();
    await open(close);
    fireEvent.click(choice());
    request.mockRejectedValueOnce(
        new HubRequestError('You no longer have access.', 403),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    await screen.findByRole('alert');
    expect(screen.queryByText('Aroha Smith')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Done' }));
    expect(screen.getByRole('alertdialog')).toHaveTextContent(
        'Discard birthday-sharing changes?',
    );
    expect(close).not.toHaveBeenCalled();
});
it('keeps a malformed acknowledgement in recovery instead of rendering an invalid person', async () => {
    await open();
    fireEvent.click(choice());
    request.mockResolvedValueOnce({
        ...receipt(21, true),
        person: { id: 21, enabled: true, version: 2 },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save preference' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(
        screen.getByRole('button', { name: 'Check saved preferences' }),
    ).toBeEnabled();
});
