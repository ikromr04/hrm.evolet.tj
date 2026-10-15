<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Position extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_id',
        'name',
        // The two words «Профиль должности» grades a position by, «КАТЕГОРИЯ»
        // and «ГРАЙД». Nothing can work either out, so whoever keeps the list
        // types them, and they stay empty until somebody does.
        'category',
        'grade',
        // The heading «Профиль должности» is printed under, typed by hand
        // because it needs the name of the position in a case nothing here
        // invents. Empty until somebody writes one, and then read in place of
        // the heading worked out from the name.
        'profile_title',
    ];

    protected static function booted(): void
    {
        // A name belongs to a department, not to the company: «Аналитик» may
        // exist in two departments and never twice in one. The index on the pair
        // says the same, but it cannot say it about the positions that have no
        // department yet — MySQL lets any number of rows share a name while the
        // department is null — so the rule is kept here as well, where a missing
        // department counts as a group of its own.
        static::saving(function (Position $position) {
            $taken = static::where('name', $position->name)
                ->where(fn ($query) => $position->department_id === null
                    ? $query->whereNull('department_id')
                    : $query->where('department_id', $position->department_id))
                ->whereKeyNot($position->id)
                ->exists();

            if ($taken) {
                throw new InvalidArgumentException($position->department_id === null
                    ? "Должность «{$position->name}» уже есть среди должностей без отдела."
                    : "Должность «{$position->name}» в этом отделе уже есть.");
            }
        });
    }

    /**
     * How a position reads wherever it is shown: «Администратор (ОАП)», its name
     * and the department it belongs to. Two «Администратор» in two departments
     * are the same word and different work, and since the name stopped being
     * unique the department is the only thing telling them apart on screen.
     *
     * The department says what it is called itself — its short name where it has
     * one and its full name where it has not — so the two directories agree. A
     * position nobody has placed yet is just its name: there are no empty
     * brackets to read.
     *
     * For showing only. What is validated, searched and kept unique inside a
     * department is the plain name.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => $this->department === null
            ? $this->name
            : "{$this->name} ({$this->department->display_name})");
    }

    /**
     * The department this position belongs to, and nothing at all while the
     * directory has not been told which one: a position is kept on file before
     * anybody knows where it sits, and reads as unplaced until then.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * What this position keeps of its own «Профиль должности»: its wording of
     * the standard sections it rewrote. Everything it did not rewrite it reads
     * from the template, so this is never the whole document —
     * RegulationSection::forPosition() puts that together.
     */
    public function profileSections(): HasMany
    {
        return $this->hasMany(RegulationSection::class);
    }

    /**
     * What somebody in this position answers for, in the order somebody entered
     * them: duties are written down as a list and read back as the same list, so
     * the one nobody thought of first stays last.
     *
     * The whole list belongs to the position, which belongs to one department,
     * so there is no longer anything to tell apart inside it. Which of these an
     * employee carries is theirs to say and is kept on the employee.
     */
    public function duties(): HasMany
    {
        return $this->hasMany(PositionDuty::class)->orderBy('order')->orderBy('id');
    }
}
