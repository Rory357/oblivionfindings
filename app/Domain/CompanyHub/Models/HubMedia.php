<?php

namespace App\Domain\CompanyHub\Models;

use Illuminate\Database\Eloquent\Model;

class HubMedia extends Model
{
    protected $table = 'hub_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['crop' => 'array', 'version' => 'integer', 'focal_x' => 'integer', 'focal_y' => 'integer', 'removed_at' => 'datetime',
            'document_root_id' => 'integer', 'document_sequence' => 'integer', 'document_version' => 'integer'];
    }

    /** Existing uploaded files already have a stable identity before schema activation. */
    public function documentId(): int
    {
        return (int) ($this->getAttribute('document_root_id') ?: $this->id);
    }

    public function documentSequence(): int
    {
        return (int) ($this->getAttribute('document_sequence') ?: 1);
    }
}
