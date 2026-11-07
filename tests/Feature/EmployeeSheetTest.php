<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserAward;
use App\Models\UserChild;
use App\Models\UserDegree;
use App\Models\UserEducation;
use App\Models\UserLanguage;
use App\Models\UserMilitaryRecord;
use App\Models\UserPaperRecord;
use App\Models\UserRelative;
use App\Models\UserWorkExperience;
use App\Support\EmployeeFields;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «Личный листок по учёту кадров», retyped on the card and saved in one go.
 *
 * The sheet is the paper a personnel office keeps, and its answers used to be
 * corrected a window at a time — the name in «Основное», the passport in
 * «Паспорт», the places of study one record each. Read as a sheet it is a
 * single page, and it is now edited as one: eleven blocks of the card filled in
 * together and written in one transaction.
 *
 * So the two things worth pinning hardest are the two the one request is for:
 * that everything typed arrives, and that a refusal in the eleventh block
 * leaves nothing behind from the first. The third is the reason the door has no
 * single gate on it — the sheet spans the lines of eleven different rights, so
 * what a viewer may change is weighed line by line, and a form carrying a field
 * its owner was never shown writes none of it.
 */
class EmployeeSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /**
     * The one account that passes every check, whatever the rights say: most of
     * these tests are about what the sheet saves rather than about who saves it.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /**
     * Somebody whose position may read and change exactly these lines of a
     * colleague's card, and nothing else.
     *
     * Both rights for each, because changing is never wider than reading:
     * nobody retypes what they cannot see. The two lines no position can be
     * closed to have no right to read them at all, so none is asked for.
     */
    private function editor(string ...$lines): User
    {
        return $this->position(
            ...array_map(EmployeeFields::permission(...), array_diff($lines, EmployeeFields::ALWAYS_VISIBLE)),
            ...array_map(EmployeeFields::editPermission(...), $lines),
        );
    }

    /** And somebody who may read those lines without retyping any of them. */
    private function reader(string ...$lines): User
    {
        return $this->position(...array_map(
            EmployeeFields::permission(...),
            array_diff($lines, EmployeeFields::ALWAYS_VISIBLE),
        ));
    }

    /** A position holding exactly these rights, plus the staff list. */
    private function position(string ...$rights): User
    {
        $role = Role::create(['name' => 'r-'.Role::count(), 'title' => 'Роль '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...$rights]);

        return User::factory()->create()->assignRole($role);
    }

    /**
     * A card with the row the sheet lives on already filed and nothing in it.
     *
     * Not the factory's row: that one arrives with a birthplace, a passport and
     * two telephone numbers on it, and these tests are about what a save writes
     * rather than about what a factory invents. The name is fixed for the same
     * reason — the factory picks one out of a hat, and half these assertions are
     * about a name a save was not allowed to change.
     */
    private function blankCard(): User
    {
        $employee = User::factory()->create([
            'surname' => 'Рахимова',
            'name' => 'Зарина',
            'patronymic' => null,
            'sex' => null,
        ]);
        $employee->details()->create([]);

        return $employee;
    }

    /** A paper of the kind the sheet's documents are scanned as. */
    private function paper(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /**
     * A place of study already on the card, with its diploma filed the way a
     * save files one: the columns, and the file behind them on the private disk.
     */
    private function studied(User $employee, string $institution, string $document): UserEducation
    {
        return UserEducation::factory()->for($employee)->create([
            'institution' => $institution,
            'faculty' => 'Экономический',
            'specialty' => 'Финансы',
            'qualification' => 'бакалавр',
            'started_year' => 2008,
            'graduated_year' => 2012,
            // The name on the disk is the disk's own; what the paper is called
            // is the column beside it.
            'document_path' => Storage::disk('documents')->putFile(
                "employees/{$employee->id}",
                $this->paper('scan.pdf'),
            ),
            'document_name' => $document,
        ]);
    }

    /**
     * One record already on one of the three lists that are a line of text and
     * one paper apiece, with its paper filed the way a save files one.
     *
     * @param  HasMany<UserPaperRecord, User>  $records  which of the three lists it stands in
     */
    private function record(User $employee, HasMany $records, string $name, string $document): UserPaperRecord
    {
        return $records->create([
            'name' => $name,
            // The name on the disk is the disk's own; what the paper is called
            // is the column beside it.
            'document_path' => Storage::disk('documents')->putFile(
                "employees/{$employee->id}",
                $this->paper('scan.pdf'),
            ),
            'document_name' => $document,
        ]);
    }

    /**
     * One such record on each of the three lists at once.
     *
     * Every test below that is about the papers of those lists is about all
     * three of them, because they are one behaviour three times over: the save
     * reconciles «Учёная степень, звание», «Государственные награды,
     * поощрения» and «Отношение к воинской обязанности» through the same walk,
     * and a question answered for one of them is answered for the other two by
     * the same code or not at all.
     *
     * @param  string|null  $named  what all three records say, for a test that
     *                              has to tell two records of one list apart; by
     *                              default each says what the sheet below sends
     * @return array<string, UserPaperRecord> keyed by the name the sheet sends the list under
     */
    private function recorded(User $employee, ?string $named = null): array
    {
        return [
            'degrees' => $this->record(
                $employee,
                $employee->degrees(),
                $named ?? 'кандидат экономических наук, доцент',
                'Аттестат доцента.pdf',
            ),
            'awards' => $this->record(
                $employee,
                $employee->awards(),
                $named ?? 'Медаль „Хизмати шоиста“',
                'Указ.pdf',
            ),
            'military_records' => $this->record(
                $employee,
                $employee->militaryRecords(),
                $named ?? 'Военнообязанный, старший лейтенант запаса',
                'Военный билет.pdf',
            ),
        ];
    }

    /**
     * And the three lists as the sheet sends them back: one row each, the
     * record it is, said again, with whatever is to happen to its paper.
     *
     * Written out here for the reason the places of study are: the sheet edits
     * every list whole, so a record has to come back complete — and by its id,
     * or it is a row somebody took off the card and filed anew — even when the
     * only thing being said about it is what is to become of its document.
     *
     * @param  array<string, UserPaperRecord>  $rows  as recorded() filed them
     * @param  array<string, mixed>  $about  a correction, or the asking for the paper to go
     * @return array<string, list<array<string, mixed>>> the three lists, ready to override the sheet's own
     */
    private function papers(array $rows, array $about = []): array
    {
        return array_map(fn (UserPaperRecord $row) => [[
            'id' => $row->id,
            'name' => $row->name,
            ...$about,
        ]], $rows);
    }

    /**
     * One place of study as the sheet sends it back: the row it is, answered in
     * full, with whatever is to happen to its diploma.
     *
     * Written out here because four of these tests send it — the sheet edits
     * every list whole, so a record has to come back complete even when the
     * only thing being said about it is what is to become of its paper.
     *
     * @param  array<string, mixed>  $about  the diploma: a file, the asking for it to go, or neither
     * @return list<array<string, mixed>>
     */
    private function studies(UserEducation $row, array $about = []): array
    {
        return [[
            'id' => $row->id,
            'institution' => $row->institution,
            'faculty' => 'Экономический',
            'specialty' => 'Финансы',
            'qualification' => 'бакалавр',
            'started_year' => 2008,
            'graduated_year' => 2012,
            ...$about,
        ]];
    }

    /**
     * The sheet as the page sends it: the eleven blocks at once, under the names
     * «Новый сотрудник» gives them, because one shape serves both.
     *
     * Every list travels even when it is empty — the sheet shows all of them, so
     * a field that did not arrive is a field somebody dropped rather than a list
     * somebody emptied.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sheet(array $overrides = []): array
    {
        return [
            /* Основное */
            'surname' => 'Азимова',
            'name' => 'Нилуфар',
            'patronymic' => 'Рустамовна',
            'sex' => 'female',
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'nationality' => 'таджичка',
            'citizenship' => ['Таджикистан'],

            /* Учёная степень, звание */
            // A list answered before it is filled, as the three blocks that
            // keep a paper apiece have been since November 2026: the answer is
            // the flag, and what there is of it is the rows.
            'has_degrees' => true,
            'degrees' => [['name' => 'кандидат экономических наук, доцент']],

            /* Образование */
            'has_educations' => true,
            'educations' => [
                [
                    'institution' => 'ТНУ',
                    'faculty' => 'Экономический',
                    'specialty' => 'Финансы',
                    'qualification' => 'бакалавр',
                    'started_year' => 2008,
                    'graduated_year' => 2012,
                ],
            ],

            /* Знание языков */
            // Named rather than numbered: the language is written on the card
            // as it was typed, and nothing anywhere has to have heard of it
            // first — the box only offers what other cards already say.
            'languages' => [['name' => 'Английский', 'level' => UserLanguage::LEVELS[0]]],

            /* Трудовая деятельность */
            'has_experience' => true,
            'experiences' => [
                [
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Аналитик',
                    'started_month' => 3,
                    'started_year' => 2013,
                    'ended_month' => 8,
                    'ended_year' => 2020,
                    'reason' => 'По собственному желанию',
                ],
            ],

            /* Государственные награды, поощрения */
            'has_awards' => true,
            'awards' => [['name' => 'Медаль „Хизмати шоиста“']],

            /* Отношение к воинской обязанности */
            'has_military_records' => true,
            'military_records' => [['name' => 'Военнообязанный, старший лейтенант запаса']],

            /* Семейное положение и состав семьи */
            'marital_status' => 'married',
            'spouse_name' => 'Азимов Рустам',
            // A month and a year for every one of them: «Состав семьи» is one
            // list of people, and the sheet asks it the same three things.
            'spouse_birth_date' => '1988-02',
            'spouse_occupation' => 'Инженер, ООО «Ориён»',
            'has_children' => true,
            'children' => [['full_name' => 'Азимова Сабина', 'birth_date' => '2015-05', 'occupation' => '3 класс, СОШ № 12']],
            'relatives' => [
                ['relation' => 'Мать', 'full_name' => 'Рахимова Зарина Курбоновна', 'birth_date' => '1971-03', 'occupation' => 'Пенсионерка'],
                ['relation' => 'Брат', 'full_name' => 'Рахимов Далер Саидович', 'birth_date' => '2001-09', 'occupation' => 'Студент'],
            ],

            /* Паспорт */
            'passport_series' => 'A',
            'passport_number' => '1234567',
            'passport_issued_at' => '2020-01-01',
            'passport_issued_by' => 'ОВД района Фирдавси',
            'tax_number' => '123456789',

            /* Контакты */
            'home_address' => 'г. Душанбе, пр. Рудаки 55',
            'phone' => '901112233',
            'sos_phone' => '902223344',
            'sos_contact' => 'Мама',

            ...$overrides,
        ];
    }

    /**
     * One button, one request, and every one of the eleven blocks on file
     * afterwards.
     *
     * The whole point of the door: the sheet used to need eleven saves, and a
     * personnel officer who got through nine of them had a card nobody could
     * describe.
     */
    public function test_the_whole_sheet_is_saved_in_one_request()
    {
        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        $details = $employee->details;

        // Основное: the lines on the account, and the lines beside it.
        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('Нилуфар', $employee->name);
        $this->assertSame('Рустамовна', $employee->patronymic);
        $this->assertSame('female', $employee->sex);
        $this->assertSame('1990-04-17', $details->birth_date->toDateString());
        $this->assertSame('г. Худжанд', $details->birth_place);
        $this->assertSame('таджичка', $details->nationality);
        $this->assertSame(['Таджикистан'], $employee->citizenships->pluck('name')->all());

        // The three lists that are a line of text and one paper apiece, each
        // with the answer that says there are any.
        $this->assertTrue($details->has_degrees);
        $this->assertSame(['кандидат экономических наук, доцент'], $employee->degrees->pluck('name')->all());
        $this->assertTrue($details->has_awards);
        $this->assertSame(['Медаль „Хизмати шоиста“'], $employee->awards->pluck('name')->all());
        $this->assertTrue($details->has_military_records);
        $this->assertSame(
            ['Военнообязанный, старший лейтенант запаса'],
            $employee->militaryRecords->pluck('name')->all(),
        );

        // Образование, with the answer that says there are any.
        $this->assertTrue($details->has_educations);
        $this->assertSame('ТНУ', $employee->educations->first()->institution);
        $this->assertSame('бакалавр', $employee->educations->first()->qualification);
        $this->assertSame(2012, $employee->educations->first()->graduated_year);

        // Знание языков, each language a row of the card with its level on it.
        $this->assertSame(['Английский'], $employee->languages->pluck('name')->all());
        $this->assertSame(UserLanguage::LEVELS[0], $employee->languages->first()->level);

        // Трудовая деятельность, with its own answer.
        $this->assertTrue($details->has_experience);
        $this->assertSame('ООО «Ориён»', $employee->workExperiences->first()->organization);
        $this->assertSame('По собственному желанию', $employee->workExperiences->first()->reason);

        // Семья: the status, the spouse, the children and the rest of them.
        $this->assertSame('married', $details->marital_status);
        $this->assertSame('Азимов Рустам', $details->spouse_name);
        // The month that was sent, kept as the first of itself.
        $this->assertSame('1988-02-01', $details->spouse_birth_date->toDateString());
        $this->assertSame('Инженер, ООО «Ориён»', $details->spouse_occupation);
        $this->assertTrue($details->has_children);
        $this->assertSame(['Азимова Сабина'], $employee->children->pluck('full_name')->all());
        $this->assertSame('2015-05-01', $employee->children->first()->birth_date->toDateString());
        $this->assertSame('3 класс, СОШ № 12', $employee->children->first()->occupation);
        $this->assertSame(['Мать', 'Брат'], $employee->relatives->pluck('relation')->all());
        $this->assertSame(
            [['1971-03', 'Пенсионерка'], ['2001-09', 'Студент']],
            $employee->relatives->map(fn ($r) => [$r->birth_date->format('Y-m'), $r->occupation])->all(),
        );

        // Паспорт.
        $this->assertSame('A', $details->passport_series);
        $this->assertSame('1234567', $details->passport_number);
        $this->assertSame('2020-01-01', $details->passport_issued_at->toDateString());
        $this->assertSame('ОВД района Фирдавси', $details->passport_issued_by);
        $this->assertSame('123456789', $details->tax_number);

        // Контакты, with the telephones tidied into E.164 the way every form of
        // this card tidies them.
        $this->assertSame('г. Душанбе, пр. Рудаки 55', $details->home_address);
        $this->assertSame('+992901112233', $details->phone);
        $this->assertSame('+992902223344', $details->sos_phone);
        $this->assertSame('Мама', $details->sos_contact);
    }

    /**
     * A second save replaces the lists rather than adding to them.
     *
     * The sheet shows every row of them at once, so it edits them whole: a row
     * somebody took out of the form is a row they took off the card.
     */
    public function test_the_lists_are_replaced_by_what_the_sheet_sent()
    {
        $employee = $this->blankCard();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'educations' => [[
                    'institution' => 'РТСУ',
                    'faculty' => 'Юридический',
                    'specialty' => 'Право',
                    'qualification' => 'магистр',
                    'started_year' => 2013,
                    'graduated_year' => 2015,
                ]],
                'languages' => [['name' => 'Русский', 'level' => UserLanguage::LEVELS[0]]],
                'degrees' => [['name' => 'доктор экономических наук']],
                'awards' => [['name' => 'Орден „Шараф“']],
                'military_records' => [['name' => 'Снят с воинского учёта']],
                'children' => [],
                'has_children' => false,
                'relatives' => [],
                'experiences' => [],
                'has_experience' => false,
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();

        $this->assertSame(['РТСУ'], $employee->educations->pluck('institution')->all());
        $this->assertSame(['Русский'], $employee->languages->pluck('name')->all());
        // The three lists are replaced the same way, now that they are lists:
        // a row sent without an id is a row nothing on the card answers for.
        $this->assertSame(['доктор экономических наук'], $employee->degrees->pluck('name')->all());
        $this->assertSame(['Орден „Шараф“'], $employee->awards->pluck('name')->all());
        $this->assertSame(['Снят с воинского учёта'], $employee->militaryRecords->pluck('name')->all());
        $this->assertSame(0, $employee->children()->count());
        $this->assertSame(0, $employee->relatives()->count());
        $this->assertSame(0, $employee->workExperiences()->count());

        // One row of each list in the database and no orphans left behind —
        // the languages among them, now that a language spoken is a row of the
        // card rather than a line pointed at in a list of everybody's.
        $this->assertSame(1, UserEducation::count());
        $this->assertSame(1, UserLanguage::count());
        $this->assertSame(1, UserDegree::count());
        $this->assertSame(1, UserAward::count());
        $this->assertSame(1, UserMilitaryRecord::count());
        $this->assertSame(0, UserChild::count());
        $this->assertSame(0, UserRelative::count());
        $this->assertSame(0, UserWorkExperience::count());
    }

    /**
     * A viewer saves the lines that are theirs and leaves the rest as they
     * stand, however much the request carries.
     *
     * This is the whole defence of the door: the sheet spans eleven blocks of
     * the card, each with a right of its own, so a page built from stale rights
     * — or a request built by hand — may well name a field its sender was never
     * shown. What they may not change is simply not read.
     */
    public function test_a_viewer_saves_only_the_lines_that_are_theirs()
    {
        $employee = $this->blankCard();
        $employee->details()->update([
            'birth_place' => 'г. Куляб',
            'passport_number' => '7654321',
            // «Нет» on a line that is a list now: the answer is the flag beside
            // it, and a save that may not touch the block must not turn it into
            // «есть» by carrying rows the viewer was never shown.
            'has_degrees' => false,
            'sos_phone' => '+992905554433',
            'sos_contact' => 'Брат',
        ]);
        $employee->update(['patronymic' => 'Саидовна']);
        UserEducation::factory()->for($employee)->create(['institution' => 'ТГМУ']);
        UserRelative::factory()->for($employee)->create(['relation' => 'Отец']);
        $employee->awards()->create(['name' => 'Медаль „Хизмати шоиста“']);

        // Three lines of the sheet out of twenty-five.
        $this->actingAs($this->editor('surname', 'phone', 'children'))
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        $details = $employee->details;

        // Their three lines are written.
        $this->assertSame('Азимова', $employee->surname);
        $this->assertSame('+992901112233', $details->phone);
        $this->assertTrue($details->has_children);
        $this->assertSame(['Азимова Сабина'], $employee->children->pluck('full_name')->all());

        // And everything the request also carried is exactly as it was: the
        // name they may not retype, the lines of blocks that were never theirs,
        // and the two lists the sheet would have replaced.
        $this->assertSame('Зарина', $employee->name);
        $this->assertSame('Саидовна', $employee->patronymic);
        $this->assertNull($employee->sex);
        $this->assertNull($details->birth_date);
        $this->assertSame('г. Куляб', $details->birth_place);
        $this->assertNull($details->nationality);
        $this->assertSame([], $employee->citizenships->pluck('name')->all());
        $this->assertNull($details->marital_status);
        $this->assertNull($details->spouse_name);
        $this->assertNull($details->passport_series);
        $this->assertSame('7654321', $details->passport_number);
        $this->assertNull($details->tax_number);
        $this->assertNull($details->home_address);
        $this->assertSame('+992905554433', $details->sos_phone);
        $this->assertSame('Брат', $details->sos_contact);

        // The lists of blocks that are not theirs keep their rows, and the
        // answers behind them stay as they stand — «нет» where it was given,
        // unasked where it was not.
        $this->assertSame(['ТГМУ'], $employee->educations->pluck('institution')->all());
        $this->assertSame(['Отец'], $employee->relatives->pluck('relation')->all());
        $this->assertSame(['Медаль „Хизмати шоиста“'], $employee->awards->pluck('name')->all());
        $this->assertSame(0, $employee->languages()->count());
        $this->assertSame(0, $employee->workExperiences()->count());
        $this->assertSame(0, $employee->degrees()->count());
        $this->assertSame(0, $employee->militaryRecords()->count());
        $this->assertNull($details->has_educations);
        $this->assertNull($details->has_experience);
        $this->assertFalse($details->has_degrees);
        $this->assertNull($details->has_awards);
        $this->assertNull($details->has_military_records);
    }

    /**
     * A refusal anywhere on the sheet leaves none of it written.
     *
     * The user chose one button and one request over a save per block, so that
     * the sheet is never «сохранён наполовину»; the refusal below is in the
     * seventh block of eleven, and the first block must not survive it.
     */
    public function test_a_refusal_anywhere_leaves_nothing_of_the_sheet_written()
    {
        $employee = $this->blankCard();

        // A job left three years before it was joined, which no rule can see on
        // its own: the two halves of each date are separate fields.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'experiences' => [[
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Аналитик',
                    'started_month' => 3,
                    'started_year' => 2021,
                    'ended_month' => 8,
                    'ended_year' => 2018,
                    'reason' => 'Соглашение сторон',
                ]],
            ]))
            ->assertSessionHasErrors('experiences.0.ended_year');

        $employee->refresh();

        // Not the name in the first block, not a column of the row beside it,
        // and not a row of any of the eight lists.
        $this->assertSame('Рахимова', $employee->surname);
        $this->assertNull($employee->details->birth_date);
        $this->assertNull($employee->details->has_degrees);
        $this->assertNull($employee->details->passport_number);
        $this->assertSame([], $employee->citizenships->pluck('name')->all());
        $this->assertSame(0, UserDegree::count());
        $this->assertSame(0, UserAward::count());
        $this->assertSame(0, UserMilitaryRecord::count());
        $this->assertSame(0, UserEducation::count());
        $this->assertSame(0, UserWorkExperience::count());
        $this->assertSame(0, UserChild::count());
        $this->assertSame(0, UserRelative::count());
        $this->assertSame(0, $employee->languages()->count());
    }

    /**
     * Every block says what is wrong under its own field.
     *
     * Eleven blocks on one page and one request: a refusal has to name the box
     * it belongs to, row and all, or the sheet has nothing to show it under.
     */
    public function test_every_block_says_what_is_wrong_under_its_own_field()
    {
        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'surname' => '',
                'birth_date' => now()->addWeek()->toDateString(),
                'phone' => 'не телефон',
                'passport_issued_at' => now()->addWeek()->toDateString(),
                'spouse_name' => '',
                'children' => [['full_name' => '', 'birth_date' => '2015-05']],
                'relatives' => [['relation' => '', 'full_name' => 'Рахимов Далер']],
                'languages' => [['name' => 'Английский', 'level' => 'сносно']],
                'educations' => [[
                    'institution' => 'РТСУ',
                    'faculty' => 'Юридический',
                    'specialty' => 'Право',
                    'qualification' => 'магистр',
                    'started_year' => 2015,
                    // A diploma cannot predate enrolment.
                    'graduated_year' => 2012,
                ]],
                // A record of one of the three lists that says nothing at all:
                // the row is the answer, so an empty one is a row somebody
                // opened and did not fill in.
                'degrees' => [['name' => '']],
            ]))
            ->assertSessionHasErrors([
                'surname',
                'birth_date',
                'phone',
                'passport_issued_at',
                'spouse_name',
                'children.0.full_name',
                'relatives.0.relation',
                'languages.0.level',
                'educations.0.graduated_year',
                'degrees.0.name',
            ]);
    }

    /**
     * A paper on a record of each of the three lists that keep one, and a
     * diploma on a place of study, all filed by the one save.
     *
     * A file is the one part of the sheet that is not a column: it goes to the
     * private disk and the row keeps where it went and what it is called.
     *
     * The name is the file's own, and nothing else is asked for it. There used
     * to be a box of its own beside each document, which had HR typing «Диплом
     * бакалавра» next to a file already called that; the name is now edited on
     * the file in the form before it is sent, so the one name that arrives is
     * the name the card shows.
     */
    public function test_the_documents_of_the_sheet_are_filed_by_the_one_save()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'degrees' => [[
                    'name' => 'кандидат экономических наук, доцент',
                    'document' => $this->paper('Аттестат доцента.pdf'),
                ]],
                'awards' => [[
                    'name' => 'Медаль „Хизмати шоиста“',
                    'document' => $this->paper('Указ.pdf'),
                ]],
                'military_records' => [[
                    'name' => 'Военнообязанный, старший лейтенант запаса',
                    'document' => $this->paper('Военный билет.pdf'),
                ]],
                'educations' => [[
                    'institution' => 'ТНУ',
                    'faculty' => 'Экономический',
                    'specialty' => 'Финансы',
                    'qualification' => 'бакалавр',
                    'started_year' => 2008,
                    'graduated_year' => 2012,
                    'document' => $this->paper('Диплом бакалавра.pdf'),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();

        foreach ([
            'degrees' => 'Аттестат доцента.pdf',
            'awards' => 'Указ.pdf',
            'militaryRecords' => 'Военный билет.pdf',
        ] as $list => $name) {
            $record = $employee->{$list}->sole();
            $this->assertNotNull($record->document_path);
            $this->assertSame($name, $record->document_name);
            Storage::disk('documents')->assertExists($record->document_path);
        }

        $education = $employee->educations->first();
        $this->assertSame('Диплом бакалавра.pdf', $education->document_name);
        Storage::disk('documents')->assertExists($education->document_path);

        // Four papers, each under the colleague they belong to, so everything
        // about one person can be found — and removed — without reading rows.
        $this->assertCount(4, Storage::disk('documents')->allFiles());
        $this->assertCount(4, Storage::disk('documents')->files("employees/{$employee->id}"));
    }

    /**
     * «Нет» on one of the three lists is the flag, and it takes the rows and
     * their papers with it.
     *
     * The answer used to be the word «Нет» typed into the line itself — the one
     * way a line of free text had of telling «нет» from «никто не спрашивал» —
     * and a document sent beside it was refused as a card contradicting
     * itself. The line is a list now, so the answer sits beside it the way the
     * children's always has, and the contradiction cannot be sent at all: a
     * paper only arrives on a record, and a record is «есть» whatever the flag
     * says. What «Нет» means here is therefore the stronger thing — the list is
     * emptied, and no paper of it is left lying on the disk.
     */
    public function test_a_list_answered_no_is_written_as_the_flag_and_keeps_nothing()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $papers = $this->recorded($employee);

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'has_degrees' => false,
                'degrees' => [],
                'has_awards' => false,
                'awards' => [],
                'has_military_records' => false,
                'military_records' => [],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $details = $employee->refresh()->details;

        $this->assertFalse($details->has_degrees);
        $this->assertFalse($details->has_awards);
        $this->assertFalse($details->has_military_records);
        $this->assertSame(0, $employee->degrees()->count());
        $this->assertSame(0, $employee->awards()->count());
        $this->assertSame(0, $employee->militaryRecords()->count());

        foreach ($papers as $row) {
            $this->assertNull($row->fresh());
            Storage::disk('documents')->assertMissing($row->document_path);
        }

        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * A paper the sheet is asked to take off goes, and goes off the disk, and
     * the answer it was proof of stays.
     *
     * Until the asking existed, a scan attached by mistake could only be got
     * rid of by answering the line «Нет» — which threw the answer away along
     * with it, so a badly scanned аттестат cost the card the degree. There are
     * routes that delete one of these papers outright, and they are still
     * there for the card's own windows; the sheet cannot use them, because a
     * removal that happened at once would be the one thing on this page
     * «Отмена» could not put back.
     *
     * All four papers at once, because they are one behaviour four times over:
     * one record of each of the three lists that keep a document, and the
     * diploma of a place of study. Each is asked for on the row it hangs off,
     * which is where a paper lives now that the three lines are lists.
     */
    public function test_a_paper_the_sheet_is_asked_to_take_off_goes_and_the_answer_stays()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $papers = $this->recorded($employee);
        $row = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                ...$this->papers($papers, ['document_remove' => true]),
                'educations' => $this->studies($row, ['document_remove' => true]),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $details = $employee->refresh()->details;

        foreach ($papers as $was) {
            $record = $was->fresh();

            // The record is the row it was, and now holds no paper.
            $this->assertSame($was->id, $record->id);
            $this->assertSame($was->name, $record->name);
            $this->assertNull($record->document_path);
            // The name goes with the paper: a name with nothing behind it is a
            // label the card would offer to open and have nothing to hand over.
            $this->assertNull($record->document_name);
            Storage::disk('documents')->assertMissing($was->document_path);
        }

        // And every one of the three lists still says what it said, under the
        // answer it said it under. That is the whole of why the asking is a
        // thing of its own rather than «Нет».
        $this->assertTrue($details->has_degrees);
        $this->assertTrue($details->has_awards);
        $this->assertTrue($details->has_military_records);

        // The record of study is the row it was, and now holds no diploma.
        $this->assertSame($row->id, $employee->educations->sole()->id);
        $this->assertNull($row->fresh()->document_path);
        $this->assertNull($row->fresh()->document_name);
        $this->assertSame('ТНУ', $row->fresh()->institution);

        // Nothing is left lying on the disk under the colleague's folder.
        $this->assertCount(0, Storage::disk('documents')->allFiles());
    }

    /**
     * And a save that says nothing about the papers leaves all four where they
     * are.
     *
     * This is the reason the asking has to be asked for in so many words. No
     * file has always meant «do not touch the file» on this card — most saves
     * of the sheet are about a telephone number nine screens away — so a
     * removal read out of an absence would strip every paper a card holds
     * every time anybody retyped anything.
     */
    public function test_an_ordinary_save_leaves_every_paper_where_it_is()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $papers = $this->recorded($employee);
        $row = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                ...$this->papers($papers),
                'educations' => $this->studies($row),
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();

        foreach ($papers as $kept) {
            $this->assertSame($kept->document_path, $kept->fresh()->document_path);
            $this->assertSame($kept->document_name, $kept->fresh()->document_name);
            Storage::disk('documents')->assertExists($kept->document_path);
        }

        $this->assertSame($row->document_path, $row->fresh()->document_path);
        $this->assertSame('Диплом бакалавра', $row->fresh()->document_name);
        Storage::disk('documents')->assertExists($row->document_path);
        $this->assertCount(4, Storage::disk('documents')->allFiles());
    }

    /**
     * A paper attached in the same request beats the asking for the old one to
     * go.
     *
     * Somebody who chose a file plainly wants it, whatever they pressed before
     * reaching for it — the box itself takes the asking back the moment one is
     * chosen — and a save that honoured the removal instead would throw away
     * the one thing in the request nobody can doubt was meant. So the new paper
     * is on file, and only the one it replaced leaves the disk.
     */
    public function test_a_paper_attached_in_the_same_request_beats_the_asking()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $degree = $this->record(
            $employee,
            $employee->degrees(),
            'кандидат экономических наук, доцент',
            'Аттестат доцента.pdf',
        );
        $row = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');
        $hung = $row->document_path;

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'degrees' => [[
                    'id' => $degree->id,
                    'name' => $degree->name,
                    'document' => $this->paper('Аттестат доцента, пересканированный.pdf'),
                    'document_remove' => true,
                ]],
                'educations' => $this->studies($row, [
                    'document' => $this->paper('Диплом бакалавра, пересканированный.pdf'),
                    'document_remove' => true,
                ]),
            ]))
            ->assertSessionHasNoErrors();

        $kept = $degree->fresh();

        $this->assertNotNull($kept->document_path);
        $this->assertNotSame($degree->document_path, $kept->document_path);
        $this->assertSame('Аттестат доцента, пересканированный.pdf', $kept->document_name);
        Storage::disk('documents')->assertExists($kept->document_path);
        Storage::disk('documents')->assertMissing($degree->document_path);

        $this->assertNotSame($hung, $row->fresh()->document_path);
        $this->assertSame('Диплом бакалавра, пересканированный.pdf', $row->fresh()->document_name);
        Storage::disk('documents')->assertExists($row->fresh()->document_path);
        Storage::disk('documents')->assertMissing($hung);

        // Two papers on the card and two files on the disk: nothing was left
        // over by either of the two saves inside this one.
        $this->assertCount(2, Storage::disk('documents')->allFiles());
    }

    /**
     * And the asking is refused with the line it belongs to.
     *
     * The paper and the line are one answer, so one right carries both: taking
     * a colleague's аттестат off their card is changing what the card says
     * about their degree, and somebody who may not retype the line may not
     * strip it of what it is proof of either. The flag loses its rules with the
     * line, which is what keeps it out of the save — nothing is argued with,
     * and nothing is written.
     */
    public function test_a_paper_is_not_taken_off_by_somebody_who_may_not_change_the_line()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $papers = $this->recorded($employee);
        $row = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');

        // Somebody whose one line of this sheet is the telephone number, with a
        // form that asks for all four papers to go.
        $this->actingAs($this->editor('phone'))
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                ...$this->papers($papers, ['document_remove' => true]),
                'educations' => $this->studies($row, ['document_remove' => true]),
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $details = $employee->refresh()->details;

        foreach ($papers as $kept) {
            // The record is there, and so is the paper hanging off it: a list
            // that is not this viewer's is not reconciled at all, so not even
            // the row is rewritten.
            $this->assertSame($kept->document_path, $kept->fresh()->document_path);
            $this->assertSame($kept->document_name, $kept->fresh()->document_name);
            Storage::disk('documents')->assertExists($kept->document_path);
        }

        $this->assertSame($row->document_path, $row->fresh()->document_path);
        Storage::disk('documents')->assertExists($row->document_path);
        $this->assertCount(4, Storage::disk('documents')->allFiles());

        // The one line that was theirs is on file, so the save did happen.
        $this->assertSame('+992901112233', $details->phone);
    }

    /**
     * «Есть» is an answer that needs a row behind it, and the sentence says what
     * to do about it rather than reading the rule out.
     *
     * All six lists the sheet asks before it fills: an empty one used to mean
     * both «нет» and «nobody asked», and the sheet counted the two alike. The
     * three that are a line of text and one paper apiece are asked the same
     * way as of November 2026, each in its own words.
     */
    public function test_a_list_answered_yes_and_left_empty_is_refused_in_words()
    {
        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'has_educations' => true,
                'educations' => [],
                'has_experience' => true,
                'experiences' => [],
                'has_children' => true,
                'children' => [],
                'has_degrees' => true,
                'degrees' => [],
                'has_awards' => true,
                'awards' => [],
                'has_military_records' => true,
                'military_records' => [],
            ]))
            ->assertSessionHasErrors([
                'educations' => 'Добавьте хотя бы одно учебное заведение или ответьте «Нет».',
                'experiences' => 'Добавьте хотя бы одно место работы или ответьте «Нет».',
                'children' => 'Добавьте хотя бы одного ребёнка или ответьте «Нет».',
                'degrees' => 'Добавьте хотя бы одну степень или звание, или ответьте «Нет».',
                'awards' => 'Добавьте хотя бы одну награду или поощрение, или ответьте «Нет».',
                'military_records' => 'Добавьте хотя бы одну запись или ответьте «Нет».',
            ]);
    }

    /**
     * Rows mean «есть» whatever the flag said, and only «нет» is believed as it
     * was sent.
     *
     * The two cannot be allowed to drift: a card claiming studies it does not
     * list is the old ambiguity the other way up.
     */
    public function test_rows_answer_for_themselves_and_only_no_is_taken_as_sent()
    {
        $employee = $this->blankCard();
        $admin = $this->sysadmin();

        // Told there are none, with rows in every list: the rows win.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'has_educations' => false,
                'has_experience' => false,
                'has_children' => false,
                'has_degrees' => false,
                'has_awards' => false,
                'has_military_records' => false,
            ]))
            ->assertSessionHasNoErrors();

        $details = $employee->refresh()->details;
        $this->assertTrue($details->has_educations);
        $this->assertTrue($details->has_experience);
        $this->assertTrue($details->has_children);
        $this->assertTrue($details->has_degrees);
        $this->assertTrue($details->has_awards);
        $this->assertTrue($details->has_military_records);

        // «Нет» with nothing in the lists is the answer nothing else records.
        // Which is what the three lines that keep a paper used to say by having
        // the word «Нет» typed into the line itself.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'has_educations' => false,
                'educations' => [],
                'has_experience' => false,
                'experiences' => [],
                'has_children' => false,
                'children' => [],
                'has_degrees' => false,
                'degrees' => [],
                'has_awards' => false,
                'awards' => [],
                'has_military_records' => false,
                'military_records' => [],
            ]))
            ->assertSessionHasNoErrors();

        $details = $employee->refresh()->details;
        $this->assertFalse($details->has_educations);
        $this->assertFalse($details->has_experience);
        $this->assertFalse($details->has_children);
        $this->assertFalse($details->has_degrees);
        $this->assertFalse($details->has_awards);
        $this->assertFalse($details->has_military_records);

        // And an empty list with no answer at all leaves the question open,
        // which is where every card starts.
        $this->actingAs($admin)
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'has_educations' => null,
                'educations' => [],
                'has_experience' => null,
                'experiences' => [],
                'has_children' => null,
                'children' => [],
                'has_degrees' => null,
                'degrees' => [],
                'has_awards' => null,
                'awards' => [],
                'has_military_records' => null,
                'military_records' => [],
            ]))
            ->assertSessionHasNoErrors();

        $details = $employee->refresh()->details;
        $this->assertNull($details->has_educations);
        $this->assertNull($details->has_experience);
        $this->assertNull($details->has_children);
        $this->assertNull($details->has_degrees);
        $this->assertNull($details->has_awards);
        $this->assertNull($details->has_military_records);
    }

    /**
     * The languages are not asked «есть или нет»: one of them there has to be,
     * so the sentence says what to do rather than offering a way out.
     */
    public function test_a_sheet_with_no_language_at_all_is_refused()
    {
        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet(['languages' => []]))
            ->assertSessionHasErrors(['languages' => 'Добавьте хотя бы один язык.']);

        $this->assertSame('Рахимова', $employee->refresh()->surname);
    }

    /**
     * Nothing the sheet does not ask about is accepted through this door.
     *
     * The sign-in address, the company's number, the kind of work, the hire
     * date, the role, where somebody stands and the hardware they hold are other
     * blocks of the card, each with a window of its own. A save that reached
     * them from here would be writing what the sheet never showed — and the role
     * in particular is what decides whether the site opens for a person at all.
     */
    public function test_the_blocks_that_are_not_on_the_sheet_are_not_written_by_it()
    {
        $employee = $this->blankCard();
        $employee->update(['email' => 'nilufar@evolet.tj', 'employee_number' => '00123']);
        $employee->details()->update(['hired_at' => '2020-01-01']);
        $employee->syncRoles(['specialist']);

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'email' => 'podmena@evolet.tj',
                'employee_number' => '00999',
                'employment_type' => 'Полная занятость',
                'hired_at' => '2000-01-01',
                'role' => 'sysadmin',
                'placements' => [],
                'equipment' => [],
            ]))
            ->assertSessionHasNoErrors();

        $employee->refresh();

        $this->assertSame('nilufar@evolet.tj', $employee->email);
        $this->assertSame('00123', $employee->employee_number);
        $this->assertNull($employee->employment_type);
        $this->assertSame('2020-01-01', $employee->details->hired_at->toDateString());
        $this->assertSame(['specialist'], $employee->roles->pluck('name')->all());
    }

    /**
     * Whoever may change no line of the sheet does not open it.
     *
     * The route carries no gate of its own on purpose — the sheet spans eleven
     * blocks, and asking whether somebody may change the passport in order to
     * decide whether they may retype a surname is the wrong question — so this
     * is the answer the request itself gives.
     */
    public function test_a_viewer_who_may_change_no_line_of_the_sheet_is_refused()
    {
        $employee = $this->blankCard();

        // Reads four lines of the card and changes none of them.
        $this->actingAs($this->reader('birth_date', 'passport_number', 'degree', 'phone'))
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertForbidden();

        // And somebody whose one right is a block the sheet does not carry at
        // all is in the same position: the hire date is its own window.
        $this->actingAs($this->editor('hired_at'))
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertForbidden();

        $this->assertSame('Рахимова', $employee->refresh()->surname);
    }

    /** And a stranger to the application is sent to sign in first. */
    public function test_the_sheet_is_not_open_to_a_guest()
    {
        $employee = $this->blankCard();

        $this->put("/employees/{$employee->id}/sheet", $this->sheet())->assertRedirect('/login');

        $this->assertSame('Рахимова', $employee->refresh()->surname);
    }

    /**
     * No record id travels on the sheet, so no record of another card is
     * reachable through it.
     *
     * The rows of the five lists that carry one say which record on file they
     * are, so the id is asked about rather than taken: it has to name a record
     * of the card in the address. The card's own windows check the same thing
     * about the one record they take, and say the same thing about it — a
     * stranger's id is refused, not quietly written onto somebody else's row and
     * not quietly dropped either, which would leave the sheet showing a record
     * it had just filed a second copy of.
     */
    public function test_another_cards_record_is_not_reachable_through_the_sheet()
    {
        $stranger = $this->blankCard();
        $stranger->details()->update(['has_educations' => true, 'has_experience' => true, 'has_degrees' => true]);
        $diploma = UserEducation::factory()->for($stranger)->create(['institution' => 'ТГМУ']);
        $job = UserWorkExperience::factory()->for($stranger)->create(['organization' => 'ООО «Шифобахш»']);
        $degree = $stranger->degrees()->create(['name' => 'кандидат медицинских наук']);

        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'educations' => [[
                    'id' => $diploma->id,
                    'user_id' => $stranger->id,
                    'institution' => 'РТСУ',
                    'faculty' => 'Юридический',
                    'specialty' => 'Право',
                    'qualification' => 'магистр',
                    'started_year' => 2013,
                    'graduated_year' => 2015,
                ]],
                'experiences' => [[
                    'id' => $job->id,
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Аналитик',
                    'started_month' => 3,
                    'started_year' => 2013,
                    'ended_month' => 8,
                    'ended_year' => 2020,
                    'reason' => 'По собственному желанию',
                ]],
                'degrees' => [[
                    'id' => $degree->id,
                    'name' => 'кандидат экономических наук, доцент',
                ]],
            ]))
            ->assertSessionHasErrors(['educations.0.id', 'experiences.0.id', 'degrees.0.id']);

        // The stranger's records are untouched and still theirs, and nothing
        // was written on the card the door was opened on.
        $this->assertSame('ТГМУ', $diploma->refresh()->institution);
        $this->assertSame($stranger->id, $diploma->user_id);
        $this->assertSame('ООО «Шифобахш»', $job->refresh()->organization);
        $this->assertSame('кандидат медицинских наук', $degree->refresh()->name);
        $this->assertSame($stranger->id, $degree->user_id);
        $this->assertSame(0, $employee->educations()->count());
        $this->assertSame(0, $employee->workExperiences()->count());
        $this->assertSame(0, $employee->degrees()->count());
        $this->assertSame('Рахимова', $employee->refresh()->surname);
    }

    /**
     * A place of study already on the card goes on being the row it was, and
     * keeps the diploma hanging off it.
     *
     * This is why the rows carry an id. The sheet sends no file for a document
     * it is not changing — no file means no change to the file, here as
     * everywhere else on this card — so a list written from scratch on every
     * save would have left every paper on the card behind it, and the row that
     * came back would point at nothing.
     */
    public function test_a_place_of_study_sent_back_by_its_id_keeps_its_row_and_its_diploma()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $kept = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');
        $removed = $this->studied($employee, 'ТГМУ', 'Диплом магистра');

        // The first row comes back by its id with a correction and no file; the
        // second does not come back at all; and a third is new.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'educations' => [
                    [
                        'id' => $kept->id,
                        'institution' => 'ТНУ',
                        'faculty' => 'Экономический',
                        'specialty' => 'Финансы и кредит',
                        'qualification' => 'магистр',
                        'started_year' => 2008,
                        'graduated_year' => 2012,
                    ],
                    [
                        'institution' => 'РТСУ',
                        'faculty' => 'Юридический',
                        'specialty' => 'Право',
                        'qualification' => 'магистр',
                        'started_year' => 2013,
                        'graduated_year' => 2015,
                    ],
                ],
            ]))
            ->assertSessionHasNoErrors();

        // The row that came back is the same row, corrected, pointing at the
        // same file — which is still on the disk.
        $this->assertSame($kept->document_path, $kept->fresh()->document_path);
        $this->assertSame('Финансы и кредит', $kept->fresh()->specialty);
        $this->assertSame('магистр', $kept->fresh()->qualification);
        Storage::disk('documents')->assertExists($kept->document_path);

        // The row that did not is gone, and its diploma with it: a record taken
        // off the card leaves no paper lying on the disk.
        $this->assertNull($removed->fresh());
        Storage::disk('documents')->assertMissing($removed->document_path);

        // And the third is on the card, with no document of its own. Two rows,
        // earliest first, which is how the card reads them.
        $written = $employee->refresh()->educations;
        $this->assertSame(['ТНУ', 'РТСУ'], $written->pluck('institution')->all());
        $this->assertNull($written->last()->document_path);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    /**
     * And a record of one of the three lists that keep a paper, likewise: it
     * goes on being the row it was, corrected, with its document still hanging
     * off it — and a record that did not come back is off the card, paper and
     * all.
     *
     * The three lists were one line of free text with one document each until
     * November 2026, so this is the question the change was made to be able to
     * ask: a card may now say two things on such a line, each with its own
     * proof, and correcting the wording of one must not cost it the proof.
     * Which is what the ids are for — a list rewritten from scratch on every
     * save would hand every row a new one and leave its paper behind.
     */
    public function test_a_record_of_the_three_lists_sent_back_by_its_id_keeps_its_row_and_its_paper()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $kept = $this->recorded($employee);
        $dropped = $this->recorded($employee, 'Записано по ошибке');

        // Each of the three lists comes back as two rows: the one on file by
        // its id, corrected and carrying no file, and a second that is new. The
        // row in between does not come back at all.
        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet(array_map(
                fn (UserPaperRecord $row) => [
                    ['id' => $row->id, 'name' => "{$row->name} (уточнено)"],
                    ['name' => 'Ещё одна запись'],
                ],
                $kept,
            )))
            ->assertSessionHasNoErrors();

        foreach ($kept as $row) {
            $corrected = $row->fresh();

            $this->assertSame("{$row->name} (уточнено)", $corrected->name);
            $this->assertSame($row->document_path, $corrected->document_path);
            $this->assertSame($row->document_name, $corrected->document_name);
            Storage::disk('documents')->assertExists($row->document_path);
        }

        // The rows that did not come back are gone, and their papers with them:
        // a record taken off the card leaves no file lying on the disk.
        foreach ($dropped as $row) {
            $this->assertNull($row->fresh());
            Storage::disk('documents')->assertMissing($row->document_path);
        }

        // Two rows on each list, earliest first, the new one holding no paper
        // of its own — so three papers on the disk and not six.
        $employee->refresh();

        foreach (['degrees', 'awards', 'militaryRecords'] as $list) {
            $this->assertCount(2, $employee->{$list});
            $this->assertSame('Ещё одна запись', $employee->{$list}->last()->name);
            $this->assertNull($employee->{$list}->last()->document_path);
        }

        $this->assertCount(3, Storage::disk('documents')->allFiles());
    }

    /**
     * A diploma sent for a row that already had one replaces it, and only the
     * one it replaced leaves the disk.
     */
    public function test_a_new_diploma_on_a_row_replaces_the_one_it_had()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        $row = $this->studied($employee, 'ТНУ', 'Диплом бакалавра');
        $was = $row->document_path;

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'educations' => [[
                    'id' => $row->id,
                    'institution' => 'ТНУ',
                    'faculty' => 'Экономический',
                    'specialty' => 'Финансы',
                    'qualification' => 'бакалавр',
                    'started_year' => 2008,
                    'graduated_year' => 2012,
                    'document' => $this->paper('Диплом бакалавра, пересканированный.pdf'),
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertNotSame($was, $row->document_path);
        // The name is the file's own, which is the name somebody typed over it
        // in the form before sending it.
        $this->assertSame('Диплом бакалавра, пересканированный.pdf', $row->document_name);

        // The one it replaced is off the disk and the new one is on it, so the
        // folder holds one paper rather than two.
        Storage::disk('documents')->assertMissing($was);
        Storage::disk('documents')->assertExists($row->document_path);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    /**
     * The jobs held before are kept the same way, for the row's own sake.
     *
     * There is no document at stake here, only the identity of the record:
     * rewriting the list from scratch on every save churns the table and files
     * the jobs again in whatever order the form happened to hold them.
     */
    public function test_a_past_job_sent_back_by_its_id_keeps_its_row()
    {
        $employee = $this->blankCard();
        $kept = UserWorkExperience::factory()->for($employee)->create(['organization' => 'ООО «Ориён»']);
        $removed = UserWorkExperience::factory()->for($employee)->create(['organization' => 'ООО «Шифобахш»']);

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $this->sheet([
                'experiences' => [[
                    'id' => $kept->id,
                    'organization' => 'ООО «Ориён»',
                    'position' => 'Старший аналитик',
                    'started_month' => 3,
                    'started_year' => 2013,
                    'ended_month' => 8,
                    'ended_year' => 2020,
                    'reason' => 'По собственному желанию',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Старший аналитик', $kept->fresh()->position);
        $this->assertNull($removed->fresh());
        $this->assertSame([$kept->id], $employee->refresh()->workExperiences->pluck('id')->all());
    }

    /**
     * A list with nothing in it cannot reach the server at all, so a list that
     * did not arrive is an empty list.
     *
     * A form field is a name and a value; a list with no values appends no
     * field, and the name never comes. Over plain form encoding nothing noticed
     * — but the moment a paper is attached anywhere on the sheet the save goes
     * multipart, and every empty list goes missing with it. A colleague with no
     * relatives could not be given a diploma, which is a refusal of a save that
     * was correct.
     */
    public function test_an_empty_list_a_multipart_save_could_not_send_is_read_as_empty()
    {
        Storage::fake('documents');

        $employee = $this->blankCard();
        UserRelative::factory()->for($employee)->create(['relation' => 'Отец']);
        UserChild::factory()->for($employee)->create();
        $employee->details()->update(['has_children' => true]);

        // The sheet as a browser sends it with a document attached and five of
        // its lists empty: those five appended nothing, so their names are not
        // in the request at all. The paper that makes it multipart hangs off a
        // record of one of the three lists, which is where every paper of this
        // sheet hangs now.
        $payload = $this->sheet([
            'degrees' => [[
                'name' => 'кандидат экономических наук, доцент',
                'document' => $this->paper('Аттестат доцента.pdf'),
            ]],
            'has_children' => false,
            'has_experience' => false,
            'has_awards' => false,
            'has_military_records' => false,
        ]);
        unset(
            $payload['children'],
            $payload['relatives'],
            $payload['experiences'],
            $payload['awards'],
            $payload['military_records'],
        );

        $this->actingAs($this->sysadmin())
            ->put("/employees/{$employee->id}/sheet", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Saved — and the lists that could not be sent are written as empty
        // rather than left as they stood, because the sheet shows all eight at
        // once and this save spoke for every one of them.
        $this->assertSame(0, $employee->children()->count());
        $this->assertSame(0, $employee->relatives()->count());
        $this->assertSame(0, $employee->workExperiences()->count());
        $this->assertSame(0, $employee->awards()->count());
        $this->assertSame(0, $employee->militaryRecords()->count());
        $this->assertFalse($employee->refresh()->details->has_children);
        $this->assertFalse($employee->details->has_experience);
        $this->assertFalse($employee->details->has_awards);
        $this->assertFalse($employee->details->has_military_records);

        // The paper that made the save multipart in the first place is on file.
        Storage::disk('documents')->assertExists($employee->degrees->sole()->document_path);
    }

    /**
     * The sheet's four free lines are offered what the other cards say on them.
     *
     * A diploma may name something no list has heard of, and a line somebody
     * writes a degree or a medal on is free — so these are suggestions and not
     * a choice: the box takes whatever is typed either way. Three of the four
     * are read off the records of other people's cards, which are as private as
     * the line they used to be, so a viewer who may not read the block is
     * offered nothing for it.
     */
    public function test_the_card_offers_what_other_cards_say_on_the_sheets_free_lines()
    {
        $other = $this->blankCard();
        $other->degrees()->create(['name' => 'кандидат экономических наук']);
        $other->awards()->create(['name' => 'Медаль „Хизмати шоиста“']);
        $other->militaryRecords()->create(['name' => 'Военнообязанный, старший лейтенант запаса']);
        UserEducation::factory()->for($other)->create(['qualification' => 'провизор']);

        $employee = $this->blankCard();

        $this->actingAs($this->sysadmin())
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.degrees', ['кандидат экономических наук'])
                ->where('options.awards', ['Медаль „Хизмати шоиста“'])
                ->where('options.militaries', ['Военнообязанный, старший лейтенант запаса'])
                ->where('options.qualifications', fn ($offered) => collect($offered)->contains('провизор')
                    && collect($offered)->contains('бакалавр'))
            );

        // Somebody who edits the sheet but may read none of those four lines on
        // a colleague's card is offered nothing for them — and still gets the
        // half-dozen qualifications that are nobody's data.
        $this->actingAs($this->editor('phone'))
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('options.degrees', [])
                ->where('options.awards', [])
                ->where('options.militaries', [])
                ->where('options.qualifications', fn ($offered) => collect($offered)->doesntContain('провизор')
                    && collect($offered)->contains('бакалавр'))
            );
    }

    /**
     * One's own sheet is weighed against the rights for one's own card, which
     * are a different set from the rights over everybody else's.
     *
     * Somebody who may retype their own telephone number has no business
     * retyping their own passport, and the two questions are not the same one.
     */
    public function test_ones_own_sheet_is_weighed_against_the_rights_over_ones_own_card()
    {
        $role = Role::create(['name' => 'self', 'title' => 'Сам о себе', 'guard_name' => 'web']);
        $role->syncPermissions([
            EmployeeFields::permission('phone', EmployeeFields::OWN),
            EmployeeFields::editPermission('phone', EmployeeFields::OWN),
            EmployeeFields::permission('passport_number', EmployeeFields::OWN),
        ]);

        $employee = $this->blankCard()->assignRole($role);
        $employee->details()->update(['passport_number' => '7654321']);

        $this->actingAs($employee)
            ->put("/employees/{$employee->id}/sheet", $this->sheet())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $employee->refresh();
        $this->assertSame('+992901112233', $employee->details->phone);
        // The passport they may read and not retype, and the name they were
        // never given either right over.
        $this->assertSame('7654321', $employee->details->passport_number);
        $this->assertSame('Рахимова', $employee->surname);
    }
}
