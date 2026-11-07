<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * Who a note is from, written into the note itself.
 *
 * A list of notes is read the way a list of letters is read — first for who
 * sent it, then for what it says — and the sender cannot be worked out
 * afterwards: the request a note points at keeps only its author and whoever
 * answered it, and either of them may be the sender depending on which note it
 * is. So each class says it at the moment it writes the note.
 *
 * Shared because all three notes of the personal sheet say it the same way, and
 * because a second kind of note will want to say it too.
 */
trait NamesTheSender
{
    /**
     * The surname first, then the name and the patronymic, as a sheet is signed
     * and as every list in this application reads a person.
     */
    protected function fullName(User $person): string
    {
        return trim("{$person->surname} {$person->name} {$person->patronymic}");
    }

    /**
     * The sender as the list names them, or nothing where there is no person
     * behind the note.
     *
     * The surname and the name, without the patronymic: this is a column of a
     * list, where a person is named as briefly as they can still be told apart,
     * and the sentence under the heading says the whole of it where the whole
     * of it matters. No photograph either — a face adds nothing to a line that
     * already carries a name, and the face of somebody the reader may not look
     * at would be a hole in the column where the picture was refused.
     *
     * @return array{id: int, name: string}|null
     */
    protected function sender(?User $actor): ?array
    {
        if ($actor === null) {
            return null;
        }

        return [
            'id' => $actor->id,
            'name' => trim("{$actor->surname} {$actor->name}"),
        ];
    }
}
