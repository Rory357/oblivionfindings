import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import type {
    ComplianceRecord,
    ComplianceVersion,
    VehicleProfile,
} from '@/components/fleet-assets/vehicle-workspace/types';
import {
    applicabilityNames,
    outcomeNames,
} from '@/components/fleet-assets/vehicle-workspace/types';
import { Button } from '@/components/ui/button';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { FileText, History, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
const sections = [
    {
        key: 'source',
        label: 'Source evidence',
        blurb: 'Current version and original files',
        icon: FileText,
    },
    {
        key: 'history',
        label: 'Version history',
        blurb: 'Retained evidence and decisions',
        icon: History,
    },
    {
        key: 'resolution',
        label: 'Resolution path',
        blurb: 'Existing authority and next actions',
        icon: ShieldCheck,
    },
];
export function ComplianceSourceDialog({
    vehicle,
    record,
    onClose,
    onProfile,
}: {
    vehicle: VehicleProfile;
    record: ComplianceRecord;
    onClose: () => void;
    onProfile: () => void;
}) {
    const [section, setSection] = useState(0),
        [file, setFile] = useState<PreviewFile | null>(null);
    const version = (current: ComplianceVersion) => (
        <ReviewCard
            key={current.id}
            title={`Version ${current.version}`}
            icon={FileText}
        >
            <ReviewRow
                label="Applicability"
                value={applicabilityNames[current.applicability]}
            />
            <ReviewRow
                label="Basis"
                value={current.applicability_basis ?? 'Not recorded'}
            />
            <ReviewRow label="Outcome" value={outcomeNames[current.outcome]} />
            <ReviewRow
                label="Reference"
                value={
                    current.evidence_reference ??
                    current.source_reference ??
                    'Not recorded'
                }
            />
            <ReviewRow
                label="Expiry / next due"
                value={
                    current.expires_on
                        ? formatDateOnly(current.expires_on)
                        : 'Not recorded'
                }
            />
            {record.kind === 'ruc' && (
                <ReviewRow
                    label="RUC coverage"
                    value={
                        current.ruc_start_km !== null &&
                        current.ruc_end_km !== null
                            ? `${current.ruc_start_km.toLocaleString('en-NZ')}–${current.ruc_end_km.toLocaleString('en-NZ')} km`
                            : 'Not recorded'
                    }
                />
            )}
            <ReviewRow
                label="Recorded by"
                value={current.recorded_by ?? 'Not recorded'}
            />
            <ReviewRow
                label="Recorded at"
                value={
                    current.created_at
                        ? formatDateTime(current.created_at)
                        : 'Not recorded'
                }
            />
            <ReviewRow label="Notes" value={current.reason ?? 'None'} />
            <div className="mt-3 flex flex-col items-start gap-2">
                {current.files.length ? (
                    current.files.map((document) => (
                        <Button
                            key={document.id}
                            variant="outline"
                            onClick={() =>
                                setFile({
                                    id: document.id,
                                    name: document.name,
                                    mime: document.mime,
                                    bytes: document.size_bytes,
                                    version: current.version,
                                    source: vehicle.name,
                                    previewUrl: document.url,
                                    downloadUrl: document.url,
                                    unavailableReason: document.url
                                        ? null
                                        : 'This file is not available for viewing. Review its scan and access state in Vehicle documents.',
                                })
                            }
                        >
                            <FileText className="size-4" />
                            {document.name}
                        </Button>
                    ))
                ) : (
                    <p className="text-caption">No original files recorded.</p>
                )}
            </div>
        </ReviewCard>
    );
    return (
        <>
            <WizardShell
                open
                maxWidth="min(92vw, 900px)"
                onClose={onClose}
                title={`${record.label} · source evidence`}
                description={vehicle.name}
                railIcon={FileText}
                railTitle={record.label}
                railSub={vehicle.name}
                steps={sections}
                stepIndex={section}
                onStepClick={setSection}
                headerLabel={sections[section].label}
                footerStart={
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                }
                footerEnd={
                    <Button onClick={onProfile}>Open vehicle profile</Button>
                }
            >
                <WizardStepPane key={section}>
                    <div className="space-y-5">
                        {section === 0 ? (
                            record.current ? (
                                version(record.current)
                            ) : (
                                <p>
                                    No current evidence version is recorded.
                                    Assess applicability from the vehicle’s
                                    source.
                                </p>
                            )
                        ) : section === 1 ? (
                            <>
                                <p className="text-caption">
                                    Showing {record.history.length} of{' '}
                                    {record.history_count} retained versions.
                                </p>
                                {record.history.map(version)}
                            </>
                        ) : (
                            <>
                                <h2 className="text-section-title">
                                    Use the source-specific next action
                                </h2>
                                <p>
                                    Update evidence through the vehicle’s
                                    evidence record. Correct missing mileage
                                    through Mileage. Repairs, retests and
                                    authorised release remain in Maintenance.
                                </p>
                                <p className="text-subtle">
                                    Planning an inspection does not renew
                                    evidence or confirm a provider appointment.
                                    Bookings and exact-trip eligibility are
                                    assessed for the selected use. There is no
                                    general compliance override here.
                                </p>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <FilePreviewDialog file={file} onClose={() => setFile(null)} />
        </>
    );
}
