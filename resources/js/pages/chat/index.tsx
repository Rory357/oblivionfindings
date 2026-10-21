import { ChatWorkspace } from '@/components/chat/chat-workspace';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ChatPage({
    chatClient = null,
    chatFamily = false,
    initialConversation = null,
    initialMessage = null,
    initialCallHistory = null,
    initialPerson = null,
}: {
    chatClient?: { id: number; name: string } | null;
    chatFamily?: boolean;
    initialConversation?: number | null;
    initialMessage?: number | null;
    initialCallHistory?: 'missed' | null;
    initialPerson?: number | null;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Home',
                    href:
                        chatFamily && chatClient
                            ? `/portal/clients/${chatClient.id}/dashboard`
                            : '/dashboard',
                },
                { title: 'Chat', href: '/chat' },
            ]}
        >
            <Head title="Chat" />
            <ChatWorkspace
                client={chatClient}
                family={chatFamily}
                initialConversation={initialConversation}
                initialMessage={initialMessage}
                initialCallHistory={initialCallHistory}
                initialPerson={initialPerson}
            />
        </AppLayout>
    );
}
