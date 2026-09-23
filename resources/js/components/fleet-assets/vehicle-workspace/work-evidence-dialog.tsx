import { Textarea } from '@/components/ui/textarea';
import { useRef, useState } from 'react';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import {
    fieldProps,
    RecordDialog,
    StagedFilesField,
    WizardField,
} from './wizard-kit';

/**
 * Add evidence to a Maintenance work order from the vehicle. Files are kept
 * on the work order with its other evidence; nothing here completes work.
 */
export function WorkEvidenceDialog({
    workOrderId,
    workLabel,
    onClose,
    onSaved,
}: {
    workOrderId: number;
    workLabel: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [files, setFiles] = useState<File[]>([]);
    const [description, setDescription] = useState('');
    const [error, setError] = useState('');
    const [done, setDone] = useState(0);
    const command = useVehicleRecordCommand(isJsonObject);
    // One key per chosen file, stable across retries of the same selection.
    const keys = useRef(new Map<File, string>());
    const keyFor = (file: File) => {
        const known = keys.current.get(file);
        if (known) return known;
        const key = `work-evidence-${crypto.randomUUID()}`;
        keys.current.set(file, key);
        return key;
    };

    const save = async () => {
        if (!files.length) {
            setError('Choose at least one file.');
            return;
        }
        for (let index = done; index < files.length; index++) {
            const file = files[index];
            const body = new FormData();
            body.append('parent_type', 'work');
            body.append('parent_id', String(workOrderId));
            body.append('request_key', keyFor(file));
            body.append('file', file);
            body.append('category', 'Work evidence');
            if (description.trim())
                body.append('description', description.trim());
            const result = await command.submit(
                `/fleet-assets/maintenance/work-orders/${workOrderId}/attachments`,
                body,
            );
            if (!result) return;
            setDone(index + 1);
        }
        onSaved();
        onClose();
    };

    return (
        <RecordDialog
            title="Upload work evidence"
            description={`${workLabel}. Files stay on the work order with its other evidence. Uploading evidence does not complete the work or release the vehicle.`}
            command={command}
            submitLabel={
                done > 0 && done < files.length
                    ? `Continue (${done} of ${files.length} saved)`
                    : 'Save evidence'
            }
            onSubmit={save}
            onClose={onClose}
        >
            <StagedFilesField
                label="Evidence files"
                files={files}
                onChange={(next) => {
                    setFiles(next);
                    setDone(0);
                    setError('');
                }}
                error={error || command.errors.file}
                optional={false}
            />
            <WizardField
                id="work-evidence-description"
                label="Description"
                optional
            >
                <Textarea
                    {...fieldProps('work-evidence-description')}
                    rows={2}
                    maxLength={2000}
                    value={description}
                    onChange={(event) => setDescription(event.target.value)}
                />
            </WizardField>
        </RecordDialog>
    );
}
