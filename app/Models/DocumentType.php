<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One paper the company keeps on file for everybody: «Копия паспорта», «ИНН»,
 * «Копия диплома».
 *
 * A reference list rather than a set of columns, because what a personnel
 * office asks for changes with the rules it works under, and every change would
 * otherwise be a migration. A type says what the paper is called, what is
 * wanted of it in a word, whether the original is handed over instead of sent,
 * and whether it is asked of everybody. How it arrives is not a question any
 * more: a document is one or more PDFs.
 */
class DocumentType extends Model
{
    protected $fillable = [
        'name',
        'position',
        'hint',
        'on_paper',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'on_paper' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /**
     * The list in the order the office reads it, and alphabetically within a
     * place where two papers somehow share one — which they do not, but a list
     * whose order depended on the order rows happen to come back in would be a
     * list that moved about.
     *
     * @param  Builder<DocumentType>  $query
     */
    public function scopeInOrder($query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /** What people have handed in against it. */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
