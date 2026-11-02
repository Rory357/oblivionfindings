import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { Archive, Lock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConversationFinder } from './conversation-finder';
import {
    conversationLabel,
    governanceRequest,
    INVESTIGATION_API,
    type InvestigableConversation,
    type PreservationHold,
} from './types';

export const PRESERVATION_NOTICE =
    'Preservation holds override scheduled disposal only for their authorised scope. While a hold is active, senders cannot edit or remove messages in that conversation. Retention is not configured. Exports are made by an investigator from an active case view: a watermarked PDF of the granted conversations only, with every export logged.';

/** Mockup M22 "Exports and preservation": case, reason, reviewed authority, then a logged hold. */
export function PreservationHolds({
    actorId,
    investigator,
    holds: initialHolds = [],
    autoload = false,
}: {
    actorId: number;
    investigator: boolean;
    holds?: PreservationHold[];
    autoload?: boolean;
}) {
    const [caseReference, setCaseReference] = useState('');
    const [reason, setReason] = useState('');
    const [authority, setAuthority] = useState(false);
    const [conversation, setConversation] =
        useState<InvestigableConversation | null>(null);
    const [holds, setHolds] = useState(initialHolds);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [releasing, setReleasing] = useState<number | null>(null);
    const [releaseReason, setReleaseReason] = useState('');

    useEffect(() => {
        if (!investigator || !autoload) return;
        let current = true;
        governanceRequest<{ holds: PreservationHold[] }>(
            actorId,
            `${INVESTIGATION_API}/overview`,
        )
            .then((overview) => {
                if (current) setHolds(overview.holds);
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
                    <Archive />
                    <AlertDescription>{PRESERVATION_NOTICE}</AlertDescription>
                </Alert>
                <div className="flex items-start gap-3 rounded-lg bg-muted/40 p-3 text-sm">
                    <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p>
                        Placing or releasing a hold needs the restricted
                        investigator permission. Settings access alone does not
                        include it.
                    </p>
                </div>
            </div>
        );

    const ready =
        caseReference.trim() !== '' &&
        reason.trim().length >= 10 &&
        authority &&
        conversation !== null;

    async function place() {
        if (!ready || saving || !conversation) return;
        setSaving(true);
        setError('');
        setNotice('');
        try {
            const result = await governanceRequest<{
                holds: PreservationHold[];
            }>(actorId, `${INVESTIGATION_API}/holds`, {
                method: 'POST',
                body: JSON.stringify({
                    conversation_id: conversation.id,
                    case_reference: caseReference.trim(),
                    reason: reason.trim(),
                    authority_reviewed: authority,
                }),
            });
            setHolds(result.holds);
            setNotice('Preservation hold placed and logged.');
            setConversation(null);
            setAuthority(false);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setSaving(false);
        }
    }

    async function release(hold: PreservationHold) {
        if (releaseReason.trim().length < 10 || saving) return;
        setSaving(true);
        setError('');
        try {
            const result = await governanceRequest<{
                holds: PreservationHold[];
            }>(actorId, `${INVESTIGATION_API}/holds/${hold.id}/release`, {
                method: 'POST',
                body: JSON.stringify({ reason: releaseReason.trim() }),
            });
            setHolds(result.holds);
            setReleasing(null);
            setReleaseReason('');
            setNotice('Hold released and logged.');
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="hold-case">Case reference</Label>
                <Input
                    id="hold-case"
                    value={caseReference}
                    maxLength={100}
                    disabled={saving}
                    onChange={(event) => setCaseReference(event.target.value)}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="hold-reason">Reason</Label>
                <Textarea
                    id="hold-reason"
                    value={reason}
                    maxLength={2000}
                    disabled={saving}
                    onChange={(event) => setReason(event.target.value)}
                />
            </div>
            <ConversationFinder
                actorId={actorId}
                label="Conversation to preserve"
                selected={conversation}
                onSelect={setConversation}
                disabled={saving}
            />
            <Alert variant="info" role="note">
                <Archive />
                <AlertDescription>{PRESERVATION_NOTICE}</AlertDescription>
            </Alert>
            <div className="flex items-center gap-3">
                <Checkbox
                    id="hold-authority"
                    checked={authority}
                    disabled={saving}
                    onCheckedChange={(checked) =>
                        setAuthority(checked === true)
                    }
                />
                <Label htmlFor="hold-authority">
                    Case authority has been reviewed.
                </Label>
            </div>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {notice && (
                <p role="status" className="text-sm text-status-success">
                    {notice}
                </p>
            )}
            <div className="flex justify-end">
                <Button
                    disabled={!ready || saving}
                    onClick={() => void place()}
                >
                    Place preservation hold
                </Button>
            </div>
            {holds.length > 0 && (
                <section aria-labelledby="hold-list" className="grid gap-2">
                    <h3 id="hold-list" className="text-section-title">
                        Preservation holds
                    </h3>
                    <ul className="grid gap-2">
                        {holds.map((hold) => (
                            <li
                                key={hold.id}
                                className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-medium">
                                        {hold.case_reference}
                                    </p>
                                    <StatusBadge
                                        variant={
                                            hold.active ? 'warning' : 'neutral'
                                        }
                                    >
                                        {hold.active ? 'Held' : 'Released'}
                                    </StatusBadge>
                                </div>
                                <p className="break-words text-muted-foreground">
                                    {hold.conversation
                                        ? conversationLabel(hold.conversation)
                                        : 'Conversation unavailable'}
                                </p>
                                <p className="break-words">{hold.reason}</p>
                                <p className="text-caption">
                                    Placed by {hold.placed_by},{' '}
                                    {formatDateTime(hold.placed_at)}
                                    {hold.released_at &&
                                        ` · Released by ${hold.released_by}, ${formatDateTime(hold.released_at)}`}
                                </p>
                                {hold.active && releasing !== hold.id && (
                                    <div>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setReleasing(hold.id);
                                                setReleaseReason('');
                                            }}
                                        >
                                            Release hold
                                        </Button>
                                    </div>
                                )}
                                {releasing === hold.id && (
                                    <div className="grid gap-2">
                                        <Label htmlFor={`release-${hold.id}`}>
                                            Why can this hold end?
                                        </Label>
                                        <Textarea
                                            id={`release-${hold.id}`}
                                            value={releaseReason}
                                            disabled={saving}
                                            onChange={(event) =>
                                                setReleaseReason(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setReleasing(null)
                                                }
                                            >
                                                Keep the hold
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                disabled={
                                                    saving ||
                                                    releaseReason.trim()
                                                        .length < 10
                                                }
                                                onClick={() =>
                                                    void release(hold)
                                                }
                                            >
                                                Release hold
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
