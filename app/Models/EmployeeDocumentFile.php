<?php

namespace App\Models;

use App\Support\DocumentStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of one document: a scan chosen from a disk, or a photograph taken on
 * the spot. A document may be made of several of them, and the type it answers
 * says how many.
 *
 * The path is never sent anywhere. It is the row that says which file may be
 * read, and the address that answers for it names the row — see the controller
 * that serves them.
 */
class EmployeeDocumentFile extends Model
{
    protected $fillable = ['path', 'name', 'size', 'mime'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        // A file whose row is gone is a file nobody can reach and nobody will
        // ever delete, so the row takes it with it. A cascade in the database
        // does not come through here — whoever deletes that way collects the
        // paths first and forgets them itself.
        static::deleted(fn (self $file) => DocumentStore::forget($file->path));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }
}
