<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserWorkExperience;
use App\Support\EmployeeCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * One previous job at a time, the way the tab lists them: adding a job should
 * not rewrite the ones already on file.
 *
 * What a record may hold — and the comparison between its two dates, which no
 * rule can make on its own — is described in EmployeeCard, which is where the
 * create page reads it from as well.
 */
class EmployeeWorkExperienceController extends Controller
{
    public function store(Request $request, User $employee): RedirectResponse
    {
        $employee->workExperiences()->create($this->validated($request));
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    /**
     * «Есть» or «Нет», which is the question the list is asked before it is
     * filled: an empty list used to mean both "they have never worked anywhere"
     * and "nobody has asked", and the sheet counted the two alike.
     *
     * Answered on its own and not alongside a record, because the records are
     * edited one at a time here while this is said about all of them at once.
     * «Есть» is not accepted from this door — rows are what make it true, and a
     * card claiming jobs it does not list would be the old ambiguity the other
     * way up.
     */
    public function answer(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate(['has_experience' => ['present', 'nullable', 'boolean']]);

        $employee->details()->updateOrCreate([], [
            'has_experience' => EmployeeCard::listFlag($data['has_experience'], $employee->workExperiences()->get()->all()),
        ]);

        return back();
    }

    /**
     * Several at once, for a list of past jobs filed in one go rather than a
     * record at a time.
     */
    public function storeMany(Request $request, User $employee): RedirectResponse
    {
        $records = (array) $request->input('records', []);

        $validator = Validator::make(
            $request->all(),
            [
                'records' => ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS],
                ...EmployeeCard::experienceRules('records.*.'),
            ],
            attributes: EmployeeCard::experienceAttributes('records.*.'),
        );

        foreach (array_keys($records) as $index) {
            $record = (array) $records[$index];
            $validator->after(fn ($validator) => EmployeeCard::checkExperienceDates($validator, $record, "records.{$index}."));
        }

        $employee->workExperiences()->createMany($validator->validate()['records']);
        $this->rememberWhetherThereAre($employee);

        return back();
    }

    public function update(Request $request, User $employee, UserWorkExperience $experience): RedirectResponse
    {
        $this->belongsTo($employee, $experience);
        $experience->update($this->validated($request));

        return back();
    }

    public function destroy(User $employee, UserWorkExperience $experience): RedirectResponse
    {
        $this->belongsTo($employee, $experience);
        $experience->delete();

        // The last job taken off the card leaves the question open again rather
        // than answered «нет»: a record removed is a correction, and nobody has
        // said this person never worked anywhere.
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
            'has_experience' => EmployeeCard::listFlag(
                $employee->details()->value('has_experience'),
                $employee->workExperiences()->get()->all(),
            ),
        ]);
    }

    /**
     * The record id travels beside an employee id, so the two must agree:
     * otherwise one person's URL would reach another person's record.
     */
    private function belongsTo(User $employee, UserWorkExperience $experience): void
    {
        abort_unless($experience->user_id === $employee->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validator = Validator::make(
            $request->all(),
            EmployeeCard::experienceRules(),
            attributes: EmployeeCard::experienceAttributes(),
        );

        $validator->after(fn ($validator) => EmployeeCard::checkExperienceDates($validator, $request->all()));

        return $validator->validate();
    }
}
