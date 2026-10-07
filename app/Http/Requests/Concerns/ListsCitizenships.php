<?php

namespace App\Http\Requests\Concerns;

/**
 * Citizenship is a list of countries. Before the rules look at it, each is
 * trimmed, blanks and repeats drop out, and an empty list becomes no answer —
 * the same as a field left blank.
 */
trait ListsCitizenships
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('citizenship') || ! is_array($this->input('citizenship'))) {
            return;
        }

        $countries = collect($this->input('citizenship'))
            ->map(fn ($country) => is_string($country) ? trim($country) : $country)
            ->filter(fn ($country) => $country !== '' && $country !== null)
            ->unique()
            ->values()
            ->all();

        $this->merge(['citizenship' => $countries === [] ? null : $countries]);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function citizenshipRules(): array
    {
        return [
            'citizenship' => ['nullable', 'array', 'max:5'],
            'citizenship.*' => ['string', 'max:100'],
        ];
    }
}
