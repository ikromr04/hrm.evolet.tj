<?php

namespace App\Models;

use App\Support\DocumentStore;
use Database\Factories\UserEducationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEducation extends Model
{
    /** @use HasFactory<UserEducationFactory> */
    use HasFactory;

    protected $table = 'user_educations';

    /**
     * When a record goes, its diploma goes with it.
     *
     * Otherwise the file stays on the disk for good, named by nothing and
     * reachable by no route — which sounds harmless until a backup is
     * restored somewhere it should not have been, and a folder of other
     * people's diplomas is sitting in it.
     *
     * After the row has gone rather than before: a delete that fails leaves the
     * record standing, and a record standing must still have its diploma. The
     * other way about would take the file first and refuse the row second.
     */
    protected static function booted(): void
    {
        static::deleted(fn (self $education) => DocumentStore::forget($education->document_path));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'institution',
        'faculty',
        'specialty',
        'qualification',
        'started_year',
        'graduated_year',
        'document_path',
        'document_name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
