<?php

namespace App\Notifications;

use App\Models\SheetChangeRequest;
use Illuminate\Notifications\Notification;

/**
 * What personnel are told when a colleague asks for their personal sheet to be
 * changed.
 *
 * A head of department and a specialist may read their own card and write
 * nothing on it but the papers they attach. Everything else they can only ask
 * for, and an ask that nobody is told about is an ask that sits there: the
 * person who sent it has no idea whether it was seen, and the office has no
 * list of what is waiting. This note is that list's entry — it names who asked
 * and leads to the request itself, where the whole sheet is laid out line by
 * line against what the card says today.
 *
 * It goes to everybody who may deal with it rather than to one named officer:
 * there is no queue owner here, and a request that waited for somebody on
 * holiday would wait a fortnight.
 *
 * Kept in the database and not sent by letter, so unlike the three letters in
 * this folder it is not queued. A database note is one insert in the same
 * request that caused it, and queueing it would buy nothing while costing the
 * one thing that matters: the bell has to carry the new number the moment the
 * request is sent, not whenever a worker next wakes up. There is no worker
 * running on this installation at all, which is the other half of it.
 */
class SheetChangeRequested extends Notification
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
            'kind' => 'sheet_request',
            'title' => 'Личный листок на проверку',
            'body' => "{$name} просит записать изменения в свой личный листок.",
            'url' => route('sheet_requests.show', $this->request),
            'employee' => ['id' => $employee->id, 'name' => $name],
            'request_id' => $this->request->id,
        ];
    }
}
