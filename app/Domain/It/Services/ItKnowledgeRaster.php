<?php

namespace App\Domain\It\Services;

use Illuminate\Validation\ValidationException;

/** The image is decoded before use; header dimensions alone are not accepted. */
final class ItKnowledgeRaster
{
    public const MAX_BYTES = 20971520;

    public const MAX_DIMENSION = 8192;

    public const MAX_PIXELS = 16000000;

    public const MIME_TYPES = ['image/png', 'image/jpeg'];

    public static function policy(): array
    {
        return ['maxBytes' => self::MAX_BYTES, 'maxWidth' => self::MAX_DIMENSION, 'maxHeight' => self::MAX_DIMENSION,
            'maxPixels' => self::MAX_PIXELS, 'mimeTypes' => self::MIME_TYPES];
    }

    public function inspect(string $path, string $mime): array
    {
        $invalid = static fn () => ValidationException::withMessages(['file' => 'Choose a valid PNG or JPEG image, up to 20 MB, 8,192 pixels per side and 16 million pixels in total.']);
        if (! in_array($mime, self::MIME_TYPES, true) || ! is_file($path) || ! is_readable($path)) {
            throw $invalid();
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw $invalid();
        }
        $size = @getimagesize($path);
        if (! is_array($size) || ($size['mime'] ?? null) !== $mime || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw $invalid();
        }
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['file' => 'Image verification is unavailable. Your document has not changed. Try again when image checking is available.']);
        }
        $image = @imagecreatefromstring(file_get_contents($path));
        if ($image === false) {
            throw $invalid();
        }
        try {
            if (imagesx($image) !== $size[0] || imagesy($image) !== $size[1]) {
                throw $invalid();
            }
        } finally {
            imagedestroy($image);
        }

        return ['mime' => $mime, 'width' => $size[0], 'height' => $size[1], 'bytes' => $bytes];
    }
}
