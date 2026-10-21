import {
    chatSettingLabels,
    describeChatSetting,
} from '@/components/chat/chat-settings-policy';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { formatDateTime } from '@/lib/datetime';
import { Info } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ADMINISTRATION_API, governanceRequest } from './types';

type Entry = {
    id: number;
    kind: 'policy' | 'offboarding';
    actor: string;
    created_at: string;
    changes: {
        scope: string;
        field: string;
        before: unknown;
        after: unknown;
    }[];
    offboarding: {
        person: string;
        memberships_ended: number;
        work_moved: number;
    } | null;
};

function describe(value: unknown) {
    if (value === '(inherited)') return 'Inherited';
    if (value !== null && typeof value === 'object') return 'Configured';
    return describeChatSetting(value);
}

/** Mockup M22 "Settings audit": policy changes only, never investigation records or chat content. */
export function SettingsAudit({
    actorId,
    sites,
}: {
    actorId: number;
    sites: { id: number; name: string }[];
}) {
    const [entries, setEntries] = useState<Entry[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        let current = true;
        governanceRequest<{ entries: Entry[] }>(
            actorId,
            `${ADMINISTRATION_API}/audit`,
        )
            .then((result) => {
                if (current) setEntries(result.entries);
            })
            .catch((failure: Error) => {
                if (current) setError(failure.message);
            });
        return () => {
            current = false;
        };
    }, [actorId]);

    const scopeName = (scope: string) =>
        scope === 'organisation'
            ? 'Organisation defaults'
            : (sites.find((site) => String(site.id) === scope)?.name ??
              'Site ' + scope);
    const label = (field: string) =>
        (chatSettingLabels as Record<string, string>)[field] ?? field;

    return (
        <div className="grid gap-3">
            <Alert variant="info" role="note">
                <Info />
                <AlertDescription>
                    This log records policy changes. It does not grant access to
                    private chat content.
                </AlertDescription>
            </Alert>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {!entries && !error && (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading the settings audit…
                </p>
            )}
            {entries && entries.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No Chat policy changes have been recorded.
                </p>
            )}
            {entries && entries.length > 0 && (
                <ul className="grid gap-2">
                    {entries.map((entry) => (
                        <li
                            key={entry.id}
                            className="rounded-lg border bg-muted/40 p-3 text-sm"
                        >
                            <p className="font-medium">
                                {entry.kind === 'policy'
                                    ? [
                                          ...new Set(
                                              entry.changes.map((change) =>
                                                  scopeName(change.scope),
                                              ),
                                          ),
                                      ].join(', ') || 'Organisation defaults'
                                    : 'Offboarding'}{' '}
                                · {formatDateTime(entry.created_at)}
                            </p>
                            {entry.kind === 'policy' ? (
                                <p className="mt-1 break-words">
                                    {entry.changes.length
                                        ? entry.changes
                                              .map(
                                                  (change) =>
                                                      `${label(change.field)}: ${describe(change.before)} → ${describe(change.after)}`,
                                              )
                                              .join('; ')
                                        : 'Saved without changing a value'}
                                </p>
                            ) : (
                                <p className="mt-1">
                                    Ended expired access for{' '}
                                    {entry.offboarding?.person}:{' '}
                                    {entry.offboarding?.memberships_ended}{' '}
                                    membership(s),{' '}
                                    {entry.offboarding?.work_moved} work item(s)
                                    moved.
                                </p>
                            )}
                            <p className="text-caption mt-1">{entry.actor}</p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
