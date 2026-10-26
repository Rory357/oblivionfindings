import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { MessageTemplate } from './message-templates';
import { MessageTemplateSettings } from './settings-messages';
import type {
    ReferralConfiguration,
    ReferralSettingsPage,
} from './settings-types';

afterEach(cleanup);

const published: MessageTemplate = {
    id: 'first_contact',
    label: 'First contact attempt',
    channel: 'email',
    audience: 'person',
    subject: 'About your enquiry with {provider_name}',
    body: 'Kia ora {first_name}, please call {provider_name}. Ref {reference}',
    enabled: true,
};
const unpublished: MessageTemplate = {
    id: 'call_back',
    label: 'Call back',
    channel: 'sms',
    audience: 'person',
    subject: null,
    body: 'Kia ora {first_name}, please call us back.',
    enabled: true,
};
const configuration = (templates: MessageTemplate[]) =>
    ({
        public: {
            enabled: false,
            provider_name: 'Fictional Living',
            contact_phone: '09 555 0100',
            response_hours: 'Weekdays',
            notice_version: 'v1',
            notice: '',
            acknowledgement_enabled: false,
        },
        communications: { templates },
    }) as unknown as ReferralConfiguration;
const settings = {
    configuration: configuration([published]),
    delivery: {
        email_configured: true,
        sms_configured: false,
        link_length: 110,
    },
} as unknown as ReferralSettingsPage;

function show(templates: MessageTemplate[], readOnly = false) {
    const onChange = vi.fn();
    const value = configuration(templates);
    render(
        <MessageTemplateSettings
            value={value}
            onChange={onChange}
            readOnly={readOnly}
            settings={settings}
            tab="delivery"
            jump={vi.fn()}
        />,
    );
    return { onChange, value };
}

it('turns a published template off but never removes it, from one menu', () => {
    const { onChange, value } = show([published, unpublished]);
    fireEvent.contextMenu(screen.getByText('First contact attempt'));
    const remove = screen.getByRole('menuitem', { name: /Remove template/ });
    expect(remove).toHaveAttribute('aria-disabled', 'true');
    expect(
        screen.getByText(
            'Published templates can’t be removed. Turn it off instead.',
        ),
    ).toBeInTheDocument();
    fireEvent.click(remove);
    expect(onChange).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('menuitem', { name: 'Turn off' }));
    const next = onChange.mock.calls[0][0] as ReferralConfiguration;
    expect(next.communications?.templates[0].enabled).toBe(false);
    expect(next.communications?.templates).toHaveLength(2);
    // The working copy is edited, never the configuration it came from.
    expect(value.communications?.templates[0].enabled).toBe(true);
});

it('removes a template that was never published after a confirmation', () => {
    const { onChange } = show([published, unpublished]);
    fireEvent.contextMenu(screen.getByText('Call back'));
    fireEvent.click(screen.getByRole('menuitem', { name: /Remove template/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Remove template' }));
    const next = onChange.mock.calls[0][0] as ReferralConfiguration;
    expect(next.communications?.templates.map((item) => item.id)).toEqual([
        'first_contact',
    ]);
});

it('adds a template with a sample preview and refuses merge fields outside the safe list', () => {
    const { onChange } = show([published]);
    fireEvent.click(screen.getByRole('button', { name: /Add template/ }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/^Name/), {
        target: { value: 'Interpreter offer' },
    });
    fireEvent.change(within(dialog).getByLabelText(/^Subject/), {
        target: { value: 'An interpreter for {first_name}' },
    });
    fireEvent.change(within(dialog).getByLabelText(/^Message/), {
        target: {
            value: 'Kia ora {first_name}, we can arrange an interpreter about {diagnosis}. Ref {reference}',
        },
    });
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Add template' }),
    );
    expect(dialog).toHaveTextContent(
        'Remove {diagnosis}. Only the listed merge fields can be used',
    );
    expect(onChange).not.toHaveBeenCalled();

    fireEvent.change(within(dialog).getByLabelText(/^Message/), {
        target: {
            value: 'Kia ora {first_name}, we can arrange an interpreter. Ref {reference}',
        },
    });
    // Synthetic details only.
    expect(dialog).toHaveTextContent(
        'Kia ora Aroha, we can arrange an interpreter. Ref 01J0SAMPLE00REFERENCE00000',
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Add template' }),
    );
    const next = onChange.mock.calls[0][0] as ReferralConfiguration;
    expect(next.communications?.templates[1]).toEqual({
        id: 'interpreter_offer',
        label: 'Interpreter offer',
        channel: 'email',
        audience: 'person',
        subject: 'An interpreter for {first_name}',
        body: 'Kia ora {first_name}, we can arrange an interpreter. Ref {reference}',
        enabled: true,
    });
});

it('counts text parts and keeps a published template on its channel', () => {
    const { onChange } = show([published, unpublished]);
    fireEvent.click(screen.getByText('Call back'));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).queryByLabelText(/^Subject/)).toBeNull();
    fireEvent.change(within(dialog).getByLabelText(/^Message/), {
        target: { value: 'Please call us back today. '.repeat(20) },
    });
    expect(dialog).toHaveTextContent('of 3 text parts');
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Apply to draft' }),
    );
    expect(dialog).toHaveTextContent('Shorten this text message');
    expect(onChange).not.toHaveBeenCalled();
    cleanup();

    show([published]);
    fireEvent.click(screen.getByText('First contact attempt'));
    // Text message, plus the referrer and someone-involved audiences.
    expect(
        within(screen.getByRole('dialog')).getAllByText(
            'Published templates keep how they’re sent and who they go to. Add a new template instead.',
        ),
    ).toHaveLength(3);
});

it('marks standard templates that are still off as ready to review', () => {
    const { onChange } = show([
        { ...published, enabled: false },
        { ...unpublished, enabled: false },
    ]);
    // Only the standard template is marked; an organisation's own template isn't.
    expect(screen.getAllByText('Ready to review')).toHaveLength(1);
    fireEvent.click(
        screen.getByRole('switch', { name: 'Offer “First contact attempt”' }),
    );
    const next = onChange.mock.calls[0][0] as ReferralConfiguration;
    expect(next.communications?.templates[0].enabled).toBe(true);
});
