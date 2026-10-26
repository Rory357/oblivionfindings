import {
    ActualEventTimeField,
    actualEventInstant,
} from '@/components/clinical/actual-event-time-field';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { approvalModeLabel } from '@/components/health-safety/settings/approval-policy';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    restraintHref,
    type RestraintPlanCommand,
    type RestraintWorkspace,
} from '@/lib/health-safety/restraint-navigation';
import { useMemo } from 'react';
import { SourceCommandStatus } from './command-status';
import {
    EMPTY_RESTRAINT_PICKERS,
    type PlanDetail,
    type RestraintPickers,
} from './types';
import { useCaptureChoices } from './use-capture-choices';
import { useSourceCommand, type CommandContext } from './use-source-command';

export const PLAN_COMMAND_LABELS: Record<
    RestraintPlanCommand | 'create',
    string
> = {
    create: 'Create behaviour-support plan',
    edit: 'Edit plan candidate',
    'submit-review': 'Send for review',
    activate: 'Request activation',
    archive: 'Archive plan',
    review: 'Record plan review',
    decision: 'Record approval decision',
};
const canKey = (command: RestraintPlanCommand) =>
    command === 'decision'
        ? 'approve'
        : (command.replaceAll('-', '_') as
              | 'edit'
              | 'submit_review'
              | 'activate'
              | 'archive'
              | 'review');
const receiptCommand = (command: RestraintPlanCommand | 'create') =>
    command === 'edit'
        ? 'update'
        : command === 'decision'
          ? 'approval'
          : command.replaceAll('-', '_');

export function BehaviourSupportPlanCommandForm({
    detail,
    command,
    workspace = 'view',
    pickers: provided,
    returnTo,
    onDone,
    onCancel,
    prescope,
    captureAllowed = true,
    captureCheckHref = '/health-safety/restraints/report?kind=plan',
}: {
    detail: PlanDetail | null;
    command: RestraintPlanCommand | 'create';
    workspace?: RestraintWorkspace;
    pickers?: Partial<RestraintPickers>;
    returnTo: string;
    onDone: (href?: string | null) => void;
    onCancel: () => void;
    prescope?: {
        clientId?: number;
        siteId?: number;
        stayId?: number;
        locked?: boolean;
    };
    captureAllowed?: boolean;
    captureCheckHref?: string;
}) {
    const choices = useCaptureChoices(provided, captureCheckHref, 'plan');
    const pickers =
        command === 'create'
            ? choices.pickers
            : { ...EMPTY_RESTRAINT_PICKERS, ...provided };
    const content = detail?.content;
    const targeted =
        command === 'decision' ||
        command === 'activate' ||
        command === 'review';
    const context = useMemo<CommandContext | null>(
        () =>
            detail
                ? {
                      id: detail.id,
                      version: detail.lock_version,
                      allowed:
                          command !== 'create' && detail.can[canKey(command)],
                      ...(targeted
                          ? {
                                policyRevision:
                                    detail.current_policy?.policy_revision,
                                candidateId: detail.candidate?.id,
                                candidateDigest:
                                    detail.candidate?.content_digest,
                            }
                          : {}),
                  }
                : null,
        [detail, command, targeted],
    );
    const source = useSourceCommand({
        kind: 'plan',
        command: receiptCommand(command),
        context,
        captureAllowed: captureAllowed && choices.ready,
        receiptKey: 'behaviour_support_plan_confirmation',
        // A normal review may create a new candidate when its review date changes.
        // Approval and activation instead decide the exact existing candidate.
        childKey:
            (command === 'decision' || command === 'activate') &&
            detail?.candidate
                ? 'candidate_version_id'
                : undefined,
        childId:
            command === 'decision' || command === 'activate'
                ? detail?.candidate?.id
                : undefined,
        checkHref: detail
            ? restraintHref('plans', detail.id, {
                  workspace: command === 'decision' ? 'approval' : workspace,
              })
            : captureCheckHref,
        readContext: (value) => {
            const response = value as {
                detail?: PlanDetail;
                can?: { create_plan: boolean };
            };
            const d = response?.detail;
            return command === 'create'
                ? {
                      id: 0,
                      version: 0,
                      allowed: response.can?.create_plan === true,
                  }
                : d
                  ? {
                        id: d.id,
                        version: d.lock_version,
                        allowed: d.can[canKey(command)],
                        ...(targeted
                            ? {
                                  policyRevision:
                                      d.current_policy?.policy_revision,
                                  candidateId: d.candidate?.id,
                                  candidateDigest: d.candidate?.content_digest,
                              }
                            : {}),
                    }
                  : null;
        },
        initial: {
            client_id: String(prescope?.clientId ?? ''),
            site_id: String(prescope?.siteId ?? ''),
            title: content?.title ?? '',
            triggers: content?.triggers ?? '',
            de_escalation_strategies: content?.de_escalation_strategies ?? '',
            approved_interventions: content?.approved_interventions ?? '',
            prohibited_interventions: content?.prohibited_interventions ?? '',
            restrictive_practice_type: content?.restrictive_practice_type ?? '',
            developed_by: String(content?.developed_by ?? ''),
            developed_at: content?.developed_at ?? '',
            review_date: content?.review_date ?? '',
            notes:
                command === 'create' || command === 'edit'
                    ? (content?.notes ?? '')
                    : '',
            outcome: '',
            reviewed_at: '',
            reviewed_offset: '',
            next_review_date: content?.review_date ?? '',
            resulting_status: '',
            approval_decision: '',
            origin:
                detail?.current_policy?.mode === 'authorised_reviewer'
                    ? 'authorised_reviewer'
                    : 'internal_specialist',
            approver_id: '',
            approver_role: '',
            approved_at: '',
            approved_offset: '',
            effective_from: '',
            effective_offset: '',
            effective_to: '',
            effective_to_offset: '',
            basis: '',
            external_name: '',
            external_role: '',
            external_organisation: '',
            external_reference: '',
            external_decision_date: '',
            supersedes_approval_id: '',
            credential_id: '',
            activate_requested: false,
            evidence_keys: [] as string[],
        },
    });
    const { form, change } = source;
    const checkedDetail = (
        source.checkedResponse as { detail?: PlanDetail } | null
    )?.detail;
    const liveDetail = checkedDetail ?? detail;
    const approvalOptions = liveDetail?.approval_options;
    const currentFacts = checkedDetail
        ? [
              {
                  label: 'Current record version',
                  value: String(checkedDetail.lock_version),
              },
              { label: 'Current plan status', value: checkedDetail.status },
              {
                  label: 'Current approval policy',
                  value: approvalModeLabel(
                      checkedDetail.current_policy?.mode ?? null,
                  ),
              },
              {
                  label: 'Current candidate',
                  value: checkedDetail.candidate
                      ? 'Version ' + checkedDetail.candidate.sequence
                      : null,
              },
              ...Object.entries(
                  checkedDetail.candidate?.content ?? checkedDetail.content,
              )
                  .filter(([key]) => key !== 'developed_by')
                  .map(([key, value]) => ({
                      label: key.replaceAll('_', ' '),
                      value: value == null ? null : String(value),
                  })),
          ]
        : undefined;
    const chooseClient = (value: string) =>
        void choices.select(value, (id, site) => {
            change('client_id', id);
            change('site_id', site);
            change('developed_by', '');
        });
    type Data = typeof form.data;
    const text = (key: keyof Data, label: string, required = false) => (
        <Field
            key={key}
            label={label}
            required={required}
            error={form.errors[key]}
        >
            <Textarea
                value={String(form.data[key] ?? '')}
                required={required}
                onChange={(event) => change(key, event.target.value as never)}
            />
        </Field>
    );
    const select = (
        key: keyof Data,
        label: string,
        options: { value: string; label: string }[],
        required = false,
    ) => (
        <Field
            key={key}
            label={label}
            required={required}
            error={form.errors[key]}
        >
            <SelectInput
                frontline
                value={String(form.data[key])}
                options={options}
                placeholder="Choose an option"
                onChange={(value) => {
                    change(key, value as never);
                    if (key === 'origin') {
                        change('approver_id', '');
                        change('credential_id', '');
                    }
                }}
            />
        </Field>
    );
    const person = (
        key: keyof Data,
        label: string,
        options: { id: number; name: string }[],
    ) => (
        <Field key={key} label={label} error={form.errors[key]}>
            {key === 'client_id' && prescope?.locked ? (
                <p className="text-subtle">
                    {pickers.clients.find(
                        (item) => String(item.id) === form.data.client_id,
                    )?.name ?? 'Current stay person'}
                </p>
            ) : (
                <>
                    <HazardStaffPicker
                        value={String(form.data[key])}
                        onChange={(value) => {
                            if (key === 'client_id' && command === 'create')
                                chooseClient(value);
                            else {
                                change(key, value as never);
                                if (key === 'approver_id')
                                    change('credential_id', '');
                            }
                        }}
                        options={options.map((item) => ({
                            value: String(item.id),
                            label: item.name,
                        }))}
                        placeholder={label}
                        listLabel={label}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        className="frontline-hit mt-3"
                        onClick={() =>
                            key === 'client_id' && command === 'create'
                                ? chooseClient('')
                                : change(key, '' as never)
                        }
                    >
                        Clear selection
                    </Button>
                </>
            )}
        </Field>
    );
    const date = (key: keyof Data, label: string) => (
        <Field key={key} label={label} error={form.errors[key]}>
            <DatePicker
                compact
                id={'plan-' + String(key)}
                label={label}
                value={String(form.data[key])}
                onChange={(value) => change(key, value as never)}
                allowClear
            />
        </Field>
    );
    const time = (
        key: keyof Data,
        offset: keyof Data,
        label: string,
        actual = true,
        required = true,
    ) =>
        actual ? (
            <ActualEventTimeField
                key={key}
                id={'plan-' + String(key)}
                label={label}
                value={String(form.data[key])}
                offset={String(form.data[offset])}
                onChange={(value, zone) => {
                    change(key, value as never);
                    change(offset, zone as never);
                }}
                error={form.errors[key]}
            />
        ) : (
            <DrillTimeField
                key={key}
                label={label}
                value={String(form.data[key])}
                offset={String(form.data[offset])}
                onChange={(value, zone) => {
                    change(key, value as never);
                    change(offset, zone as never);
                }}
                required={required}
            />
        );
    const instant = (key: keyof Data, offset: keyof Data) =>
        form.data[key]
            ? actualEventInstant(
                  String(form.data[key]),
                  String(form.data[offset]),
              )
            : null;
    const target = () => ({
        candidate_version_id: source.guard.candidateId,
        content_digest: source.guard.candidateDigest,
    });
    const values = (): Record<string, unknown> => {
        const v = form.data;
        if (command === 'create' || command === 'edit')
            return {
                title: v.title,
                triggers: v.triggers || null,
                de_escalation_strategies: v.de_escalation_strategies || null,
                approved_interventions: v.approved_interventions || null,
                prohibited_interventions: v.prohibited_interventions || null,
                restrictive_practice_type: v.restrictive_practice_type || null,
                ...(command === 'create' ||
                v.developed_by !== String(content?.developed_by ?? '')
                    ? {
                          developed_by: v.developed_by
                              ? Number(v.developed_by)
                              : null,
                      }
                    : {}),
                developed_at: v.developed_at || null,
                review_date: v.review_date || null,
                notes: v.notes || null,
                ...(command === 'create'
                    ? {
                          client_id: Number(v.client_id),
                          ...(v.site_id ? { site_id: Number(v.site_id) } : {}),
                          status: 'draft',
                          ...(prescope?.stayId
                              ? { stay_id: prescope.stayId }
                              : {}),
                      }
                    : {}),
            };
        if (command === 'activate') return target();
        if (command === 'review')
            return {
                outcome: v.outcome,
                reviewed_at: instant('reviewed_at', 'reviewed_offset'),
                notes: v.notes || null,
                ...(v.next_review_date !== content?.review_date
                    ? { next_review_date: v.next_review_date || null }
                    : {}),
                ...(v.resulting_status
                    ? { resulting_status: v.resulting_status }
                    : {}),
                ...(v.resulting_status === 'active' ? target() : {}),
            };
        if (command === 'decision')
            return {
                ...target(),
                approval_decision: v.approval_decision,
                origin: v.origin,
                approver_id:
                    v.origin === 'external_specialist'
                        ? null
                        : Number(v.approver_id),
                approver_role: v.approver_role,
                approved_at: instant('approved_at', 'approved_offset'),
                effective_from: instant('effective_from', 'effective_offset'),
                effective_to: instant('effective_to', 'effective_to_offset'),
                basis: v.basis,
                evidence_references: v.evidence_keys.map((key) => {
                    const [type, id] = key.split(':');
                    return { type, id: Number(id) };
                }),
                activate_requested: v.activate_requested,
                ...(v.credential_id
                    ? { credential_id: Number(v.credential_id) }
                    : {}),
                ...(v.supersedes_approval_id
                    ? {
                          supersedes_approval_id: Number(
                              v.supersedes_approval_id,
                          ),
                      }
                    : {}),
                ...(v.origin === 'external_specialist'
                    ? {
                          external_name: v.external_name,
                          external_role: v.external_role,
                          external_organisation: v.external_organisation,
                          external_reference: v.external_reference,
                          external_decision_date: v.external_decision_date,
                      }
                    : {}),
            };
        return {};
    };
    const endpoint =
        command === 'create'
            ? '/health-safety/restraints/plans'
            : detail?.commands.endpoints[
                  command === 'edit'
                      ? 'update'
                      : (command.replaceAll(
                            '-',
                            '_',
                        ) as keyof PlanDetail['commands']['endpoints'])
              ];
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (endpoint)
                    source.save(command === 'edit' ? 'put' : 'post', endpoint, {
                        ...values(),
                        return_to: returnTo,
                        action: command === 'create' ? null : command,
                        section:
                            command === 'decision'
                                ? 'approval'
                                : command === 'review'
                                  ? 'review'
                                  : 'content',
                    });
            }}
        >
            <h2 className="text-section-title">
                {PLAN_COMMAND_LABELS[command]}
            </h2>
            {targeted && (
                <p className="text-subtle text-muted-foreground">
                    {approvalModeLabel(
                        liveDetail?.current_policy?.mode ?? null,
                    )}
                    . Decisions apply to the exact candidate shown here. They do
                    not establish professional qualifications, consent or
                    medication authority.
                </p>
            )}
            <SourceCommandStatus
                {...source}
                checking={source.checking || choices.checking}
                currentFacts={currentFacts}
                canCheck
                onCheck={() => void source.checkCurrent()}
                onReview={source.useCurrent}
                onDone={() => onDone(source.confirmedHref)}
            />
            {command === 'create' && choices.checking && (
                <p role="status" className="text-caption">
                    Checking this person’s current choices…
                </p>
            )}
            {command === 'create' && choices.failure && (
                <div className="space-y-3">
                    <p
                        role="alert"
                        className="text-caption text-status-critical"
                    >
                        {choices.failure}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => chooseClient(form.data.client_id)}
                    >
                        Retry current choices
                    </Button>
                </div>
            )}
            {!source.receipt && (
                <fieldset
                    disabled={
                        form.processing || source.checking || !source.allowed
                    }
                    className="space-y-5"
                >
                    {(command === 'create' || command === 'edit') && (
                        <>
                            {command === 'create' &&
                                person('client_id', 'Person', pickers.clients)}
                            <Field
                                label="Plan title"
                                required
                                error={form.errors.title}
                            >
                                <Input
                                    value={form.data.title}
                                    required
                                    maxLength={255}
                                    onChange={(event) =>
                                        change('title', event.target.value)
                                    }
                                />
                            </Field>
                            {text('triggers', 'Known triggers')}
                            {text(
                                'de_escalation_strategies',
                                'Alternatives and de-escalation',
                            )}
                            {text(
                                'approved_interventions',
                                'Proposed interventions (one per line)',
                            )}
                            {text(
                                'prohibited_interventions',
                                'Interventions that must not be used (one per line)',
                            )}
                            {select(
                                'restrictive_practice_type',
                                'Restrictive practice type',
                                [
                                    'physical',
                                    'chemical',
                                    'mechanical',
                                    'seclusion',
                                    'environmental',
                                ].map((value) => ({ value, label: value })),
                            )}
                            {person(
                                'developed_by',
                                'Recorded developer',
                                pickers.staff,
                            )}
                            {date('developed_at', 'Developed date')}
                            {date('review_date', 'Next review date')}
                            {text('notes', 'Plan notes')}
                        </>
                    )}
                    {command === 'review' && (
                        <>
                            {select(
                                'outcome',
                                'Review outcome',
                                [
                                    'continued',
                                    'modified',
                                    'reduced',
                                    'discontinued',
                                    'escalated',
                                ].map((value) => ({ value, label: value })),
                                true,
                            )}
                            {time(
                                'reviewed_at',
                                'reviewed_offset',
                                'Actual review time',
                            )}
                            {date('next_review_date', 'Next review date')}
                            {select(
                                'resulting_status',
                                'Requested plan status',
                                [
                                    'draft',
                                    'active',
                                    'under_review',
                                    'archived',
                                ].map((value) => ({
                                    value,
                                    label: value.replaceAll('_', ' '),
                                })),
                            )}
                            {text('notes', 'Review findings')}
                        </>
                    )}
                    {command === 'decision' && (
                        <>
                            {select(
                                'approval_decision',
                                'Decision',
                                ['approve', 'decline', 'revoke'].map(
                                    (value) => ({ value, label: value }),
                                ),
                                true,
                            )}
                            {select(
                                'origin',
                                'Who provided the decision?',
                                liveDetail?.current_policy?.mode ===
                                    'authorised_reviewer'
                                    ? [
                                          {
                                              value: 'authorised_reviewer',
                                              label: 'Authorised reviewer',
                                          },
                                      ]
                                    : [
                                          {
                                              value: 'internal_specialist',
                                              label: 'Designated internal specialist',
                                          },
                                          {
                                              value: 'external_specialist',
                                              label: 'Recorded external specialist',
                                          },
                                      ],
                                true,
                            )}
                            {form.data.origin !== 'external_specialist' ? (
                                person(
                                    'approver_id',
                                    'Actual approver',
                                    approvalOptions?.internal_approvers ?? [],
                                )
                            ) : (
                                <>
                                    {text(
                                        'external_name',
                                        'External approver’s name',
                                        true,
                                    )}
                                    {text(
                                        'external_role',
                                        'External approver’s factual role',
                                        true,
                                    )}
                                    {text(
                                        'external_organisation',
                                        'External organisation',
                                        true,
                                    )}
                                    {text(
                                        'external_reference',
                                        'Actual decision reference',
                                        true,
                                    )}
                                    {date(
                                        'external_decision_date',
                                        'External decision date',
                                    )}
                                </>
                            )}
                            {text(
                                'approver_role',
                                'Factual approver role',
                                true,
                            )}
                            {time(
                                'approved_at',
                                'approved_offset',
                                'Actual decision time',
                            )}
                            {time(
                                'effective_from',
                                'effective_offset',
                                'Effective from',
                                false,
                            )}
                            {time(
                                'effective_to',
                                'effective_to_offset',
                                'Effective until (optional)',
                                false,
                                false,
                            )}
                            {text('basis', 'Decision basis', true)}
                            {form.data.origin === 'internal_specialist' && (
                                <>
                                    {select(
                                        'credential_id',
                                        'Recorded credential (optional)',
                                        approvalOptions?.credentials
                                            .filter(
                                                (item) =>
                                                    String(item.approver_id) ===
                                                    form.data.approver_id,
                                            )
                                            .map((item) => ({
                                                value: String(item.id),
                                                label:
                                                    item.label +
                                                    (item.issuer
                                                        ? ' · ' + item.issuer
                                                        : ''),
                                            })) ?? [],
                                    )}
                                    <p className="text-caption text-muted-foreground">
                                        A recorded credential is supporting
                                        evidence; this page does not verify
                                        professional eligibility.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="frontline-hit"
                                        onClick={() =>
                                            change('credential_id', '')
                                        }
                                    >
                                        Clear credential
                                    </Button>
                                </>
                            )}
                            {form.data.approval_decision === 'revoke' &&
                                select(
                                    'supersedes_approval_id',
                                    'Approval being revoked',
                                    detail?.approvals
                                        .filter(
                                            (item) =>
                                                item.decision === 'approve',
                                        )
                                        .map((item) => ({
                                            value: String(item.id),
                                            label:
                                                'Approval ' +
                                                item.id +
                                                ' · candidate ' +
                                                item.plan_version_id,
                                        })) ?? [],
                                    true,
                                )}
                            <Field label="Evidence supporting this decision">
                                <div className="space-y-3">
                                    {approvalOptions?.evidence.map((item) => {
                                        const key =
                                            item.reference.type +
                                            ':' +
                                            item.reference.id;
                                        return (
                                            <label
                                                key={key}
                                                className="text-subtle flex min-h-11 items-center gap-3"
                                            >
                                                <Checkbox
                                                    disabled={!item.selectable}
                                                    checked={form.data.evidence_keys.includes(
                                                        key,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        change(
                                                            'evidence_keys',
                                                            checked === true
                                                                ? [
                                                                      ...form
                                                                          .data
                                                                          .evidence_keys,
                                                                      key,
                                                                  ]
                                                                : form.data.evidence_keys.filter(
                                                                      (value) =>
                                                                          value !==
                                                                          key,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {item.label} ·{' '}
                                                {item.availability}
                                            </label>
                                        );
                                    })}
                                </div>
                            </Field>
                            {form.data.approval_decision === 'approve' && (
                                <Field label="Activate this approved candidate">
                                    <Checkbox
                                        checked={form.data.activate_requested}
                                        onCheckedChange={(value) =>
                                            change(
                                                'activate_requested',
                                                value === true,
                                            )
                                        }
                                    />
                                </Field>
                            )}
                        </>
                    )}
                    {command === 'activate' && (
                        <p className="text-subtle">
                            Activation requires an applicable recorded approval
                            for this candidate. A request can be saved while
                            approval is pending; that does not activate the
                            plan.
                        </p>
                    )}
                    {command === 'submit-review' && (
                        <p className="text-subtle">
                            The current candidate will be sent for review.
                            Existing versions and decisions stay in history.
                        </p>
                    )}
                    {command === 'archive' && (
                        <p className="text-subtle">
                            The plan will be archived. Its versions and
                            decisions stay in history, and connected
                            intervention reviews remain open until completed
                            separately.
                        </p>
                    )}
                    {Object.entries(form.errors).length > 0 && (
                        <ul
                            role="alert"
                            className="text-caption list-disc space-y-1 pl-5 text-status-critical"
                        >
                            {Object.entries(form.errors).map(([key, error]) => (
                                <li key={key}>{String(error)}</li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <Button
                            type="submit"
                            className="frontline-tap"
                            disabled={
                                source.stale ||
                                source.checkFailed ||
                                !source.allowed ||
                                form.processing ||
                                source.checking ||
                                !endpoint
                            }
                        >
                            {form.processing ? 'Saving…' : 'Save and confirm'}
                        </Button>
                        <Button
                            variant="outline"
                            type="button"
                            className="frontline-tap"
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                    </div>
                </fieldset>
            )}
        </form>
    );
}
