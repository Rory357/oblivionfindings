export function FamilyNoteOwner({ name }: { name?: string | null }) {
    if (!name) return null;
    return (
        <p className="mt-2 text-xs text-muted-foreground">
            Follow-up owner:{' '}
            <span className="font-medium text-foreground">{name}</span>
        </p>
    );
}
