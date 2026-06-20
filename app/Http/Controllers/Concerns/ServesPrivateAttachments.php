<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves Health & Safety (and Privacy) evidence attachments from a PRIVATE disk
 * through an authenticated controller route, with defence-in-depth headers that
 * close the stored-XSS / content-sniffing class of attack.
 *
 *   • The file lives on the {@see self::PRIVATE_ATTACHMENT_DISK} disk
 *     (config/filesystems.php → 'private', serve:false), so it is NEVER reachable
 *     at a public /storage/... URL — only via a route that has already run auth
 *     plus the owning module's IDOR / permission checks.
 *   • X-Content-Type-Options: nosniff — the browser must honour our Content-Type
 *     and may not re-interpret a mislabelled upload as executable HTML/JS.
 *   • Content-Security-Policy: default-src 'none'; sandbox — even if a scriptable
 *     file (HTML/SVG) slipped past the upload mimetype allowlist and is opened as a
 *     top-level document, scripts/forms/plugins are inert and it cannot be framed.
 *
 * Served as an attachment by default (mirrors the prior Storage::download()
 * behaviour); pass $disposition='inline' for a preview endpoint. Inline <img>/
 * preview rendering is unaffected either way — Content-Disposition is ignored for
 * subresource loads, so a thumbnail pointed at this route still renders.
 *
 * Callers remain responsible for resolving + authorising the attachment (route
 * model binding, the FK/morph IDOR guard, permission middleware) BEFORE calling
 * this — the trait only does existence + hardened streaming.
 */
trait ServesPrivateAttachments
{
    /** The single private disk every H&S / Privacy attachment is stored on. */
    protected static string $PRIVATE_ATTACHMENT_DISK = 'private';

    /**
     * Stream a stored attachment with the hardened header set.
     *
     * @param  string|null  $disk          Stored disk (falls back to the private disk).
     * @param  string       $path          Stored relative path.
     * @param  string       $downloadName  Filename presented to the browser.
     * @param  string|null  $mime          Stored MIME (sets Content-Type for reliable preview).
     * @param  string       $disposition   'attachment' (default) or 'inline'.
     */
    protected function streamPrivateAttachment(
        ?string $disk,
        string $path,
        string $downloadName,
        ?string $mime = null,
        string $disposition = 'attachment',
    ): StreamedResponse {
        $disk = $disk ?: self::$PRIVATE_ATTACHMENT_DISK;

        abort_unless(Storage::disk($disk)->exists($path), 404);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ];

        // A trustworthy, explicit Content-Type (combined with nosniff) keeps images/
        // PDFs previewing while denying the browser any room to sniff something worse.
        if ($mime) {
            $headers['Content-Type'] = $mime;
        }

        return Storage::disk($disk)->response($path, $downloadName, $headers, $disposition);
    }
}
