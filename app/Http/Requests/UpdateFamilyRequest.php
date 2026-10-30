<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The "Семья" card of the profile: marital status, the spouse and the children.
 *
 * The block is given line by line, so the route lets in whoever may change any
 * one of the three. A line this viewer may not change has no rules here at all,
 * which keeps it out of validated() and so out of the save: an empty spouse or
 * an empty list of children sent by somebody who may only set the marital status
 * must not wipe what HR has on file.
 *
 * What each of those lines may hold is described in EmployeeCard, which is where
 * the create page reads it from as well.
 */
class UpdateFamilyRequest extends FormRequest
{
    /**
     * The route already requires the right to change something in the block.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $employee */
        $employee = $this->route('employee');
        // editableBy() picks the scope itself: one's own card is read against the
        // profile rights, anybody else's against the employees ones.
        $editable = EmployeeFields::editableBy($this->user(), $employee);
        $lines = array_values(array_intersect(EmployeeCard::FAMILY_LINES, $editable));

        $rules = EmployeeCard::familyRules($lines);

        // The children are replaced wholesale, so the list travels even when it
        // is empty — a form that forgot the field must not read as "the children
        // on file are gone". Which is the one thing this card says differently
        // from the create page, where there is nothing on file to lose.
        if (in_array('children', $lines, true)) {
            $rules['children'] = ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return EmployeeCard::familyAttributes();
    }
}
