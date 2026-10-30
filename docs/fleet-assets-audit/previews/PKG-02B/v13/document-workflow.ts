import type { EvidenceFile } from './evidence-field';
import type { Store, VehicleReminder } from './operations';

export const PREVIEW_NOW = '2026-09-22T09:30';
type Values = Record<string, string>;
export const documentGroup = (file: EvidenceFile) =>
    file.documentGroup || file.id;
export const documentReminders = (
    data: Pick<Store, 'followups'>,
    file?: EvidenceFile,
) =>
    file
        ? data.followups.filter(
              (r) =>
                  r.status !== 'Completed' &&
                  (r.documentGroup === documentGroup(file) ||
                      (r.source === file.id && r.id.startsWith('REM-DOC-'))),
          )
        : [];

export function suggestedRenewalTime(expiry: string) {
    if (!expiry) return '';
    const date = new Date(expiry + 'T12:00:00Z');
    if (!Number.isFinite(date.getTime())) return '';
    date.setUTCDate(date.getUTCDate() - 30);
    return (
        (date.toISOString().slice(0, 10) < '2026-09-23'
            ? '2026-09-23'
            : date.toISOString().slice(0, 10)) + 'T09:00'
    );
}

export function validateDocument(values: Values) {
    if (values.expiry && values.expiry < values.issued)
        return 'Expiry must be on or after the document date.';
    if (values.remind !== 'true') return '';
    if (!values.expiry)
        return 'Add an expiry date here before scheduling its reminder.';
    if (!values.remindAt || values.remindAt <= PREVIEW_NOW)
        return 'Choose a future reminder date and time.';
    if (values.remindAt.slice(0, 10) > values.expiry)
        return 'The renewal reminder must be on or before expiry. For an expired document, add a follow-up from Reminders.';
    if (!values.reminderOwner || !values.reminderBackup)
        return 'Choose the reminder owner and backup.';
    return '';
}

/** One document set can contain several files; its renewal remains one calendar item. */
export function saveDocument(
    data: Store,
    values: Values,
    uploaded: EvidenceFile[],
    previous?: EvidenceFile,
    edit = false,
): Store {
    const group = previous
        ? documentGroup(previous)
        : 'DOCSET-DEMO-' + crypto.randomUUID().slice(0, 8);
    const belongs = (f: EvidenceFile) =>
        documentGroup(f) === group && (!f.status || f.status === 'Current');
    const metadata = {
        documentGroup: group,
        category: values.category,
        reference: values.reference,
        issued: values.issued,
        expiry: values.expiry,
        finance: values.finance === 'none' ? '' : values.finance,
        note: values.reason,
    };
    const files = uploaded.map((f) => ({
        ...f,
        ...metadata,
        owner: 'VH-014',
        status: 'Current',
        replaces: previous?.id,
    }));
    const source = edit ? previous!.id : files[0]?.id;
    if (!source) return data;
    const linked = documentReminders(data, previous);
    const primary = linked[0];
    const history = `22 Sep · Coordinator · ${edit ? 'Updated document details' : previous ? 'Replaced document set' : 'Uploaded document set'}: ${values.reason} · Expiry ${previous?.expiry || 'none'} → ${values.expiry || 'none'}`;
    const renewal: VehicleReminder | undefined =
        values.remind === 'true'
            ? {
                  id:
                      primary?.id ||
                      'REM-DOC-' + crypto.randomUUID().slice(0, 8),
                  documentGroup: group,
                  title: values.category + ' renewal',
                  source,
                  at: values.remindAt,
                  owner: values.reminderOwner,
                  backup: values.reminderBackup,
                  repeat: 0,
                  notes: values.reason,
                  status:
                      primary?.status === 'Acknowledged' &&
                      primary.at === values.remindAt
                          ? 'Acknowledged'
                          : 'Scheduled',
                  history: [
                      ...(primary?.history || []),
                      history + ' · Renewal reminder linked to ' + source,
                  ],
              }
            : undefined;
    return {
        ...data,
        documents: [
            ...data.documents.map((f) =>
                belongs(f)
                    ? edit
                        ? { ...f, ...metadata }
                        : { ...f, status: 'Superseded' }
                    : f,
            ),
            ...files,
        ],
        profile: {
            ...data.profile,
            history: [...data.profile.history, history],
        },
        followups: [
            ...data.followups.map((r) => {
                if (!linked.some((x) => x.id === r.id)) return r;
                if (renewal && r.id === primary?.id) return renewal;
                return {
                    ...r,
                    source,
                    documentGroup: group,
                    status: 'Paused',
                    history: [
                        ...r.history,
                        history + ' · Reminder paused; follow-up retained',
                    ],
                };
            }),
            ...(renewal && !primary ? [renewal] : []),
        ],
    };
}
