import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    SendMessageDialog,
    type ComposeContext,
    type ComposeRecipient,
} from './send-message-dialog';
import type { ReferralDetail } from './types';

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post,
        get,
        isAxiosError: (e: { isAxiosError?: boolean }) => !!e.isAxiosError,
    },
}));
vi.mock('@/hooks/use-settings-leave-confirmation', () => ({
    useSettingsLeaveConfirmation: () => ({ confirmation: null }),
}));
// The approved date-time picker, reduced to a labelled input.
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
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
beforeEach(() => {
    post.mockReset();
    get.mockReset();
    Element.prototype.scrollTo = vi.fn();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

const referral = {
    name: 'Aroha Fictional',
    reference: 'REF-FICTIONAL',
    version: 3,
} as ReferralDetail;
const template =
    'Kia ora {first_name}, please call {provider_name} on {contact_phone}. Ref {reference}';
const family = 'contact:1f0c0c4e-0000-4000-8000-000000000001';

const peopleInvolved: ComposeRecipient[] = [
    {
        key: family,
        kind: 'contact',
        audience: 'contact',
        role_label: 'Family or whānau',
        name: 'Mere Fictional',
        first_name: 'Mere',
        relationship: 'Aunt',
        reason: null,
        preferences: {
            channel: 'email',
            leave_message: false,
            safe_time: null,
            communication: null,
        },
        channels: {
            email: { address: 'mere@example.test', reason: null },
            sms: {
                address: null,
                reason: 'Their safe way to be contacted is by email, so texts aren’t sent.',
            },
        },
    },
    {
        key: 'contact:1f0c0c4e-0000-4000-8000-000000000002',
        kind: 'contact',
        audience: 'contact',
        role_label: 'Supporter',
        name: 'Sam Lapsed',
        first_name: null,
        relationship: null,
        reason: 'Their contact review isn’t current. Review them in People involved first.',
        preferences: null,
        channels: {
            email: { address: null, reason: 'Review needed.' },
            sms: { address: null, reason: 'Review needed.' },
        },
    },
];

function compose(
    overrides: Partial<ComposeContext> = {},
    safeTime: string | null = null,
): ComposeContext {
    return {
        version: 3,
        blocked: null,
        recipients: [
            {
                key: 'person',
                kind: 'enquiry',
                audience: 'person',
                role_label: 'The person',
                name: 'Aroha Fictional',
                first_name: 'Aroha',
                relationship: 'Contacted us themselves',
                reason: null,
                preferences: {
                    channel: 'email',
                    leave_message: true,
                    safe_time: safeTime,
                    communication: null,
                },
                channels: {
                    email: { address: 'aroha@example.test', reason: null },
                    sms: {
                        address: '021 555 0101',
                        reason: 'Their safe way to be contacted is by email, so texts aren’t sent.',
                    },
                },
            },
            {
                key: 'referrer',
                kind: 'enquiry',
                audience: 'referrer',
                role_label: 'Referrer',
                name: null,
                first_name: null,
                relationship: 'Contacted us themselves',
                reason: 'They contacted us themselves, so there is no separate referrer.',
                preferences: null,
                channels: {
                    email: { address: null, reason: 'No separate referrer.' },
                    sms: { address: null, reason: 'No separate referrer.' },
                },
            },
        ],
        templates: [
            {
                id: 'first_contact',
                label: 'First contact attempt',
                channel: 'email',
                audience: 'person',
                subject: 'About your enquiry with {provider_name}',
                body: template,
                enabled: true,
                fields: [
                    'provider_name',
                    'first_name',
                    'contact_phone',
                    'reference',
                ],
                reason: null,
            },
            {
                id: 'documents_needed',
                label: 'Documents we still need',
                channel: 'email',
                audience: 'person',
                subject: 'Documents',
                body: 'Upload here: {document_link}',
                enabled: true,
                fields: ['document_link'],
                reason: 'Create a secure document request first. This template sends its link.',
            },
            {
                id: 'tried_to_call',
                label: 'We tried to call you',
                channel: 'sms',
                audience: 'person',
                subject: null,
                body: 'Kia ora {first_name}, we tried to call.',
                enabled: true,
                fields: ['first_name'],
                reason: null,
            },
            {
                id: 'please_call_us',
                label: 'Please call us',
                channel: 'email',
                audience: 'contact',
                subject: 'A message from {provider_name}',
                body: 'Kia ora {first_name}, please call {provider_name}. Ref {reference}',
                enabled: true,
                fields: ['provider_name', 'first_name', 'reference'],
                reason: null,
            },
        ],
        values: {
            reference: 'REF-FICTIONAL',
            provider_name: 'Fictional Living',
            coordinator_name: 'Sam Taylor',
            contact_phone: '09 555 0100',
            response_hours: 'Weekdays',
        },
        senders: {
            email: 'Fictional Living <referrals@example.test>',
            sms: null,
        },
        document_requests: [],
        link_length: 110,
        limits: {
            sms_parts: 3,
            edit_allowance: 400,
            email_body: 4000,
            schedule_days: 30,
        },
        ...overrides,
    };
}

const continueButton = () => screen.getByRole('button', { name: 'Continue' });
const message = () => screen.getByRole('textbox', { name: /^Message/ });

function show(onQueued = vi.fn(), onDenied = vi.fn()) {
    render(
        <SendMessageDialog
            referral={referral}
            onClose={vi.fn()}
            onQueued={onQueued}
            onDenied={onDenied}
        />,
    );
    return { onQueued, onDenied };
}

it('limits the channel to saved preferences, locks merge fields and queues the reviewed message', async () => {
    get.mockResolvedValue({ data: compose() });
    post.mockResolvedValue({
        data: { saved: true, communication_id: 9, scheduled_for: null },
    });
    const { onQueued } = show();
    await screen.findByText('Their contact preferences');
    // Blocked choices stay listed with the reason.
    expect(
        screen.getByText(
            'Their safe way to be contacted is by email, so texts aren’t sent.',
        ),
    ).toBeInTheDocument();
    expect(
        screen.getByText(
            'They contacted us themselves, so there is no separate referrer.',
        ),
    ).toBeInTheDocument();
    fireEvent.click(continueButton());

    // Only email templates for the person are offered; a blocked one explains itself.
    expect(
        screen.queryByRole('button', { name: /We tried to call you/ }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /Please call us/ }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByText(
            'Create a secure document request first. This template sends its link.',
        ),
    ).toBeInTheDocument();
    fireEvent.click(continueButton());
    expect(screen.getByRole('alert')).toHaveTextContent('Choose a template.');
    fireEvent.click(
        screen.getByRole('button', { name: /First contact attempt/ }),
    );
    expect(message()).toHaveValue(template);
    expect(
        screen.getByText(
            'Kia ora Aroha, please call Fictional Living on 09 555 0100. Ref REF-FICTIONAL',
        ),
    ).toBeInTheDocument();

    fireEvent.change(message(), {
        target: { value: template.replace('{first_name}', 'there') },
    });
    fireEvent.click(continueButton());
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Keep the merge fields exactly as they are',
    );
    expect(screen.queryByText('Edited for this message')).toBeNull();

    const edited = template.replace(
        '{contact_phone}.',
        '{contact_phone} after 10am.',
    );
    fireEvent.change(message(), { target: { value: edited } });
    fireEvent.click(continueButton());
    expect(screen.getByText('Edited for this message')).toBeInTheDocument();
    expect(
        screen.getByText('Fictional Living <referrals@example.test>'),
    ).toBeInTheDocument();
    // No safe time was given, so it goes out straight away.
    expect(
        screen.getByText(/Sent within about a minute of queueing/),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    await screen.findByText('Message queued');
    expect(post).toHaveBeenCalledWith(
        '/operations/referrals/REF-FICTIONAL/communications',
        expect.objectContaining({
            version: 3,
            recipient: 'person',
            channel: 'email',
            template_id: 'first_contact',
            body: edited,
            document_request_id: null,
            send_at: null,
        }),
    );
    expect(onQueued).toHaveBeenCalledTimes(1);
});

it('holds the message until the time chosen next to their safe time, and resends the exact request after an uncertain result', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-21T00:00:00Z'));
    get.mockResolvedValue({ data: compose({}, 'Weekdays after 5pm only') });
    post.mockRejectedValueOnce({ isAxiosError: true }).mockResolvedValueOnce({
        data: {
            saved: true,
            communication_id: 9,
            scheduled_for: '2026-10-21T04:30:00Z',
        },
    });
    show();
    await screen.findByText('Weekdays after 5pm only');
    fireEvent.click(continueButton());
    fireEvent.click(
        screen.getByRole('button', { name: /First contact attempt/ }),
    );
    fireEvent.click(continueButton());
    // The stated safe time is shown next to the picker.
    expect(screen.getByText('“Weekdays after 5pm only”')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Choose when to send it, in NZ time.',
    );
    expect(post).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Send at (NZ time)'), {
        target: { value: '2026-10-21T17:30' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Send the same request again',
        }),
    );
    await screen.findByText('Message scheduled');
    expect(post).toHaveBeenCalledTimes(2);
    expect(post.mock.calls[1]).toEqual(post.mock.calls[0]);
    expect(post.mock.calls[0][1]).toMatchObject({
        send_at: '2026-10-21T17:30+13:00',
    });
});

it('messages a reviewed contact in People involved with templates written for them', async () => {
    get.mockResolvedValue({
        data: compose({
            recipients: [...compose().recipients, ...peopleInvolved],
        }),
    });
    post.mockResolvedValue({
        data: { saved: true, communication_id: 9, scheduled_for: null },
    });
    show();
    await screen.findByText('Their contact preferences');
    // A contact whose review has lapsed stays listed with the reason.
    expect(
        screen.getByText(
            'Their contact review isn’t current. Review them in People involved first.',
        ),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Mere Fictional/ }));
    expect(
        screen.getByText('Contact permitted · no phone messages'),
    ).toBeInTheDocument();
    fireEvent.click(continueButton());
    expect(
        screen.queryByRole('button', { name: /First contact attempt/ }),
    ).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Please call us/ }));
    fireEvent.click(continueButton());
    expect(
        screen.getByText(
            'Mere Fictional (Family or whānau) · mere@example.test',
        ),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    await screen.findByText('Message queued');
    expect(post.mock.calls[0][1]).toMatchObject({
        recipient: family,
        channel: 'email',
        template_id: 'please_call_us',
    });
});

it('reloads the referral’s current version when it changed while writing, keeping the recipient chosen', async () => {
    const recipients = [...compose().recipients, ...peopleInvolved];
    get.mockResolvedValueOnce({
        data: compose({ recipients }),
    }).mockResolvedValueOnce({
        data: compose({
            recipients,
            version: 4,
            senders: {
                email: 'Renamed sender <new@example.test>',
                sms: null,
            },
        }),
    });
    post.mockRejectedValueOnce({
        isAxiosError: true,
        response: { status: 409, data: { message: 'Someone has updated' } },
    }).mockResolvedValueOnce({ data: { saved: true, communication_id: 9 } });
    show();
    await screen.findByText('Their contact preferences');
    fireEvent.click(screen.getByRole('button', { name: /Mere Fictional/ }));
    fireEvent.click(continueButton());
    fireEvent.click(screen.getByRole('button', { name: /Please call us/ }));
    fireEvent.click(continueButton());
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    expect(
        await screen.findByText(/This referral changed while you were writing/),
    ).toBeInTheDocument();
    // The latest details are shown before it can be queued again.
    await screen.findByText('Renamed sender <new@example.test>');
    expect(get).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Queue message' }));
    await screen.findByText('Message queued');
    expect(post.mock.calls[1][1]).toMatchObject({
        version: 4,
        recipient: family,
    });
    expect(post.mock.calls[1][1].request_key).not.toBe(
        post.mock.calls[0][1].request_key,
    );
});

it('explains why the referral cannot be messaged and offers nothing to send', async () => {
    get.mockResolvedValue({
        data: compose({
            blocked:
                'This referral is closed. Reopen it before sending a message.',
        }),
    });
    show();
    expect(
        (
            await screen.findAllByText(
                'This referral is closed. Reopen it before sending a message.',
            )
        ).length,
    ).toBeGreaterThan(0);
    expect(continueButton()).toBeDisabled();
});

it('hands back to the panel when access has been withdrawn', async () => {
    get.mockRejectedValue({ isAxiosError: true, response: { status: 403 } });
    const { onDenied } = show();
    await vi.waitFor(() => expect(onDenied).toHaveBeenCalledTimes(1));
    expect(screen.queryByText('Aroha Fictional')).toBeNull();
});
