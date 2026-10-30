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

        return back();
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
