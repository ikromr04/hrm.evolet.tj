<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserEducation;
use App\Support\DocumentStore;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One place of study at a time. The other private blocks are saved whole, but
 * a degree is a record of its own: adding one should not rewrite the rest.
 *
 * What a record may hold is described in EmployeeCard, which is where the
 * create page reads it from as well.
 */
class EmployeeEducationController extends Controller
{
    public function store(Request $request, User $employee): RedirectResponse
    {
        $employee->educations()->create(EmployeeCard::educationRow($employee, $this->validated($request)));
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * «Есть» or «Нет», which is the question the list is asked before it is
     * filled: an empty list used to mean both "they studied nowhere" and
     * "nobody has asked", and the sheet counted the two alike.
     *
     * Answered on its own and not alongside a record, because the records are
     * edited one at a time here while this is said about all of them at once.
     * «Есть» is not accepted from this door — rows are what make it true, and a
     * card claiming studies it does not list would be the old ambiguity the
     * other way up.
     */
    public function answer(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate(['has_educations' => ['present', 'nullable', 'boolean']]);

        $employee->details()->updateOrCreate([], [
            'has_educations' => EmployeeCard::listFlag($data['has_educations'], $employee->educations()->get()->all()),
        ]);

        return back();
    }

    /**
     * Several at once, for a list of degrees filed in one go rather than a
     * record at a time.
     */
    public function storeMany(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'records' => ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS],
            ...EmployeeCard::educationRules('records.*.'),
        ], attributes: EmployeeCard::educationAttributes('records.*.'));

        EmployeeCard::saveEducations($employee, $data['records']);
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * A record corrected.
     *
     * A document sent with the correction replaces the one on file, and the
     * one on file is then taken off the disk — after the row has been
     * written, so a save that fails leaves the record with the diploma it
     * still points at. A correction that sends no document leaves the
     * document alone: the field is absent from the form of a record whose
     * studies are still going on, and absent is not «take it away».
     */
    public function update(Request $request, User $employee, UserEducation $education): RedirectResponse
    {
        $this->belongsTo($employee, $education);

        $was = $education->document_path;
        $education->update(EmployeeCard::educationRow($employee, $this->validated($request)));

        if ($education->document_path !== $was) {
            DocumentStore::forget($was);
        }

        return back();
    }

    /**
     * The diploma itself, handed to somebody who may read this card's record
     * of where its owner studied.
     *
     * Asked the way the page that lists the records asks it, so the answer
     * here is the answer that decided whether to show the record at all. The
     * route carries no gate of its own on purpose: the gates beside it are
     * about changing a record, and reading one is a different question — the
     * same one the photograph of a face is read under.
     */
    public function document(Request $request, User $employee, UserEducation $education): BinaryFileResponse
    {
        $this->belongsTo($employee, $education);

        abort_unless(in_array('educations', EmployeeFields::visibleTo($request->user(), $employee), true), 403);

        // The record is the whole index of what may be read here: nothing is
        // assembled out of what somebody typed, so there is no path to climb
        // out of, and a document since replaced stops being answered the
        // moment the row stops naming it.
        abort_if($education->document_path === null, 404);

        // Or the row outlived the file, which somebody removed by hand.
        abort_unless(DocumentStore::disk()->exists($education->document_path), 404);

        return DocumentStore::answer($request, $education->document_path, $education->document_name);
    }

    public function destroy(User $employee, UserEducation $education): RedirectResponse
    {
        $this->belongsTo($employee, $education);
        $education->delete();

        // The last place of study taken off the card leaves the question open
        // again rather than answered «нет»: a record removed is a correction,
        // and nobody has said this person studied nowhere.
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * The answer, read off the rows after they have changed.
     *
     * Rows on file say «есть» whatever was answered before, and a list
     * that ends up empty is not made to say «нет» — only somebody saying
     * so does that, and this keeps the «нет» they said. A list filed empty
     * is no answer at all, which is where the card started.
     */
    private function rememberWhetherThereAre(User $employee): void
    {
        $employee->details()->updateOrCreate([], [
            'has_educations' => EmployeeCard::listFlag(
                $employee->details()->value('has_educations'),
                $employee->educations()->get()->all(),
            ),
        ]);
    }

    /**
     * The record id travels beside an employee id, so the two must agree:
     * otherwise one person's URL would reach another person's record.
     */
    private function belongsTo(User $employee, UserEducation $education): void
    {
        abort_unless($education->user_id === $employee->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate(EmployeeCard::educationRules(), attributes: EmployeeCard::educationAttributes());
    }
}
