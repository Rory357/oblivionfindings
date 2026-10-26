import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { LoadingState } from '@/components/ui/loading-state';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { DocumentRequestsPanel } from './document-requests-panel';
import { EvidencePicker, type ReferralDocument } from './evidence-picker';
import { EvidenceUploadDialog } from './evidence-upload-dialog';
import {
    label,
    referralHref,
    type Permissions,
    type ReferralDetail,
} from './types';

export function DocumentsPanel({
    referral,
    permissions,
}: {
    referral: ReferralDetail;
    permissions: Permissions;
}) {
    const audiences = (['assessment', 'funding'] as const).filter(
        (audience) => permissions[`${audience}.view`],
    );
    const [audience, setAudience] = useState<'assessment' | 'funding'>(
        audiences[0] ?? 'assessment',
    );
    const [upload, setUpload] = useState<{
        previous?: ReferralDocument;
    } | null>(null);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const manage =
        permissions['evidence.manage'] && permissions[`${audience}.manage`];
    async function scan(document: ReferralDocument) {
        if (busy) return;
        setBusy(true);
        setMessage('');
        try {
            await axios.post(
                `${referralHref(referral.reference)}/documents/${document.id}/rescan`,
                { version: referral.version },
            );
            setMessage(
                'Scan attempt recorded. Check the document status below.',
            );
        } catch (caught) {
            setMessage(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'Scan was not confirmed. Review the latest document before trying again.')
                    : 'Scan was not confirmed.',
            );
        } finally {
            router.reload({
                only: ['referral'],
                preserveScroll: true,
                onFinish: () => {
                    setRevision((value) => value + 1);
                    setBusy(false);
                },
            });
        }
    }
    if (!audiences.length) return null;
    return (
        <div className="space-y-5">
            <div>
                <h2 className="text-section-title">Evidence</h2>
                <p className="text-subtle">
                    Current collection authority is checked before uploading.
                    Expired and replaced files remain available as history; they
                    cannot support a new funding verification.
                </p>
            </div>
            <div className="flex flex-wrap items-end justify-between gap-3">
                <Field label="Document access">
                    <SelectInput
                        value={audience}
                        placeholder="Choose document access"
                        onChange={(value) =>
                            setAudience(value as typeof audience)
                        }
                        options={audiences.map((value) => ({
                            value,
                            label: label(value),
                        }))}
                    />
                </Field>
                {manage &&
                    referral.stage !== 'closed' &&
                    !referral.general_enquiry && (
                        <Button onClick={() => setUpload({})}>
                            <Plus className="size-4" />
                            Add document
                        </Button>
                    )}
            </div>
            {message && (
                <Alert variant="info">
                    <AlertDescription>{message}</AlertDescription>
                </Alert>
            )}
            {busy && (
                <div role="status">
                    <LoadingState message="Scanning document…" />
                </div>
            )}
            <DocumentRequestsPanel
                key={`requests-${audience}`}
                referral={referral}
                audience={audience}
                permissions={permissions}
                onReceivedChange={() => setRevision((value) => value + 1)}
            />
            <EvidencePicker
                key={audience}
                reference={referral.reference}
                audience={audience}
                refresh={revision}
                onReplace={
                    manage && referral.stage !== 'closed'
                        ? (previous) => setUpload({ previous })
                        : undefined
                }
                onRescan={
                    manage && !busy
                        ? (document) => void scan(document)
                        : undefined
                }
            />
            {upload && (
                <EvidenceUploadDialog
                    referral={referral}
                    audience={audience}
                    previous={upload.previous}
                    onClose={() => {
                        setUpload(null);
                        setRevision((value) => value + 1);
                    }}
                />
            )}
        </div>
    );
}
