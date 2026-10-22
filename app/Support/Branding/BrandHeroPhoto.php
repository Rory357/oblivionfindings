<?php

namespace App\Support\Branding;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The website and sign-in photo (Settings › Branding, client demo site, 23 Oct 2026).
 *
 * One photo, shared by the public home hero and both sign-in pages. It is stored on the public
 * disk beside the logos, but never as uploaded: it is decoded and re-encoded as a JPEG through
 * GD, so camera metadata (GPS location, device, capture time) never reaches a public page. It is
 * turned upright from its EXIF orientation first, and its long edge is capped at 2400 px.
 */
final class BrandHeroPhoto
{
    public const PATH_SETTING = 'branding.hero_photo_path';

    /** Stored by the branding text-field loop as "branding.hero_alt". */
    public const ALT_SETTING = 'branding.hero_alt';

    public const DIRECTORY = 'branding';

    public const MAX_EDGE = 2400;

    /** Upload limits, shown on the settings page and enforced by BrandingController. */
    public const MAX_KILOBYTES = 8192;

    public const MIN_WIDTH = 1200;

    public const MIN_HEIGHT = 800;

    /** Decoding cost guard: GD holds 4 bytes a pixel. */
    private const MAX_PIXELS = 40_000_000;

    /** @return list<string> */
    public static function rules(): array
    {
        return [
            'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES,
            'dimensions:min_width='.self::MIN_WIDTH.',min_height='.self::MIN_HEIGHT,
        ];
    }

    /** The public URL of the current photo, or null. */
    public static function url(?string $path): ?string
    {
        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Re-encode the image at $source and store it; returns its public-disk path.
     *
     * @throws ValidationException when the file is not a readable photo
     */
    public function store(string $source, string $field = 'hero_photo'): string
    {
        $bytes = is_file($source) ? @file_get_contents($source) : false;
        $size = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if (! function_exists('imagecreatefromstring') || ! $size || $size[0] < 1 || $size[1] < 1) {
            throw ValidationException::withMessages([$field => 'This photo could not be read. Choose a JPG, PNG or WebP photo.']);
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw ValidationException::withMessages([$field => 'This photo is too large to process. Choose one under 40 megapixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw ValidationException::withMessages([$field => 'This photo could not be read. Choose a JPG, PNG or WebP photo.']);
        }

        // Scale first (the full-size image is the memory peak), then turn it upright.
        $image = $this->bounded($image);
        $image = $this->upright($image, $source, $size[2] ?? null);

        ob_start();
        try {
            $written = imagejpeg($image, null, 85);
            $jpeg = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $written || ! is_string($jpeg) || $jpeg === '') {
            throw ValidationException::withMessages([$field => 'This photo could not be saved. Try another copy.']);
        }

        $path = self::DIRECTORY.'/hero-'.Str::lower(Str::random(24)).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return $path;
    }

    /** Make $path the current photo; the previous file is removed. */
    public function makeCurrent(string $path): void
    {
        $this->forgetFile();
        AppSetting::updateOrCreate(['key' => self::PATH_SETTING], ['value' => $path]);
    }

    /** Remove the photo and its description. */
    public function remove(): void
    {
        $this->forgetFile();
        AppSetting::query()->whereIn('key', [self::PATH_SETTING, self::ALT_SETTING])->delete();
    }

    private function forgetFile(): void
    {
        $existing = AppSetting::query()->where('key', self::PATH_SETTING)->value('value');
        if (is_string($existing) && $existing !== '') {
            Storage::disk('public')->delete($existing);
        }
    }

    /** @param \GdImage $image */
    private function bounded($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // Always draw onto a fresh truecolour canvas: it drops the source's
        // palette/alpha quirks, and a transparent PNG gets a white ground.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    /** @param \GdImage $image */
    private function upright($image, string $source, ?int $type)
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) ((@exif_read_data($source, 'IFD0'))['Orientation'] ?? 1);
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        return $image;
    }
}
