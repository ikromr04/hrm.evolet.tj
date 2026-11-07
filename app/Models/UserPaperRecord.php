<?php

namespace App\Models;

use App\Support\DocumentStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a card's list that is a line of text and the one paper behind
 * it: a degree, an award, a standing with the army.
 *
 * Three lists are kept on exactly these terms — see UserDegree, UserAward and
 * UserMilitaryRecord, which are this class and a table name apiece. They used
 * to be three columns of free text with a document behind each, one answer
 * allowed per card; a person has two medals as readily as one, so each is now
 * a list, and what one record of such a list holds is said here once.
 *
 * Not UserEducation's parent, close though the two are: a place of study
 * answers five questions and this answers one, and a class that held both
 * would be a class describing nothing in particular.
 */
abstract class UserPaperRecord extends Model
{
    /**
     * When a record goes, its paper goes with it.
     *
     * Otherwise the file stays on the disk for good, named by nothing and
     * reachable by no route — which sounds harmless until a backup is restored
     * somewhere it should not have been, and a folder of other people's papers
     * is sitting in it.
     *
     * After the row has gone rather than before: a delete that fails leaves the
     * record standing, and a record standing must still have its paper. The
     * other way about would take the file first and refuse the row second.
     */
    protected static function booted(): void
    {
        static::deleted(fn (self $record) => DocumentStore::forget($record->document_path));
    }

    /**
     * The attributes that are mass assignable.
     *
     * Where the paper went is one of them, because the save puts the file away
     * itself and then writes the row that points at it — see
     * App\Support\EmployeeCard::paperRow().
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'document_path',
        'document_name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
