<?php

namespace App\Notifications;

use App\Models\SheetChangeRequest;
use Illuminate\Notifications\Notification;

/**
 * What the colleague is told when the office writes their sheet in.
 *
 * Accepting a request changes the card itself, and the person who asked is the
 * one who cannot see that it happened: they sent a form off and the page they
 * sent it from went back to reading as it did before. Without this note they
 * would be left opening their own card every day to see whether the new address
 * had appeared. So the note says it, and leads to the card, where the change
 * now stands.
 *
 * It goes to the author alone. Who filed what is on the request, and personnel
 * do not need telling about their own decision.
 *
 * Kept in the database rather than sent by letter, so unlike the three letters
 * in this folder it is not queued: it is one insert in the same request that
 * accepted the sheet, and the bell beside the author's name has to carry it
 * from that moment rather than from whenever a worker next runs. Nothing is
 * waiting on a mail server here, so there is nothing to put a queue between.
 */
class SheetChangeAccepted extends Notification
{
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
        // The surname first, then the name and the patronymic, as a sheet is
        // signed and as every list in this application reads a person.
        $name = trim("{$employee->surname} {$employee->name} {$employee->patronymic}");

        return [
            'kind' => 'sheet_request_accepted',
            'title' => 'Личный листок принят',
            'body' => 'Отдел кадров принял изменения — они записаны в вашу карточку.',
            // The card itself, not the request: what was asked for is now what
            // the card says, and the request is a page about the past.
            'url' => route('profile'),
            'employee' => ['id' => $employee->id, 'name' => $name],
            'request_id' => $this->request->id,
        ];
    }
}
