import type { KnowledgeDiagramV2 } from './contract';

export function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob),
        link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const safeFilename = (title: string) =>
    title
        .replace(/[^\p{L}\p{N} _-]/gu, '')
        .trim()
        .slice(0, 100) || 'diagram';
export const downloadJson = (
    value: KnowledgeDiagramV2,
    deliver = downloadBlob,
) =>
    deliver(
        new Blob([JSON.stringify(value, null, 2)], {
            type: 'application/json',
        }),
        `${safeFilename(value.title)}.diagram.json`,
    );
const asDataUrl = (blob: Blob) =>
    new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () =>
            reject(new Error('An image could not be exported.'));
        reader.readAsDataURL(blob);
    });
/** Export is a generated visual artifact. Raster data URLs are never written back into diagram source. */
export async function serializeSvg(
    svg: SVGSVGElement,
    blobs: ReadonlyMap<string, Blob>,
): Promise<string> {
    const clone = svg.cloneNode(true) as SVGSVGElement;
    clone
        .querySelectorAll('[data-editor-only]')
        .forEach((node) => node.remove());
    clone
        .querySelectorAll('[data-printable="false"]')
        .forEach((node) => node.remove());
    clone.querySelectorAll('[tabindex], [role="button"]').forEach((node) => {
        node.removeAttribute('tabindex');
        node.removeAttribute('role');
        node.removeAttribute('aria-pressed');
    });
    const view = svg.viewBox.baseVal;
    clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    clone.setAttribute('width', String(view.width));
    clone.setAttribute('height', String(view.height));
    clone.removeAttribute('style');
    clone.removeAttribute('class');
    for (const element of clone.querySelectorAll('image')) {
        const src = element.getAttribute('href') ?? '',
            blob = blobs.get(src);
        if (!blob)
            throw new Error(
                'Wait for every visible image to load before exporting.',
            );
        element.setAttribute('href', await asDataUrl(blob));
    }
    return new XMLSerializer().serializeToString(clone);
}
export async function downloadRaster(
    svg: string,
    filename: string,
    mime: 'image/png' | 'image/jpeg',
    width: number,
    height: number,
    deliver = downloadBlob,
): Promise<void> {
    const scale = Math.min(
        2,
        4096 / width,
        4096 / height,
        Math.sqrt(16000000 / (width * height)),
    );
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    try {
        const image = new Image();
        await new Promise<void>((resolve, reject) => {
            image.onload = () => resolve();
            image.onerror = () =>
                reject(
                    new Error('The drawing could not be rendered for export.'),
                );
            image.src = url;
        });
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        if (!context)
            throw new Error('Image export is unavailable in this browser.');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise<Blob>((resolve, reject) =>
            canvas.toBlob(
                (value) =>
                    value
                        ? resolve(value)
                        : reject(new Error('Image export failed.')),
                mime,
                0.94,
            ),
        );
        deliver(blob, filename);
    } finally {
        URL.revokeObjectURL(url);
    }
}
