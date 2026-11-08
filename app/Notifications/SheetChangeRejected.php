<?php

namespace App\Notifications;

use App\Models\SheetChangeRequest;
use App\Notifications\Concerns\NamesTheSender;
use Illuminate\Notifications\Notification;

/**
 * What the colleague is told when the office sends their sheet back.
 *
 * A refusal is the one of the three that must carry words. «Отклонено» on its
 * own leaves the author with the same sheet, the same mistake in it and nothing
 * to go on, and the next thing they do is send the same request again. So the
 * reason personnel wrote travels in the note itself, where it is read without
 * opening anything, and the note leads to the card, which is where a new
 * request is sent from.
 *
 * It goes to the author alone, like the acceptance.
 *
 * The comment is required when a request is refused, so there is normally one;
 * the sentence still reads without it rather than trailing off after a colon,
 * because a note is written once and kept, and a note that reads as broken
 * would be kept broken.
 *
 * Kept in the database rather than sent by letter, so unlike the three letters
 * in this folder it is not queued: it is one insert in the same request that
 * refused the sheet, and the author's bell has to carry it from that moment
 * rather than from whenever a worker next runs.
 */
class SheetChangeRejected extends Notification
{
    use NamesTheSender;

    public function __construct(public readonly SheetChangeRequest $request) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $employee = $this->request->user;
        $name = $this->fullName($employee);
        $comment = trim((string) $this->request->comment);

        return [
            'kind' => 'sheet_request_rejected',
            'title' => 'Личный листок отклонён',
            'body' => $comment === ''
                ? 'Отдел кадров отклонил изменения в вашем личном листке.'
                : "Отдел кадров отклонил изменения: {$comment}",
            // The request itself, where the refusal is read against what was
            // asked for: the reason, who gave it, and every line of the sheet
            // beside the card's own answer. The card says the same thing in one
            // sentence over the sheet, and that is where the corrected listok is
            // sent from — but somebody told «нет» is asking which line, and only
            // this page answers that.
            'url' => route('sheet_requests.show', $this->request),
            // The officer who answered, and not the person whose sheet it
            // is: this note goes to that person, and a list telling them their
            // own name would be telling them nothing. Null for a row answered
            // before anybody was recorded as answering it.
            'from' => $this->sender($this->request->reviewer),
            'employee' => ['id' => $employee->id, 'name' => $name],
            'request_id' => $this->request->id,
        ];
    }
}
