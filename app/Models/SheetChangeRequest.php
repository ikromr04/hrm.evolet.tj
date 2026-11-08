<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Личный листок» as somebody typed it, waiting for the office to say yes.
 *
 * The two roles that are shown their own card may retype the sheet, and what
 * they press is «Отправить»: the card is not written, a row is filed here, and
 * personnel either accept it — whereupon it is written in one go, by the same
 * code a direct save goes through — or send it back with a reason. The right to
 * change a line and the right to ask for it to be changed are two different
 * things, and this is the second one.
 *
 * The whole sheet is in the payload, not the lines that differ. What the office
 * is shown is the sheet beside the card, line by line, with the changed ones
 * marked; a request carrying only the corrections could not be read that way,
 * and it would also mean trusting a form to say which lines it had touched.
 *
 * The papers are on the disk already, because a file is either kept the moment
 * it arrives or it is lost. They are listed in «papers» as well as named inside
 * the payload: that list is what a refusal deletes, and deleting off a flat list
 * is work that cannot half-happen the way walking the payload could.
 *
 * «lines» is that reading, kept. While a request waits, the page works out what
 * it asks for by comparing the payload with the card as it stands this second;
 * once it has been answered that comparison is no longer available — accepting
 * writes the sheet onto the card, and the card then agrees with the payload
 * line for line. So the comparison is made at the decision and written down,
 * and the page reads it where it is there. Null on a request still waiting, and
 * on the rows answered before the column existed.
 */
class SheetChangeRequest extends Model
{
    /** Filed and waiting. One per person: a second «Отправить» replaces it. */
    public const PENDING = 'pending';

    /** Written onto the card, by whoever accepted it. */
    public const ACCEPTED = 'accepted';

    /** Sent back, with the reason on the row until the sheet is sent again. */
    public const REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'status',
        'payload',
        'papers',
        'lines',
        'comment',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'papers' => 'array',
            'lines' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Whose sheet it is, who typed it: here they are the same person. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** And who in the office answered it; empty where that account is gone. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The ones nobody has answered yet.
     *
     * @param  Builder<SheetChangeRequest>  $query
     * @return Builder<SheetChangeRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
