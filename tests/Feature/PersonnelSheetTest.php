<?php

namespace Tests\Feature;

use App\Models\Citizenship;
use App\Models\Language;
use App\Models\User;
use App\Models\UserChild;
use App\Models\UserEducation;
use App\Models\UserRelative;
use App\Models\UserWorkExperience;
use App\Support\EmployeeCard;
use App\Support\EmployeeFields;
use App\Support\PersonnelSheet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * How far a card has got through «Личный листок по учёту кадров».
 *
 * The figure is answers and not rows: the department reads it to decide whom to
 * go and ask, so a card that says «нет» three times is further along than one
 * nobody has asked, and a card naming three universities is no further along
 * than one naming a single university. The lines that have no right answer —
 * the languages, the rest of the family — are not on the sheet at all.
 *
 * And it is counted over the lines the reader may read. A percentage taken over
 * the whole card would be a statement about the lines somebody is not allowed to
 * see, and the list of what is still wanted would name them outright.
 */
class PersonnelSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * The one account that reads every line, whatever the rights say: most of
     * these tests are about the counting rather than about who may count.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /**
     * Somebody whose position reads the staff list and exactly these lines of a
     * colleague's card, as FieldVisibilityTest builds one.
     */
    private function reader(string ...$fields): User
    {
        $role = Role::create(['name' => 'reader-'.Role::count(), 'title' => 'Читатель '.Role::count(), 'guard_name' => 'web']);
        $role->syncPermissions(['employees.view', ...array_map(EmployeeFields::permission(...), $fields)]);

        return User::factory()->create()->assignRole($role);
    }

    /**
     * A card with nothing on it but the name, which is the one thing a card
     * cannot be without.
     *
     * Not the factory's card: that one arrives with a birthplace, a passport and
     * two telephone numbers already on it, and these tests are about what an
     * answer counts as rather than about what a factory invents.
     */
    private function blankCard(): User
    {
        $employee = User::factory()->create(['patronymic' => null, 'sex' => null]);
        $employee->details()->create([]);

        return $employee;
    }

    /** The same card with every line of the sheet answered. */
    private function fullCard(): User
    {
        $employee = User::factory()->create(['patronymic' => 'Саидович', 'sex' => 'male']);
        $employee->details()->create([
            'birth_date' => '1990-04-17',
            'birth_place' => 'г. Худжанд',
            'nationality' => 'таджик',
            'degree' => 'кандидат экономических наук, доцент',
            'award' => 'Медаль „Хизмати шоиста“',
            'military' => 'Военнообязанный, старший лейтенант запаса',
            'has_educations' => true,
            'has_experience' => true,
            'marital_status' => 'married',
            'has_children' => true,
            'passport_series' => 'А',
            'passport_number' => '1234567',
            'passport_issued_at' => '2020-01-01',
            'passport_issued_by' => 'МВД Республики Таджикистан',
            'tax_number' => '123456789',
            'home_address' => 'г. Душанбе, пр. Рудаки 55',
            'phone' => '+992901112233',
            'sos_phone' => '+992902223344',
        ]);
        $employee->citizenships()->attach(Citizenship::idsFor(['Таджикистан']));

        return $employee;
    }

    /**
     * The twenty-two lines the sheet counts, written out rather than read back
     * out of the constant.
     *
     * Reading the constant would only ask the code whether it agrees with
     * itself. These are the lines a personnel officer goes down, and a line that
     * quietly left the list — or joined it — changes what every figure on every
     * card means.
     */
    public function test_the_sheet_counts_these_lines_and_no_others()
    {
        $this->assertSame([
            'surname' => 'Фамилия',
            'name' => 'Имя',
            'patronymic' => 'Отчество',
            'sex' => 'Пол',
            'birth_date' => 'Дата рождения',
            'birth_place' => 'Место рождения',
            'nationality' => 'Национальность',
            'citizenship' => 'Гражданство',
            'degree' => 'Учёная степень, звание',
            'award' => 'Государственные награды, поощрения',
            'military' => 'Отношение к воинской обязанности',
            'educations' => 'Образование',
            'work_experiences' => 'Трудовая деятельность',
            'marital_status' => 'Семейное положение',
            'children' => 'Дети',
            'passport_number' => 'Серия и номер паспорта',
            'passport_issued_at' => 'Дата выдачи паспорта',
            'passport_issued_by' => 'Кем выдан паспорт',
            'tax_number' => 'ИНН',
            'home_address' => 'Домашний адрес',
            'phone' => 'Телефон',
            'sos_phone' => 'Экстренный контакт',
        ], PersonnelSheet::LINES);

        // Every line is a field of the card, which is what makes it countable:
        // a line of the paper with nothing behind it is one the card cannot
        // answer, and it would count as missing on every card for ever.
        $this->assertSame([], array_diff(array_keys(PersonnelSheet::LINES), EmployeeFields::keys()));
    }

    public function test_an_empty_card_has_answered_nothing_but_its_name_and_a_full_one_is_finished()
    {
        $admin = $this->sysadmin();

        // Two of the twenty-two: a card cannot exist without a name, so that is
        // the floor rather than nought.
        $this->assertSame(9, PersonnelSheet::filled($this->blankCard(), $admin));
        $this->assertSame([
            'Отчество',
            'Пол',
            'Дата рождения',
            'Место рождения',
            'Национальность',
            'Гражданство',
            'Учёная степень, звание',
            'Государственные награды, поощрения',
            'Отношение к воинской обязанности',
            'Образование',
            'Трудовая деятельность',
            'Семейное положение',
            'Дети',
            'Серия и номер паспорта',
            'Дата выдачи паспорта',
            'Кем выдан паспорт',
            'ИНН',
            'Домашний адрес',
            'Телефон',
            'Экстренный контакт',
        ], PersonnelSheet::missing($this->blankCard(), $admin));

        $full = $this->fullCard();
        $this->assertSame(100, PersonnelSheet::filled($full, $admin));
        $this->assertSame([], PersonnelSheet::missing($full, $admin));
    }

    /**
     * «Нет» is an answer, and an empty line is the absence of one.
     *
     * The three lines a card keeps as free text — the degree, the awards, the
     * military papers — are each one line of the sheet, and most people have
     * nothing to put in any of them. Somebody has to be able to say so, or those
     * cards would never finish and the department would go on asking.
     */
    public function test_no_counts_as_an_answer_on_a_line_while_an_empty_line_does_not()
    {
        $admin = $this->sysadmin();
        $employee = $this->blankCard();

        $this->assertSame(9, PersonnelSheet::filled($employee, $admin));
        $this->assertContains('Учёная степень, звание', PersonnelSheet::missing($employee, $admin));

        $employee->details()->update([
            'degree' => EmployeeCard::NONE,
            'award' => EmployeeCard::NONE,
            'military' => EmployeeCard::NONE,
        ]);

        // Five of the twenty-two now, and the department has nothing to ask
        // about these three.
        $employee = $employee->fresh();
        $this->assertSame(23, PersonnelSheet::filled($employee, $admin));
        $this->assertSame([], array_intersect(
            ['Учёная степень, звание', 'Государственные награды, поощрения', 'Отношение к воинской обязанности'],
            PersonnelSheet::missing($employee, $admin),
        ));
    }

    /**
     * The three lists are answered by their flag and not by their rows.
     *
     * Rows arrive without anybody having been asked — a card filled in before
     * the question existed carries them — so the rows alone cannot be the
     * answer; and «нет» has no row to arrive as, so the flag alone can.
     */
    public function test_the_three_lists_are_answered_by_their_flag_and_not_by_their_rows()
    {
        $admin = $this->sysadmin();

        // Rows on file and nobody asked: the three lines are still wanted.
        $unasked = $this->blankCard();
        UserEducation::factory(2)->for($unasked)->create();
        UserWorkExperience::factory(2)->for($unasked)->create();
        UserChild::factory(2)->for($unasked)->create();

        $this->assertSame(9, PersonnelSheet::filled($unasked, $admin));
        $this->assertSame(
            ['Образование', 'Трудовая деятельность', 'Дети'],
            array_values(array_intersect(
                PersonnelSheet::missing($unasked, $admin),
                ['Образование', 'Трудовая деятельность', 'Дети'],
            )),
        );

        // Asked and answered «нет», with no row anywhere: all three are done.
        $asked = $this->blankCard();
        $asked->details()->update(['has_educations' => false, 'has_experience' => false, 'has_children' => false]);

        $asked = $asked->fresh();
        $this->assertSame(23, PersonnelSheet::filled($asked, $admin));
        $this->assertSame([], array_intersect(
            ['Образование', 'Трудовая деятельность', 'Дети'],
            PersonnelSheet::missing($asked, $admin),
        ));
    }

    /**
     * Three universities count the same as one.
     *
     * How many places a person studied at is not a measure of how far the sheet
     * has got; whether anybody asked them is. A figure that counted rows would
     * tell the department to go back to the card with the fullest answer.
     */
    public function test_a_card_with_three_universities_counts_the_same_as_one_with_a_single_university()
    {
        $admin = $this->sysadmin();

        $one = $this->blankCard();
        $one->details()->update(['has_educations' => true]);
        UserEducation::factory()->for($one)->create();

        $three = $this->blankCard();
        $three->details()->update(['has_educations' => true]);
        UserEducation::factory(3)->for($three)->create();

        $this->assertSame(
            PersonnelSheet::filled($one->fresh(), $admin),
            PersonnelSheet::filled($three->fresh(), $admin),
        );
    }

    /**
     * The languages and the rest of the family are not counted at all.
     *
     * There is no number of either that is right, so a card naming one language
     * would otherwise read as finished while a card naming three read the same,
     * and neither figure would mean anything.
     */
    public function test_the_languages_and_the_relatives_are_not_counted_whatever_they_hold()
    {
        $admin = $this->sysadmin();

        $bare = $this->blankCard();
        $before = PersonnelSheet::filled($bare, $admin);
        $wanted = PersonnelSheet::missing($bare, $admin);

        $spoken = $this->blankCard();
        $spoken->languages()->attach(Language::create(['name' => 'Английский']), ['level' => 'advanced']);
        $spoken->languages()->attach(Language::create(['name' => 'Русский']), ['level' => 'advanced']);
        UserRelative::factory(2)->for($spoken)->create();

        $this->assertSame($before, PersonnelSheet::filled($spoken->fresh(), $admin));
        $this->assertSame($wanted, PersonnelSheet::missing($spoken->fresh(), $admin));

        // And neither is named as wanted on the card that holds none of them.
        $this->assertSame([], array_intersect(['Языки и уровень', 'Родство'], $wanted));

        // A full card stays finished with neither a language nor a relative on
        // it, which is the other half of the same thing.
        $this->assertSame(100, PersonnelSheet::filled($this->fullCard(), $admin));
    }

    /**
     * Counted over the lines this reader may read, and no others.
     *
     * The card below has almost nothing on it, so the figure a reader of the
     * whole sheet would see is nothing like the figure a reader of four lines
     * sees — and that is the point: «67%» here is a statement about those four
     * lines, not a hint about the eighteen behind them.
     */
    public function test_the_count_is_taken_over_the_lines_the_viewer_may_read()
    {
        $employee = $this->blankCard();
        $employee->details()->update(['birth_date' => '1990-04-17', 'phone' => '+992901112233']);
        $employee = $employee->fresh();

        // Two of the four lines are answered, and the name is read by everybody,
        // so six lines are counted and four of them say something.
        $reader = $this->reader('birth_date', 'degree', 'tax_number', 'phone');

        $this->assertSame(67, PersonnelSheet::filled($employee, $reader));

        // Exactly the two unanswered lines of the four, in the order the sheet
        // asks them — and not one of the lines this reader may not read, which
        // naming would tell them what is on a card they cannot open.
        $this->assertSame(
            ['Учёная степень, звание', 'ИНН'],
            PersonnelSheet::missing($employee, $reader),
        );

        // The same card, read whole, is a different figure altogether.
        $this->assertSame(18, PersonnelSheet::filled($employee, $this->sysadmin()));
    }

    /**
     * A reader of no line of the sheet is told that there is nothing wanted
     * rather than that nothing is filled in.
     *
     * Nought would read as a card nobody has touched, which is a statement about
     * lines this reader may not see.
     *
     * The floor is the name. The surname and the name are the two lines no
     * position can be closed to — EmployeeFields::ALWAYS_VISIBLE — and both are
     * lines of the sheet, so there is always something to count and the hundred
     * PersonnelSheet returns for nothing at all is reached this way rather than
     * by counting nothing.
     */
    public function test_a_reader_of_nothing_but_the_name_is_told_the_sheet_is_done()
    {
        $employee = $this->blankCard();

        $this->assertSame(100, PersonnelSheet::filled($employee, $this->reader()));
        $this->assertSame([], PersonnelSheet::missing($employee, $this->reader()));
    }

    /**
     * The card page carries the figure and the list, so «Личный листок» can show
     * what is still wanted without working it out in the browser.
     */
    public function test_the_card_page_carries_how_much_is_answered_and_what_is_still_wanted()
    {
        $employee = $this->blankCard();
        $employee->details()->update(['birth_date' => '1990-04-17', 'phone' => '+992901112233']);

        $this->actingAs($this->sysadmin())
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheet.filled', 18)
                ->where('sheet.missing', fn ($missing) => $missing->contains('ИНН')
                    && $missing->doesntContain('Телефон')
                    && $missing->doesntContain('Дата рождения'))
            );

        // And a reader of four lines is handed the figure for those four.
        $this->actingAs($this->reader('birth_date', 'degree', 'tax_number', 'phone'))
            ->get("/employees/{$employee->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheet.filled', 67)
                ->where('sheet.missing', ['Учёная степень, звание', 'ИНН'])
            );
    }
}
