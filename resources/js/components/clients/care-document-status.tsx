export type CareDocumentRelease = { review_required: boolean } | null;

export function CareDocumentStatus({
    release,
}: {
    release?: CareDocumentRelease;
}) {
    if (!release) return null;
    return (
        <p className="text-subtle mt-2 whitespace-normal">
            {release.review_required
                ? 'Review needed. Ask the referral team to update the care handover before downloading.'
                : 'Reviewed for care staff only. Access is checked again when downloaded.'}
        </p>
    );
}
