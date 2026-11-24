<?php

namespace App\Models;

use Database\Factories\AdvanceSettlementAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['advance_settlement_item_id', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'checksum', 'uploaded_by'])]
class AdvanceSettlementAttachment extends Model
{
    /** @use HasFactory<AdvanceSettlementAttachmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AdvanceSettlementAttachment $attachment): void {
            if ($attachment->size_bytes < 1) {
                throw new \InvalidArgumentException('Bukti pertanggungjawaban harus memiliki ukuran positif.');
            }
        });
        static::updating(fn (): never => throw new LogicException('Bukti pertanggungjawaban bersifat append-only.'));
        static::deleting(fn (): never => throw new LogicException('Bukti pertanggungjawaban bersifat append-only.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AdvanceSettlementItem::class, 'advance_settlement_item_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
