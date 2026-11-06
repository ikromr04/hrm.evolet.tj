<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One paper the company keeps on file for everybody: «Копия паспорта», «ИНН»,
 * «Копия диплома».
 *
 * A reference list rather than a set of columns, because what a personnel
 * office asks for changes with the rules it works under, and every change would
 * otherwise be a migration. What a type says is how its answer may arrive —
 * how many files, chosen from a disk or photographed — and whether it is wanted
 * from everybody.
 */
class DocumentType extends Model
{
    protected $fillable = [
        'name',
        'hint',
        'min_files',
        'max_files',
        'accepts_files',
        'accepts_camera',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'min_files' => 'integer',
            'max_files' => 'integer',
            'accepts_files' => 'boolean',
            'accepts_camera' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    /** What people have handed in against it. */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
