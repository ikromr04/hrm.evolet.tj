<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Access;

/**
 * «Личный листок» as its own owner sends it in, for the office to accept.
 *
 * The same sheet as UpdateSheetRequest, asked in the same words, with two
 * things said differently: whose card it is, and which lines of it the sender
 * may answer.
 *
 * Whose card: there is no id in the address. The two roles this door is for are
 * shown their own card and nothing besides, so the sheet they send is their
 * own by definition — and an address with an id in it would be an invitation to
 * type somebody else's.
 *
 * Which lines: all of them. The parent weighs the sheet line by line against
 * the rights, because somebody correcting a colleague's card may be allowed the
 * passport and not the family. That question does not arise here. Access::
 * allowedOnOwnCard() gives these two every line of their own card to read and
 * exactly one to write, which is the right answer for a save and the wrong one
 * for this: a person answers for their own sheet whole — it is their passport,
 * their children, their address — and sending it in is not writing it. Nothing
 * they type here reaches the card until a sysadmin or HR has read it beside
 * what is on file and pressed «Принять».
 *
 * The rules are the parent's, word for word, and that is the point of
 * inheriting rather than writing a looser set: a request validated more gently
 * than a save is a request the office accepts and the card then refuses, and
 * the refusal would land weeks later on somebody who did not type it.
 */
class SubmitSheetRequest extends UpdateSheetRequest
{
    /**
     * Only the two roles that are shown their own card send sheets in.
     *
     * Everybody else saves: whoever may change a line of a card changes it, and
     * filing a request to be allowed what one is already allowed would be a
     * queue the office has to work through for nothing.
     */
    public function authorize(): bool
    {
        return Access::ownCardOnly($this->user());
    }

    /** Their own, always: nothing in the request says whose sheet this is. */
    protected function sheetOwner(): User
    {
        return $this->user();
    }

    /**
     * Every line of the sheet, because their own sheet is theirs to answer for.
     *
     * @return list<string>
     */
    protected function editableLines(): array
    {
        return array_keys(self::LINES);
    }
}
