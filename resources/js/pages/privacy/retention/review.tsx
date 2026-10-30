import { PageHero, PageLayout } from '@/components/page';
import { PrivacyActionModal } from '@/components/privacy/privacy-action-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    Clock,
    Database,
    Eye,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

type Props = {
    policies: any[];
};

/** Plain-language account of a record type's retention choices, from its registered owner. */
type OwnerGuidanceText = {
    summary?: string;
    removes?: string[];
    keeps?: string[];
    blocked_by?: string[];
    period?: string;
    /** When the period counts from, e.g. "after the referral was closed". */
    period_basis?: string;
    elsewhere?: string[];
    field_labels?: Record<string, string>;
};

function GuidanceList({ title, items }: { title: string; items?: string[] }) {
    if (!items?.length) return null;
    return (
        <div className="space-y-1">
            <p className="text-sm font-medium">{title}</p>
            <ul className="text-subtle list-inside list-disc space-y-1">
                {items.map((item) => (
                    <li key={item}>{item}</li>
                ))}
            </ul>
        </div>
    );
}

function OwnerGuidance({ guidance }: { guidance?: OwnerGuidanceText }) {
    if (!guidance?.summary) return null;
    return (
        <details className="mt-4 rounded-lg bg-muted p-3">
            <summary className="cursor-pointer font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                What this policy does
            </summary>
            <div className="mt-3 space-y-3">
                <p className="text-subtle">{guidance.summary}</p>
                <GuidanceList title="Removes" items={guidance.removes} />
                <GuidanceList title="Keeps" items={guidance.keeps} />
                <GuidanceList
                    title="Never touched while"
                    items={guidance.blocked_by}
                />
                {guidance.period && (
                    <p className="text-subtle">{guidance.period}</p>
                )}
                <GuidanceList
                    title="Handled elsewhere"
                    items={guidance.elsewhere}
                />
            </div>
        </details>
    );
}

/** Why records past the period are kept, from the preview (one count per protection). */
function ProtectedCounts({ snapshot }: { snapshot?: any }) {
    const rows: { key: string; label: string; count: number }[] = Object.values(
        snapshot?.actions ?? {},
    )
        .flatMap((action: any) => action.protected ?? [])
        .filter((row: { count: number }) => row.count > 0);
    if (!rows.length) return null;
    return (
        <ul className="text-subtle mt-2 space-y-1">
            {rows.map((row) => (
                <li key={row.key}>
                    Kept: {row.label} · {row.count}
                </li>
            ))}
        </ul>
    );
}

export default function ReviewRetention({ policies }: Props) {
    const [executePolicyId, setExecutePolicyId] = useState<number | null>(null);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Privacy', href: '/privacy/dashboard' },
                { title: 'Retention Policies', href: '/privacy/retention' },
                { title: 'Review Data', href: '/privacy/retention/review' },
            ]}
        >
            <Head title="Review Data for Retention" />

            <PageLayout
                hero={
                    <PageHero
                        icon={ClipboardCheck}
                        title="Review Data for Retention"
                        description="Review data that may be due for archival or deletion"
                        stats={[{ label: 'Policies', value: policies.length }]}
                        actions={
                            <Link href="/privacy/retention">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    Back to Policies
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <div className="space-y-4">
                    <p className="text-subtle">
                        Create a fresh preview and obtain independent approval
                        whenever a policy or its protected fields change. A
                        previous approval cannot authorize newly added fields.
                    </p>
                    {policies.length > 0 ? (
                        policies.map((policy: any) => (
                            <Card key={policy.id}>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        <div className="flex items-center gap-2">
                                            <Database className="h-5 w-5 text-primary" />
                                            {policy.policy_name}
                                        </div>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="mb-4 flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {policy.owner?.label ??
                                                policy.model_type}
                                        </Badge>
                                        {[
                                            ['archive_after_years', 'Archive'],
                                            [
                                                'retention_period_years',
                                                'Soft delete',
                                            ],
                                            [
                                                'hard_delete_after_years',
                                                'Anonymize',
                                            ],
                                        ].map(([field, label]) =>
                                            policy[field] != null ? (
                                                <Badge
                                                    key={field}
                                                    variant="outline"
                                                    className="border-status-info/30 bg-status-info-bg text-status-info"
                                                >
                                                    <Clock className="mr-1 h-3 w-3" />
                                                    {label} after{' '}
                                                    {policy[field]}{' '}
                                                    {Number(policy[field]) === 1
                                                        ? 'year'
                                                        : 'years'}
                                                </Badge>
                                            ) : null,
                                        )}
                                        <Badge variant="outline">
                                            {!policy.preview_current &&
                                            policy.preview_snapshot
                                                ? 'Fresh preview required'
                                                : policy.execution_state ===
                                                    'approved'
                                                  ? 'Approved'
                                                  : policy.execution_state ===
                                                      'previewed'
                                                    ? 'Awaiting independent approval'
                                                    : 'Draft'}
                                        </Badge>
                                    </div>
                                    {policy.owner?.guidance?.period_basis &&
                                        policy.hard_delete_after_years !=
                                            null && (
                                            // Referral periods are settings on Retention policies; say exactly when they apply.
                                            <p className="mb-3 text-sm font-medium">
                                                Personal details are removed{' '}
                                                {policy.hard_delete_after_years}{' '}
                                                {Number(
                                                    policy.hard_delete_after_years,
                                                ) === 1
                                                    ? 'year'
                                                    : 'years'}{' '}
                                                {
                                                    policy.owner.guidance
                                                        .period_basis
                                                }
                                                .
                                            </p>
                                        )}
                                    {!policy.preview_current &&
                                        policy.preview_snapshot && (
                                            <p className="mb-3 text-sm text-muted-foreground">
                                                The policy or included fields
                                                have changed. Create a fresh
                                                preview for independent review
                                                before continuing.
                                            </p>
                                        )}
                                    {policy.preview_snapshot && (
                                        <p className="text-sm text-muted-foreground">
                                            {
                                                policy.preview_snapshot
                                                    .eligible_count
                                            }{' '}
                                            eligible outcome(s) ·{' '}
                                            {
                                                policy.preview_snapshot
                                                    .exempt_count
                                            }{' '}
                                            protected by legal hold or
                                            active-case exemption
                                        </p>
                                    )}
                                    <ProtectedCounts
                                        snapshot={policy.preview_snapshot}
                                    />
                                    <OwnerGuidance
                                        guidance={policy.owner?.guidance}
                                    />
                                    {!!policy.preview_snapshot
                                        ?.anonymization_fields?.length && (
                                        <details className="mt-4 rounded-lg border p-3">
                                            <summary className="frontline-tap cursor-pointer font-medium">
                                                {policy.preview_current
                                                    ? 'Fields included in anonymization'
                                                    : 'Fields in the previous preview'}
                                            </summary>
                                            <ul className="text-subtle mt-3 grid list-inside list-disc gap-2 sm:grid-cols-2">
                                                {policy.preview_snapshot.anonymization_fields.map(
                                                    (field: string) => (
                                                        <li key={field}>
                                                            {policy.owner
                                                                ?.guidance
                                                                ?.field_labels?.[
                                                                field
                                                            ] ??
                                                                (field ===
                                                                'contact_preferences'
                                                                    ? 'Saved contact details and safe-contact instructions'
                                                                    : field
                                                                          .replaceAll(
                                                                              '_',
                                                                              ' ',
                                                                          )
                                                                          .replace(
                                                                              /^./,
                                                                              (
                                                                                  letter,
                                                                              ) =>
                                                                                  letter.toUpperCase(),
                                                                          ))}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </details>
                                    )}
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            className="min-h-[44px] sm:min-h-0"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/privacy/retention/${policy.id}/preview`,
                                                    {},
                                                )
                                            }
                                        >
                                            <Eye className="mr-2 h-4 w-4" />
                                            Create preview
                                        </Button>
                                        {policy.preview_current &&
                                            policy.execution_state ===
                                                'previewed' && (
                                                <Button
                                                    size="sm"
                                                    className="min-h-[44px] sm:min-h-0"
                                                    onClick={() =>
                                                        router.post(
                                                            `/privacy/retention/${policy.id}/approve`,
                                                            {},
                                                        )
                                                    }
                                                >
                                                    <Check className="mr-2 h-4 w-4" />
                                                    Approve preview
                                                </Button>
                                            )}
                                        {policy.approval_current &&
                                            policy.execution_state ===
                                                'approved' && (
                                                <Button
                                                    size="sm"
                                                    className="min-h-[44px] sm:min-h-0"
                                                    variant="destructive"
                                                    onClick={() =>
                                                        setExecutePolicyId(
                                                            policy.id,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="mr-2 h-4 w-4" />
                                                    Execute approved retention
                                                </Button>
                                            )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No active retention policies found.
                        </div>
                    )}
                </div>
                {executePolicyId !== null && (
                    <PrivacyActionModal
                        kind="execute"
                        recordId={executePolicyId}
                        open
                        onClose={() => setExecutePolicyId(null)}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
