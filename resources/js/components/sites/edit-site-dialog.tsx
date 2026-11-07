/**
 * Edit site (to-do #11): the Add site pop-up in edit mode, filled from the
 * Site's saved details. Opened from the Site page's pencil, the Sites list's
 * Edit (kebab and right-click) and /sites/{site}/edit links.
 */
import { PopupViewer } from '@/components/popup';
import {
    AddSiteDialog,
    type SiteEditFormPayload,
    type StepKey,
} from '@/components/sites/add-site-dialog';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import axios from 'axios';
import { Building2 } from 'lucide-react';
import { useEffect, useState } from 'react';

type LoadState =
    | { status: 'loading' }
    | { status: 'failed'; forbidden: boolean }
    | { status: 'ready'; payload: SiteEditFormPayload };

export function EditSiteDialog({
    siteId,
    isOpen,
    onClose,
    onSaved,
    initialStep,
}: {
    siteId: number | null;
    isOpen: boolean;
    onClose: () => void;
    onSaved?: () => void;
    initialStep?: StepKey;
}) {
    if (!isOpen || siteId == null) return null;
    // Keyed so each open loads the Site afresh.
    return (
        <EditSiteLoader
            key={siteId}
            siteId={siteId}
            onClose={onClose}
            onSaved={onSaved}
            initialStep={initialStep}
        />
    );
}

function EditSiteLoader({
    siteId,
    onClose,
    onSaved,
    initialStep,
}: {
    siteId: number;
    onClose: () => void;
    onSaved?: () => void;
    initialStep?: StepKey;
}) {
    const [state, setState] = useState<LoadState>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        axios
            .get<SiteEditFormPayload>(`/sites/${siteId}/edit-details`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
            .then((response) =>
                setState({ status: 'ready', payload: response.data }),
            )
            .catch((error: unknown) => {
                if (axios.isCancel(error)) return;
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;
                setState({
                    status: 'failed',
                    forbidden: status === 403 || status === 404,
                });
            });
        return () => controller.abort();
    }, [siteId, attempt]);

    if (state.status === 'ready') {
        const { site, links, reference } = state.payload;
        return (
            <AddSiteDialog
                {...reference}
                isOpen
                onClose={onClose}
                onSaved={onSaved}
                edit={{ site, links }}
                initialStep={initialStep}
            />
        );
    }

    return (
        <PopupViewer
            open
            onClose={onClose}
            icon={Building2}
            title="Edit site"
            description="Loading the site's saved details."
        >
            {state.status === 'loading' ? (
                <LoadingState message="Loading the site…" />
            ) : state.forbidden ? (
                <ErrorState
                    title="You can't edit this site"
                    message="Ask a manager with access to this site to make the change."
                />
            ) : (
                <ErrorState
                    title="The site couldn't load"
                    message="Nothing has changed. Check your connection and try again."
                    onRetry={() => {
                        setState({ status: 'loading' });
                        setAttempt((n) => n + 1);
                    }}
                />
            )}
        </PopupViewer>
    );
}
