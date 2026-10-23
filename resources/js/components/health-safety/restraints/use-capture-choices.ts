import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { EMPTY_RESTRAINT_PICKERS, type RestraintPickers } from './types';

/** Changing a person rechecks dependent choices without replacing the form or its factual entries. */
export function useCaptureChoices(
    initial: Partial<RestraintPickers> | undefined,
    href: string,
    kind: 'event' | 'plan',
) {
    const [snapshot, setSnapshot] = useState<RestraintPickers | null>(null);
    const [checking, setChecking] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const pending = useRef<AbortController | null>(null);
    useEffect(() => () => pending.current?.abort(), []);
    useSafetyDraftState({ dirty: false, busy: checking });
    const available = { ...EMPTY_RESTRAINT_PICKERS, ...initial };
    const pickers = snapshot
        ? { ...snapshot, clients: available.clients }
        : available;
    const select = async (
        value: string,
        onChange: (clientId: string, siteId: string) => void,
    ) => {
        pending.current?.abort();
        setSnapshot(null);
        setFailure(null);
        const selected = available.clients.find(
            (item) => String(item.id) === value,
        );
        onChange(value, selected?.site_id ? String(selected.site_id) : '');
        if (!value) {
            setChecking(false);
            return;
        }
        const controller = new AbortController();
        pending.current = controller;
        setChecking(true);
        try {
            const response = await axios.get(href, {
                headers: { Accept: 'application/json' },
                params: { kind, client_id: value },
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            const data = response.data as {
                pickers?: RestraintPickers;
                can?: { capture: boolean; create_plan: boolean };
            };
            const current = data.pickers?.clients.find(
                (item) => String(item.id) === value,
            );
            if (
                !current ||
                !data.pickers ||
                (kind === 'plan' ? !data.can?.create_plan : !data.can?.capture)
            )
                throw new Error('Current choices unavailable');
            setSnapshot(data.pickers);
            onChange(value, current.site_id ? String(current.site_id) : '');
        } catch (error) {
            if (!controller.signal.aborted) {
                setSnapshot({
                    ...EMPTY_RESTRAINT_PICKERS,
                    clients: available.clients,
                });
                setFailure(
                    axios.isAxiosError(error) &&
                        [401, 403, 404].includes(error.response?.status ?? 0)
                        ? 'Current access to this person is unavailable. Your factual entries remain here.'
                        : 'The current choices could not be checked. Choose the person again to retry. Your factual entries remain here.',
                );
            }
        } finally {
            if (pending.current === controller) {
                pending.current = null;
                setChecking(false);
            }
        }
    };
    return { pickers, checking, failure, ready: !checking && !failure, select };
}
