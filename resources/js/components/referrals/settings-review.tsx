import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { Eye, PenLine, Send, type LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ReferralDateTime, validReferralDateTime } from './date-time';
import { SettingsImpact } from './settings-impact';
import {
    describeChange,
    type configurationChanges,
    type ConfigurationVersion,
    type ReferralConfiguration,
    type SettingsImpactSummary,
} from './settings-types';
import { OnOff } from './settings-ui';

export type ConfigurationChange = ReturnType<
    typeof configurationChanges
>[number];

/** Every changed value, for the reviewer who wants the detail behind the summary. */
export function ChangeList({
    changes,
    configuration,
    open = false,
}: {
    changes: ConfigurationChange[];
    configuration: ReferralConfiguration;
    open?: boolean;
}) {
    if (!changes.length)
        return <p className="text-subtle">No settings values change.</p>;
    return (
        <details open={open} className="rounded-lg border border-border">
            <summary className="cursor-pointer rounded-lg px-4 py-3 text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                Every changed value ({changes.length})
            </summary>
            <dl className="scrollbar-pretty max-h-72 space-y-3 overflow-y-auto border-t border-border px-4 py-3">
                {changes.map((change) => (
                    <div key={change.label}>
                        <dt className="text-caption font-medium text-foreground">
                            {describeChange(change.label, configuration)}
                        </dt>
                        <dd className="text-subtle grid gap-1 break-words sm:grid-cols-2">
                            <span>Before: {change.before}</span>
                            <span>After: {change.after}</span>
                        </dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

export type PreviewState =
    | { state: 'loading' }
    | { state: 'error'; message: string }
    | {
          state: 'ready';
          impact: SettingsImpactSummary;
          governance_required: boolean;
      };

const loosens = (impact?: SettingsImpactSummary | null) =>
    (impact?.lines ?? []).some((line) => line.loosens);

function ReviewWizard({
    title,
    description,
    railTitle,
    railSub,
    noteStep,
    impactStep,
    noteReady,
    submitLabel,
    destructive,
    busy,
    error,
    onClose,
    onSubmit,
}: {
    title: string;
    description: string;
    railTitle: string;
    railSub: string;
    noteStep: {
        label: string;
        blurb: string;
        icon: LucideIcon;
        body: ReactNode;
    };
    impactStep: ReactNode;
    noteReady: boolean;
    submitLabel: string;
    destructive: boolean;
    busy: boolean;
    error: string;
    onClose: () => void;
    onSubmit: () => void;
}) {
    const [step, setStep] = useState(0);
    const steps = [
        {
            key: 'impact',
            label: 'Impact & changes',
            blurb: 'What changes for whom',
            icon: Eye,
        },
        {
            key: 'note',
            label: noteStep.label,
            blurb: noteStep.blurb,
            icon: noteStep.icon,
            disabled: busy,
        },
    ];
    return (
        <WizardShell
            open
            maxWidth="min(92vw, 1100px)"
            onClose={() => !busy && onClose()}
            title={title}
            description={description}
            railIcon={noteStep.icon}
            railTitle={railTitle}
            railSub={railSub}
            steps={steps}
            stepIndex={step}
            onStepClick={(index) => !busy && setStep(index)}
            pct={step === 0 ? 50 : noteReady ? 100 : 50}
            pctLabel="Review"
            footerStart={
                <Button variant="outline" disabled={busy} onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                step === 0 ? (
                    <Button onClick={() => setStep(1)}>Continue</Button>
                ) : (
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setStep(0)}
                        >
                            Back
                        </Button>
                        <Button
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={busy || !noteReady}
                            onClick={onSubmit}
                        >
                            {submitLabel}
                        </Button>
                    </>
                )
            }
        >
            <WizardStepPane key={step}>
                <div className="space-y-4">
                    {error && <SettingsNotice>{error}</SettingsNotice>}
                    {step === 0 ? impactStep : noteStep.body}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/** "Review changes": what the working draft changes, then a change note, then save it for someone else to publish. */
export function SaveDraftWizard({
    preview,
    onRetryPreview,
    changes,
    configuration,
    busy,
    error,
    recovering,
    onClose,
    onSave,
}: {
    preview: PreviewState;
    onRetryPreview: () => void;
    changes: ConfigurationChange[];
    configuration: ReferralConfiguration;
    busy: boolean;
    error: string;
    recovering: boolean;
    onClose: () => void;
    onSave: (reason: string) => void;
}) {
    const [reason, setReason] = useState('');
    const ready = preview.state === 'ready';
    const governed = ready && preview.governance_required;
    const weaker = ready && loosens(preview.impact);
    return (
        <ReviewWizard
            title="Review settings changes"
            description="Check what changes for whom, then save a draft for another person to publish."
            railTitle="Save a draft"
            railSub={`${changes.length} ${changes.length === 1 ? 'change' : 'changes'} · nothing applies until published`}
            impactStep={
                preview.state === 'loading' ? (
                    <LoadingState message="Checking your changes…" />
                ) : preview.state === 'error' ? (
                    <ErrorState
                        title="Your changes couldn’t be checked"
                        message={preview.message}
                        onRetry={onRetryPreview}
                    />
                ) : (
                    <>
                        {(governed || weaker) && (
                            <SettingsNotice>
                                {weaker
                                    ? 'These changes loosen a safeguard. '
                                    : ''}
                                A person who can approve weaker safeguards must
                                publish this draft.
                            </SettingsNotice>
                        )}
                        <SettingsImpact impact={preview.impact} />
                        <ChangeList
                            changes={changes}
                            configuration={configuration}
                        />
                    </>
                )
            }
            noteStep={{
                label: 'Change note',
                blurb: 'Why you made the change',
                icon: PenLine,
                body: (
                    <>
                        <p className="text-subtle">
                            Saving a draft doesn’t change the website or any
                            referral. A different person reviews and publishes
                            it.
                        </p>
                        <Field label="Reason for this change" required>
                            <Textarea
                                value={reason}
                                rows={5}
                                maxLength={2000}
                                disabled={busy || recovering}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                        </Field>
                    </>
                ),
            }}
            noteReady={ready && (recovering || reason.trim().length >= 5)}
            submitLabel={recovering ? 'Retry saving the draft' : 'Save draft'}
            destructive={governed || weaker}
            busy={busy}
            error={error}
            onClose={onClose}
            onSubmit={() => onSave(reason)}
        />
    );
}

/** "Review and publish": the draft's impact, then when it starts and a required publication note. */
export function PublishWizard({
    version,
    impact,
    governanceRequired,
    changes,
    configuration,
    busy,
    error,
    recovering,
    onClose,
    onPublish,
}: {
    version: ConfigurationVersion;
    impact: SettingsImpactSummary | null;
    governanceRequired: boolean;
    changes: ConfigurationChange[];
    configuration: ReferralConfiguration;
    busy: boolean;
    error: string;
    recovering: boolean;
    onClose: () => void;
    onPublish: (input: { note: string; effective: string }) => void;
}) {
    const [note, setNote] = useState('');
    const [effective, setEffective] = useState('');
    const [scheduled, setScheduled] = useState(false);
    const startValid = validReferralDateTime(effective);
    const weaker = loosens(impact);
    return (
        <ReviewWizard
            title={`Publish version ${version.version}`}
            description="Check what changes for whom, then record when it starts and why it was approved."
            railTitle={`Version ${version.version}`}
            railSub={`Saved by ${version.created_by_name ?? 'a colleague'}`}
            impactStep={
                <>
                    <p className="text-subtle">
                        Reason given: {version.reason}
                    </p>
                    {(governanceRequired || weaker) && (
                        <SettingsNotice>
                            {weaker ? 'This draft loosens a safeguard. ' : ''}
                            By publishing, you also record your approval of
                            these safeguard changes.
                        </SettingsNotice>
                    )}
                    {impact ? (
                        <SettingsImpact impact={impact} />
                    ) : (
                        <p className="text-subtle">
                            The impact of this draft couldn’t be shown. Reload
                            the page to check it again.
                        </p>
                    )}
                    <ChangeList
                        changes={changes}
                        configuration={configuration}
                    />
                </>
            }
            noteStep={{
                label: 'Publication note',
                blurb: 'When it starts and why',
                icon: Send,
                body: (
                    <>
                        <div className="flex items-center justify-between gap-4">
                            <span className="text-[13px] font-medium">
                                Start at a later date and time
                            </span>
                            <OnOff
                                label="Start at a later date and time"
                                checked={scheduled}
                                disabled={busy || recovering}
                                onChange={(next) => {
                                    setScheduled(next);
                                    if (!next) setEffective('');
                                }}
                            />
                        </div>
                        {scheduled ? (
                            <ReferralDateTime
                                id="settings-publish-start"
                                label="Starts"
                                value={effective}
                                onChange={setEffective}
                                error={
                                    effective && !startValid
                                        ? 'Choose a date and time that happens once in NZ time.'
                                        : undefined
                                }
                            />
                        ) : (
                            <p className="text-caption">
                                The version starts as soon as you publish it.
                            </p>
                        )}
                        <Field label="Reason and approval reference" required>
                            <Textarea
                                value={note}
                                rows={5}
                                maxLength={2000}
                                disabled={busy || recovering}
                                onChange={(event) =>
                                    setNote(event.target.value)
                                }
                            />
                        </Field>
                        <p className="text-caption">
                            New enquiries use this version from its start. Open
                            referrals keep their current settings until someone
                            moves them.
                        </p>
                    </>
                ),
            }}
            noteReady={
                recovering ||
                (note.trim().length >= 5 && (!scheduled || startValid))
            }
            submitLabel={
                recovering
                    ? 'Retry publishing'
                    : `Publish version ${version.version}`
            }
            destructive={governanceRequired || weaker}
            busy={busy}
            error={error}
            onClose={onClose}
            onSubmit={() => onPublish({ note, effective })}
        />
    );
}
