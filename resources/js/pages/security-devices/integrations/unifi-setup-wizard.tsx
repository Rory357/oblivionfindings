import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateTimeLong, formatTime } from '@/lib/datetime';
import {
    Bell,
    Building2,
    Check,
    ChevronsUpDown,
    Clock3,
    KeyRound,
    Settings2,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { UnifiProps } from './unifi';

export type UnifiSetupKind = 'key' | 'rotate' | 'mapping' | 'defaults';
export type UnifiSetupValues = {
    api_key: string;
    site_id: string;
    mapping_token: string;
    refresh_interval_minutes: number;
    alert_motion_events: boolean;
    alert_device_offline: boolean;
    quiet_hours_start: string | null;
    quiet_hours_end: string | null;
};
export type UnifiSetupChange = <FieldName extends keyof UnifiSetupValues>(
    field: FieldName,
    value: UnifiSetupValues[FieldName],
) => void;
export type UnifiSetupWizardProps = {
    kind: UnifiSetupKind;
    values: UnifiSetupValues;
    onChange: UnifiSetupChange;
    providerConnection: UnifiProps['providerConnection'];
    sites: UnifiProps['sites'];
    discoveredSites: UnifiProps['discoveredSites'];
    pending: boolean;
    uncertain: boolean;
    error: string;
    success: { title: string; description: string } | null;
    onSubmit: () => void;
    onClose: () => void;
};

type Choice = { value: string; label: string };
function RecordChoice({
    id,
    label,
    value,
    choices,
    onChange,
    disabled,
    invalid,
    errorId,
}: {
    id: string;
    label: string;
    value: string;
    choices: Choice[];
    onChange: (value: string) => void;
    disabled: boolean;
    invalid: boolean;
    errorId: string;
}) {
    const [open, setOpen] = useState(false);
    const current = choices.find((choice) => choice.value === value);
    return (
        <Popover
            open={open && !disabled}
            onOpenChange={(next) => {
                if (!disabled) setOpen(next);
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open && !disabled}
                    aria-invalid={invalid}
                    aria-describedby={invalid ? errorId : undefined}
                    disabled={disabled}
                    className="h-auto min-h-10 w-full justify-between text-left whitespace-normal"
                >
                    <span className="min-w-0 break-words">
                        {current?.label ??
                            (value
                                ? 'Selection no longer available'
                                : 'Choose ' + label)}
                    </span>
                    <ChevronsUpDown className="ml-2 size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-32px)] p-0"
                aria-label={label + ' choices'}
                onEscapeKeyDown={(event) => event.stopPropagation()}
            >
                <Command
                    label={'Search ' + label}
                    filter={(item, search) =>
                        choices
                            .find((choice) => choice.value === item)
                            ?.label.toLocaleLowerCase()
                            .includes(search.trim().toLocaleLowerCase())
                            ? 1
                            : 0
                    }
                >
                    <CommandInput
                        aria-label={'Search ' + label}
                        placeholder={'Search ' + label + '…'}
                    />
                    <CommandList>
                        <CommandEmpty>
                            No current choices match this search.
                        </CommandEmpty>
                        <CommandGroup>
                            {choices.map((choice) => (
                                <CommandItem
                                    key={choice.value}
                                    value={choice.value}
                                    onSelect={() => {
                                        if (disabled) return;
                                        onChange(choice.value);
                                        setOpen(false);
                                    }}
                                >
                                    <Check
                                        aria-hidden="true"
                                        className={
                                            choice.value === value
                                                ? 'opacity-100'
                                                : 'opacity-0'
                                        }
                                    />
                                    <span className="min-w-0 break-words">
                                        {choice.label}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

const reasonLabels: Record<string, string> = {
    provider_outage: 'Provider outage',
    credential_compromise: 'Credential compromise',
    planned_maintenance: 'Planned maintenance',
    security_review: 'Security review',
    other_operational_reason: 'Other operational reason',
};
function recordedTime(value: string | null | undefined) {
    if (!value) return 'Not recorded';
    if (!/(Z|[+-]\d{2}:\d{2})$/.test(value)) return 'Time zone not recorded';
    return formatDateTimeLong(value, 'Time unavailable');
}
const validClock = (value: string | null) =>
    value === null || value === '' || /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
/**
 * "10:00 pm" for a stored H:i quiet-hours time; the stored value and the
 * picker keep H:i. Mid-January is always NZDT (+13:00) with no clock change,
 * so the shared NZ formatter shows the wall-clock time exactly as saved.
 */
export function quietHoursTime(
    value: string | null | undefined,
    fallback: string,
): string {
    if (!value) return fallback;
    if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) return value;
    return formatTime(`2026-01-15T${value}:00+13:00`, value);
}
const connectionLabels: Record<string, string> = {
    connected: 'Connected',
    disconnected: 'Disconnected',
    error: 'Error',
    disabled: 'Disabled',
};

/** Root owns values, requests, authority and outcomes; this child owns presentation only. */
export function UnifiSetupWizard(props: UnifiSetupWizardProps) {
    return <SetupBody key={props.kind} {...props} />;
}
function SetupBody({
    kind,
    values,
    onChange,
    providerConnection,
    sites,
    discoveredSites,
    pending,
    uncertain,
    error,
    success,
    onSubmit,
    onClose,
}: UnifiSetupWizardProps) {
    const [step, setStep] = useState(0);
    const [attempted, setAttempted] = useState(false);
    const isKey = kind === 'key' || kind === 'rotate';
    const stopped =
        providerConnection?.status === 'disabled' ||
        providerConnection?.requires_credential_replacement === true;
    const compromised =
        providerConnection?.disabled_reason === 'credential_compromise';
    const locked = pending || uncertain;
    const title =
        kind === 'mapping'
            ? 'Map UniFi location'
            : kind === 'defaults'
              ? 'UniFi defaults'
              : kind === 'rotate'
                ? stopped
                    ? 'Replace UniFi API key'
                    : 'Rotate UniFi API key'
                : 'Save UniFi API key';
    const site = sites.find((item) => String(item.id) === values.site_id);
    const location = discoveredSites.find(
        (item) =>
            item.mapping_token === values.mapping_token &&
            item.mapping_token.length === 64,
    );
    const keyError = !values.api_key.trim()
        ? 'Enter an API key.'
        : values.api_key.length > 4096
          ? 'The API key must be 4096 characters or fewer.'
          : '';
    const siteError = site
        ? ''
        : values.site_id
          ? 'This Site is no longer available. Choose a current approved Site.'
          : 'Choose an approved Site.';
    const locationError = location
        ? ''
        : values.mapping_token
          ? 'This provider location is no longer available. Refresh the locations before saving.'
          : 'Choose a current UniFi location.';
    const intervalError =
        Number.isInteger(values.refresh_interval_minutes) &&
        values.refresh_interval_minutes >= 1 &&
        values.refresh_interval_minutes <= 1440
            ? ''
            : 'Enter a whole number of minutes from 1 to 1440.';
    // Quiet hours are both set or both clear; one end on its own would be
    // ignored, so it is refused here as it is on the server.
    const quietStart = values.quiet_hours_start || '',
        quietEnd = values.quiet_hours_end || '';
    const startError = !validClock(values.quiet_hours_start)
        ? 'Choose a valid start time.'
        : !quietStart && quietEnd
          ? 'Set a start time as well as an end time, or clear both to turn quiet hours off.'
          : quietStart && quietStart === quietEnd
            ? 'Quiet hours must start and end at different times.'
            : '';
    const endError = !validClock(values.quiet_hours_end)
        ? 'Choose a valid end time.'
        : quietStart && !quietEnd
          ? 'Set an end time as well as a start time, or clear both to turn quiet hours off.'
          : '';
    const valid = isKey
        ? !keyError
        : kind === 'mapping'
          ? !siteError && !locationError
          : !intervalError && !startError && !endError;
    const lastStep = kind === 'defaults' ? 2 : 1;
    const showErrors = attempted || step === lastStep;
    const steps: WizardStep[] =
        kind === 'defaults'
            ? [
                  {
                      key: 'collection',
                      label: 'Collection',
                      blurb: 'Refresh interval',
                      icon: Clock3,
                  },
                  {
                      key: 'alerts',
                      label: 'Alerts',
                      blurb: 'Events and quiet hours',
                      icon: Bell,
                  },
                  {
                      key: 'review',
                      label: 'Review',
                      blurb: 'Check before saving',
                      icon: Check,
                  },
              ]
            : [
                  {
                      key: 'details',
                      label: isKey ? 'API key' : 'Locations',
                      blurb: isKey
                          ? 'Enter the replacement securely'
                          : 'Choose current locations',
                      icon: isKey ? KeyRound : Building2,
                  },
                  {
                      key: 'review',
                      label: 'Review',
                      blurb: 'Check before saving',
                      icon: Check,
                  },
              ];
    const jump = (index: number) => {
        if (!locked && !success) setStep(index);
    };
    const close = () => {
        if (!pending) onClose();
    };
    const advance = () => {
        if (locked || success) return;
        setAttempted(true);
        const stepValid =
            kind === 'defaults'
                ? step === 0
                    ? !intervalError
                    : !startError && !endError
                : valid;
        if (stepValid) setStep(step + 1);
    };
    const save = () => {
        if (!locked && !success && valid && step === lastStep) onSubmit();
    };
    const saveLabel =
        kind === 'mapping'
            ? 'Save mapping'
            : kind === 'defaults'
              ? 'Save defaults'
              : kind === 'rotate'
                ? stopped
                    ? 'Save replacement key'
                    : 'Save new key'
                : 'Save key';
    const keyGuidance =
        'Saving a key does not connect UniFi. Collection remains stopped until a connection test succeeds.';
    return (
        <WizardShell
            open
            onClose={close}
            title={title}
            description="Review the current UniFi setup before saving."
            railIcon={
                isKey ? KeyRound : kind === 'mapping' ? Building2 : Settings2
            }
            railTitle={title}
            railSub="UniFi integration"
            steps={steps.map((item) => ({ ...item, disabled: locked }))}
            stepIndex={step}
            onStepClick={jump}
            footerStart={
                <>
                    <Button
                        variant="outline"
                        disabled={pending}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                    {step > 0 && (
                        <Button
                            variant="outline"
                            disabled={locked}
                            onClick={() => jump(step - 1)}
                        >
                            Back
                        </Button>
                    )}
                </>
            }
            footerEnd={
                step < lastStep ? (
                    <Button disabled={locked} onClick={advance}>
                        Continue
                    </Button>
                ) : (
                    <Button disabled={locked || !valid} onClick={save}>
                        {pending && <Spinner aria-hidden="true" />}
                        {pending ? 'Saving…' : saveLabel}
                    </Button>
                )
            }
            success={
                success ? (
                    <WizardSuccessPane
                        title={success.title}
                        blurb={
                            <>
                                {success.description}
                                {isKey && <> {keyGuidance}</>}
                                {compromised && (
                                    <>
                                        {' '}
                                        A saved replacement does not revoke the
                                        compromised key in UniFi. Revoke it
                                        there if you have not already done so.
                                    </>
                                )}
                            </>
                        }
                        actions={
                            <Button disabled={pending} onClick={close}>
                                Close
                            </Button>
                        }
                    />
                ) : undefined
            }
        >
            {error && (
                <Alert variant="destructive" className="mb-4">
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}
            {uncertain && (
                <div role="alert" className="mb-4">
                    <InfoCard icon={ShieldAlert} tone="warn">
                        The save was not confirmed. Read the current saved
                        status before trying again. Repeating Save is blocked.
                    </InfoCard>
                </div>
            )}
            {stopped && (
                <InfoCard icon={ShieldAlert} tone="warn">
                    <p>
                        UniFi collection and webhook intake are disabled.
                        Existing Devices, mappings and evidence remain
                        available.
                    </p>
                    <ReviewRow
                        label="Reason"
                        value={
                            reasonLabels[
                                providerConnection?.disabled_reason ?? ''
                            ] ?? 'Not recorded'
                        }
                    />
                    <ReviewRow
                        label="Disabled at"
                        value={recordedTime(providerConnection?.disabled_at)}
                    />
                    <p>
                        A replacement API key and a successful connection test
                        are needed to resume collection. If compromise is
                        suspected, revoke that key in UniFi as well.
                    </p>
                </InfoCard>
            )}
            {!stopped &&
                providerConnection?.status === 'disconnected' &&
                providerConnection.recovery_credentials_replaced_at && (
                    <InfoCard icon={KeyRound}>
                        Replacement credentials were saved{' '}
                        {recordedTime(
                            providerConnection.recovery_credentials_replaced_at,
                        )}
                        . Collection remains stopped until a connection test
                        succeeds.
                    </InfoCard>
                )}
            <WizardStepPane>
                {step === lastStep ? (
                    <div className="space-y-4">
                        <StepHead
                            icon={Check}
                            title="Review UniFi setup"
                            blurb="Check these entries before saving. Saved settings do not establish current Device health."
                        />
                        {!valid && (
                            <p role="alert" className="text-status-critical">
                                Some entries are missing or no longer available.
                                Go Back and correct them before saving.
                            </p>
                        )}
                        <ReviewCard
                            icon={
                                isKey
                                    ? KeyRound
                                    : kind === 'mapping'
                                      ? Building2
                                      : Settings2
                            }
                            title={
                                isKey
                                    ? 'API key'
                                    : kind === 'mapping'
                                      ? 'Location mapping'
                                      : 'Collection'
                            }
                            onEdit={locked ? undefined : () => jump(0)}
                        >
                            {isKey ? (
                                <>
                                    <ReviewRow
                                        label="API key"
                                        value={
                                            !keyError
                                                ? 'New key entered'
                                                : 'Valid key required'
                                        }
                                    />
                                    <ReviewRow
                                        label="Existing key"
                                        value={
                                            providerConnection?.secret_last4
                                                ? '••••' +
                                                  providerConnection.secret_last4.slice(
                                                      -4,
                                                  )
                                                : 'Not configured'
                                        }
                                    />
                                    <ReviewRow
                                        label="Current connection"
                                        value={
                                            providerConnection
                                                ? (connectionLabels[
                                                      providerConnection.status
                                                  ] ?? 'Not recorded')
                                                : 'Not configured'
                                        }
                                    />
                                    <p className="text-caption mt-2">
                                        {keyGuidance}
                                    </p>
                                </>
                            ) : kind === 'mapping' ? (
                                <>
                                    <ReviewRow
                                        label="Approved Site"
                                        value={site?.name ?? 'Site unavailable'}
                                    />
                                    <ReviewRow
                                        label="UniFi location"
                                        value={
                                            location?.name ??
                                            'Provider location unavailable'
                                        }
                                    />
                                    <ReviewRow
                                        label="Reported device count"
                                        value={
                                            location?.device_count ??
                                            'Not recorded'
                                        }
                                    />
                                </>
                            ) : (
                                <ReviewRow
                                    label="Refresh interval (minutes)"
                                    value={values.refresh_interval_minutes}
                                />
                            )}
                        </ReviewCard>
                        {kind === 'defaults' && (
                            <ReviewCard
                                icon={Bell}
                                title="Alerts and quiet hours"
                                onEdit={locked ? undefined : () => jump(1)}
                            >
                                <ReviewRow
                                    label="Alert on motion events"
                                    value={
                                        values.alert_motion_events
                                            ? 'On'
                                            : 'Off'
                                    }
                                />
                                <ReviewRow
                                    label="Alert when a device goes offline"
                                    value={
                                        values.alert_device_offline
                                            ? 'On'
                                            : 'Off'
                                    }
                                />
                                <ReviewRow
                                    label="Quiet hours start"
                                    value={quietHoursTime(
                                        values.quiet_hours_start,
                                        'Not set',
                                    )}
                                />
                                <ReviewRow
                                    label="Quiet hours end"
                                    value={quietHoursTime(
                                        values.quiet_hours_end,
                                        'Not set',
                                    )}
                                />
                                <p className="text-caption mt-2">
                                    New Zealand time. Critical alerts are never
                                    held back.
                                </p>
                            </ReviewCard>
                        )}
                    </div>
                ) : (
                    <fieldset disabled={locked} className="space-y-5">
                        {isKey ? (
                            <>
                                <StepHead
                                    icon={KeyRound}
                                    title="API key"
                                    blurb="Enter the new key. It will never appear in the review or success pane."
                                />
                                <Field
                                    label="API key"
                                    required
                                    htmlFor="unifi-setup-api-key"
                                    error={showErrors ? keyError : undefined}
                                    errorId="unifi-setup-api-key-error"
                                >
                                    <Input
                                        id="unifi-setup-api-key"
                                        type="password"
                                        autoComplete="new-password"
                                        value={values.api_key}
                                        onChange={(event) => {
                                            if (!locked)
                                                onChange(
                                                    'api_key',
                                                    event.target.value,
                                                );
                                        }}
                                        aria-invalid={showErrors && !!keyError}
                                        aria-describedby={
                                            showErrors && keyError
                                                ? 'unifi-setup-api-key-error'
                                                : undefined
                                        }
                                    />
                                </Field>
                                <p className="text-subtle">{keyGuidance}</p>
                            </>
                        ) : kind === 'mapping' ? (
                            <>
                                <StepHead
                                    icon={Building2}
                                    title="Choose locations"
                                    blurb="Choose one approved Site and a location from the current UniFi discovery."
                                />
                                <Field
                                    label="Approved Site"
                                    required
                                    htmlFor="unifi-setup-site"
                                    error={
                                        showErrors || values.site_id
                                            ? siteError
                                            : undefined
                                    }
                                    errorId="unifi-setup-site-error"
                                >
                                    <RecordChoice
                                        id="unifi-setup-site"
                                        label="Approved Site"
                                        value={values.site_id}
                                        choices={sites.map((item) => ({
                                            value: String(item.id),
                                            label:
                                                item.name +
                                                ' · Site ' +
                                                item.id,
                                        }))}
                                        onChange={(value) =>
                                            onChange('site_id', value)
                                        }
                                        disabled={locked}
                                        invalid={
                                            (showErrors || !!values.site_id) &&
                                            !!siteError
                                        }
                                        errorId="unifi-setup-site-error"
                                    />
                                </Field>
                                <Field
                                    label="UniFi location"
                                    required
                                    htmlFor="unifi-setup-location"
                                    error={
                                        showErrors || values.mapping_token
                                            ? locationError
                                            : undefined
                                    }
                                    errorId="unifi-setup-location-error"
                                >
                                    <RecordChoice
                                        id="unifi-setup-location"
                                        label="UniFi location"
                                        value={values.mapping_token}
                                        choices={discoveredSites
                                            .filter(
                                                (item) =>
                                                    item.mapping_token
                                                        .length === 64,
                                            )
                                            .map((item) => ({
                                                value: item.mapping_token,
                                                label: item.name,
                                            }))}
                                        onChange={(value) =>
                                            onChange('mapping_token', value)
                                        }
                                        disabled={locked}
                                        invalid={
                                            (showErrors ||
                                                !!values.mapping_token) &&
                                            !!locationError
                                        }
                                        errorId="unifi-setup-location-error"
                                    />
                                </Field>
                                {!discoveredSites.length && (
                                    <p role="status" className="text-subtle">
                                        No provider locations are available.
                                        Sync UniFi locations before creating a
                                        mapping.
                                    </p>
                                )}
                            </>
                        ) : step === 0 ? (
                            <>
                                <StepHead
                                    icon={Clock3}
                                    title="Collection defaults"
                                    blurb="Set the refresh interval. Changing this setting does not restart a stopped connection."
                                />
                                <Field
                                    label="Refresh interval (minutes)"
                                    required
                                    htmlFor="unifi-setup-refresh"
                                    error={intervalError || undefined}
                                    errorId="unifi-setup-refresh-error"
                                >
                                    <Input
                                        id="unifi-setup-refresh"
                                        type="number"
                                        min={1}
                                        max={1440}
                                        step={1}
                                        value={
                                            Number.isFinite(
                                                values.refresh_interval_minutes,
                                            )
                                                ? values.refresh_interval_minutes
                                                : ''
                                        }
                                        onChange={(event) => {
                                            if (!locked)
                                                onChange(
                                                    'refresh_interval_minutes',
                                                    event.target.value === ''
                                                        ? NaN
                                                        : Number(
                                                              event.target
                                                                  .value,
                                                          ),
                                                );
                                        }}
                                        aria-invalid={!!intervalError}
                                        aria-describedby={
                                            intervalError
                                                ? 'unifi-setup-refresh-error'
                                                : undefined
                                        }
                                    />
                                </Field>
                            </>
                        ) : (
                            <>
                                <StepHead
                                    icon={Bell}
                                    title="Alerts and quiet hours"
                                    blurb="Choose which events raise alerts. Quiet hours are optional; set both a start and an end, in New Zealand time. Critical alerts are never held back."
                                />
                                <Field
                                    label="Alert on motion events"
                                    htmlFor="unifi-setup-motion"
                                >
                                    <Checkbox
                                        id="unifi-setup-motion"
                                        checked={values.alert_motion_events}
                                        onCheckedChange={(checked) => {
                                            if (!locked)
                                                onChange(
                                                    'alert_motion_events',
                                                    checked === true,
                                                );
                                        }}
                                    />
                                </Field>
                                <Field
                                    label="Alert when a device goes offline"
                                    htmlFor="unifi-setup-offline"
                                >
                                    <Checkbox
                                        id="unifi-setup-offline"
                                        checked={values.alert_device_offline}
                                        onCheckedChange={(checked) => {
                                            if (!locked)
                                                onChange(
                                                    'alert_device_offline',
                                                    checked === true,
                                                );
                                        }}
                                    />
                                </Field>
                                {(
                                    [
                                        'quiet_hours_start',
                                        'quiet_hours_end',
                                    ] as const
                                ).map((field) => {
                                    const label =
                                        field === 'quiet_hours_start'
                                            ? 'Quiet hours start'
                                            : 'Quiet hours end';
                                    const fieldError =
                                        field === 'quiet_hours_start'
                                            ? startError
                                            : endError;
                                    const id = 'unifi-setup-' + field;
                                    return (
                                        <Field
                                            key={field}
                                            label={label}
                                            htmlFor={id}
                                            hint="Optional"
                                            error={fieldError || undefined}
                                            errorId={id + '-error'}
                                        >
                                            <div className="flex min-w-0 items-center gap-2">
                                                <TimePicker
                                                    id={id}
                                                    label={label}
                                                    value={
                                                        validClock(
                                                            values[field],
                                                        )
                                                            ? (values[field] ??
                                                              '')
                                                            : ''
                                                    }
                                                    onChange={(value) => {
                                                        if (!locked)
                                                            onChange(
                                                                field,
                                                                value,
                                                            );
                                                    }}
                                                    invalid={!!fieldError}
                                                    describedBy={
                                                        fieldError
                                                            ? id + '-error'
                                                            : undefined
                                                    }
                                                    timezone="New Zealand time"
                                                    compact
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    disabled={
                                                        locked || !values[field]
                                                    }
                                                    aria-label={
                                                        'Clear ' +
                                                        label.toLowerCase()
                                                    }
                                                    onClick={() => {
                                                        if (!locked)
                                                            onChange(
                                                                field,
                                                                null,
                                                            );
                                                    }}
                                                >
                                                    Clear
                                                </Button>
                                            </div>
                                        </Field>
                                    );
                                })}
                            </>
                        )}
                    </fieldset>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
