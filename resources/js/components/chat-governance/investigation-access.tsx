import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { CheckCircle2, Lock, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConversationFinder } from './conversation-finder';
import {
    conversationLabel,
    durationLabel,
    governanceRequest,
    INVESTIGATION_API,
    type InvestigableConversation,
    type InvestigationGrant,
} from './types';

/** Matches ChatInvestigations::MAX_CONVERSATIONS. */
export const MAX_CASE_CONVERSATIONS = 20;

/** Grants made together share a case batch; a grant made on its own is its own case. */
export function groupByCase(grants: InvestigationGrant[]) {
    const cases = new Map<
        string,
        { key: string; caseReference: string; grants: InvestigationGrant[] }
    >();
    for (const grant of grants) {
        const key = grant.case_batch ?? `grant-${grant.id}`;
        const group = cases.get(key) ?? {
            key,
            caseReference: grant.case_reference,
            grants: [],
        };
        group.grants.push(grant);
        cases.set(key, group);
    }
    for (const group of cases.values())
        group.grants.sort((a, b) => a.id - b.id);
    return [...cases.values()];
}

export const INVESTIGATION_NOTICE =
    'Restricted investigator role · Each access requires a case and reason and is logged. Global settings access alone does not authorise private chat inspection.';

/** Mockup M22 "Authorised investigation access": case, reason, scope, then a logged read-only view. */
export function InvestigationAccess({
    actorId,
    investigator,
    durations = [1, 8, 24, 72, 168],
    grants: initialGrants = [],
    onGrantsChange,
    autoload = false,
}: {
    actorId: number;
    investigator: boolean;
    durations?: number[];
    grants?: InvestigationGrant[];
    onGrantsChange?: (grants: InvestigationGrant[]) => void;
    /** Load the investigator's current case access (used inside the settings dialog). */
    autoload?: boolean;
}) {
    const [caseReference, setCaseReference] = useState('');
    const [reason, setReason] = useState('');
    const [conversations, setConversations] = useState<
        InvestigableConversation[]
    >([]);
    const [duration, setDuration] = useState('24');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [opened, setOpened] = useState<{
        url: string;
        count: number;
    } | null>(null);
    const [grants, setGrants] = useState(initialGrants);
    useEffect(() => {
        if (!investigator || !autoload) return;
        let current = true;
        governanceRequest<{ grants: InvestigationGrant[] }>(
            actorId,
            `${INVESTIGATION_API}/overview`,
        )
            .then((overview) => {
                if (current) setGrants(overview.grants);
            })
            .catch((failure: Error) => {
                if (current) setError(failure.message);
            });
        return () => {
            current = false;
        };
    }, [actorId, investigator, autoload]);

    if (!investigator)
        return (
            <div className="grid gap-3">
                <Alert variant="info" role="note">
                    <ShieldCheck />
                    <AlertDescription>{INVESTIGATION_NOTICE}</AlertDescription>
                </Alert>
                <div className="flex items-start gap-3 rounded-lg bg-muted/40 p-3 text-sm">
                    <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p>
                        Your account does not hold the restricted investigator
                        permission. An administrator assigns it to named people
                        in Settings › Roles; it is never part of a role by
                        default.
                    </p>
                </div>
            </div>
        );

    const ready =
        caseReference.trim() !== '' &&
        reason.trim().length >= 10 &&
        conversations.length > 0;

    async function submit() {
        if (!ready || saving) return;
        setSaving(true);
        setError('');
        try {
            const result = await governanceRequest<{
                grant_ids: number[];
                url: string;
            }>(actorId, `${INVESTIGATION_API}/grants`, {
                method: 'POST',
                body: JSON.stringify({
                    conversation_ids: conversations.map((item) => item.id),
                    case_reference: caseReference.trim(),
                    reason: reason.trim(),
                    duration_hours: Number(duration),
                }),
            });
            setOpened({ url: result.url, count: result.grant_ids.length });
            const overview = await governanceRequest<{
                grants: InvestigationGrant[];
            }>(actorId, `${INVESTIGATION_API}/overview`);
            setGrants(overview.grants);
            onGrantsChange?.(overview.grants);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setSaving(false);
        }
    }

    async function end(grant: InvestigationGrant) {
        setError('');
        try {
            const result = await governanceRequest<{
                grants: InvestigationGrant[];
            }>(actorId, `${INVESTIGATION_API}/grants/${grant.id}/end`, {
                method: 'POST',
            });
            setGrants(result.grants);
            onGrantsChange?.(result.grants);
        } catch (failure) {
            setError((failure as Error).message);
        }
    }

    const activeCases = groupByCase(grants.filter((grant) => grant.active));

    return (
        <div className="grid gap-4">
            <Alert variant="info" role="note">
                <ShieldCheck />
                <AlertDescription>{INVESTIGATION_NOTICE}</AlertDescription>
            </Alert>
            <div className="grid gap-2">
                <Label htmlFor="investigation-case">Case reference</Label>
                <Input
                    id="investigation-case"
                    value={caseReference}
                    maxLength={100}
                    disabled={saving}
                    onChange={(event) => setCaseReference(event.target.value)}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="investigation-reason">Reason for access</Label>
                <Textarea
                    id="investigation-reason"
                    value={reason}
                    maxLength={2000}
                    disabled={saving}
                    onChange={(event) => setReason(event.target.value)}
                />
                <p className="text-caption">
                    At least 10 characters. Recorded with every view of this
                    case scope.
                </p>
            </div>
            <ConversationFinder
                actorId={actorId}
                multiple
                max={MAX_CASE_CONVERSATIONS}
                selection={conversations}
                onSelectionChange={setConversations}
                disabled={saving}
            />
            <div className="grid gap-2">
                <Label htmlFor="investigation-duration">
                    Access ends after
                </Label>
                <Select
                    value={duration}
                    disabled={saving}
                    onValueChange={setDuration}
                >
                    <SelectTrigger id="investigation-duration">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {durations.map((hours) => (
                            <SelectItem key={hours} value={String(hours)}>
                                {durationLabel(hours)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {opened && (
                <Alert variant="success" role="status">
                    <CheckCircle2 />
                    <AlertDescription>
                        <span>
                            Case access logged for {opened.count}{' '}
                            {opened.count === 1
                                ? 'conversation'
                                : 'conversations'}
                            . The case scope is read-only. While access is
                            active you can export a watermarked PDF; every
                            export is logged.
                        </span>
                        <a
                            href={opened.url}
                            className="font-medium underline underline-offset-4"
                        >
                            Open the read-only case view
                        </a>
                    </AlertDescription>
                </Alert>
            )}
            <div className="flex justify-end">
                <Button
                    disabled={!ready || saving}
                    onClick={() => void submit()}
                >
                    {saving ? 'Logging…' : 'Log and open authorised scope'}
                </Button>
            </div>
            {activeCases.length > 0 && (
                <section
                    aria-labelledby="investigation-active"
                    className="grid gap-2"
                >
                    <h3
                        id="investigation-active"
                        className="text-section-title"
                    >
                        Your active case access
                    </h3>
                    <ul className="grid gap-2">
                        {activeCases.map((group) => (
                            <li
                                key={group.key}
                                className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-medium">
                                        {group.caseReference}
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge variant="info">
                                            Read-only
                                        </StatusBadge>
                                        <span className="text-caption">
                                            {group.grants.length}{' '}
                                            {group.grants.length === 1
                                                ? 'conversation'
                                                : 'conversations'}
                                        </span>
                                    </div>
                                </div>
                                <ul
                                    aria-label={`Conversations for case ${group.caseReference}`}
                                    className="grid gap-2"
                                >
                                    {group.grants.map((grant) => (
                                        <li
                                            key={grant.id}
                                            className="flex flex-wrap items-center justify-between gap-3 rounded-md border bg-card p-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="break-words">
                                                    {grant.conversation
                                                        ? conversationLabel(
                                                              grant.conversation,
                                                          )
                                                        : 'Conversation unavailable'}
                                                </p>
                                                <p className="text-caption">
                                                    Ends{' '}
                                                    {formatDateTime(
                                                        grant.expires_at,
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex gap-2">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <a
                                                        href={`/settings/chat/investigations/${grant.id}`}
                                                    >
                                                        Open
                                                    </a>
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        void end(grant)
                                                    }
                                                >
                                                    End access
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
