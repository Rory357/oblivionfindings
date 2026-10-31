import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { FileText, Globe, Layers, Plus, Route } from 'lucide-react';
import { useState } from 'react';
import { SettingsForms } from './settings-forms';
import { SettingsRouting } from './settings-routing';
import {
    editConfiguration,
    referenceFor,
    type ReferralConfiguration,
    type SettingsAreaProps,
} from './settings-types';
import {
    AlwaysOn,
    ConfigDialog,
    DialogRow,
    GroupGrid,
    NumberRow,
    OnOff,
    OverviewGrid,
    SettingGroup,
    SettingRow,
    SwitchRow,
    TextRow,
} from './settings-ui';

type Service = ReferralConfiguration['services'][number];
type Dialog =
    | { kind: 'notice' }
    | { kind: 'contact' }
    | { kind: 'questions' }
    | { kind: 'routing' }
    | { kind: 'service'; index: number | null };

const count = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
type PublicSettings = ReferralConfiguration['public'];
const CONTACT_DETAILS = [
    ['office_address', 'office address'],
    ['contact_email', 'email'],
    ['opening_hours', 'opening hours'],
] as const;
/** "Office address, email and opening hours shown" — or what's still missing. */
const contactSummary = (value: PublicSettings) => {
    const shown = CONTACT_DETAILS.filter(([key]) => value[key]?.trim()).map(
        ([, name]) => name,
    );
    if (!shown.length)
        return 'Not set · the Contact us page shows the phone number only';
    const list =
        shown.length === 1
            ? shown[0]
            : `${shown.slice(0, -1).join(', ')} and ${shown.at(-1)}`;
    return `${list.charAt(0).toUpperCase()}${list.slice(1)} shown on the Contact us page`;
};

/** Intake: the public enquiry form, services, questions, documents and routing of new enquiries. */
export function IntakeSettings({
    value,
    onChange,
    readOnly,
    settings,
    tab,
    jump,
}: SettingsAreaProps) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const edit = (change: (copy: ReferralConfiguration) => void) =>
        editConfiguration(value, onChange, change);
    const fields = value.forms?.fields ?? [];
    const routing = value.routing ?? {
        enabled: false,
        fallback_owner_ids: [],
        fallback_max_open: 50,
        rules: [],
    };
    const documents = value.document_requests ?? {
        enabled: false,
        maximum_days: 7,
    };
    const published = settings.latest
        ? settings.configuration.services.map((service) => service.id)
        : [];
    const typeName = (id: string) =>
        settings.service_types?.find((type) => type.id === id)?.name ??
        id.replaceAll('_', ' ');
    const settingSummary = (service: Service) =>
        service.context_types == null
            ? 'Can start in any available setting'
            : service.context_types.length
              ? `Starts in: ${service.context_types.map(typeName).join(', ')}`
              : 'No setting chosen yet';

    if (tab === 'overview')
        return (
            <OverviewGrid
                title="Intake"
                caption="Website enquiries, services, questions and documents"
                cards={[
                    {
                        key: 'website',
                        icon: Globe,
                        title: 'Public entry forms',
                        rows: [
                            [
                                'Online enquiries',
                                value.public.enabled ? 'On' : 'Off',
                            ],
                            [
                                'Information notice',
                                `Version ${value.public.notice_version}`,
                            ],
                            [
                                'Extra questions',
                                `${fields.filter((field) => field.enabled).length} in use`,
                            ],
                        ],
                        cta: 'Review public entry forms',
                        onReview: () => jump('intake', 'configuration'),
                    },
                    {
                        key: 'services',
                        icon: Layers,
                        title: 'Services people can ask about',
                        rows: [
                            [
                                'Offered',
                                value.services
                                    .filter((service) => service.enabled)
                                    .map((service) => service.label)
                                    .join(', ') || 'None',
                            ],
                            [
                                'Turned off',
                                `${value.services.filter((service) => !service.enabled).length}`,
                            ],
                        ],
                        cta: 'Review services',
                        onReview: () => jump('intake', 'configuration'),
                    },
                    {
                        key: 'documents',
                        icon: FileText,
                        title: 'Document collection',
                        rows: [
                            [
                                'Secure document requests',
                                documents.enabled ? 'On' : 'Off',
                            ],
                            [
                                'Longest a link stays open',
                                count(documents.maximum_days, 'day'),
                            ],
                        ],
                        cta: 'Review document collection',
                        onReview: () => jump('intake', 'configuration'),
                    },
                    {
                        key: 'routing',
                        icon: Route,
                        title: 'Routing new enquiries',
                        rows: [
                            [
                                'Automatic assignment',
                                routing.enabled ? 'On' : 'Off',
                            ],
                            ['Rules', count(routing.rules.length, 'rule')],
                            [
                                'Fallback owners',
                                `${routing.fallback_owner_ids.length}`,
                            ],
                        ],
                        cta: 'Review routing',
                        onReview: () => jump('intake', 'configuration'),
                    },
                ]}
            />
        );

    return (
        <div className="space-y-5">
            <GroupGrid>
                <SettingGroup
                    icon={Globe}
                    title="Public entry forms"
                    caption="Keep first contact short. Funding and full assessment details can follow."
                >
                    <SwitchRow
                        label="Accept online enquiries"
                        hint="Needs a contact phone number and an information notice before it can be published."
                        checked={value.public.enabled}
                        disabled={readOnly}
                        onChange={(enabled) =>
                            edit((copy) => {
                                copy.public.enabled = enabled;
                            })
                        }
                    />
                    <TextRow
                        label="Provider name"
                        value={value.public.provider_name}
                        disabled={readOnly}
                        maxLength={120}
                        onChange={(next) =>
                            edit((copy) => {
                                copy.public.provider_name = next;
                            })
                        }
                    />
                    <TextRow
                        label="Contact phone number"
                        type="tel"
                        value={value.public.contact_phone ?? ''}
                        disabled={readOnly}
                        maxLength={80}
                        onChange={(next) =>
                            edit((copy) => {
                                copy.public.contact_phone = next;
                            })
                        }
                    />
                    <TextRow
                        label="Staffed response hours"
                        hint="Say when people can expect a reply. Don’t promise cover you can’t staff."
                        value={value.public.response_hours}
                        disabled={readOnly}
                        maxLength={300}
                        onChange={(next) =>
                            edit((copy) => {
                                copy.public.response_hours = next;
                            })
                        }
                    />
                    <DialogRow
                        label="Contact us page details"
                        hint={contactSummary(value.public)}
                        action={readOnly ? 'Review details' : 'Edit details'}
                        onOpen={() => setDialog({ kind: 'contact' })}
                    />
                    <SettingRow
                        label="Privacy and safe-contact questions"
                        hint="Contact details, authority to refer, the privacy acknowledgement and how it’s safe to get in touch."
                        control={<AlwaysOn>Always asked</AlwaysOn>}
                    />
                    <DialogRow
                        label="Information collection notice"
                        hint={`Version “${value.public.notice_version}” · people read it before they send an enquiry`}
                        action={readOnly ? 'Review notice' : 'Edit notice'}
                        onOpen={() => setDialog({ kind: 'notice' })}
                    />
                    <DialogRow
                        label="Extra intake questions"
                        hint={`${fields.filter((field) => field.enabled).length} in use · ${fields.filter((field) => !field.enabled).length} archived`}
                        action={
                            readOnly ? 'Review questions' : 'Edit questions'
                        }
                        onOpen={() => setDialog({ kind: 'questions' })}
                    />
                </SettingGroup>
                <SettingGroup
                    icon={Layers}
                    title="Services people can ask about"
                    caption="Turn a service off to stop new enquiries for it. Referrals already made keep it."
                    actions={
                        readOnly ? undefined : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setDialog({ kind: 'service', index: null })
                                }
                            >
                                <Plus className="size-4" />
                                Add service
                            </Button>
                        )
                    }
                >
                    {value.services.map((service, index) => (
                        <SettingRow
                            key={service.id || index}
                            label={service.label || 'New service'}
                            hint={settingSummary(service)}
                            control={
                                <span className="inline-flex items-center gap-3">
                                    <OnOff
                                        label={`Offer ${service.label}`}
                                        checked={service.enabled}
                                        disabled={readOnly}
                                        onChange={(enabled) =>
                                            edit((copy) => {
                                                copy.services[index].enabled =
                                                    enabled;
                                            })
                                        }
                                    />
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setDialog({
                                                kind: 'service',
                                                index,
                                            })
                                        }
                                    >
                                        {readOnly ? 'Review' : 'Configure'}
                                    </Button>
                                </span>
                            }
                        />
                    ))}
                </SettingGroup>
                <SettingGroup
                    icon={FileText}
                    title="Document collection and retention"
                    caption="Website enquiries never ask for documents. Staff request what they need later."
                >
                    <SwitchRow
                        label="Secure document requests"
                        hint="Staff send one expiring link, with a code shared separately, to collect one document. Files are scanned and reviewed before use."
                        checked={documents.enabled}
                        disabled={readOnly}
                        onChange={(enabled) =>
                            edit((copy) => {
                                copy.document_requests = {
                                    ...documents,
                                    enabled,
                                };
                            })
                        }
                    />
                    <NumberRow
                        label="Longest a link stays open"
                        hint="1 to 14 days. Staff can choose a shorter time for each request."
                        unit="days"
                        min={1}
                        max={14}
                        value={documents.maximum_days}
                        disabled={readOnly}
                        onChange={(days) =>
                            edit((copy) => {
                                copy.document_requests = {
                                    ...documents,
                                    maximum_days: days,
                                };
                            })
                        }
                    />
                    <SettingRow
                        label="Record retention"
                        hint="Referral records follow the organisation’s privacy retention schedule. Nothing here deletes records or overrides a legal hold."
                        control={<AlwaysOn>Privacy schedule</AlwaysOn>}
                    />
                </SettingGroup>
                <SettingGroup
                    icon={Route}
                    title="Routing new enquiries"
                    caption="Rules run in order and choose the receiving team and owner. Assignment never grants access."
                >
                    <SwitchRow
                        label="Assign website enquiries automatically"
                        hint="When off, website enquiries wait in central intake for someone to assign them."
                        checked={routing.enabled}
                        disabled={readOnly}
                        onChange={(enabled) =>
                            edit((copy) => {
                                copy.routing = { ...routing, enabled };
                            })
                        }
                    />
                    <DialogRow
                        label="Routing rules and fallback owners"
                        hint={`${count(routing.rules.filter((rule) => rule.enabled).length, 'rule')} in use · ${count(routing.fallback_owner_ids.length, 'fallback owner')}`}
                        action={
                            readOnly ? 'Review routing' : 'Configure routing'
                        }
                        onOpen={() => setDialog({ kind: 'routing' })}
                    />
                </SettingGroup>
            </GroupGrid>
            {dialog?.kind === 'notice' && (
                <ConfigDialog
                    title="Information collection notice"
                    description="People read this before they send an enquiry. A changed notice needs a new version name, and someone who can approve weaker safeguards must publish it."
                    initial={value.public}
                    readOnly={readOnly}
                    onClose={() => setDialog(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            copy.public = next;
                        })
                    }
                    validate={(next) =>
                        next.notice !== settings.configuration.public.notice &&
                        settings.latest &&
                        next.notice_version ===
                            settings.configuration.public.notice_version
                            ? 'Give the changed notice a new version name, for example the date it starts.'
                            : null
                    }
                >
                    {(draft, set) => (
                        <>
                            <Field label="Version name" required>
                                <Input
                                    value={draft.notice_version}
                                    readOnly={readOnly}
                                    maxLength={80}
                                    onChange={(event) =>
                                        set({
                                            ...draft,
                                            notice_version: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Notice shown before people send an enquiry">
                                <Textarea
                                    rows={12}
                                    readOnly={readOnly}
                                    maxLength={8000}
                                    value={draft.notice ?? ''}
                                    onChange={(event) =>
                                        set({
                                            ...draft,
                                            notice: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        </>
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'contact' && (
                <ConfigDialog<PublicSettings>
                    title="Contact us page details"
                    description="Shown on the website’s Contact us page with the contact phone number. Leave a detail blank to hide it. Messages sent from that page arrive here as general enquiries."
                    initial={value.public}
                    readOnly={readOnly}
                    width={480}
                    onClose={() => setDialog(null)}
                    onApply={(next) =>
                        edit((copy) => {
                            copy.public = {
                                ...next,
                                office_address:
                                    next.office_address?.trim() ?? '',
                                contact_email: next.contact_email?.trim() ?? '',
                                opening_hours: next.opening_hours?.trim() ?? '',
                            };
                        })
                    }
                    validate={(next) =>
                        next.contact_email?.trim() &&
                        !/^\S+@\S+\.\S+$/.test(next.contact_email.trim())
                            ? 'Check the email address, or leave it blank.'
                            : null
                    }
                >
                    {(draft, set) => (
                        <>
                            <Field label="Office address">
                                <Input
                                    value={draft.office_address ?? ''}
                                    readOnly={readOnly}
                                    maxLength={300}
                                    autoComplete="off"
                                    placeholder="Level 1, 18 Example Street, Porirua 5022"
                                    onChange={(event) =>
                                        set({
                                            ...draft,
                                            office_address: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Contact email">
                                <Input
                                    type="email"
                                    value={draft.contact_email ?? ''}
                                    readOnly={readOnly}
                                    maxLength={254}
                                    autoComplete="off"
                                    onChange={(event) =>
                                        set({
                                            ...draft,
                                            contact_email: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Opening hours">
                                <Input
                                    value={draft.opening_hours ?? ''}
                                    readOnly={readOnly}
                                    maxLength={200}
                                    autoComplete="off"
                                    placeholder="Monday to Friday, 8.30am to 5pm"
                                    onChange={(event) =>
                                        set({
                                            ...draft,
                                            opening_hours: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        </>
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'questions' && (
                <ConfigDialog
                    title="Extra intake questions"
                    description="Ask only what’s needed. Answers never approve funding, establish consent or change the person’s record."
                    initial={value}
                    readOnly={readOnly}
                    width={900}
                    onClose={() => setDialog(null)}
                    onApply={onChange}
                >
                    {(draft, set) => (
                        <SettingsForms
                            embedded
                            value={draft}
                            onChange={set}
                            disabled={readOnly}
                            readOnly={readOnly}
                        />
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'routing' && (
                <ConfigDialog
                    title="Routing new enquiries"
                    description="Rules run in order. Try an example before you apply the change."
                    initial={value}
                    readOnly={readOnly}
                    width={900}
                    onClose={() => setDialog(null)}
                    onApply={onChange}
                >
                    {(draft, set) => (
                        <SettingsRouting
                            embedded
                            value={draft}
                            onChange={set}
                            disabled={readOnly}
                            staff={settings.routing_staff ?? []}
                            sites={settings.routing_sites ?? []}
                        />
                    )}
                </ConfigDialog>
            )}
            {dialog?.kind === 'service' && (
                <ServiceDialog
                    service={
                        dialog.index === null
                            ? null
                            : value.services[dialog.index]
                    }
                    readOnly={readOnly}
                    types={settings.service_types ?? []}
                    taken={value.services.map((service) => service.id)}
                    published={published}
                    onClose={() => setDialog(null)}
                    onApply={(service) =>
                        edit((copy) => {
                            if (dialog.index === null)
                                copy.services.push(service);
                            else copy.services[dialog.index] = service;
                        })
                    }
                />
            )}
        </div>
    );
}

function ServiceDialog({
    service,
    readOnly,
    types,
    taken,
    published,
    onClose,
    onApply,
}: {
    service: Service | null;
    readOnly: boolean;
    types: { id: string; name: string }[];
    taken: string[];
    published: string[];
    onClose: () => void;
    onApply: (service: Service) => void;
}) {
    const isNew = service === null;
    return (
        <ConfigDialog<Service>
            title={isNew ? 'Add a service' : (service?.label ?? 'Service')}
            description={
                isNew
                    ? 'New services start turned off until you turn them on.'
                    : published.includes(service?.id ?? '')
                      ? 'Renaming keeps the history of referrals already made for this service.'
                      : 'This service hasn’t been published yet.'
            }
            initial={
                service ?? {
                    id: '',
                    label: '',
                    enabled: false,
                    context_types: null,
                }
            }
            readOnly={readOnly}
            onClose={onClose}
            applyLabel={isNew ? 'Add service' : 'Apply to draft'}
            validate={(draft) =>
                !draft.label.trim()
                    ? 'Give the service a name.'
                    : draft.context_types && !draft.context_types.length
                      ? 'Choose at least one kind of setting, or allow any setting.'
                      : null
            }
            onApply={(draft) =>
                onApply({
                    ...draft,
                    label: draft.label.trim(),
                    id: draft.id || referenceFor(draft.label, taken),
                })
            }
        >
            {(draft, set) => (
                <>
                    <Field label="Service name" required>
                        <Input
                            value={draft.label}
                            readOnly={readOnly}
                            maxLength={120}
                            onChange={(event) =>
                                set({ ...draft, label: event.target.value })
                            }
                        />
                    </Field>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-[13px] font-medium">
                            Offer this service for new enquiries
                        </span>
                        <OnOff
                            label="Offer this service for new enquiries"
                            checked={draft.enabled}
                            disabled={readOnly}
                            onChange={(enabled) => set({ ...draft, enabled })}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-[13px] font-medium">
                            Limit the kinds of setting it can start in
                        </span>
                        <OnOff
                            label="Limit the kinds of setting it can start in"
                            checked={draft.context_types != null}
                            disabled={readOnly}
                            onChange={(limited) =>
                                set({
                                    ...draft,
                                    context_types: limited ? [] : null,
                                })
                            }
                        />
                    </div>
                    {draft.context_types != null && (
                        <fieldset
                            disabled={readOnly}
                            className="grid gap-x-5 gap-y-2 sm:grid-cols-2"
                        >
                            <legend className="text-caption mb-2">
                                Applies to support preparation, handover and the
                                service-start review. Site access still applies.
                            </legend>
                            {types.map((type) => (
                                <label
                                    key={type.id}
                                    className="text-subtle flex items-center gap-3"
                                >
                                    <Checkbox
                                        checked={
                                            draft.context_types?.includes(
                                                type.id,
                                            ) ?? false
                                        }
                                        onCheckedChange={(checked) => {
                                            const chosen = (
                                                draft.context_types ?? []
                                            ).filter((id) => id !== type.id);
                                            set({
                                                ...draft,
                                                context_types:
                                                    checked === true
                                                        ? [...chosen, type.id]
                                                        : chosen,
                                            });
                                        }}
                                    />
                                    {type.name}
                                </label>
                            ))}
                        </fieldset>
                    )}
                </>
            )}
        </ConfigDialog>
    );
}
