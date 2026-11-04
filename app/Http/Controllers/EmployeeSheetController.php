<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSheetRequest;
use App\Models\Citizenship;
use App\Models\User;
use App\Support\DocumentStore;
use App\Support\EmployeeCard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * «Личный листок по учёту кадров», saved in one go.
 *
 * The card's own windows each keep a block of it, and every one of them is
 * still there; this is the sheet read as the paper it is. Eleven blocks are
 * retyped together and written together — one transaction, so either all of it
 * is on file or none of it is. The alternative was a save per block, and a
 * sheet that failed on the ninth of them would be a sheet «сохранён
 * наполовину»: nobody could then say which half.
 *
 * Each part is saved the way its own window already saves it, through the same
 * calls — the languages, the children, the rest of the family, the places of
 * study, the three lines that keep a document apiece. Two ways of writing one
 * block is one way somebody will fix and one they will not.
 *
 * What the request did not carry is not written. The sheet spans the lines of
 * eleven different rights and UpdateSheetRequest validates only the ones this
 * viewer may change, so a line that is missing here is a line that was never
 * theirs — and it is left exactly as it stands on file rather than blanked by a
 * form that never showed it.
 */
class EmployeeSheetController extends Controller
{
    /** The lines of the sheet that live on the account rather than beside it. */
    private const ON_USER = ['surname', 'name', 'patronymic', 'sex'];

    /** And the plain columns of the row that holds the rest, in the sheet's order. */
    private const ON_DETAILS = [
        'birth_date', 'birth_place', 'nationality',
        'marital_status', 'spouse_name', 'spouse_birth_date', 'spouse_occupation',
        'passport_series', 'passport_number', 'passport_issued_at', 'passport_issued_by', 'tax_number',
        'home_address', 'phone', 'sos_phone', 'sos_contact',
    ];

    /** The three blocks that are one line of text and the one document behind it. */
    private const LINES_WITH_DOCUMENTS = ['degree', 'award', 'military'];

    /**
     * The sheet retyped.
     *
     * The documents the save stops pointing at are taken off the disk after the
     * transaction has closed and not inside it: a save that fails leaves the
     * card with the papers it still names, and a file removed inside a
     * transaction is gone whether or not the rows come back.
     */
    public function update(UpdateSheetRequest $request, User $employee): RedirectResponse
    {
        $data = $request->validated();

        /** @var list<string|null> $replaced */
        $replaced = [];

        DB::transaction(function () use ($employee, $data, &$replaced) {
            $employee->update(Arr::only($data, self::ON_USER));

            // Countries by name: one missing from the directory is added to it,
            // so it can be typed into the sheet rather than into «Справочники»
            // first — exactly as the card's own «Основное» adds one.
            if (array_key_exists('citizenship', $data)) {
                $employee->citizenships()->sync(Citizenship::idsFor($data['citizenship'] ?? []));
            }

            $details = Arr::only($data, self::ON_DETAILS);

            // The three lines whose answer carries a paper. The file is not a
            // column, so it is put away on the way in and the row keeps where it
            // went; what the row pointed at before is noted so the disk does not
            // keep a copy nothing names any more.
            foreach (self::LINES_WITH_DOCUMENTS as $line) {
                $columns = EmployeeCard::lineColumns($employee, $data, $line);
                $was = $employee->details?->{"{$line}_document_path"};

                if (array_key_exists("{$line}_document_path", $columns) && $columns["{$line}_document_path"] !== $was) {
                    $replaced[] = $was;
                }

                $details = [...$details, ...$columns];
            }

            // «Нет» and «не указано» look the same in an empty list, so the
            // answer is worked out rather than taken as sent: rows mean «есть»
            // whatever the flag said, and only «нет» is believed as it came.
            // Asked of each list only where the list itself was carried — a
            // flag without the rows it describes is not an answer about them.
            foreach (['children' => 'has_children', 'educations' => 'has_educations', 'experiences' => 'has_experience'] as $list => $flag) {
                if (array_key_exists($list, $data)) {
                    $details[$flag] = EmployeeCard::listFlag($data[$flag] ?? null, $data[$list]);
                }
            }

            if ($details !== []) {
                $employee->details()->updateOrCreate([], $details);
            }

            // The three lists that have no identity beyond what they say, each
            // replaced by what was sent: the sheet shows every row of them at
            // once, so it edits them whole, and a language the directory has not
            // heard of is added to it on the way in.
            if (array_key_exists('languages', $data)) {
                EmployeeCard::saveLanguages($employee, $data['languages']);
            }

            if (array_key_exists('children', $data)) {
                EmployeeCard::saveChildren($employee, $data['children']);
            }

            if (array_key_exists('relatives', $data)) {
                EmployeeCard::saveRelatives($employee, $data['relatives']);
            }

            // The places of study, which are not replaced but reconciled: a row
            // already on the card goes on being that row, so the diploma
            // hanging off it stays where it is. The sheet sends no file for a
            // document it is not changing — and here as everywhere else on this
            // card, no file means no change to the file — so a list written from
            // scratch every save would leave every paper on the card behind it.
            if (array_key_exists('educations', $data)) {
                $replaced = [...$replaced, ...$this->reconcile(
                    $employee->educations(),
                    $data['educations'],
                    fn (array $record) => $this->educationColumns($employee, $record),
                    'document_path',
                )];
            }

            // And the jobs held before, on the same terms. There is no document
            // at stake here, only the row itself: rewriting the list from
            // scratch churns the table and files the jobs again in whatever
            // order the form happened to hold them.
            if (array_key_exists('experiences', $data)) {
                $this->reconcile($employee->workExperiences(), $data['experiences']);
            }
        });

        foreach ($replaced as $path) {
            DocumentStore::forget($path);
        }

        return back();
    }

    /**
     * One place of study as the columns to write, with the asking for its
     * diploma to go honoured.
     *
     * The flag is not a column, and it is the sheet's rather than the card's:
     * the window that keeps one record at a time replaces a diploma on a save
     * and takes it off through a request of its own, while nothing on this page
     * is written until «Сохранить» — so a removal has to wait with everything
     * else, and the only way to make it wait is to send it along. Which is why
     * it is read here and not in EmployeeCard::educationRow(), where the create
     * page would find it and have nothing on file to take off.
     *
     * A file beats the flag, for the reason it does on the three lines that
     * keep a paper apiece: somebody who attached one plainly wants it. So the
     * removal is read off what educationRow() came back with — a row carrying a
     * new paper already names it, and only a row that names none is asked
     * whether the old one is to go.
     *
     * The name goes with the paper. It is what the card shows as the thing one
     * presses to open the document, and a name with nothing behind it is a
     * label pointing at nothing.
     *
     * @param  array<string, mixed>  $record  as validated, the file and the flag included
     * @return array<string, mixed> the columns to write
     */
    private function educationColumns(User $employee, array $record): array
    {
        $asked = (bool) ($record['document_remove'] ?? false);
        unset($record['document_remove']);

        $columns = EmployeeCard::educationRow($employee, $record);

        if ($asked && ! array_key_exists('document_path', $columns)) {
            $columns['document_path'] = null;
            $columns['document_name'] = null;
        }

        return $columns;
    }

    /**
     * A list of records on the card brought into line with the list the sheet
     * sent, row by row rather than wholesale.
     *
     * Each row says which record on file it is, or says nothing and is a new
     * one; a record whose id did not come back is one somebody took off the
     * sheet. That is the whole of the difference from the lists kept beside
     * these — the children, the rest of the family, the languages, which have no
     * identity beyond what they say and are replaced whole. A place of study
     * does: a diploma hangs off it, and the sheet sends no file for a document
     * it is not changing.
     *
     * The ids were checked against this very card on the way in, so an id here
     * names a record of it or names nothing.
     *
     * @param  HasMany<Model, User>  $relation
     * @param  array<array-key, array<string, mixed>>  $records
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $columns  how a record becomes columns, where it is more than its own fields
     * @param  string|null  $document  the column a record keeps its file in, where it keeps one
     * @return list<string|null> the files nothing on the card points at any more
     */
    private function reconcile(HasMany $relation, array $records, ?callable $columns = null, ?string $document = null): array
    {
        $onFile = $relation->get()->keyBy('id');

        // Taken off in one statement and through a query of their own rather
        // than model by model: deleting a record of study fires the hook that
        // removes its diploma from the disk, and a file removed inside a
        // transaction is gone whether or not the rows come back.
        $gone = $onFile->except(array_filter(array_column($records, 'id')));
        $orphaned = $document === null ? [] : array_values($gone->pluck($document)->all());

        if ($gone->isNotEmpty()) {
            $gone->toQuery()->delete();
        }

        foreach ($records as $record) {
            $id = $record['id'] ?? null;
            unset($record['id']);

            $written = $columns === null ? $record : $columns($record);

            // A row already on the card is corrected rather than filed again,
            // which is what keeps its id — and so whatever hangs off that id.
            if ($id !== null && $onFile->has($id)) {
                $row = $onFile->get($id);
                $was = $document === null ? null : $row->{$document};

                $row->update($written);

                // Only where this save actually brought another paper: a row
                // that stayed as it was must not lose the one it had.
                if ($document !== null && $row->{$document} !== $was) {
                    $orphaned[] = $was;
                }

                continue;
            }

            $relation->create($written);
        }

        return $orphaned;
    }
}
