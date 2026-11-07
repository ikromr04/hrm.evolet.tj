<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * «Личный листок по учёту кадров» written onto a card, however it arrived.
 *
 * The sheet is saved in one go: eleven blocks of the card retyped together and
 * written together, so that either all of it is on file or none of it is. The
 * alternative was a save per block, and a sheet that failed on the ninth of
 * them would be a sheet «сохранён наполовину»: nobody could then say which
 * half.
 *
 * Each part is saved the way its own window already saves it, through the same
 * calls — the languages, the children, the rest of the family, the places of
 * study, the three lists that are a line of text and one paper apiece. Two ways
 * of writing one block is one way somebody will fix and one they will not.
 *
 * What the request did not carry is not written. The sheet spans the lines of
 * eleven different rights and UpdateSheetRequest validates only the ones the
 * sender may change, so a line that is missing here is a line that was never
 * theirs — and it is left exactly as it stands on file rather than blanked by a
 * form that never showed it.
 *
 * This lives apart from the controller because there are now two doors onto the
 * same writing. Somebody who may change the lines saves the sheet and it is
 * written; the two roles that are shown their own card send it instead, and the
 * very same words are written when personnel accept the request — by this call,
 * from SheetChangeRequestController, weeks later and by other hands. Two copies
 * of this would be one copy somebody fixes and one they do not, and the half
 * that was not fixed is the half that writes to a card.
 */
final class SheetWriter
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

    /**
     * The sheet written, and the files nothing points at any more handed back.
     *
     * Neither the transaction nor the deleting is done here, and that is the
     * caller's business on purpose: the rows belong in one transaction, and the
     * documents the save stops pointing at have to come off the disk after that
     * transaction has closed rather than inside it — a save that fails leaves
     * the card with the papers it still names, and a file removed inside a
     * transaction is gone whether or not the rows come back. So the paths are
     * returned and whoever opened the transaction closes it and then deletes.
     *
     * @param  array<string, mixed>  $data  the sheet as validated
     * @return list<string|null> the paths nothing on the card looks at any more
     */
    public static function write(User $employee, array $data): array
    {
        /** @var list<string|null> $replaced */
        $replaced = [];

        $employee->update(Arr::only($data, self::ON_USER));

        // Countries by name: one missing from the directory is added to it,
        // so it can be typed into the sheet rather than into «Справочники»
        // first — exactly as the card's own «Основное» adds one.
        if (array_key_exists('citizenship', $data)) {
            EmployeeCard::saveCitizenships($employee, $data['citizenship'] ?? []);
        }

        $details = Arr::only($data, self::ON_DETAILS);

        // «Нет» and «не указано» look the same in an empty list, so the
        // answer is worked out rather than taken as sent: rows mean «есть»
        // whatever the flag said, and only «нет» is believed as it came.
        // Asked of each list only where the list itself was carried — a
        // flag without the rows it describes is not an answer about them.
        //
        // Six of them now: the degrees, the awards and the military records
        // used to be one line of free text each, where «Нет» was typed into
        // the line itself to tell it from a line nobody had filled in. They
        // are lists as of November 2026, so the «нет» sits beside the list
        // in a flag of its own, which is where the children's has always
        // been — and reading it is the same work for all six.
        foreach ([
            'children' => 'has_children',
            'educations' => 'has_educations',
            'experiences' => 'has_experience',
            'degrees' => 'has_degrees',
            'awards' => 'has_awards',
            'military_records' => 'has_military_records',
        ] as $list => $flag) {
            if (array_key_exists($list, $data)) {
                $details[$flag] = EmployeeCard::listFlag($data[$flag] ?? null, $data[$list]);
            }
        }

        if ($details !== []) {
            $employee->details()->updateOrCreate([], $details);
        }

        // The three lists that have no identity beyond what they say, each
        // replaced by what was sent: the sheet shows every row of them at
        // once, so it edits them whole, and a language nobody here has been
        // written down as speaking is kept as it was typed.
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
            $replaced = [...$replaced, ...self::reconcile(
                $employee->educations(),
                $data['educations'],
                fn (array $record) => self::papered($record, fn (array $row) => EmployeeCard::educationRow($employee, $row)),
                'document_path',
            )];
        }

        // And the jobs held before, on the same terms. There is no document
        // at stake here, only the row itself: rewriting the list from
        // scratch churns the table and files the jobs again in whatever
        // order the form happened to hold them.
        if (array_key_exists('experiences', $data)) {
            self::reconcile($employee->workExperiences(), $data['experiences']);
        }

        // And the three lists that are a line of text and one paper apiece,
        // reconciled rather than replaced for the reason the places of study
        // are: a paper hangs off every record, and the sheet sends no file
        // for a document it is not changing. Until November 2026 the three
        // were a single line of the row above, which is why anybody looking
        // for them will look among its columns and not find them here.
        //
        // Which relation each list is saved into is the one thing that tells
        // them apart — the rules, the columns and the reconciling are all
        // the same work — so they are written once and walked.
        foreach ([
            'degrees' => $employee->degrees(),
            'awards' => $employee->awards(),
            'military_records' => $employee->militaryRecords(),
        ] as $list => $records) {
            if (! array_key_exists($list, $data)) {
                continue;
            }

            $replaced = [...$replaced, ...self::reconcile(
                $records,
                $data[$list],
                fn (array $record) => self::papered($record, fn (array $row) => EmployeeCard::paperRow($employee, $row)),
                'document_path',
            )];
        }

        return $replaced;
    }

    /**
     * One record that carries a paper as the columns to write, with the asking
     * for that paper to go honoured.
     *
     * All four of the sheet's lists that keep a document come through here — the
     * places of study and the three that are a line of text and one paper
     * apiece — because the question is the same in each: what EmployeeCard makes
     * of the record is the record's own business, and whether the paper on file
     * is to go is the sheet's.
     *
     * The flag is not a column, and it is the sheet's rather than the card's:
     * the windows that keep one record at a time replace a document on a save
     * and take it off through a request of their own, while nothing on this page
     * is written until «Сохранить» — so a removal has to wait with everything
     * else, and the only way to make it wait is to send it along. Which is why
     * it is read here and not in EmployeeCard::educationRow() or paperRow(),
     * where the create page would find it and have nothing on file to take off.
     *
     * A file beats the flag: somebody who attached one plainly wants it. So the
     * removal is read off what the row came back as — a record carrying a new
     * paper already names it, and only one that names none is asked whether the
     * old one is to go.
     *
     * The name goes with the paper. It is what the card shows as the thing one
     * presses to open the document, and a name with nothing behind it is a
     * label pointing at nothing.
     *
     * @param  array<string, mixed>  $record  as validated, the file and the flag included
     * @param  callable(array<string, mixed>): array<string, mixed>  $row  what this kind of record comes to
     * @return array<string, mixed> the columns to write
     */
    private static function papered(array $record, callable $row): array
    {
        $asked = (bool) ($record['document_remove'] ?? false);
        unset($record['document_remove']);

        $columns = $row($record);

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
     * does, and so does a degree, an award or a military record: a paper hangs
     * off each of them, and the sheet sends no file for a document it is not
     * changing.
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
    private static function reconcile(HasMany $relation, array $records, ?callable $columns = null, ?string $document = null): array
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
