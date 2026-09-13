<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItEmailAttachment;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Models\ItAttachment;
use Closure;

/** Extract bounded file evidence separately from the outer message author/body. */
final class ItEmailAttachments
{
    public const MAX_FILES = 5;

    public const MAX_FILE_BYTES = ItAttachment::MAX_SIZE_KB * 1024;

    // Five maximum files in base64, plus bounded MIME/header/body JSON overhead.
    public const MESSAGE_RESPONSE_BYTES = 73400320;

    public const FILE_RESPONSE_BYTES = 31457280;

    /** @return list<ItEmailAttachment> */
    public function gmail(array $payload): array
    {
        $files = [];
        $count = 0;
        $this->gmailPart($payload, 0, $count, $files);
        $this->validateBatch($files);

        return $files;
    }

    /** Receives one completely drained, bounded Graph collection, never a partial page. */
    public function graph(array $rows): array
    {
        if (! array_is_list($rows) || count($rows) > self::MAX_FILES) {
            throw new ItInboundContentException('too_many_attachments');
        }
        $files = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment') {
                throw new ItInboundContentException('unsupported_attachment_type');
            }
            if (! is_bool($row['isInline'] ?? false)) {
                throw new ItInboundContentException('invalid_attachment_metadata');
            }
            $mime = $this->mime($row['contentType'] ?? null);
            $inline = $row['isInline'] ?? false;
            $name = $this->name($row['name'] ?? null, $mime, $inline, count($files) + 1);
            $id = $this->remoteId($row['id'] ?? null);
            $size = $this->size($row['size'] ?? null);
            $data = array_key_exists('contentBytes', $row) ? $this->encoded($row['contentBytes']) : null;
            $files[] = new ItEmailAttachment($name, $mime, $size, $inline, 'microsoft', $id, $data, $row['name']);
        }
        $this->validateBatch($files);

        return $files;
    }

    /** Download closure is bound by the adapter to this exact mailbox/message. */
    public function contents(ItEmailAttachment $file, Closure $download): string
    {
        $this->validateBatch([$file]);
        $data = $file->encodedContent;
        if ($data === null) {
            if ($file->remoteId === null) {
                throw new ItInboundContentException('invalid_attachment_content');
            }
            $body = $download($file->remoteId);
            if (! is_array($body) || ($body['size'] ?? null) !== $file->size) {
                throw new ItInboundContentException('attachment_content_changed');
            }
            if ($file->provider === 'google') {
                $data = $this->encoded($body['data'] ?? null);
            } else {
                if (($body['id'] ?? null) !== $file->remoteId || ($body['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment'
                    || $this->mime($body['contentType'] ?? null) !== $file->mime
                    || ($body['name'] ?? null) !== $file->providerName
                    || ($body['isInline'] ?? false) !== $file->inline) {
                    throw new ItInboundContentException('attachment_content_changed');
                }
                $data = $this->encoded($body['contentBytes'] ?? null);
            }
        }

        return ItEmailBase64::decode(['data' => $data, 'size' => $file->size], self::MAX_FILE_BYTES,
            'invalid_attachment_content', 'attachment_too_large');
    }

    private function gmailPart(array $part, int $depth, int &$count, array &$files): void
    {
        if (++$count > ItEmailContent::MAX_PARTS || $depth > ItEmailContent::MAX_DEPTH) {
            throw new ItInboundContentException('message_structure_too_large');
        }
        $mime = $this->mime($part['mimeType'] ?? null);
        $headers = (new ItEmailHeaders)->read($part['headers'] ?? []);
        $disposition = (new ItEmailHeaders)->primaryValue($headers['content-disposition'] ?? '');
        $name = $part['filename'] ?? '';
        $providerName = $name;
        if (! is_string($name)) {
            throw new ItInboundContentException('invalid_attachment_name');
        }
        if ($name === '' && $disposition !== 'attachment' && str_starts_with($mime, 'multipart/')) {
            if (! is_array($part['parts'] ?? null) || ! array_is_list($part['parts'])) {
                throw new ItInboundContentException('invalid_attachment_metadata');
            }
            foreach ($part['parts'] as $child) {
                if (! is_array($child)) {
                    throw new ItInboundContentException('invalid_attachment_metadata');
                }
                $this->gmailPart($child, $depth + 1, $count, $files);
            }

            return;
        }
        if ($name === '' && $disposition !== 'attachment' && in_array($mime, ['text/plain', 'text/html'], true)) {
            if (isset($part['parts']) && $part['parts'] !== []) {
                throw new ItInboundContentException('invalid_attachment_metadata');
            }

            return;
        }
        if (count($files) >= self::MAX_FILES) {
            throw new ItInboundContentException('too_many_attachments');
        }
        if (! is_array($part['body'] ?? null) || str_starts_with($mime, 'multipart/') || $mime === 'message/rfc822') {
            throw new ItInboundContentException('unsupported_attachment_type');
        }
        $inline = $disposition !== 'attachment' && $name === '' || $disposition === 'inline';
        $name = $this->name($name, $mime, $inline, count($files) + 1);
        $body = $part['body'];
        $size = $this->size($body['size'] ?? null);
        $id = array_key_exists('attachmentId', $body) ? $this->remoteId($body['attachmentId']) : null;
        if ($id !== null && isset($body['data']) && $body['data'] !== '') {
            throw new ItInboundContentException('invalid_attachment_content');
        }
        $data = $id === null ? $this->encoded($body['data'] ?? ($size === 0 ? '' : null)) : null;
        $files[] = new ItEmailAttachment($name, $mime, $size, $inline, 'google', $id, $data, $providerName);
    }

    public function validateBatch(array $files): void
    {
        if (! array_is_list($files) || count($files) > self::MAX_FILES) {
            throw new ItInboundContentException('too_many_attachments');
        }
        $ids = [];
        $bytes = 0;
        foreach ($files as $file) {
            if (! $file instanceof ItEmailAttachment) {
                throw new ItInboundContentException('invalid_attachment_metadata');
            }
            $bytes += $this->size($file->size);
            $this->name($file->name, $this->mime($file->mime), false, 1);
            if (! in_array($file->provider, ['google', 'microsoft'], true)) {
                throw new ItInboundContentException('invalid_attachment_metadata');
            }
            if ($file->remoteId !== null) {
                $id = $this->remoteId($file->remoteId);
                if (isset($ids[$id])) {
                    throw new ItInboundContentException('ambiguous_attachment_identity');
                }
                $ids[$id] = true;
            }
        }
        if ($bytes > self::MAX_FILES * self::MAX_FILE_BYTES) {
            throw new ItInboundContentException('attachments_too_large');
        }
    }

    private function size(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new ItInboundContentException('invalid_attachment_metadata');
        }
        if ($value > self::MAX_FILE_BYTES) {
            throw new ItInboundContentException('attachment_too_large');
        }

        return $value;
    }

    private function remoteId(mixed $id): string
    {
        if (! is_string($id) || $id === '' || strlen($id) > 4096 || preg_match('/[\x00-\x20\x7f]/', $id)) {
            throw new ItInboundContentException('invalid_attachment_metadata');
        }

        return $id;
    }

    private function mime(mixed $mime): string
    {
        if (! is_string($mime) || strlen($mime) > 255 || preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D', $mime) !== 1) {
            throw new ItInboundContentException('invalid_attachment_metadata');
        }

        return strtolower($mime);
    }

    private function name(mixed $name, string $mime, bool $inline, int $number): string
    {
        if ($name === '' && $inline) {
            $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;
            if ($extension !== null) {
                $name = 'inline-image-'.$number.'.'.$extension;
            }
        }
        if (! is_string($name) || $name === '' || strlen($name) > 255 || ! mb_check_encoding($name, 'UTF-8')
            || preg_match('~[\p{Cc}\p{Cf}/\\\\:]~u', $name) || trim($name) !== $name || str_ends_with($name, '.')
            || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $name)) {
            throw new ItInboundContentException('invalid_attachment_name');
        }
        if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), explode(',', ItAttachment::ALLOWED_MIMES), true)) {
            throw new ItInboundContentException('unsupported_attachment_type');
        }

        return $name;
    }

    private function encoded(mixed $value): string
    {
        if (! is_string($value)) {
            throw new ItInboundContentException('invalid_attachment_content');
        }
        if (strlen($value) > 4 * (int) ceil(self::MAX_FILE_BYTES / 3)) {
            throw new ItInboundContentException('attachment_too_large');
        }

        return $value;
    }
}
