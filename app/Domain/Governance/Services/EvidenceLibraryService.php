<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\EvidenceLibrary;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class EvidenceLibraryService
{
    public function storeEvidence(
        string $title,
        string $category,
        UploadedFile $file,
        User $uploadedBy,
        ?string $description = null,
        ?string $validUntil = null,
        array $tags = []
    ): EvidenceLibrary {
        $path = $file->store('governance/evidence/' . $category, 'local');

        return EvidenceLibrary::create([
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'valid_until' => $validUntil,
            'tags' => $tags,
            'uploaded_by' => $uploadedBy->id,
            'status' => 'active',
        ]);
    }

    public function linkToObligation(EvidenceLibrary $evidence, int $obligationId): void
    {
        $evidence->complianceObligations()->syncWithoutDetaching([$obligationId]);
    }

    public function getByCategory(string $category): \Illuminate\Database\Eloquent\Collection
    {
        return EvidenceLibrary::where('category', $category)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getExpiringSoon(int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return EvidenceLibrary::whereNotNull('valid_until')
            ->whereDate('valid_until', '<=', now()->addDays($days))
            ->where('status', 'active')
            ->orderBy('valid_until')
            ->get();
    }
}
