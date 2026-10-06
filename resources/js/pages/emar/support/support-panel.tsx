import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import {
    AlertCircle,
    ClipboardList,
    FileSignature,
    Pill,
    User,
} from 'lucide-react';
import { PLAN_STATES, SUPPORT, type Medicine, type SupportPlan } from './types';

export function PlanBadge({ plan }: { plan: SupportPlan }) {
    const state = PLAN_STATES[plan.state];
    return <StatusBadge variant={state.variant}>{state.label}</StatusBadge>;
}

/** P02/care-plan integration: a fully read-only rendering when actions are omitted. */
export function MedicationSupportPanel({
    plan,
    onAssess,
    onAgreement,
    onMedicine,
    onConsent,
}: {
    plan: SupportPlan;
    onAssess?: () => void;
    onAgreement?: () => void;
    onMedicine?: (medicine: Medicine) => void;
    onConsent?: () => void;
}) {
    const ctx = useEntityContextMenu<Medicine>();
    const actions = (m: Medicine): MenuItem[] => [
        ...(onMedicine
            ? [
                  {
                      label: 'View support',
                      icon: Pill,
                      onClick: () => onMedicine(m),
                  },
              ]
            : []),
        ...(plan.can_assess &&
        onMedicine &&
        (!m.controlled || plan.can_set_controlled)
            ? [
                  {
                      label: m.requested_mode
                          ? 'Give more staff support'
                          : 'Set support',
                      icon: User,
                      onClick: () => onMedicine(m),
                  },
              ]
            : []),
    ];
    return (
        <div className="flex flex-col gap-5">
            {plan.state !== 'current' && (
                <Alert>
                    <AlertCircle className="size-4" />
                    <AlertTitle>
                        <PlanBadge plan={plan} />
                    </AlertTitle>
                    <AlertDescription className="space-y-2">
                        {plan.legacy_review_required ? (
                            <p>
                                Existing support stays as recorded. Ask a house
                                lead, coordinator or clinical lead to review the
                                agreement evidence. Self-managed medicines
                                remain informational; this review does not
                                instruct staff to give another dose.
                            </p>
                        ) : plan.state === 'none' ? (
                            <p>
                                No assessment — staff give every medicine
                                (Administer).
                            </p>
                        ) : plan.state === 'overdue' ? (
                            <p>
                                Support plan review date passed on{' '}
                                {formatDateOnly(
                                    toDateInput(
                                        plan.assessment?.reassessment_date,
                                    ),
                                )}{' '}
                                — support stays as it is until the reassessment.
                            </p>
                        ) : plan.state === 'unknown' ? (
                            <p>
                                The next review date is not recorded. Ask a
                                house lead, coordinator or clinical lead to
                                reassess support.
                            </p>
                        ) : plan.state === 'soon' ? (
                            <p>
                                Reassess by{' '}
                                {formatDateOnly(
                                    toDateInput(
                                        plan.assessment?.reassessment_date,
                                    ),
                                )}
                                .
                            </p>
                        ) : (
                            plan.reviews.map((r) => (
                                <p key={r.id}>
                                    {r.reason} Reassess by{' '}
                                    {formatDateTime(r.due_at)}.
                                </p>
                            ))
                        )}
                        {plan.can_assess && onAssess && (
                            <Button onClick={onAssess}>
                                {plan.assessment
                                    ? 'Reassess'
                                    : 'Assess support'}
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}
            {plan.agreement_needed && (
                <Alert>
                    <FileSignature className="size-4" />
                    <AlertTitle>Agreement needed</AlertTitle>
                    <AlertDescription>
                        {plan.legacy_review_required
                            ? 'Record the formal agreement for the existing plan. Existing support stays as shown while its evidence is reviewed; new Self-managed or Prompt choices await the agreement.'
                            : 'Self-managed and Prompt choices stay Administer until the agreement is recorded.'}
                        {plan.can_assess && onAgreement && (
                            <div className="mt-3">
                                <Button onClick={onAgreement}>
                                    Record the agreement
                                </Button>
                            </div>
                        )}
                    </AlertDescription>
                </Alert>
            )}
            <ListCaption
                title="Support by medicine"
                caption={
                    plan.medicines.length +
                    ' shown' +
                    (plan.concealed_count
                        ? ' · ' +
                          plan.concealed_count +
                          ' controlled ' +
                          (plan.concealed_count === 1
                              ? 'medicine concealed'
                              : 'medicines concealed')
                        : '')
                }
                right={
                    plan.can_record_consent && onConsent ? (
                        <Button variant="outline" onClick={onConsent}>
                            Record a change the person asked for
                        </Button>
                    ) : undefined
                }
            />
            {plan.medicines.length ? (
                <>
                    <div className="hidden md:block">
                        <EntityTable
                            rows={plan.medicines}
                            rowKey={(m) => m.id}
                            identityLabel="Medicine"
                            identityWidth="1.8fr"
                            identity={(m) => ({
                                icon: Pill,
                                name: m.name,
                                subline: m.dosage ?? 'Strength not recorded',
                            })}
                            columns={[
                                {
                                    key: 'support',
                                    label: 'Support now',
                                    width: '1fr',
                                    cell: (m) => (
                                        <StatusBadge variant="neutral">
                                            {SUPPORT[m.mode].label}
                                        </StatusBadge>
                                    ),
                                },
                                {
                                    key: 'recording',
                                    label: 'What staff record',
                                    width: '1.8fr',
                                    cell: (m) => (
                                        <div className="whitespace-normal">
                                            {SUPPORT[m.mode].recorded}
                                            {m.requested_mode === null && (
                                                <p className="text-caption">
                                                    Not set yet — staff give it
                                                </p>
                                            )}
                                            {m.agreement_needed && (
                                                <p className="text-caption">
                                                    {
                                                        SUPPORT[
                                                            m.requested_mode!
                                                        ].label
                                                    }{' '}
                                                    awaits the agreement
                                                </p>
                                            )}
                                        </div>
                                    ),
                                },
                            ]}
                            actionsFor={actions}
                            onOpen={(m) => onMedicine?.(m)}
                            onRowContextMenu={(e, m) => ctx.open(e, m)}
                            minWidth={650}
                            rowHeight="content"
                        />
                    </div>
                    <ul className="space-y-3 md:hidden">
                        {plan.medicines.map((m) => (
                            <li
                                key={m.id}
                                onContextMenu={(e) => ctx.open(e, m)}
                            >
                                <Card>
                                    <CardHeader>
                                        <div className="flex items-start justify-between gap-3">
                                            <CardTitle>{m.name}</CardTitle>
                                            <EntityKebab
                                                actions={actions(m)}
                                                label={m.name + ' actions'}
                                            />
                                        </div>
                                        <p className="text-caption">
                                            {m.dosage ??
                                                'Strength not recorded'}
                                        </p>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <StatusBadge variant="neutral">
                                            {SUPPORT[m.mode].label}
                                        </StatusBadge>
                                        <p>{SUPPORT[m.mode].recorded}</p>
                                        {m.requested_mode === null && (
                                            <p className="text-caption">
                                                Not set yet — staff give it
                                            </p>
                                        )}
                                        {onMedicine && (
                                            <Button
                                                variant="outline"
                                                className="frontline-tap"
                                                onClick={() => onMedicine(m)}
                                            >
                                                View support
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <EmptyState
                    icon={Pill}
                    title="No visible active medicines"
                    description={
                        plan.concealed_count
                            ? 'Controlled medicines are concealed from your account.'
                            : 'There are no active medicines on this record.'
                    }
                />
            )}
            {!plan.can_assess && (
                <p className="text-subtle">
                    House leads, coordinators and clinical leads can assess
                    support and record the agreement.
                </p>
            )}
            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={ClipboardList}
                    title={ctx.ctx.record.name}
                    items={actions(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}
        </div>
    );
}
