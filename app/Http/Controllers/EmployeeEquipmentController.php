<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EmployeeCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handing a colleague what they need to work with, several units at once —
 * a workplace put together in one go rather than a unit at a time from the
 * fleet. What may be handed over, and the rule that only free units move,
 * is described in EmployeeCard; the create page hands out through the same
 * call.
 */
class EmployeeEquipmentController extends Controller
{
    public function store(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            // The dialog sends the list even when nothing was picked, so an
            // empty one is an answer rather than a form out of step with itself.
            'equipment' => ['present', 'array', 'max:'.EmployeeCard::MAX_ROWS],
            ...EmployeeCard::equipmentRules(),
        ], attributes: EmployeeCard::equipmentAttributes());

        EmployeeCard::issueEquipment($employee, $data['equipment'], $data['issued_at'] ?? null);

        return back();
    }
}
