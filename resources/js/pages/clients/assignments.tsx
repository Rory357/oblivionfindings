import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function ClientAssignments({ client, workers, assignedIds }) {
    const { props } = usePage() as any;
    const flash = props.flash;

    const [selected, setSelected] = useState<number[]>(assignedIds ?? []);
    const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>(
        'idle',
    );

    useEffect(() => {
        setSelected(assignedIds ?? []);
    }, [assignedIds]);

    const isSelected = useMemo(() => new Set(selected), [selected]);

    function toggle(id: number) {
        setSelected((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }

    function save() {
        setStatus('saving');

        router.put(
            `/clients/${client.id}/assignments`,
            { user_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setStatus('saved');
                    router.reload({ only: ['assignedIds'] });

                    // reset "Saved" after a moment
                    setTimeout(() => setStatus('idle'), 1500);
                },
                onError: () => {
                    setStatus('error');
                },
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                // remove the show breadcrumb until you actually use /clients/{id}
                {
                    title: 'Assignments',
                    href: `/clients/${client.id}/assignments`,
                },
            ]}
        >
            <Head
                title={`Assignments • ${client.first_name} ${client.last_name}`}
            />

            <div className="space-y-4">
                {/* Visible messages */}
                {flash?.success && (
                    <div className="rounded-md border p-3 text-sm">
                        {flash.success}
                    </div>
                )}

                {status === 'error' && (
                    <div className="rounded-md border border-red-500/40 p-3 text-sm">
                        Something went wrong saving assignments. Check the
                        network response.
                    </div>
                )}

                <div className="m-4 rounded-xl border p-4">
                    <div className="text-sm text-slate-500">Client</div>
                    <div className="text-lg font-semibold">
                        {client.first_name} {client.last_name}
                    </div>
                    <div className="text-sm text-slate-500">
                        Status: {client.status}
                    </div>
                </div>

                <div className="m-4 rounded-xl border p-4">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <div className="text-lg font-semibold">
                                Assigned support workers
                            </div>
                            <div className="text-sm text-slate-500">
                                Tick workers to assign them to this client.
                            </div>
                        </div>

                        <button
                            onClick={save}
                            disabled={status === 'saving'}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-60"
                            type="button"
                        >
                            {status === 'saving'
                                ? 'Saving…'
                                : status === 'saved'
                                  ? 'Saved ✅'
                                  : 'Save'}
                        </button>
                    </div>

                    <div className="mt-4 divide-y">
                        {workers.map((w) => (
                            <label
                                key={w.id}
                                className="flex cursor-pointer items-center gap-3 py-3"
                            >
                                <input
                                    type="checkbox"
                                    checked={isSelected.has(w.id)}
                                    onChange={() => toggle(w.id)}
                                    className="h-4 w-4"
                                />
                                <div className="flex flex-col">
                                    <span className="text-sm font-medium">
                                        {w.name}
                                    </span>
                                    <span className="text-xs text-slate-500">
                                        {w.email}
                                    </span>
                                </div>
                            </label>
                        ))}

                        {workers.length === 0 && (
                            <div className="py-6 text-sm text-slate-500">
                                No support workers exist yet.
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
