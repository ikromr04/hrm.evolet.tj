<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserEducation;
use App\Support\EmployeeCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $employee->educations()->create($this->validated($request));

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

        $employee->educations()->createMany($data['records']);

        return back();
    }

    public function update(Request $request, User $employee, UserEducation $education): RedirectResponse
    {
        $this->belongsTo($employee, $education);
        $education->update($this->validated($request));

        return back();
    }

    public function destroy(User $employee, UserEducation $education): RedirectResponse
    {
        $this->belongsTo($employee, $education);
        $education->delete();

        return back();
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
