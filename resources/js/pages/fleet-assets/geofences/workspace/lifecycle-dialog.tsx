import { Input } from '@/components/ui/input';
import { useState } from 'react';
import { base, request, useRemote } from './api';
import type { BoundaryRecord, Impact, RuleRecord } from './data';
import { Button, Modal, Notice } from './ui';

export type LifecycleAction =
    | { kind: 'legacy'; boundary: BoundaryRecord }
    | { kind: 'remove-rule'; rule: RuleRecord };
export function LifecycleDialog({
    action,
    onClose,
    onSaved,
}: {
    action: LifecycleAction;
    onClose: () => void;
    onSaved: () => void;
}) {
    const legacy = action.kind === 'legacy',
        boundary = legacy ? action.boundary : null;
    const [reason, setReason] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const check = useRemote<{ impact: Impact; boundary: BoundaryRecord }>(
        boundary ? base + '/' + boundary.id : null,
    );
    const pause =
        check.data?.boundary.legacy_monitoring ?? boundary?.legacy_monitoring;
    const label = legacy
        ? pause
            ? 'Pause existing monitoring'
            : 'Remove legacy resource links'
        : 'Remove inactive rule';
    const submit = async () => {
        setBusy(true);
        setError('');
        try {
            if (action.kind === 'legacy')
                await request(
                    base + '/' + action.boundary.id + '/legacy-links',
                    'POST',
                    {
                        expected_revision: check.data?.boundary.revision,
                        action: pause ? 'pause' : 'unlink',
                        reason,
                    },
                );
            else
                await request(base + '/rules/' + action.rule.id, 'DELETE', {
                    expected_version: action.rule.revision,
                    reason,
                });
            onSaved();
            onClose();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <Modal
            title={label}
            description={
                boundary?.name ??
                (action.kind === 'remove-rule' ? action.rule.label : '')
            }
            onClose={() => !busy && onClose()}
            footer={
                <>
                    <Button variant="outline" disabled={busy} onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={
                            busy ||
                            reason.trim().length < 3 ||
                            (legacy &&
                                (!check.data ||
                                    check.loading ||
                                    !!check.error ||
                                    check.data.impact.protected_dependency))
                        }
                        onClick={submit}
                    >
                        {busy ? 'Saving…' : label}
                    </Button>
                </>
            }
        >
            <div className="bnd-dialog flow-stack">
                {error && <Notice tone="critical" title={error} />}
                {check.error && (
                    <Notice tone="critical" title={check.error} />
                )}{' '}
                {check.loading && (
                    <p role="status">Checking current dependencies…</p>
                )}
                {check.data?.impact.protected_dependency ? (
                    <Notice
                        tone="warning"
                        title="A protected dependency requires its owner’s review"
                    >
                        Resolve the linked location or attendance dependency in
                        its owning workspace. Private records are not disclosed
                        here.
                    </Notice>
                ) : (
                    <>
                        <Notice title="Evidence is retained">
                            {legacy
                                ? pause
                                    ? 'Pause applies to all legacy resource links for this shared area. It does not remove links or generate an exit event.'
                                    : 'All remaining legacy resource links will be removed. Inactive purpose assignments remain independent. The server checks access to every linked resource before saving.'
                                : 'This removes the assignment from current use. Its reviewed geometry, settings and change history remain available.'}
                        </Notice>
                        <label className="field">
                            Reason
                            <Input
                                autoFocus
                                maxLength={1000}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        </label>
                    </>
                )}
            </div>
        </Modal>
    );
}
