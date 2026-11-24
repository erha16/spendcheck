<?php

namespace App\Models;

use Database\Factories\TransactionAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['transaction_id', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'checksum', 'uploaded_by'])]
class TransactionAttachment extends Model
{
    /** @use HasFactory<TransactionAttachmentFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Transaction attachments are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Transaction attachments are append-only.'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
