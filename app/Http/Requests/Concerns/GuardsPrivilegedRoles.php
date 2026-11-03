<?php

namespace App\Http\Requests\Concerns;

use App\Models\Position;
use App\Models\User;
use App\Support\Access;
use App\Support\Placements;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Whose role may be changed, and to what — and whether the role and the job on a
 * card can both be true.
 *
 * A role is an ordinary line of a card with two things the right to that line
 * does not decide. One is the system administrator: it is handed to nobody, not
 * even by the account that holds it, and that account cannot give it up either —
 * there would be nobody left who can reach everything. The other is
 * «HR-специалист», which passes every check in the same way; personnel who could
 * appoint personnel would be the whole of the access system in one person's gift,
 * so that one is the system administrator's to give.
 */
trait GuardsPrivilegedRoles
{
    /**
     * A role being handed out, checked as the value itself so the message lands
     * on the field it was picked in.
     */
    protected function privilegedRoleGuard(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            // An empty field names no role, and nothing is being handed out:
            // whoever saves such a card is given the role their positions imply
            // instead. Said here rather than left to the catalogue, because an
            // empty name asked about as though it were one is how a check of
            // this kind comes to answer about a role nobody picked.
            if (! is_string($value) || blank($value)) {
                return;
            }

            // Leaving a role on a card that already holds it is not handing it
            // out, or an account could not so much as have its surname fixed.
            $employee = $this->route('employee');

            if ($employee instanceof User && $employee->hasRole($value)) {
                return;
            }

            if ($value === Access::SOLE_ROLE) {
                $fail('Роль системного администратора не выдаётся: она одна и уже занята.');

                return;
            }

            if ($value === Access::HR && ! $this->user()?->hasRole(Access::SOLE_ROLE)) {
                $fail('Роль HR-специалиста выдаёт только системный администратор.');
            }
        };
    }

    /**
     * Whose role this person may change at all, and what they may not do to their
     * own. It is read from what the form sent against what the person holds now,
     * which a rule looking at the one value cannot tell apart.
     */
    protected function guardPrivilegedChanges(Validator $validator, User $employee): void
    {
        // A form that does not carry the role is not changing it: there is one
        // window for it and others for the rest of the card.
        if (! $this->has('role')) {
            return;
        }

        // One value against one value, since a person holds one role. An empty
        // field and no role at all are the same answer, so they are made to look
        // the same before they are compared — and so is anything that is not a
        // name at all, a list of them included: a stale page that still sends
        // the old array is refused by the rules above, and asking that array
        // what role it names would bring the save down before they could.
        $sent = $this->input('role');
        $asked = is_string($sent) && filled($sent) ? $sent : null;
        $held = $employee->roles->first()?->name;

        // Sending the same role back is not a change, or nobody could so much as
        // fix a colleague's surname on a card whose role is not theirs. Naming
        // none is a change like any other and is not waved through: the save
        // would work a role out from the positions and write that instead.
        if ($asked === $held) {
            return;
        }

        $locked = Access::roleLockedReason($this->user(), $employee);

        if ($locked !== null) {
            $validator->errors()->add('role', $locked);
        }
    }

    /**
     * Whose jobs this person may rearrange.
     *
     * Read the same way the role is: against what the card holds now, because a
     * window that saves the whole block sends the jobs back whether or not
     * anybody touched them, and a refusal over an untouched line would stop
     * somebody fixing a surname.
     */
    protected function guardPositionChanges(Validator $validator, User $employee): void
    {
        if (! $this->has('placements')) {
            return;
        }

        $locked = Access::positionsLockedReason($this->user(), $employee);

        if ($locked === null || $this->placementsAsked() === $this->placementsHeld($employee)) {
            return;
        }

        $validator->errors()->add('placements', $locked);
    }

    /**
     * Whether the role on the card and the jobs on it can both be true.
     *
     * A head leads something, so one of the jobs on their card is a leading one;
     * a specialist leads nothing, so none of theirs is. The page offers the jobs
     * that fit the role picked, which makes this the second half of that — the
     * form narrows the list for the person's sake and the server decides.
     *
     * The role is the one the request named, or, when it named none, the one the
     * card holds and is about to keep. Either way the card is answered for as it
     * will stand, and not as it was sent.
     *
     * @param  User|null  $employee  the card being changed, or null for one being written
     */
    protected function guardRoleAgainstPositions(Validator $validator, ?User $employee = null): void
    {
        // Nothing to answer for: a window that does not carry the jobs is not the
        // window that could have broken this, and the ones it did not send are
        // the ones already on the card.
        $sent = $this->has('placements') || $employee === null;
        $positions = $sent ? $this->placementsAsked() : $this->placementsHeld($employee);
        $ids = array_column($positions, 'position_id');

        // The role the card will stand under: the one the request named, or —
        // when it named none, because the window carries the jobs and not the
        // role — the one the card holds and is about to keep. Deriving it from
        // the jobs instead would answer for a role nobody is saving.
        $named = $this->input('role');
        $role = match (true) {
            is_string($named) && filled($named) => $named,
            $employee !== null => $employee->roles->first()?->name,
            default => Access::derive(null, $ids),
        };

        // A card holding no role at all, saved by a window that carries the jobs
        // and not the role: there is no role to answer for and the save writes
        // none. Standing in a role of our own choosing would refuse the jobs in
        // the name of something nobody holds.
        if ($role === null) {
            return;
        }

        $leading = Position::query()->whereIn('id', $ids)->where('is_head', true)->exists();
        $wrong = Access::roleAgainstJobs($role, $leading);

        if ($wrong !== null) {
            $validator->errors()->add('placements', $wrong);
        }
    }

    /**
     * Whether this request moves either of the two lines at all.
     *
     * Asked before the pair is held to account, so that a card which already
     * carries a role and a job that disagree — written before there was a rule
     * about it, or by a seeder — is not refused every time somebody saves a
     * surname in the same window. What is being changed is answered for; what
     * was already there is left where it is.
     */
    protected function changesRoleOrPositions(User $employee): bool
    {
        $named = $this->input('role');
        $asked = is_string($named) && filled($named) ? $named : null;

        if ($this->has('role') && $asked !== $employee->roles->first()?->name) {
            return true;
        }

        return $this->has('placements') && $this->placementsAsked() !== $this->placementsHeld($employee);
    }

    /**
     * The jobs as the request sends them and as the card holds them, in one
     * shape and one order, so that "the same jobs" is one comparison.
     *
     * @return list<array{position_id: int, duty_ids: list<int>}>
     */
    private function placementsAsked(): array
    {
        return $this->normalise((array) $this->input('placements', []));
    }

    /** @return list<array{position_id: int, duty_ids: list<int>}> */
    private function placementsHeld(User $employee): array
    {
        return $this->normalise(Placements::of($employee));
    }

    /**
     * @param  array<mixed>  $placements
     * @return list<array{position_id: int, duty_ids: list<int>}>
     */
    private function normalise(array $placements): array
    {
        return collect($placements)
            ->map(fn (mixed $placement) => is_array($placement) ? $placement : [])
            ->map(fn (array $placement) => [
                'position_id' => (int) ($placement['position_id'] ?? 0),
                'duty_ids' => collect($placement['duty_ids'] ?? [])
                    ->map(fn (mixed $id) => (int) $id)
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->sortBy('position_id')
            ->values()
            ->all();
    }
}
