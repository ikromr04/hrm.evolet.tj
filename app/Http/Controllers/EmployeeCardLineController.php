<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\DocumentStore;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A block of the card that is one line of text and the one document behind it:
 * «Учёная степень, звание» and «Государственные награды, поощрения».
 *
 * Saved whole like the passport and the contacts rather than record by record
 * like the places of study, because there is one answer in each and it is a
 * line of text. The document is the part that is not a column: a save may
 * replace it, and taking it away is a request of its own — the same reasoning
 * as everywhere else that a file hangs off a card.
 *
 * The two blocks are kept by one class because they are kept on one set of
 * terms, down to the words of the refusals; what a block may hold is described
 * in EmployeeCard, which is where the create page reads it from as well. What
 * differs is the column, the right it is read under and what the block is
 * called — and that is what the two below say.
 */
abstract class EmployeeCardLineController extends Controller
{
    /** The column the line lives in, which is also the name of its right. */
    abstract protected function line(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    /** @return array<string, string> */
    abstract protected function attributes(): array;

    /**
     * The line retyped, with the document the form may bring along.
     *
     * The line is written only when the form carried it: a form that did not
     * must not read as «there is none», and an emptied box does carry the line
     * — as nothing, which is the answer it means.
     *
     * A document sent with the save replaces the one on file, and the one on
     * file is then taken off the disk — after the row has been written, so a
     * save that fails leaves the card with the document it still points at.
     */
    public function update(Request $request, User $employee): RedirectResponse
    {
        $line = $this->line();
        $data = $request->validate($this->rules(), attributes: $this->attributes());

        $was = $employee->details?->{"{$line}_document_path"};

        $details = $employee->details()->updateOrCreate([], EmployeeCard::lineColumns($employee, $data, $line));

        if ($details->{"{$line}_document_path"} !== $was) {
            DocumentStore::forget($was);
        }

        return back();
    }

    /**
     * The document taken off the card, and off the disk with it.
     *
     * The line stays: a scan may be the wrong one or unreadable, and losing the
     * answer along with the paper would be losing it over the paperwork.
     */
    public function destroy(User $employee): RedirectResponse
    {
        $line = $this->line();
        $details = $employee->details;

        abort_if($details?->{"{$line}_document_path"} === null, 404);

        $was = $details->{"{$line}_document_path"};
        $details->update(["{$line}_document_path" => null, "{$line}_document_name" => null]);

        DocumentStore::forget($was);

        return back();
    }

    /**
     * The document itself, handed to somebody who may read this part of the
     * card.
     *
     * Asked the way the page that shows the block asks it, so the answer here
     * is the answer that decided whether to show it at all. The route carries
     * no gate of its own on purpose: the gates beside it are about changing the
     * block, and reading it is a different question — the same one the
     * photograph of a face is read under.
     */
    public function document(Request $request, User $employee): BinaryFileResponse
    {
        $line = $this->line();

        abort_unless(in_array($line, EmployeeFields::visibleTo($request->user(), $employee), true), 403);

        $details = $employee->details;

        // The column is the whole index of what may be read here: nothing is
        // assembled out of what somebody typed, so there is no path to climb
        // out of, and a document since replaced stops being answered the moment
        // the column stops naming it.
        abort_if($details?->{"{$line}_document_path"} === null, 404);

        // Or the column outlived the file, which somebody removed by hand.
        abort_unless(DocumentStore::disk()->exists($details->{"{$line}_document_path"}), 404);

        return DocumentStore::answer($request, $details->{"{$line}_document_path"}, $details->{"{$line}_document_name"});
    }
}
