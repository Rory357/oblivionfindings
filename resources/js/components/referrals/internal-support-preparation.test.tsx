import '@testing-library/jest-dom/vitest';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { InternalSupportPreparation } from './internal-support-preparation';

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: { get, post, isAxiosError: () => true } }));
vi.mock('./date-time', () => ({
    ReferralDateTime: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
vi.mock('@/components/wizard/primitives', async (original) => ({
    ...(await original<typeof import('@/components/wizard/primitives')>()),
    SelectInput: ({
        ariaLabel,
        value,
        onChange,
        options,
    }: {
        ariaLabel: string;
        value: string;
        onChange: (value: string) => void;
        options: { value: string; label: string }[];
    }) => (
        <select
            aria-label={ariaLabel}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">Choose</option>
            {options.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
const options = {
    person: {
        id: 1,
        name: 'Existing Fictional Person',
        status: 'onboarding',
        fingerprint: 'a'.repeat(64),
        site_id: 2,
        service_context_id: 3,
    },
    settings_version_id: 7,
    team: 'Intake',
    services: [{ id: 'supported_living', label: 'Supported living' }],
    sites: [{ id: 2, name: 'Approved site' }],
    contexts: [
        { id: 3, name: 'Community support', site_id: 2, type: 'home_support' },
    ],
    existing: [],
};
beforeEach(() => {
    vi.clearAllMocks();
    get.mockResolvedValue({ data: options });
});

it('filters delivery settings and clears an incompatible selection when the offered service changes', async () => {
    get.mockResolvedValueOnce({
        data: {
            ...options,
            services: [
                {
                    id: 'home',
                    label: 'Home support',
                    context_types: ['home_support'],
                },
                {
                    id: 'respite',
                    label: 'Respite support',
                    context_types: ['planned_respite'],
                },
            ],
            contexts: [
                ...options.contexts,
                {
                    id: 4,
                    name: 'Respite setting',
                    site_id: 2,
                    type: 'planned_respite',
                },
            ],
        },
    });
    render(<InternalSupportPreparation personId={1} />);
    fireEvent.click(screen.getByRole('button', { name: 'Prepare support' }));
    await screen.findByText('Existing Fictional Person');
    fireEvent.change(screen.getByLabelText('Service'), {
        target: { value: 'home' },
    });
    expect(screen.getByLabelText('Service setting')).toHaveValue('3');
    expect(
        screen.queryByRole('option', { name: 'Respite setting' }),
    ).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Service'), {
        target: { value: 'respite' },
    });
    expect(screen.getByLabelText('Service setting')).toHaveValue('');
    expect(
        screen.queryByRole('option', { name: 'Community support' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('option', { name: 'Respite setting' }),
    ).toBeInTheDocument();
});

it('preselects the reviewed booking site and setting and clears the setting when the site changes', async () => {
    get.mockResolvedValueOnce({
        data: {
            ...options,
            sites: [...options.sites, { id: 8, name: 'Respite destination' }],
            contexts: [
                ...options.contexts,
                { id: 9, name: 'Planned respite', site_id: 8 },
            ],
        },
    });
    render(
        <InternalSupportPreparation
            personId={1}
            defaultSiteId={8}
            defaultContextId={9}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Prepare support' }));
    await screen.findByText('Existing Fictional Person');
    expect(screen.getByLabelText('Site')).toHaveValue('8');
    expect(screen.getByLabelText('Service setting')).toHaveValue('9');
    fireEvent.change(screen.getByLabelText('Site'), { target: { value: '2' } });
    expect(screen.getByLabelText('Service setting')).toHaveValue('');
    expect(
        screen.queryByRole('option', { name: 'Planned respite' }),
    ).not.toBeInTheDocument();
});
async function reviewPreparation() {
    render(<InternalSupportPreparation personId={1} />);
    fireEvent.click(screen.getByRole('button', { name: 'Prepare support' }));
    await screen.findByText('Existing Fictional Person');
    fireEvent.change(screen.getByLabelText('Service'), {
        target: { value: 'supported_living' },
    });
    fireEvent.change(screen.getByLabelText('Why is support being prepared?'), {
        target: { value: 'Prepare new daily living support with this person' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Next action'), {
        target: { value: 'Review participation and consent' },
    });
    fireEvent.change(screen.getByLabelText('Next action due'), {
        target: { value: new Date(Date.now() + 86400000).toISOString() },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(
        screen.getByRole('checkbox', { name: /I have checked existing cases/ }),
    );
}

it('locks uncertain details and retries the exact request while preventing duplicate clicks', async () => {
    let reject: (reason: unknown) => void = () => undefined;
    post.mockReturnValueOnce(
        new Promise((_resolve, failure) => {
            reject = failure;
        }),
    );
    await reviewPreparation();
    fireEvent.click(screen.getByRole('button', { name: 'Open support case' }));
    fireEvent.click(screen.getByRole('button', { name: 'Saving…' }));
    expect(post).toHaveBeenCalledTimes(1);
    const original = structuredClone(post.mock.calls[0][1]);
    await act(async () => reject(new Error('Connection ended')));
    expect(
        await screen.findByText(/The save could not be confirmed/),
    ).toBeInTheDocument();
    expect(screen.getByRole('checkbox')).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Back' }),
    ).not.toBeInTheDocument();
    post.mockResolvedValueOnce({
        data: { url: '/operations/referrals/confirmed-case' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Retry same request' }));
    await screen.findByText('Support case opened');
    expect(post.mock.calls[1][1]).toEqual(original);
    expect(
        screen.getByRole('link', { name: 'Open support case' }),
    ).toHaveAttribute('href', '/operations/referrals/confirmed-case');
    expect(screen.getByText(/services have not started/)).toBeInTheDocument();
});

it('requires a fresh review after conflict and removes person details if access is withdrawn', async () => {
    post.mockRejectedValueOnce({ response: { status: 409 } });
    await reviewPreparation();
    fireEvent.click(screen.getByRole('button', { name: 'Open support case' }));
    expect(
        await screen.findByRole('button', { name: 'Reload current details' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('checkbox')).toBeDisabled();
    get.mockRejectedValueOnce({ response: { status: 403 } });
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload current details' }),
    );
    await screen.findByText(/Preparation could not be loaded/);
    await waitFor(() =>
        expect(
            screen.queryByText('Existing Fictional Person'),
        ).not.toBeInTheDocument(),
    );
    expect(post).toHaveBeenCalledTimes(1);
});
