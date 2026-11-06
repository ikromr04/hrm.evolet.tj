<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one person has handed in against one of the papers the company asks for.
 *
 * One row per person per type: a card shows what stands now. A paper that was
 * turned down is replaced by the next attempt rather than kept beside it —
 * nobody reads the history of a passport copy, and the reason it was turned
 * down is on the row until something better arrives.
 *
 * The status is the whole of the difference between the two ways a document
 * gets here. Handed in by the person, it waits to be looked at; put there by
 * the office, it has been looked at already — by whoever put it there, who is
 * the very person who would otherwise be passing it.
 */
class EmployeeDocument extends Model
{
    /** Waiting for somebody in the office to look at it. */
    public const PENDING = 'pending';

    /** Looked at and accepted — or filed by the office itself. */
    public const APPROVED = 'approved';

    /** Sent back, with the reason on the row. */
    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'document_type_id',
        'status',
        'comment',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Whose card it belongs to. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Which of the papers asked for it answers. */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    /** The scans and photographs it is made of. */
    public function files(): HasMany
    {
        return $this->hasMany(EmployeeDocumentFile::class);
    }

    /** Who handed it in; empty where that account is gone. */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** And who looked at it. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
