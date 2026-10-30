<?php

namespace App\Services\Medication\MedicineCatalogue;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class CatalogueDataset
{
    public const MAX_BYTES = 2097152;

    public const MAX_PRODUCTS = 1000;

    public const FIELDS = ['code_system', 'code', 'name', 'strength', 'form'];

    public function parse(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['dataset' => 'Use a JSON dataset no larger than 2 MiB.']);
        }
        try {
            $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['dataset' => 'Use a valid JSON array of medicine products.']);
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['dataset' => 'Import between 1 and 1,000 products.']);
        }
        $seen = [];
        foreach ($rows as &$row) {
            $row = $this->product($row);
            $hash = $this->identity($row);
            if (isset($seen[$hash])) {
                throw ValidationException::withMessages(['dataset' => 'The dataset contains a duplicate exact product identity.']);
            }
            $seen[$hash] = true;
            $row['identity_sha256'] = $hash;
        }

        return $rows;
    }

    public function product(mixed $row): array
    {
        if (! is_array($row) || array_diff(array_keys($row), self::FIELDS) || array_diff(self::FIELDS, array_keys($row))) {
            throw ValidationException::withMessages(['dataset' => 'Each product needs only code_system, code, name, strength and form.']);
        }
        $limits = ['code_system' => 40, 'code' => 100, 'name' => 200, 'strength' => 100, 'form' => 100];
        foreach ($limits as $field => $limit) {
            if (! is_string($row[$field]) || trim($row[$field]) === '' || mb_strlen($row[$field]) > $limit || preg_match('/[\x00-\x1F\x7F]/u', $row[$field])) {
                throw ValidationException::withMessages(['dataset' => 'Product fields must be bounded, non-empty text without control characters.']);
            }
            $row[$field] = trim(preg_replace('/\s+/u', ' ', $row[$field]));
        }
        if (! preg_match('/^[a-zA-Z0-9_.-]+$/D', $row['code_system'])) {
            throw ValidationException::withMessages(['dataset' => 'Use a stable code-system identifier.']);
        }

        return array_intersect_key($row, $limits);
    }

    public function identity(array $row): string
    {
        $row = $this->product(array_intersect_key($row, array_flip(self::FIELDS)));
        // Space/case normalization only. Never guess dose, strength, salt, form or code.
        $parts = array_map(fn (string $field): string => mb_strtolower($row[$field]), self::FIELDS);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function image(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'Use a valid medicine image no larger than 2 MiB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $image = @getimagesize($file->getRealPath());
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime]) || ! is_array($image) || ($image['mime'] ?? null) !== $mime || $image[0] < 1 || $image[1] < 1 || $image[0] > 4096 || $image[1] > 4096) {
            throw ValidationException::withMessages(['photo' => 'Use a JPEG, PNG or WebP image up to 4,096 pixels on each side.']);
        }

        return ['mime' => $mime, 'extension' => $extensions[$mime], 'sha256' => hash_file('sha256', $file->getRealPath())];
    }
}
