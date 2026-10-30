<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateContactsRequest;
use App\Http\Requests\UpdateFamilyRequest;
use App\Http\Requests\UpdatePersonalDataRequest;
use App\Models\Citizenship;
use App\Models\EmploymentType;
use App\Models\User;
use App\Support\AccessMemo;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use App\Support\Placements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edits one block of the profile at a time, the way the page shows it: each
 * method takes just the fields of its own card.
 */
class EmployeeDetailsController extends Controller
{
    /**
     * The name and sex live on the user, as do the number the company files
     * somebody under and the two directories a card points at with one entry
     * each; the rest of the plain fields live on the details.
     */
    private const ON_USER = ['surname', 'name', 'patronymic', 'sex', 'employee_number', 'employment_type_id'];

    /**
     * The lines a card points at one entry of, as the form names them: the
     * entry is written by name, and one missing from its list is added to it.
     *
     * @var array<string, class-string<EmploymentType|Branch>>
     */
    private const NAMED = [
        'employment_type' => EmploymentType::class,
    ];

    /** What the card saves beside the details row: the roles, and where they stand. */
    private const RELATIONS = ['roles', 'placements'];

    /**
     * What a line of these two blocks is called in the request, where that is not
     * the name of the field itself. The two are the blocks a position is given
     * line by line, so a form may carry more than the viewer is allowed to
     * change — from a stale page, or from somebody trying it on — and what they
     * may not change is simply not saved.
     *
     * @var array<string, list<string>>
     */
    private const LINES = [
        // Always readable, not always theirs to retype.
        'surname' => ['surname'],
        'name' => ['name'],
        'patronymic' => ['patronymic'],
        'sex' => ['sex'],
        'birth_date' => ['birth_date'],
        'birth_place' => ['birth_place'],
        'citizenship' => ['citizenship'],
        'nationality' => ['nationality'],
        'home_address' => ['home_address'],
        'employee_number' => ['employee_number'],
        'employment_type' => ['employment_type'],
        'roles' => ['roles'],
        // One answer behind two lines of the card: a department, a position of
        // that department and the duties picked out of it. Changing where
        // somebody stands means changing both lines at once, so a viewer who
        // holds only one of them changes neither — half a placement would file
        // a position in a department the save was not allowed to write.
        'positions' => ['placements'],
        'departments' => ['placements'],
        'email' => ['email'],
        'phone' => ['phone'],
        'sos_phone' => ['sos_phone', 'sos_contact'],
    ];

    /**
     * The "Основное" card.
     */
    public function personal(UpdatePersonalDataRequest $request, User $employee): RedirectResponse
    {
        $data = $this->allowed($request, $employee, $request->validated());

        DB::transaction(function () use ($employee, $data) {
            // Named in the form, numbered on the account: a kind of work or a
            // branch the list has not heard of is added to it here, so it can
            // be typed into the card rather than into «Справочники» first.
            foreach (self::NAMED as $line => $model) {
                if (array_key_exists($line, $data)) {
                    $data["{$line}_id"] = $model::idFor($data[$line]);
                }
            }

            $employee->update(Arr::only($data, self::ON_USER));

            $details = Arr::except($data, [...self::ON_USER, ...self::RELATIONS, ...array_keys(self::NAMED), 'citizenship']);

            // Countries by name: one missing from the directory is added to it,
            // so it can be typed in right here rather than in «Справочники».
            if (array_key_exists('citizenship', $data)) {
                $employee->citizenships()->sync(Citizenship::idsFor($data['citizenship'] ?? []));
            }

            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }

            // A line left out of the form is left as it is, rather than being
            // emptied by a save that was never allowed to touch it.
            if (array_key_exists('roles', $data)) {
                $employee->syncRoles($data['roles']);
                // This is the one save that can rewrite the rights of whoever is
                // making it — a person may edit their own card — so whatever
                // this request had already worked out about what they may read
                // is dropped rather than carried past the change.
                AccessMemo::forget();
            }

            // Departments the employee stays in keep their head flag, and the
            // duties of a position they no longer hold go with it.
            if (array_key_exists('placements', $data)) {
                Placements::save($employee, (array) $data['placements']);
            }
        });

        return back();
    }

    /**
     * The "Паспорт" card. Every field is optional: a new hire may be on file
     * before their document is.
     */
    public function passport(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate(EmployeeCard::passportRules(), attributes: EmployeeCard::passportAttributes());

        $employee->details()->updateOrCreate([], $data);

        return back();
    }

    /**
     * The "Контакты" card. The phones are normalised to E.164 by the request;
     * the email is the sign-in address and so lives on the user.
     */
    public function contacts(UpdateContactsRequest $request, User $employee): RedirectResponse
    {
        $data = $this->allowed($request, $employee, $request->validated());

        DB::transaction(function () use ($employee, $data) {
            if (array_key_exists('email', $data)) {
                $employee->update(['email' => $data['email']]);
            }

            $details = Arr::except($data, 'email');

            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }
        });

        return back();
    }

    /**
     * The part of a save the viewer is allowed to make. Every line of the card is
     * on the list, the name and the surname included: those two are always
     * readable, which is not the same as always theirs to retype.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function allowed(Request $request, User $employee, array $data): array
    {
        $editable = EmployeeFields::editableBy($request->user(), $employee);

        foreach (self::LINES as $field => $keys) {
            if (! in_array($field, $editable, true)) {
                $data = Arr::except($data, $keys);
            }
        }

        return $data;
    }

    /**
     * The hire date, shown bare at the top of the sidebar. Tenure is counted
     * from it, so a date in the future would read as negative service.
     */
    public function employment(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate(EmployeeCard::employmentRules(), attributes: EmployeeCard::employmentAttributes());

        $employee->details()->updateOrCreate([], $data);

        return back();
    }

    /**
     * The "Знание языков" card. Unlike the other cards here, languages are
     * public: every colleague sees them.
     */
    public function languages(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            // The card edits the list whole, so it travels even when empty: a
            // form that forgot the field must not read as "take them away".
            'languages' => ['present', 'array'],
            ...EmployeeCard::languageRules(),
        ], attributes: EmployeeCard::languageAttributes());

        DB::transaction(fn () => EmployeeCard::saveLanguages($employee, $data['languages']));

        return back();
    }

    /**
     * The "Семья" card: marital status, the spouse and the children. The
     * children are replaced wholesale, the way the dialog edits them.
     *
     * Each of the three lines has a right of its own, and the request validates
     * only those this viewer may change; whatever it left out stays as it is on
     * file rather than being overwritten by the blanks of a form that never
     * showed it.
     */
    public function family(UpdateFamilyRequest $request, User $employee): RedirectResponse
    {
        $data = $request->validated();
        $details = Arr::only($data, ['marital_status', 'spouse_name', 'spouse_birth_date']);
        $children = $data['children'] ?? null;

        if ($children !== null) {
            $details['has_children'] = EmployeeCard::childrenFlag($data['has_children'] ?? null, $children);
        }

        DB::transaction(function () use ($employee, $details, $children) {
            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }

            // Only whoever may change the children gets to replace them.
            if ($children !== null) {
                EmployeeCard::saveChildren($employee, $children);
            }
        });

        return back();
    }
}
