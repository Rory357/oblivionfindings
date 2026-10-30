<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Models\HrRecognitionImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Canonical recognition pictures have private, record-authorised URLs only. */
class HrRecognitionMedia
{
    private array $newPaths = [];

    public function ready(): bool
    {
        return Schema::hasTable('hr_recognition_images');
    }

    public static function rules(): array
    {
        return ['images' => ['sometimes', 'array', 'max:4'], 'images.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000']];
    }

    /** The outermost write owns disk rollback, including failures after nested DB writes. */
    public function guarded(callable $callback): mixed
    {
        try {
            return $callback($this);
        } catch (\Throwable $exception) {
            foreach ($this->newPaths as $path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        } finally {
            $this->newPaths = [];
        }
    }

    /** @param list<UploadedFile> $images */
    public function store(User $actor, HrKudos $kudos, array $images, ?HrKudosReply $reply = null): void
    {
        Validator::make(['images' => $images], self::rules())->validate();
        if ($images !== [] && ! $this->ready()) {
            throw ValidationException::withMessages(['images' => 'Pictures are not available yet. Keep your message and try again after the update.']);
        }
        foreach ($images as $position => $image) {
            $path = $image->store('hr/recognition/'.$kudos->id, 'private');
            if (! $path) {
                throw new \RuntimeException('The picture could not be saved. Please try again.');
            }
            $this->newPaths[] = $path;
            HrRecognitionImage::create(['kudos_id' => $kudos->id, 'reply_id' => $reply?->id, 'uploaded_by' => $actor->id,
                'path' => $path, 'mime' => $image->getMimeType(), 'size' => $image->getSize(), 'position' => $position]);
        }
    }

    public function project(HrKudos $kudos, ?int $replyId = null): array
    {
        if (! $this->ready()) {
            return [];
        }

        return HrRecognitionImage::where('kudos_id', $kudos->id)->where('reply_id', $replyId)->orderBy('position')->orderBy('id')->get()
            ->map(fn ($image) => ['id' => (int) $image->id, 'url' => '/company-hub/recognition-images/'.$image->id,
                'mime' => $image->mime, 'size' => (int) $image->size])->all();
    }

    /** Erase bytes after commit; a failed transaction retains its valid pictures. */
    public function remove(HrKudos $kudos, ?int $replyId = null, bool $entireRecognition = false): void
    {
        if (! $this->ready()) {
            return;
        }
        $query = HrRecognitionImage::where('kudos_id', $kudos->id)->when(! $entireRecognition, fn ($query) => $query->where('reply_id', $replyId));
        $paths = (clone $query)->pluck('path')->all();
        $query->delete();
        DB::afterCommit(function () use ($paths): void {
            foreach ($paths as $path) {
                Storage::disk('private')->delete($path);
            }
        });
    }
}
