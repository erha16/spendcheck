<?php

namespace App\Models;

use Database\Factories\AdvanceAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['operational_advance_id', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'checksum', 'uploaded_by'])]
class AdvanceAttachment extends Model
{
    /** @use HasFactory<AdvanceAttachmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AdvanceAttachment $attachment): void {
            if ($attachment->size_bytes < 1) {
                throw new \InvalidArgumentException('Lampiran bon harus memiliki ukuran positif.');
            }
        });
        static::updating(fn (): never => throw new LogicException('Bukti bon bersifat append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bukti bon bersifat append-only.'));
    }

    public function operationalAdvance(): BelongsTo
    {
        return $this->belongsTo(OperationalAdvance::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
