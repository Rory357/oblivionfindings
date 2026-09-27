import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/fleet-utils';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ClipboardCheck, ScanLine } from 'lucide-react';
import { Empty, Fact, Panel, State, human } from './presentation';
import type { ProfileWorkspace } from './types';

export function OriginalChecks({ data }: { data: ProfileWorkspace }) {
    const checks = data.sources?.original_checks ?? [];
    return (
        <Panel title="Original submitted checks" icon={ClipboardCheck}>
            <p className="text-subtle text-muted-foreground">
                Open the original answers, evidence and template saved at
                submission. Later template changes do not rewrite a check.
            </p>
            {checks.length ? (
                checks.map((check) => (
                    <article
                        key={check.id}
                        className="flex flex-wrap items-start justify-between gap-4 rounded-lg border p-4"
                    >
                        <div className="min-w-0 space-y-2">
                            <strong>
                                {check.name} · CHK-{check.id}
                            </strong>
                            <p className="text-caption text-muted-foreground">
                                {formatDateTime(check.at)} ·{' '}
                                {check.by || 'Author not recorded'}
                            </p>
                            <p className="text-caption">
                                {check.kind === 'daily'
                                    ? 'Recorded observations'
                                    : check.rule_version
                                      ? `Submitted rule version ${check.rule_version}`
                                      : 'No submitted rule version'}
                                {check.corrects_id
                                    ? ` · Corrects CHK-${check.corrects_id}; original retained`
                                    : ''}
                            </p>
                            <State value={check.outcome} />
                        </div>
                        <Button variant="outline" asChild>
                            <Link href={check.url}>
                                Open original <ArrowUpRight />
                            </Link>
                        </Button>
                    </article>
                ))
            ) : (
                <Empty>
                    No original submitted checks are available for this asset.
                </Empty>
            )}
            {checks.length >= 100 && (
                <p className="text-caption">
                    Showing the latest 100 submissions. Earlier checks remain in
                    the Maintenance inspection register.
                </p>
            )}
        </Panel>
    );
}

export function ScanObservations({ data }: { data: ProfileWorkspace }) {
    const observations = data.sources?.observations ?? [];
    const last = observations[0];
    return (
        <Panel title="QR / tag observations" icon={ScanLine}>
            <p className="text-subtle text-muted-foreground">
                A scan records an observation. Assigned location changes only
                through the placement or receipt workflow.
            </p>
            {last ? (
                <>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <Fact label="Observed at">
                            {formatDateTime(last.observed_at)}
                        </Fact>
                        <Fact label="Received at">
                            {formatDateTime(last.received_at)}
                        </Fact>
                        <Fact label="Observed site">{last.site}</Fact>
                        <Fact label="Recorded by">
                            {last.by || 'Source did not record a user'}
                        </Fact>
                        <Fact label="Source">{last.source}</Fact>
                    </dl>
                    {observations.length > 1 && (
                        <details className="rounded-lg border p-3">
                            <summary className="text-subtle cursor-pointer font-medium">
                                Recent scan history ({observations.length})
                            </summary>
                            <div className="mt-3 space-y-3">
                                {observations.slice(1).map((item) => (
                                    <p key={item.id} className="text-subtle">
                                        SC-{item.id} ·{' '}
                                        {formatDateTime(item.observed_at)} ·{' '}
                                        {item.site} ·{' '}
                                        {item.by || human('unrecorded_actor')}
                                    </p>
                                ))}
                            </div>
                        </details>
                    )}
                </>
            ) : (
                <Empty>
                    No authorised QR or tag observation recorded. Reader
                    observations appear when an approved source supplies them.
                </Empty>
            )}
        </Panel>
    );
}
