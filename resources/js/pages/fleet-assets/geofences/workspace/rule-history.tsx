import { formatDateTime } from '@/lib/datetime';
import { useState } from 'react';
import { query, useRemote } from './api';
import type { Page, RuleRecord } from './data';
import { Pagination } from './history';
import { BoundaryMap } from './map';
import { PolicySummary } from './policy-summary';
import { Button, Card, Empty, Facts, Modal, Notice } from './ui';
type Entry = {
    id: number;
    assignment_id: number;
    revision: number;
    actor: string | null;
    reason: string;
    recorded_at: string;
    snapshot: {
        label: string;
        purpose: string;
        state: string;
        monitoring: string;
        response_proposal: string | null;
        geometry_snapshot: RuleRecord['geometry'];
        schedule: RuleRecord['schedule'];
        policy_proposal: RuleRecord['policy'];
    };
};
export function RuleHistory({
    boundary,
    filters,
    refresh,
    canManage,
}: {
    boundary: number;
    filters: Record<string, string>;
    refresh: number;
    canManage: boolean;
}) {
    const [page, setPage] = useState(1),
        [entry, setEntry] = useState<Entry | null>(null);
    const result = useRemote<Page<Entry>>(
        query('/' + boundary + '/rule-history', { ...filters, page }),
        refresh,
    );
    return (
        <div className="flow-stack">
            {canManage && (
                <Button asChild variant="outline">
                    <a
                        href={query('/' + boundary + '/rule-history', {
                            ...filters,
                            export: 1,
                        })}
                    >
                        Export filtered rule history
                    </a>
                </Button>
            )}
            <Notice title="Purpose rules have independent versions">
                This includes retained changes made through the shared workspace
                and vehicle profiles, including removals. Person-specific
                history stays in the authorised Client Location profile.
            </Notice>
            {result.loading ? (
                <p role="status">Loading rule history…</p>
            ) : result.error ? (
                <Notice tone="critical" title={result.error} />
            ) : result.data?.data.length ? (
                <Card className="p-5">
                    <ul className="audit-list">
                        {result.data.data.map((e) => (
                            <li key={e.id}>
                                <button onClick={() => setEntry(e)}>
                                    <span className="audit-time">
                                        {formatDateTime(e.recorded_at)}
                                        <small>
                                            Rule {e.assignment_id} · version{' '}
                                            {e.revision}
                                        </small>
                                    </span>
                                    <span>
                                        <strong>{e.snapshot.label}</strong>
                                        <p>{e.reason}</p>
                                        <small>
                                            {e.actor ?? 'Actor not retained'} ·{' '}
                                            {e.snapshot.state}
                                        </small>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Pagination
                        page={page}
                        last={result.data.last_page}
                        total={result.data.total}
                        onChange={setPage}
                    />
                </Card>
            ) : (
                <Empty title="No retained rule changes match">
                    Earlier settings are not reconstructed.
                </Empty>
            )}
            {entry && (
                <Modal
                    title={entry.snapshot.label}
                    description={`Rule ${entry.assignment_id} · retained version ${entry.revision}`}
                    width={900}
                    onClose={() => setEntry(null)}
                    footer={
                        <Button
                            variant="outline"
                            onClick={() => setEntry(null)}
                        >
                            Close history entry
                        </Button>
                    }
                >
                    <div className="bnd-dialog flow-stack">
                        <Facts
                            rows={[
                                ['Recorded', formatDateTime(entry.recorded_at)],
                                ['Actor', entry.actor ?? 'Not retained'],
                                ['Reason', entry.reason],
                                [
                                    'Purpose',
                                    entry.snapshot.purpose ?? 'Not proposed',
                                ],
                                ['State', entry.snapshot.state],
                                ['Monitoring', entry.snapshot.monitoring],
                                [
                                    'Response',
                                    entry.snapshot.response_proposal ??
                                        'Not proposed',
                                ],
                            ]}
                        />
                        <BoundaryMap
                            shape={entry.snapshot.geometry_snapshot}
                            className="location-map"
                        />
                        <PolicySummary
                            schedule={entry.snapshot.schedule}
                            policy={entry.snapshot.policy_proposal}
                        />
                    </div>
                </Modal>
            )}
        </div>
    );
}
