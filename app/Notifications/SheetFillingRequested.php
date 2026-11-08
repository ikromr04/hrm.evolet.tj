<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\NamesTheSender;
use Illuminate\Notifications\Notification;

/**
 * What a colleague is told when the office asks them to finish their sheet.
 *
 * The other way round from the rest of this folder: those notes are about a
 * sheet somebody sent in, this one is about a sheet nobody has. Personnel read
 * a card, see «Не заполнено 5» beside «Документы не приложены 1», and the one
 * thing to be done about either is to ask the person — until now by walking
 * over to them, which is why the button had nothing behind it.
 *
 * What is missing is not named here. The sheet says it itself, in two lists
 * standing beside it and marks against the parts, and a note repeating them
 * would be a second copy of a list that is already correct — out of date the
 * moment one line of it is answered. The note's work is to say that somebody
 * is waiting, and to put the sheet one press away.
 *
 * Kept in the database and not sent by letter, so unlike the three letters in
 * this folder it is not queued: the bell has to carry it the moment it is
 * asked for, and there is no worker running on this installation anyway.
 */
class SheetFillingRequested extends Notification
{
    use NamesTheSender;

    public function __construct(public readonly User $asker) {}

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
        return [
            'kind' => 'sheet_filling',
            'title' => 'Заполните личный листок',
            'body' => 'Отдел кадров просит заполнить недостающие поля и приложить документы.',
            // The sheet itself, on the reader's own card: the tab is named in
            // the address, so the page opens on the thing being asked about
            // rather than on the card's first block.
            'url' => route('profile').'#sheet',
            'from' => $this->sender($this->asker),
        ];
    }
}
