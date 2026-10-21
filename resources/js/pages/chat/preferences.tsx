import { ChatPreferencesPanel } from '@/components/chat/chat-preferences-panel';
import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';

export default function ChatPreferencesPage() {
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    return (
        <AppLayout>
            <Head title="My Chat preferences" />
            <ChatPreferencesPanel actorId={auth.user.id} />
        </AppLayout>
    );
}
