const views = new Map<number, Map<number, Set<symbol>>>();

export function registerChatSoundView(actorId: number, conversationId: number) {
    const token = Symbol();
    const actor = views.get(actorId) ?? new Map<number, Set<symbol>>();
    const conversation = actor.get(conversationId) ?? new Set<symbol>();
    conversation.add(token);
    actor.set(conversationId, conversation);
    views.set(actorId, actor);
    return () => {
        conversation.delete(token);
        if (!conversation.size) actor.delete(conversationId);
        if (!actor.size) views.delete(actorId);
    };
}

export function visibleChatSoundViews(actorId: number) {
    return document.visibilityState === 'visible' && document.hasFocus()
        ? [...(views.get(actorId)?.keys() ?? [])]
        : [];
}
