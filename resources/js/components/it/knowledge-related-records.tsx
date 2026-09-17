import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ArrowUpRight, Link2, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export const KNOWLEDGE_RECORD_TYPES = [
    { value: 'service', label: 'Systems & services' },
    { value: 'asset', label: 'Assets' },
    { value: 'device', label: 'Devices' },
    { value: 'site', label: 'Sites' },
    { value: 'vendor', label: 'Vendors' },
    { value: 'credential', label: 'Credentials (masked references)' },
    { value: 'article', label: 'Documents' },
    { value: 'problem', label: 'Problems and known errors' },
] as const;
export const KNOWLEDGE_RELATIONS = [
    { value: 'documents', label: 'Documents' },
    { value: 'depends_on', label: 'Depends on' },
    { value: 'recovery_for', label: 'Recovery for' },
    { value: 'supports', label: 'Supports' },
] as const;
export interface KnowledgeRecord {
    type: (typeof KNOWLEDGE_RECORD_TYPES)[number]['value'];
    id: number;
    label: string;
    detail: string | null;
    href: string;
    documentation_href: string;
    inactive?: boolean;
    relation?: (typeof KNOWLEDGE_RELATIONS)[number]['value'];
}
interface RecordPage {
    actor_user_id: number;
    type: KnowledgeRecord['type'];
    page: number;
    records: KnowledgeRecord[];
    has_more: boolean;
}

export function KnowledgeRelatedRecords({
    records = [],
}: {
    records?: KnowledgeRecord[];
}) {
    const sourceUrl = usePage().url;
    if (!records.length) return null;
    return (
        <section className="space-y-3">
            <h3 className="text-section-title flex items-center gap-2">
                <Link2 className="size-4" />
                Related records
            </h3>
            <div className="grid gap-2">
                {records.map((record) => (
                    <div
                        key={`${record.type}:${record.id}`}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3"
                    >
                        <div className="min-w-0">
                            <p className="text-caption">
                                {
                                    KNOWLEDGE_RELATIONS.find(
                                        (relation) =>
                                            relation.value ===
                                            (record.relation ?? 'documents'),
                                    )?.label
                                }{' '}
                                ·{' '}
                                {
                                    KNOWLEDGE_RECORD_TYPES.find(
                                        (type) => type.value === record.type,
                                    )?.label
                                }
                            </p>
                            <Link
                                href={
                                    record.type === 'credential' && sourceUrl
                                        ? `${record.href}&return_to=${encodeURIComponent(sourceUrl)}`
                                        : record.href
                                }
                                className="frontline-focus inline-flex min-h-11 items-center gap-1 rounded-md font-medium text-primary"
                            >
                                {record.label}
                                <ArrowUpRight className="size-4 shrink-0" />
                            </Link>
                            {record.detail && (
                                <p className="text-caption">
                                    {record.detail.replaceAll('_', ' ')}
                                </p>
                            )}
                            {record.inactive && (
                                <p className="text-sm font-medium text-status-warning">
                                    Inactive record — review and replace this
                                    link.
                                </p>
                            )}
                        </div>
                        {record.documentation_href !== record.href && (
                            <Link
                                href={record.documentation_href}
                                className="frontline-focus inline-flex min-h-11 items-center rounded-md text-sm text-primary"
                            >
                                Related documentation
                            </Link>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

/** Current actor-bound search shared by relationship editing and register browsing. */
export function KnowledgeRecordSearch({
    articleId,
    disabled = false,
    selected = [],
    onSelect,
    actionLabel = 'Link record',
    endpoint = '/it/knowledge/record-options',
    allowedTypes,
}: {
    articleId?: number;
    disabled?: boolean;
    selected?: KnowledgeRecord[];
    onSelect: (record: KnowledgeRecord) => void;
    actionLabel?: string;
    endpoint?: string;
    allowedTypes?: readonly KnowledgeRecord['type'][];
}) {
    const actorId = usePage<SharedData>().props.auth.user.id;
    const types = KNOWLEDGE_RECORD_TYPES.filter(
        (option) => !allowedTypes || allowedTypes.includes(option.value),
    );
    const [selectedType, setType] = useState<KnowledgeRecord['type']>(
        types[0]?.value ?? 'service',
    );
    const type =
        types.find((option) => option.value === selectedType)?.value ??
        types[0]?.value;
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<
        (RecordPage & { endpoint: string }) | null
    >(null);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setResult(null);
        setError('');
        if (!type) return;
        const timer = setTimeout(() => {
            axios
                .get<RecordPage>(endpoint, {
                    signal: controller.signal,
                    params: { type, q: search, page, article_id: articleId },
                })
                .then(({ data }) => {
                    if (controller.signal.aborted) return;
                    if (
                        data.actor_user_id !== actorId ||
                        data.type !== type ||
                        data.page !== page ||
                        !Array.isArray(data.records)
                    )
                        throw new Error('Record search changed. Try again.');
                    setResult({ ...data, endpoint });
                })
                .catch((cause: unknown) => {
                    if (controller.signal.aborted) return;
                    setResult(null);
                    setError(
                        axios.isAxiosError(cause) &&
                            [401, 403, 404, 419].includes(
                                cause.response?.status ?? 0,
                            )
                            ? 'Record search is unavailable to your current account. Refresh this page to check your access.'
                            : 'Records could not be loaded. Try again.',
                    );
                });
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [type, search, page, articleId, actorId, reload, endpoint]);
    const current =
        result?.actor_user_id === actorId &&
        result.endpoint === endpoint &&
        result.type === type &&
        result.page === page
            ? result
            : null;
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2">
                <Select
                    value={type}
                    onValueChange={(value) => {
                        setResult(null);
                        setType(value as KnowledgeRecord['type']);
                        setPage(1);
                    }}
                    disabled={disabled || types.length === 0}
                >
                    <SelectTrigger
                        className="min-h-11 w-52"
                        aria-label="Related record type"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {types.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Input
                    value={search}
                    disabled={disabled}
                    maxLength={200}
                    onChange={(event) => {
                        setResult(null);
                        setSearch(event.target.value);
                        setPage(1);
                    }}
                    placeholder="Search accessible records…"
                    aria-label="Search related records"
                    className="min-h-11 min-w-48 flex-1"
                />
            </div>
            {!type ? (
                <p className="text-sm text-muted-foreground">
                    No record types are available here.
                </p>
            ) : error ? (
                <div
                    role="alert"
                    className="rounded-lg border border-destructive/30 p-3 text-sm"
                >
                    {error}
                    <Button
                        type="button"
                        variant="outline"
                        className="ml-2"
                        disabled={disabled}
                        onClick={() => setReload((value) => value + 1)}
                    >
                        Retry
                    </Button>
                </div>
            ) : !current ? (
                <p role="status" className="text-sm text-muted-foreground">
                    Searching records…
                </p>
            ) : current.records.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No matching records within your access.
                </p>
            ) : (
                <ul className="grid gap-2">
                    {current.records.map((record) => {
                        const linked = selected.some(
                            (item) =>
                                item.type === record.type &&
                                item.id === record.id,
                        );
                        return (
                            <li
                                key={`${record.type}:${record.id}`}
                                className="flex items-center justify-between gap-3 rounded-lg border border-border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium break-words">
                                        {record.label}
                                    </p>
                                    <p className="text-caption">
                                        {record.detail?.replaceAll('_', ' ')}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={disabled || linked}
                                    onClick={() => onSelect(record)}
                                    aria-label={`${actionLabel}: ${record.label}`}
                                >
                                    <Plus className="size-4" />
                                    {linked ? 'Linked' : actionLabel}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled || page === 1 || !current}
                    onClick={() => {
                        setResult(null);
                        setPage((value) => value - 1);
                    }}
                >
                    Previous
                </Button>
                <span className="text-caption">Page {page}</span>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled || !current?.has_more}
                    onClick={() => {
                        setResult(null);
                        setPage((value) => value + 1);
                    }}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}

export function KnowledgeRelationshipEditor({
    articleId,
    records,
    onChange,
    disabled,
}: {
    articleId?: number;
    records: KnowledgeRecord[];
    onChange: (records: KnowledgeRecord[]) => void;
    disabled: boolean;
}) {
    return (
        <section className="space-y-4">
            <h3 className="text-section-title">Linked systems and records</h3>
            <p className="text-sm text-muted-foreground">
                Link the existing records this document describes. Each reader
                sees links within their access.
            </p>
            {records.map((record) => (
                <div
                    key={`${record.type}:${record.id}`}
                    className="flex flex-wrap items-center gap-3 rounded-lg border border-border p-3"
                >
                    <span className="min-w-0 flex-1 text-sm font-medium break-words">
                        {record.label}
                    </span>
                    <Select
                        value={record.relation ?? 'documents'}
                        disabled={disabled}
                        onValueChange={(relation) =>
                            onChange(
                                records.map((item) =>
                                    item.type === record.type &&
                                    item.id === record.id
                                        ? {
                                              ...item,
                                              relation:
                                                  relation as KnowledgeRecord['relation'],
                                          }
                                        : item,
                                ),
                            )
                        }
                    >
                        <SelectTrigger
                            className="min-h-11 w-44"
                            aria-label={`Relationship to ${record.label}`}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {KNOWLEDGE_RELATIONS.map((relation) => (
                                <SelectItem
                                    key={relation.value}
                                    value={relation.value}
                                >
                                    {relation.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={disabled}
                        aria-label={`Remove link to ${record.label}`}
                        onClick={() =>
                            onChange(
                                records.filter(
                                    (item) =>
                                        item.type !== record.type ||
                                        item.id !== record.id,
                                ),
                            )
                        }
                    >
                        <X className="size-4" />
                    </Button>
                </div>
            ))}
            {records.length >= 30 && (
                <p className="text-sm text-muted-foreground">
                    This document has reached its 30-link limit. Remove a link
                    to add another.
                </p>
            )}
            <KnowledgeRecordSearch
                articleId={articleId}
                disabled={disabled || records.length >= 30}
                selected={records}
                onSelect={(record) =>
                    onChange([...records, { ...record, relation: 'documents' }])
                }
            />
        </section>
    );
}
