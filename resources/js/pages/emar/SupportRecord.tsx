import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useEmarRecordBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, FileSignature, History, Pill } from 'lucide-react';
import { useState } from 'react';
import {
    AgreementDialog,
    AssessmentDialog,
    AssessmentHistoryDialog,
    ConsentDialog,
    MedicineSupportDialog,
} from './support/_dialogs';
import { MedicationSupportPanel, PlanBadge } from './support/support-panel';
import {
    SUPPORT,
    type Agreement,
    type Change,
    type Medicine,
    type SupportHistory,
    type SupportPlan,
} from './support/types';

type Props = {
    support: SupportPlan;
    history: SupportHistory[];
    changes: Change[];
    staff: { id: number; name: string }[];
    agreement_history: Agreement[];
};
type Section = 'support' | 'assessment' | 'agreement' | 'changes';
export default function SupportRecord({
    support,
    history,
    changes,
    staff,
    agreement_history,
}: Props) {
    const recordUrl = `/emar/mar?client_id=${support.client_id}&section=support`;
    const breadcrumbs = useEmarRecordBreadcrumbs({
        title: support.client_name,
        href: recordUrl,
    });
    const [section, setSection] = useState<Section>(() => {
        const s = new URLSearchParams(window.location.search).get('section');
        return ['assessment', 'agreement', 'changes'].includes(s ?? '')
            ? (s as Section)
            : 'support';
    });
    const [dialog, setDialog] = useState<
        'assess' | 'agreement' | 'consent' | null
    >(() =>
        new URLSearchParams(window.location.search).get('action') ===
            'assess' && support.can_assess
            ? 'assess'
            : null,
    );
    const [medicine, setMedicine] = useState<Medicine | null>(null),
        [past, setPast] = useState<SupportHistory | null>(null);
    const historyContext = useEntityContextMenu<SupportHistory>();
    const changeContext = useEntityContextMenu<Change>();
    const historyActions = (h: SupportHistory): MenuItem[] => [
        {
            label: 'View assessment',
            icon: ClipboardList,
            onClick: () => setPast(h),
        },
    ];
    const changeActions = (c: Change): MenuItem[] => [
        {
            label: 'View medicine support',
            icon: Pill,
            onClick: () =>
                setMedicine(
                    support.medicines.find(
                        (m) => m.id === c.client_medication_id,
                    ) ?? null,
                ),
        },
    ];
    const activeMedicine = medicine
        ? (support.medicines.find((m) => m.id === medicine.id) ?? medicine)
        : null;
    const navigate = (s: Section) => {
        setSection(s);
        router.replace({
            url: support.url + '?section=' + s,
            preserveState: true,
            preserveScroll: true,
        });
    };
    const reasons: Record<string, string> = {
        assessment: 'Assessment completed',
        support_changed: 'Support changed',
        agreement_recorded: 'Agreement recorded',
        consent_withdrawn: 'Asked staff to do more',
        independence_requested: 'Asked to do more themselves',
    };
    return (
        <AppLayout
            breadcrumbs={[
                ...breadcrumbs,
                { title: 'Support & self-administration', href: support.url },
            ]}
        >
            <Head title={support.client_name + ' · Medication support'} />
            <div className="flex flex-col gap-5">
                <PageHeader
                    variant="profile"
                    mark={
                        <span className="eh-mark-ring text-sm font-semibold">
                            {support.client_name
                                .split(/\s+/)
                                .slice(0, 2)
                                .map((name) => name[0])
                                .join('')}
                        </span>
                    }
                    backHref={recordUrl}
                    title={support.client_name}
                    wrapTitle
                    titleChip={<PlanBadge plan={support} />}
                    subline={
                        (support.site_name ?? 'House not recorded') +
                        ' · Medication support'
                    }
                    actions={
                        support.can_assess ? (
                            <PageHeaderPrimaryButton
                                onClick={() => setDialog('assess')}
                            >
                                {support.assessment
                                    ? 'Reassess'
                                    : 'Assess support'}
                            </PageHeaderPrimaryButton>
                        ) : undefined
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Medicines"
                                onClick={() => navigate('support')}
                            >
                                <PageHeaderMeterBig>
                                    {support.medicines.length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {support.concealed_count
                                        ? support.concealed_count +
                                          ' controlled concealed'
                                        : 'active visible medicines'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Assessment"
                                onClick={() => navigate('assessment')}
                            >
                                <PageHeaderMeterBig>
                                    {support.assessment
                                        ? SUPPORT[support.cap].label
                                        : 'Not assessed'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    most independence allowed
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Agreement"
                                onClick={() => navigate('agreement')}
                            >
                                <PageHeaderMeterBig>
                                    {support.agreement
                                        ? 'Recorded'
                                        : support.agreement_needed
                                          ? 'Needed'
                                          : 'Not needed'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    who agreed and how
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Next review"
                                onClick={() => navigate('assessment')}
                            >
                                <PageHeaderMeterBig>
                                    {formatDateOnly(
                                        toDateInput(
                                            support.assessment
                                                ?.reassessment_date,
                                        ),
                                        'Not recorded',
                                    )}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    support stays until reassessed
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'support',
                                    label: 'By medicine',
                                    icon: Pill,
                                },
                                {
                                    key: 'assessment',
                                    label: 'Assessment',
                                    icon: ClipboardList,
                                },
                                {
                                    key: 'agreement',
                                    label: 'Agreement',
                                    icon: FileSignature,
                                },
                                {
                                    key: 'changes',
                                    label: 'Changes',
                                    icon: History,
                                },
                            ]}
                            value={section}
                            onSelect={navigate}
                        />
                    }
                />
                {section === 'support' && (
                    <MedicationSupportPanel
                        plan={support}
                        onAssess={() => setDialog('assess')}
                        onAgreement={() => setDialog('agreement')}
                        onMedicine={setMedicine}
                        onConsent={
                            support.assessment
                                ? () => setDialog('consent')
                                : undefined
                        }
                    />
                )}
                {section === 'assessment' && (
                    <>
                        <ListCaption
                            title="Current assessment"
                            right={
                                support.can_assess ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setDialog('assess')}
                                    >
                                        {support.assessment
                                            ? 'Reassess'
                                            : 'Assess support'}
                                    </Button>
                                ) : undefined
                            }
                        />
                        {support.assessment ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Most independence allowed:{' '}
                                        {SUPPORT[support.cap].label}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <p>
                                        Assessed{' '}
                                        {formatDateOnly(
                                            toDateInput(
                                                support.assessment
                                                    .assessment_date,
                                            ),
                                        )}{' '}
                                        · reassess{' '}
                                        {formatDateOnly(
                                            toDateInput(
                                                support.assessment
                                                    .reassessment_date,
                                            ),
                                            'date not recorded',
                                        )}
                                    </p>
                                    <p>
                                        With:{' '}
                                        {support.assessment.people_involved.join(
                                            ' · ',
                                        ) || 'Not recorded'}
                                    </p>
                                    <p>
                                        Storage:{' '}
                                        {support.assessment
                                            .safe_storage_notes ||
                                            support.assessment
                                                .storage_location ||
                                            'Not recorded'}
                                    </p>
                                    <p>
                                        {support.assessment.assessor_notes ||
                                            'No notes recorded'}
                                    </p>
                                    {history.find(
                                        (h) => h.id === support.assessment?.id,
                                    ) && (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setPast(
                                                    history.find(
                                                        (h) =>
                                                            h.id ===
                                                            support.assessment!
                                                                .id,
                                                    )!,
                                                )
                                            }
                                        >
                                            View scores and capability checks
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        ) : (
                            <EmptyState
                                icon={ClipboardList}
                                title="No assessment"
                                description="Staff give every medicine until support is assessed."
                            />
                        )}
                        <ListCaption
                            title="Earlier assessments"
                            caption={
                                String(
                                    history.filter(
                                        (h) => h.id !== support.assessment?.id,
                                    ).length,
                                ) + ' shown'
                            }
                        />
                        {history.filter((h) => h.id !== support.assessment?.id)
                            .length ? (
                            <EntityTable
                                rows={history.filter(
                                    (h) => h.id !== support.assessment?.id,
                                )}
                                rowKey={(h) => h.id}
                                identityLabel="Assessment"
                                identity={(h) => ({
                                    name: formatDateOnly(
                                        toDateInput(h.assessment_date),
                                    ),
                                    subline:
                                        h.assessor_name ??
                                        'Assessor not recorded',
                                })}
                                columns={[
                                    {
                                        key: 'review',
                                        label: 'Review date',
                                        width: '1fr',
                                        cell: (h) =>
                                            formatDateOnly(
                                                toDateInput(
                                                    h.reassessment_date,
                                                ),
                                                'Not recorded',
                                            ),
                                    },
                                ]}
                                actionsFor={historyActions}
                                onRowContextMenu={(e, h) =>
                                    historyContext.open(e, h)
                                }
                                onOpen={setPast}
                                minWidth={500}
                            />
                        ) : (
                            <EmptyState
                                title="No earlier assessments"
                                description="Reassessment keeps the earlier record here."
                                icon={History}
                            />
                        )}
                    </>
                )}
                {section === 'agreement' && (
                    <>
                        <ListCaption
                            title="The agreement"
                            right={
                                support.can_assess && support.assessment ? (
                                    <Button
                                        onClick={() => setDialog('agreement')}
                                    >
                                        {support.agreement
                                            ? 'Record a new agreement'
                                            : 'Record the agreement'}
                                    </Button>
                                ) : undefined
                            }
                        />
                        {support.agreement ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        {support.agreement.agreed_by_name}
                                    </CardTitle>
                                    <p className="text-caption">
                                        {support.agreement.agreed_by_role ===
                                        'person'
                                            ? 'The person'
                                            : support.agreement
                                                    .agreed_by_role ===
                                                'guardian'
                                              ? 'Welfare guardian'
                                              : 'EPOA (personal care and welfare)'}{' '}
                                        ·{' '}
                                        {formatDateTime(
                                            support.agreement.created_at,
                                        )}
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <p>
                                        {support.agreement.method === 'signed'
                                            ? 'Signed the form'
                                            : 'Agreed out loud'}
                                        {support.agreement.witness_id
                                            ? ' · witnessed by ' +
                                              (staff.find(
                                                  (s) =>
                                                      s.id ===
                                                      support.agreement!
                                                          .witness_id,
                                              )?.name ?? 'Staff member')
                                            : ''}
                                    </p>
                                    <dl className="space-y-3">
                                        <div>
                                            <dt className="font-medium">
                                                What the person does
                                            </dt>
                                            <dd>
                                                {
                                                    support.agreement
                                                        .person_responsibilities
                                                }
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="font-medium">
                                                What staff do
                                            </dt>
                                            <dd>
                                                {
                                                    support.agreement
                                                        .staff_responsibilities
                                                }
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="font-medium">
                                                Ordering
                                            </dt>
                                            <dd>
                                                {support.agreement
                                                    .ordering_responsibility ===
                                                'person'
                                                    ? 'The person orders their own'
                                                    : support.agreement
                                                            .ordering_responsibility ===
                                                        'pharmacy'
                                                      ? 'The pharmacy supplies automatically'
                                                      : 'The service orders'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="font-medium">
                                                Storage
                                            </dt>
                                            <dd>
                                                {support.agreement
                                                    .storage_notes ||
                                                    'Not recorded'}
                                            </dd>
                                        </div>
                                    </dl>
                                    {support.agreement.attachment_url && (
                                        <Button asChild variant="outline">
                                            <a
                                                href={
                                                    support.agreement
                                                        .attachment_url
                                                }
                                            >
                                                Download signed form
                                            </a>
                                        </Button>
                                    )}
                                    <p className="text-subtle">
                                        The agreement carries over on
                                        reassessment unless its terms change.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <EmptyState
                                icon={FileSignature}
                                title={
                                    support.agreement_needed
                                        ? 'Agreement needed'
                                        : 'No agreement recorded'
                                }
                                description={
                                    support.agreement_needed
                                        ? 'Self-managed and Prompt choices remain Administer until an agreement is recorded.'
                                        : 'An agreement is required when the person keeps or takes a medicine themselves.'
                                }
                            />
                        )}
                        {agreement_history
                            .filter((a) => a.id !== support.agreement?.id)
                            .map((a) => (
                                <Card key={a.id}>
                                    <CardHeader>
                                        <CardTitle>
                                            Earlier agreement ·{' '}
                                            {formatDateTime(a.created_at)}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p>
                                            {a.agreed_by_name} ·{' '}
                                            {a.method === 'signed'
                                                ? 'Signed form'
                                                : 'Agreed out loud'}
                                        </p>
                                        <p>{a.person_responsibilities}</p>
                                        <p>{a.staff_responsibilities}</p>
                                    </CardContent>
                                </Card>
                            ))}
                    </>
                )}
                {section === 'changes' && (
                    <>
                        <ListCaption
                            title="Changes"
                            caption={
                                changes.length +
                                ' shown' +
                                (support.concealed_count
                                    ? ' · controlled medicine changes concealed'
                                    : '')
                            }
                        />
                        {changes.length ? (
                            <EntityTable
                                rows={changes}
                                rowKey={(c) => c.id}
                                identityLabel="Change"
                                identityWidth="1.6fr"
                                identity={(c) => ({
                                    name:
                                        reasons[c.reason] ?? 'Support recorded',
                                    subline: formatDateTime(c.occurred_at),
                                })}
                                columns={[
                                    {
                                        key: 'medicine',
                                        label: 'Medicine',
                                        width: '1.4fr',
                                        cell: (c) =>
                                            c.medicine_name ??
                                            support.medicines.find(
                                                (m) =>
                                                    m.id ===
                                                    c.client_medication_id,
                                            )?.name ??
                                            'Earlier medicine',
                                    },
                                    {
                                        key: 'effect',
                                        label: 'Before → after',
                                        width: '1.2fr',
                                        cell: (c) =>
                                            (c.previous_mode
                                                ? SUPPORT[c.previous_mode].label
                                                : 'Not recorded') +
                                            ' → ' +
                                            SUPPORT[c.mode].label,
                                    },
                                    {
                                        key: 'by',
                                        label: 'Recorded by',
                                        width: '1fr',
                                        cell: (c) =>
                                            c.recorded_by ?? 'Not recorded',
                                    },
                                ]}
                                actionsFor={changeActions}
                                onRowContextMenu={(e, c) =>
                                    changeContext.open(e, c)
                                }
                                rowHeight="content"
                                minWidth={720}
                            />
                        ) : (
                            <EmptyState
                                title="No support changes recorded"
                                icon={History}
                                description="Assessments, support and consent changes are kept here."
                            />
                        )}
                    </>
                )}
                <p className="text-caption">
                    <Link href={'/clients/' + support.client_id}>
                        Open the person’s record
                    </Link>
                </p>
                {dialog === 'assess' && support.can_assess && (
                    <AssessmentDialog
                        plan={support}
                        onClose={() => setDialog(null)}
                        onAgreement={() => setDialog('agreement')}
                    />
                )}
                {dialog === 'agreement' &&
                    support.can_assess &&
                    support.assessment && (
                        <AgreementDialog
                            plan={support}
                            staff={staff}
                            onClose={() => setDialog(null)}
                        />
                    )}
                {dialog === 'consent' &&
                    support.can_record_consent &&
                    support.assessment && (
                        <ConsentDialog
                            plan={support}
                            onClose={() => setDialog(null)}
                        />
                    )}
                {activeMedicine && (
                    <MedicineSupportDialog
                        plan={support}
                        medicine={activeMedicine}
                        onClose={() => setMedicine(null)}
                    />
                )}
                {historyContext.ctx && (
                    <EntityContextMenu
                        x={historyContext.ctx.x}
                        y={historyContext.ctx.y}
                        title="Assessment"
                        icon={ClipboardList}
                        items={historyActions(historyContext.ctx.record)}
                        onClose={historyContext.close}
                    />
                )}
                {changeContext.ctx && (
                    <EntityContextMenu
                        x={changeContext.ctx.x}
                        y={changeContext.ctx.y}
                        title="Support change"
                        icon={History}
                        items={changeActions(changeContext.ctx.record)}
                        onClose={changeContext.close}
                    />
                )}
                {past && (
                    <AssessmentHistoryDialog
                        assessment={past}
                        onClose={() => setPast(null)}
                    />
                )}
            </div>
        </AppLayout>
    );
}
