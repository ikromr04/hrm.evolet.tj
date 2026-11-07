<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPaperRecord;
use App\Support\DocumentStore;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A block of the card that is a list of records, each one line of text and the
 * one paper behind it: «Учёная степень, звание», «Государственные награды,
 * поощрения» and «Отношение к воинской обязанности».
 *
 * The three used to be one column apiece with one document behind it, saved
 * whole like the passport is. One answer per card was the mistake: a degree and
 * a title are two answers, a medal and a commendation are two, and the second
 * of each was being crammed into the same box with one scan to prove both. So
 * each block is now kept the way the places of study are — the
 * «Есть / Нет / Не выбрано» answer first, then any number of records, each
 * edited on its own so that adding one does not rewrite the rest.
 *
 * The terms are EmployeeEducationController's throughout, down to the words of
 * the comments: it is the same question asked of a shorter record, and whoever
 * knows one of these classes knows all four. What a record may hold is
 * described in EmployeeCard, which is where the create page reads it from as
 * well. What differs between the three lists is the relation, the line of
 * user_details that answers for it, the right it is read under, what one record
 * is called in a refusal and the name its doors are registered by — and that is
 * all the three classes below say.
 */
abstract class EmployeePaperListController extends Controller
{
    /**
     * The employee's own records, as the relation rather than as rows.
     *
     * Everything here goes through it, and that is what keeps one person's URL
     * off another person's record: a row is looked up inside the list of the
     * card named in the address, so a stranger's id is simply not in there to
     * be found. It replaces the belongsTo() guard the single-record blocks
     * carry — a check that has to be remembered at every door, where this one
     * cannot be forgotten.
     *
     * @return HasMany<UserPaperRecord, User>
     */
    abstract protected function records(User $employee): HasMany;

    /** The line of user_details that says whether there are any: 'has_degrees'. */
    abstract protected function flag(): string;

    /** The field the block is read under, which is the name of its right: 'degree'. */
    abstract protected function right(): string;

    /** What one record of this list is, in the sheet's own words, for the refusals. */
    abstract protected function named(): string;

    /**
     * The name the block's doors are registered under: 'degrees', which is to
     * say route('employees.degrees.document').
     *
     * Nothing in this class builds that address — every door here answers
     * back(), and the reading one answers the file itself — but it is the fifth
     * thing that tells one of these lists from another, and it is by this name
     * that the card's props link a record's paper. A subclass that says it
     * beside the four others is a subclass that can be read in one place.
     */
    abstract protected function routes(): string;

    public function store(Request $request, User $employee): RedirectResponse
    {
        $this->records($employee)->create(EmployeeCard::paperRow($employee, $this->validated($request)));
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * «Есть» or «Нет», which is the question the list is asked before it is
     * filled: an empty list used to mean both "there are none" and "nobody has
     * asked", and the sheet counted the two alike.
     *
     * Answered on its own and not alongside a record, because the records are
     * edited one at a time here while this is said about all of them at once.
     * «Есть» is not accepted from this door — rows are what make it true, and a
     * card claiming a degree it does not list would be the old ambiguity the
     * other way up. That refusal is listFlag's rather than this method's: it
     * reads the rows on file, and takes only «нет» at its word.
     */
    public function answer(Request $request, User $employee): RedirectResponse
    {
        $flag = $this->flag();
        $data = $request->validate([$flag => ['present', 'nullable', 'boolean']]);

        $employee->details()->updateOrCreate([], [
            $flag => EmployeeCard::listFlag($data[$flag], $this->records($employee)->get()->all()),
        ]);

        return back();
    }

    /**
     * Several at once, for a list filed in one go rather than a record at a
     * time — which is how the steps of «Новый сотрудник» file one.
     */
    public function storeMany(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'records' => ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS],
            ...EmployeeCard::paperRules('records.*.'),
        ], attributes: EmployeeCard::paperAttributes('records.*.', $this->named()));

        EmployeeCard::savePapers($employee, $this->records($employee), $data['records']);
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * A record corrected.
     *
     * A document sent with the correction replaces the one on file, and the one
     * on file is then taken off the disk — after the row has been written, so a
     * save that fails leaves the record with the paper it still points at. A
     * correction that sends no document leaves the document alone: absent is
     * not «take it away», here as everywhere else on this card.
     */
    public function update(Request $request, User $employee, int $record): RedirectResponse
    {
        $paper = $this->records($employee)->findOrFail($record);

        $was = $paper->document_path;
        $paper->update(EmployeeCard::paperRow($employee, $this->validated($request)));

        if ($paper->document_path !== $was) {
            DocumentStore::forget($was);
        }

        return back();
    }

    /**
     * The paper itself, handed to somebody who may read this card's records of
     * the block it stands in.
     *
     * Asked the way the page that lists the records asks it, so the answer here
     * is the answer that decided whether to show the record at all. The route
     * carries no gate of its own on purpose: the gates beside it are about
     * changing a record, and reading one is a different question — the same one
     * the photograph of a face is read under.
     */
    public function document(Request $request, User $employee, int $record): BinaryFileResponse
    {
        $paper = $this->records($employee)->findOrFail($record);

        abort_unless(in_array($this->right(), EmployeeFields::visibleTo($request->user(), $employee), true), 403);

        // The row is the whole index of what may be read here: nothing is
        // assembled out of what somebody typed, so there is no path to climb
        // out of, and a document since replaced stops being answered the moment
        // the row stops naming it.
        abort_if($paper->document_path === null, 404);

        // Or the row outlived the file, which somebody removed by hand.
        abort_unless(DocumentStore::disk()->exists($paper->document_path), 404);

        return DocumentStore::answer($request, $paper->document_path, $paper->document_name);
    }

    public function destroy(User $employee, int $record): RedirectResponse
    {
        $this->records($employee)->findOrFail($record)->delete();

        // The last record taken off the card leaves the question open again
        // rather than answered «нет»: a record removed is a correction, and
        // nobody has said this person has none.
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * The answer, read off the rows after they have changed.
     *
     * Rows on file say «есть» whatever was answered before, and a list that
     * ends up empty is not made to say «нет» — only somebody saying so does
     * that, and this keeps the «нет» they said. A list filed empty is no answer
     * at all, which is where the card started.
     */
    private function rememberWhetherThereAre(User $employee): void
    {
        $flag = $this->flag();

        $employee->details()->updateOrCreate([], [
            $flag => EmployeeCard::listFlag(
                $employee->details()->value($flag),
                $this->records($employee)->get()->all(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate(
            EmployeeCard::paperRules(),
            attributes: EmployeeCard::paperAttributes('', $this->named()),
        );
    }
}
