import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    checkContactForm,
    ContactForm,
    newSubmissionToken,
} from './contact-form';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/contact', props: {} }),
    Link: ({
        href,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) => (
        <a href={href} {...props} />
    ),
}));

const UUID =
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const axiosError = (status: number, data: unknown = {}) =>
    Object.assign(new Error(`HTTP ${status}`), {
        isAxiosError: true,
        response: { status, data },
    });

function fill({
    name = 'Aroha Example',
    contact = 'aroha@example.test',
    message = 'Could someone call me about support at home?',
} = {}) {
    fireEvent.change(screen.getByLabelText('Your name'), {
        target: { value: name },
    });
    fireEvent.change(screen.getByLabelText('Email or phone'), {
        target: { value: contact },
    });
    fireEvent.change(screen.getByLabelText('Your message'), {
        target: { value: message },
    });
}
const send = () =>
    fireEvent.click(screen.getByRole('button', { name: /send message/i }));

beforeEach(() => post.mockReset());
afterEach(cleanup);

it('shows honest errors for an empty message and sends nothing', async () => {
    render(<ContactForm providerName="Oblivion Living" />);
    send();
    expect(screen.getByText('Enter your name.')).toBeInTheDocument();
    expect(
        screen.getByText('Enter an email address or phone number.'),
    ).toBeInTheDocument();
    expect(screen.getByText('Write a short message.')).toBeInTheDocument();
    expect(screen.getByLabelText('Your name')).toHaveAttribute(
        'aria-describedby',
        'contact-name-error',
    );
    await waitFor(() =>
        expect(screen.getByLabelText('Your name')).toHaveFocus(),
    );
    expect(post).not.toHaveBeenCalled();
});

it('checks the contact and keeps the reply channel to what was given', () => {
    const base = {
        name: 'A',
        contact: 'aroha@example.test',
        channel: null,
        topic: null,
        message: 'Hello there',
        leave_message: true,
    };
    expect(checkContactForm(base)).toEqual({});
    expect(
        checkContactForm({ ...base, contact: 'not-an-address' }).contact,
    ).toBe('Check the email address or phone number. We need it to reply.');
    expect(checkContactForm({ ...base, channel: 'text' }).channel).toMatch(
        /give us a phone number/,
    );
    expect(
        checkContactForm({ ...base, contact: '021 555 0100', channel: 'email' })
            .channel,
    ).toMatch(/give us an email address/);
    expect(
        checkContactForm({
            ...base,
            contact: '(021) 555-0100',
            channel: 'text',
        }),
    ).toEqual({});
    expect(checkContactForm({ ...base, message: 'Hi' }).message).toBe(
        'Write a little more so we know how to help.',
    );
    expect(newSubmissionToken()).toMatch(UUID);
});

it('sends the safe-contact answers and shows the ENQ reference', async () => {
    post.mockResolvedValue({
        data: { reference: 'ENQ-2048', status: 'received' },
    });
    render(<ContactForm providerName="Oblivion Living" />);
    fill();
    fireEvent.click(screen.getByRole('radio', { name: /email/i }));
    fireEvent.click(screen.getByRole('radio', { name: /something else/i }));
    fireEvent.click(screen.getByRole('switch', { name: /safe to leave/i }));
    send();

    await screen.findByText('ENQ-2048');
    expect(post).toHaveBeenCalledTimes(1);
    const [url, payload, options] = post.mock.calls[0];
    expect(url).toBe('/contact');
    expect(options).toEqual({ headers: { Accept: 'application/json' } });
    expect(payload).toEqual({
        name: 'Aroha Example',
        contact: 'aroha@example.test',
        channel: 'email',
        topic: 'other',
        message: 'Could someone call me about support at home?',
        leave_message: false,
        website: '',
        submission_token: expect.stringMatching(UUID),
    });
    const status = screen.getByRole('status');
    expect(status).toHaveTextContent(
        'Thanks — our intake team has your message',
    );
    expect(status).toHaveTextContent(
        'We reply within two working days, by email.',
    );
    expect(status).toHaveTextContent(
        'We won’t leave a message if we can’t reach you.',
    );

    fireEvent.click(
        screen.getByRole('button', { name: 'Send another message' }),
    );
    expect(screen.getByLabelText('Your name')).toHaveValue('');
});

it('points support, referral and careers topics to the better form without blocking', () => {
    render(<ContactForm providerName={null} />);
    fireEvent.click(screen.getByRole('radio', { name: /ask about support/i }));
    expect(
        screen.getByRole('link', { name: /ask about support/i }),
    ).toHaveAttribute('href', '/referrals/new?purpose=support');
    fireEvent.click(screen.getByRole('radio', { name: /make a referral/i }));
    expect(
        screen.getByRole('link', { name: /make a referral/i }),
    ).toHaveAttribute('href', '/referrals/new?purpose=referral');
    fireEvent.click(screen.getByRole('radio', { name: /careers question/i }));
    expect(
        screen.getByRole('link', { name: /see open roles/i }),
    ).toHaveAttribute('href', '/careers');
    expect(screen.getByText(/goes to our intake team/)).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'privacy statement' }),
    ).toHaveAttribute('href', '/privacy-policy');
    expect(screen.getByText(/call 111/)).toBeInTheDocument();
});

it('maps server errors to their fields', async () => {
    post.mockRejectedValue(
        axiosError(422, {
            errors: {
                'form.contact': [
                    'Check the email address or phone number. We need it to reply.',
                ],
            },
        }),
    );
    render(<ContactForm providerName="Oblivion Living" />);
    fill();
    send();
    expect(
        await screen.findByText(
            'Check the email address or phone number. We need it to reply.',
        ),
    ).toBeInTheDocument();
    expect(screen.getByLabelText('Email or phone')).toHaveAttribute(
        'aria-invalid',
        'true',
    );
});

it('retries an unconfirmed send with the same key so it is never saved twice', async () => {
    post.mockRejectedValueOnce(new Error('Network down')).mockResolvedValueOnce(
        {
            data: { reference: 'ENQ-1001', status: 'received' },
        },
    );
    render(<ContactForm providerName="Oblivion Living" />);
    fill({ contact: '021 555 0100' });
    send();
    expect(
        await screen.findByText(/couldn’t confirm your message was sent/),
    ).toBeInTheDocument();
    send();
    await screen.findByText('ENQ-1001');
    expect(post).toHaveBeenCalledTimes(2);
    expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
    expect(post.mock.calls[0][1].channel).toBeNull();
    expect(screen.getByRole('status')).toHaveTextContent(
        'by the contact details you gave us',
    );
});

it('offers a new message when an earlier version already reached us', async () => {
    post.mockRejectedValueOnce(axiosError(409)).mockResolvedValueOnce({
        data: { reference: 'ENQ-1002', status: 'received' },
    });
    render(<ContactForm providerName="Oblivion Living" />);
    fill();
    send();
    const alert = (await screen.findByText(/earlier version/)).closest(
        '[data-slot="alert"]',
    ) as HTMLElement;
    fireEvent.click(
        within(alert).getByRole('button', { name: 'Send as a new message' }),
    );
    send();
    await screen.findByText('ENQ-1002');
    expect(post.mock.calls[1][1].submission_token).not.toBe(
        post.mock.calls[0][1].submission_token,
    );
});
