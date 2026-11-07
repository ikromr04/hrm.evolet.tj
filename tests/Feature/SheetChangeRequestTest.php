<?php

namespace Tests\Feature;

use App\Models\SheetChangeRequest;
use App\Models\User;
use App\Models\UserLanguage;
use App\Support\SheetDiff;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * «Личный листок» handed in by the person it is about, and answered by the
 * office.
 *
 * A head of department and a specialist are shown their own card and may write
 * nothing on it but the papers they hand in — an address used to be changed by
 * ringing the office and reading it out. They now retype the sheet themselves,
 * and what they press is «Отправить»: a row is filed, personnel read the sheet
 * beside what is on file, and the card is written only when one of them accepts
 * it. The right to change a line and the right to ask for it to be changed are
 * two different things, and this is the second one.
 *
 * So the first thing worth pinning is the one the whole door rests on — that
 * sending writes nothing at all, and that the new door did not quietly open the
 * old one: the same person pressing the same form at «employees/{employee}/
 * sheet» is still refused. The second is that accepting writes the sheet
 * exactly as a save writes it, papers and all, since a request accepted three
 * weeks after it was typed is accepted by hands that cannot retype it. The
 * third is what a refusal costs: the card untouched and the uploaded papers off
 * the disk, because a diploma nothing points at is a scan of somebody's name
 * and date of birth lying in a folder nobody will come looking in again.
 */
class SheetChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
    }

    /** The account that passes every check: one of the two that answer these. */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /** And the other one, who does this work all day. */
    private function personnel(): User
    {
        return User::factory()->create()->assignRole('hr');
    }

    /**
     * Somebody who sends sheets in: shown their own card, allowed to write
     * nothing on it, and holding no right the gates have ever heard of.
     *
     * Both roles that are in that position are tried below wherever the
     * question is about the door rather than about the sheet, because they are
     * one case twice over — Access::ownCardOnly() names the pair of them and
     * nothing anywhere asks which of the two is knocking.
     */
    private function author(string $role = 'specialist'): User
    {
        return $this->blankCard()->assignRole($role);
    }

    /**
     * A card with the row the sheet lives on already filed and nothing in it.
     *
     * Not the factory's row: that one arrives with a birthplace, a passport and
     * two telephone numbers on it, and half the assertions below are about a
     * card a sending did not touch. The name is fixed for the same reason.
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
     * The sheet as the card's own form sends it: the eleven blocks at once,
     * under the names «Новый сотрудник» gives them.
     *
     * The same shape a direct save is sent in, and deliberately the same
     * fixture: the request is validated by the save's own rules, so a sheet
     * that saves is a sheet that sends and the two must not drift apart in the
     * suites either.
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
     * The sheet with a paper on a record of each of the four lists that keep
     * one, as a browser sends it the moment anything is attached.
     *
     * @return array<string, mixed>
     */
    private function papered(): array
    {
        return [
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
        ];
    }

    /**
     * One sheet sent in by its own owner, through the one address they have for
     * it, and the row it was filed as.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sent(User $author, array $overrides = []): SheetChangeRequest
    {
        $this->actingAs($author)
            ->post('/profile/sheet/request', $this->sheet($overrides))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return SheetChangeRequest::query()->where('user_id', $author->id)->latest('id')->firstOrFail();
    }

    /** Whatever is on this card of the eight lists the sheet edits whole. */
    private function listed(User $employee): array
    {
        return [
            'educations' => $employee->educations()->count(),
            'degrees' => $employee->degrees()->count(),
            'awards' => $employee->awards()->count(),
            'military_records' => $employee->militaryRecords()->count(),
            'languages' => $employee->languages()->count(),
            'experiences' => $employee->workExperiences()->count(),
            'children' => $employee->children()->count(),
            'relatives' => $employee->relatives()->count(),
        ];
    }

    /**
     * «Отправить» writes nothing whatever onto the card, and the row it files
     * carries the sheet entire.
     *
     * This is the whole promise of the door, and both halves of it are load
     * bearing. Nothing written, because the card is what the company's papers
     * are made out from and a figure nobody checked is a figure that turns up
     * wrong on a document somebody has to sign. The sheet entire, because the
     * office is shown it beside the card line by line and then accepts all of
     * it: a row carrying only the lines a form claimed to have touched would be
     * taking the form's word for what changed, and would leave the other
     * twenty-odd lines to be written weeks later from nowhere at all.
     */
    public function test_a_sheet_sent_in_writes_nothing_to_the_card_and_is_filed_whole()
    {
        $author = $this->author();

        $filed = $this->sent($author);

        // The card is the card it was: the name, the row beside it, every list.
        $author->refresh();
        $this->assertSame('Рахимова', $author->surname);
        $this->assertSame('Зарина', $author->name);
        $this->assertNull($author->patronymic);
        $this->assertNull($author->sex);
        $this->assertNull($author->details->birth_date);
        $this->assertNull($author->details->passport_number);
        $this->assertNull($author->details->phone);
        $this->assertNull($author->details->marital_status);
        $this->assertNull($author->details->has_degrees);
        $this->assertSame([], $author->citizenships->pluck('name')->all());
        $this->assertSame(array_fill_keys(array_keys($this->listed($author)), 0), $this->listed($author));

        // And the sheet is in the row instead, waiting, with nobody's answer on
        // it yet.
        $this->assertSame($author->id, $filed->user_id);
        $this->assertSame(SheetChangeRequest::PENDING, $filed->status);
        $this->assertNull($filed->comment);
        $this->assertNull($filed->reviewed_by);
        $this->assertNull($filed->reviewed_at);

        // Every line the form sent is in the payload — asked as a difference of
        // keys rather than line by line, because the point is that none of them
        // is missing and a test naming twenty-five of them would go on passing
        // when the twenty-sixth was added.
        $this->assertSame([], array_diff(array_keys($this->sheet()), array_keys($filed->payload)));
        $this->assertSame('Азимова', $filed->payload['surname']);
        $this->assertSame('Рустамовна', $filed->payload['patronymic']);
        $this->assertSame('female', $filed->payload['sex']);
        $this->assertSame('1990-04-17', $filed->payload['birth_date']);
        $this->assertSame(['Таджикистан'], $filed->payload['citizenship']);
        $this->assertSame('1234567', $filed->payload['passport_number']);
        $this->assertSame('123456789', $filed->payload['tax_number']);
        // Tidied on the way in exactly as a save tidies it, so what is accepted
        // weeks later is what a save would have written on the day.
        $this->assertSame('+992901112233', $filed->payload['phone']);
        $this->assertSame('кандидат экономических наук, доцент', $filed->payload['degrees'][0]['name']);
        $this->assertSame('ТНУ', $filed->payload['educations'][0]['institution']);
        $this->assertSame(['Мать', 'Брат'], array_column($filed->payload['relatives'], 'relation'));
        $this->assertTrue($filed->payload['has_children']);

        // No files came with this one, so the request brought nothing onto the
        // disk and says so.
        $this->assertSame([], $filed->papers);
    }

    /**
     * And the same person is still refused the direct save, both of the roles
     * that may send.
     *
     * The new door must not have opened the old one. Access::allowedOnOwnCard()
     * gives these two every line of their own card to read and exactly one to
     * write — the documents they hand in — so «employees/{employee}/sheet» has
     * no line of the sheet to offer them and refuses the request outright. If
     * that ever came to pass, the whole feature would be theatre: the office
     * would be reading requests for changes the author could have written
     * themselves.
     */
    public function test_the_sender_of_a_sheet_still_cannot_save_one_onto_the_card()
    {
        foreach (['specialist', 'head'] as $role) {
            $author = $this->author($role);

            $this->actingAs($author)
                ->put("/employees/{$author->id}/sheet", $this->sheet())
                ->assertForbidden();

            $this->assertSame('Рахимова', $author->refresh()->surname);
            $this->assertNull($author->details->birth_date);
            $this->assertSame(0, $author->educations()->count());

            // The sending is theirs all the same, which is what makes the
            // refusal above a line drawn rather than a role locked out.
            $this->sent($author);
        }

        $this->assertSame(2, SheetChangeRequest::query()->count());
    }

    /**
     * A request is read by the office and by the person whose sheet it is, and
     * answered by the office alone.
     *
     * Three rules in one, because they are one door with three handles. The
     * author reads their own because the card links to it — what they sent and
     * what was said about it should be a page and not a sentence under a
     * button — and may not answer it, since a sheet one accepts oneself is a
     * sheet nobody checked. Everybody else is simply not in it: a request holds
     * a colleague's passport, their family and their address, and the one list
     * of people who may read that is the list of people who may read the card.
     */
    public function test_a_request_is_read_by_the_office_and_its_author_and_answered_by_the_office_alone()
    {
        $author = $this->author();
        $filed = $this->sent($author);

        // Neither of these two is the office: one holds no right at all, the
        // other passes gates all day and still has no business here.
        foreach ([$this->author(), $this->colleague()] as $stranger) {
            $this->actingAs($stranger)->get("/sheet-requests/{$filed->id}")->assertForbidden();
            $this->actingAs($stranger)->post("/sheet-requests/{$filed->id}/accept")->assertForbidden();
            $this->actingAs($stranger)
                ->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Не вам решать.'])
                ->assertForbidden();
        }

        // The author reads their own and is told, in so many words, that there
        // is nothing for them to press.
        $this->actingAs($author)
            ->get("/sheet-requests/{$filed->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sheet-requests/show')
                ->where('request.id', $filed->id)
                ->where('request.status', SheetChangeRequest::PENDING)
                ->where('canReview', false)
            );

        $this->actingAs($author)->post("/sheet-requests/{$filed->id}/accept")->assertForbidden();
        $this->actingAs($author)
            ->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Сам себе отказываю.'])
            ->assertForbidden();

        // Both halves of the office read it, and both are offered the two
        // buttons.
        foreach ([$this->sysadmin(), $this->personnel()] as $officer) {
            $this->actingAs($officer)
                ->get("/sheet-requests/{$filed->id}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('sheet-requests/show')
                    ->where('canReview', true)
                    ->where('request.employee.id', $author->id)
                );
        }

        // Nothing any of that did reached the row or the card.
        $this->assertSame(SheetChangeRequest::PENDING, $filed->refresh()->status);
        $this->assertNull($filed->reviewed_by);
        $this->assertSame('Рахимова', $author->refresh()->surname);
    }

    /**
     * And a stranger to the application is sent to sign in first, at all four
     * addresses.
     *
     * A test of its own because a signed-in request cannot be unsigned: these
     * doors answer «кто вы» before they answer anything else, and that is the
     * one question the tests above never ask.
     */
    public function test_none_of_it_is_open_to_a_guest()
    {
        $author = $this->author();
        $filed = $this->sent($author);

        $this->app['auth']->forgetGuards();

        $this->post('/profile/sheet/request', $this->sheet())->assertRedirect('/login');
        $this->get("/sheet-requests/{$filed->id}")->assertRedirect('/login');
        $this->post("/sheet-requests/{$filed->id}/accept")->assertRedirect('/login');
        $this->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Нет.'])->assertRedirect('/login');

        $this->assertSame(SheetChangeRequest::PENDING, $filed->refresh()->status);
        $this->assertSame(1, SheetChangeRequest::query()->count());
        $this->assertSame('Рахимова', $author->refresh()->surname);
    }

    /**
     * Accepted, the sheet is written onto the card exactly as a save writes it
     * — every one of the eleven blocks, and the papers with them.
     *
     * The one thing a request must not be is a second way of writing a card. A
     * sheet is accepted days or weeks after it was typed, by somebody who did
     * not type it and cannot retype it, so what is written has to be the whole
     * of what was sent and has to be written by the very code a direct save
     * goes through — see App\Support\SheetWriter, which exists for no other
     * reason. A block this door wrote differently would be a block that is
     * right on one of the two paths and nobody would know which.
     *
     * The papers are the part worth pinning hardest. They went to the disk the
     * moment they were uploaded — a browser cannot be asked to hold a scanned
     * diploma for three days — so what acceptance writes is a record naming a
     * file that is already there, and the card has to end up able to hand that
     * file over.
     */
    public function test_accepting_writes_every_block_of_the_sheet_onto_the_card()
    {
        Storage::fake('documents');

        $author = $this->author();
        $filed = $this->sent($author, $this->papered());
        $officer = $this->sysadmin();

        // Four papers on the disk before anybody decided anything, and none of
        // them on the card.
        $this->assertCount(4, $filed->papers);
        $this->assertCount(4, Storage::disk('documents')->allFiles());

        $this->actingAs($officer)
            ->post("/sheet-requests/{$filed->id}/accept")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $author->refresh();
        $details = $author->details;

        // Основное: the lines on the account, and the lines beside it.
        $this->assertSame('Азимова', $author->surname);
        $this->assertSame('Нилуфар', $author->name);
        $this->assertSame('Рустамовна', $author->patronymic);
        $this->assertSame('female', $author->sex);
        $this->assertSame('1990-04-17', $details->birth_date->toDateString());
        $this->assertSame('г. Худжанд', $details->birth_place);
        $this->assertSame('таджичка', $details->nationality);
        $this->assertSame(['Таджикистан'], $author->citizenships->pluck('name')->all());

        // The three lists that are a line of text and one paper apiece, each
        // with the answer that says there are any.
        $this->assertTrue($details->has_degrees);
        $this->assertSame(['кандидат экономических наук, доцент'], $author->degrees->pluck('name')->all());
        $this->assertTrue($details->has_awards);
        $this->assertSame(['Медаль „Хизмати шоиста“'], $author->awards->pluck('name')->all());
        $this->assertTrue($details->has_military_records);
        $this->assertSame(
            ['Военнообязанный, старший лейтенант запаса'],
            $author->militaryRecords->pluck('name')->all(),
        );

        // Образование, Знание языков, Трудовая деятельность.
        $this->assertTrue($details->has_educations);
        $this->assertSame('ТНУ', $author->educations->first()->institution);
        $this->assertSame(2012, $author->educations->first()->graduated_year);
        $this->assertSame(['Английский'], $author->languages->pluck('name')->all());
        $this->assertSame(UserLanguage::LEVELS[0], $author->languages->first()->level);
        $this->assertTrue($details->has_experience);
        $this->assertSame('ООО «Ориён»', $author->workExperiences->first()->organization);
        $this->assertSame('По собственному желанию', $author->workExperiences->first()->reason);

        // Семья: the status, the spouse, the children and the rest of them.
        $this->assertSame('married', $details->marital_status);
        $this->assertSame('Азимов Рустам', $details->spouse_name);
        $this->assertSame('1988-02-01', $details->spouse_birth_date->toDateString());
        $this->assertSame('Инженер, ООО «Ориён»', $details->spouse_occupation);
        $this->assertTrue($details->has_children);
        $this->assertSame(['Азимова Сабина'], $author->children->pluck('full_name')->all());
        $this->assertSame('3 класс, СОШ № 12', $author->children->first()->occupation);
        $this->assertSame(['Мать', 'Брат'], $author->relatives->pluck('relation')->all());

        // Паспорт, ИНН, Контакты — the telephones in E.164, as every form of
        // this card keeps them.
        $this->assertSame('A', $details->passport_series);
        $this->assertSame('1234567', $details->passport_number);
        $this->assertSame('2020-01-01', $details->passport_issued_at->toDateString());
        $this->assertSame('ОВД района Фирдавси', $details->passport_issued_by);
        $this->assertSame('123456789', $details->tax_number);
        $this->assertSame('г. Душанбе, пр. Рудаки 55', $details->home_address);
        $this->assertSame('+992901112233', $details->phone);
        $this->assertSame('+992902223344', $details->sos_phone);
        $this->assertSame('Мама', $details->sos_contact);

        // Each of the four papers is on the record that named it, under the
        // name the person gave it, and still on the disk — the request's files
        // are not copied anywhere on acceptance, they are what the card now
        // points at.
        foreach ([
            'degrees' => 'Аттестат доцента.pdf',
            'awards' => 'Указ.pdf',
            'militaryRecords' => 'Военный билет.pdf',
        ] as $list => $name) {
            $record = $author->{$list}->sole();
            $this->assertSame($name, $record->document_name);
            Storage::disk('documents')->assertExists($record->document_path);
        }

        $education = $author->educations->sole();
        $this->assertSame('Диплом бакалавра.pdf', $education->document_name);
        Storage::disk('documents')->assertExists($education->document_path);
        $this->assertCount(4, Storage::disk('documents')->allFiles());

        // And the card hands one over when it is asked to, which is the thing
        // the whole arrangement is for: a record that names a file the card
        // cannot open is a record of nothing.
        $handed = $this->actingAs($officer)
            ->get("/employees/{$author->id}/degrees/{$author->degrees->sole()->id}/document")
            ->assertOk();

        $this->assertStringContainsString(
            rawurlencode('Аттестат доцента.pdf'),
            (string) $handed->headers->get('content-disposition'),
        );

        // The row is answered, and says by whom and when.
        $filed->refresh();
        $this->assertSame(SheetChangeRequest::ACCEPTED, $filed->status);
        $this->assertSame($officer->id, $filed->reviewed_by);
        $this->assertNotNull($filed->reviewed_at);
        $this->assertNull($filed->comment);
    }

    /**
     * Refused, nothing of the card moves and the papers the request brought
     * come off the disk — and the reason is insisted on.
     *
     * The reason first, because without it a refusal is useless: the author is
     * left with the same sheet, the same mistake in it and nobody to ask, and
     * the next thing they do is send the very same request again.
     *
     * The papers second, and this is the half that would never be noticed if it
     * broke. They were uploaded for a request nothing will ever point at — the
     * card does not know them and never will — so a scan of somebody's diploma
     * would sit in a folder for as long as the installation lasts, which is the
     * sort of thing nobody finds out about until a backup is restored somewhere
     * it should not have been.
     */
    public function test_a_refusal_needs_a_reason_leaves_the_card_alone_and_takes_the_papers_off_the_disk()
    {
        Storage::fake('documents');

        $author = $this->author();
        $filed = $this->sent($author, $this->papered());
        $officer = $this->personnel();

        // Sent back with nothing said about it: refused in words, and nothing
        // of the request touched.
        $this->actingAs($officer)
            ->post("/sheet-requests/{$filed->id}/reject")
            ->assertSessionHasErrors(['comment' => 'Напишите, что нужно исправить.']);

        $this->assertSame(SheetChangeRequest::PENDING, $filed->refresh()->status);
        $this->assertCount(4, Storage::disk('documents')->allFiles());

        $this->actingAs($officer)
            ->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Серия паспорта не та — проверьте и отправьте снова.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $filed->refresh();
        $this->assertSame(SheetChangeRequest::REJECTED, $filed->status);
        $this->assertSame('Серия паспорта не та — проверьте и отправьте снова.', $filed->comment);
        $this->assertSame($officer->id, $filed->reviewed_by);
        $this->assertNotNull($filed->reviewed_at);

        // The card is the card it was.
        $author->refresh();
        $this->assertSame('Рахимова', $author->surname);
        $this->assertNull($author->details->birth_date);
        $this->assertNull($author->details->passport_number);
        $this->assertSame(array_fill_keys(array_keys($this->listed($author)), 0), $this->listed($author));

        // And not one of the four papers is left lying about.
        foreach ($filed->papers as $path) {
            Storage::disk('documents')->assertMissing($path);
        }

        $this->assertCount(0, Storage::disk('documents')->allFiles());

        // The row itself stays to be read: the author is shown the refusal and
        // the reason for it on their own card, which is where the next request
        // is sent from.
        $this->assertSame(1, SheetChangeRequest::query()->count());
    }

    /**
     * A second «Отправить» replaces the one still waiting, and takes its paper
     * off the disk.
     *
     * One request per person was the user's own decision, and it is the right
     * one: a second sheet is the first with more corrections on it, so two of
     * them waiting would have the office accepting the stale one on top of the
     * fresh and a card ending up as neither. The replaced request's paper goes
     * with it for the reason a refusal's does — nothing on the card ever
     * pointed at it, and now there is not even a row to point at it.
     */
    public function test_a_second_sending_replaces_the_one_still_waiting()
    {
        Storage::fake('documents');

        $author = $this->author();

        $first = $this->sent($author, [
            'degrees' => [[
                'name' => 'кандидат экономических наук, доцент',
                'document' => $this->paper('Аттестат доцента.pdf'),
            ]],
        ]);

        $was = $first->papers;
        $this->assertCount(1, $was);

        $second = $this->sent($author, [
            'home_address' => 'г. Худжанд, ул. Ленина 1',
            'degrees' => [[
                'name' => 'кандидат экономических наук, доцент',
                'document' => $this->paper('Аттестат доцента, пересканированный.pdf'),
            ]],
        ]);

        // One row where there were two, and it is the second sheet.
        $this->assertNull($first->fresh());
        $this->assertSame(1, SheetChangeRequest::query()->where('user_id', $author->id)->count());
        $this->assertSame(SheetChangeRequest::PENDING, $second->status);
        $this->assertSame('г. Худжанд, ул. Ленина 1', $second->payload['home_address']);
        $this->assertSame(
            'Аттестат доцента, пересканированный.pdf',
            $second->payload['degrees'][0]['document_name'],
        );

        // The paper the replaced request brought is off the disk and the new
        // one is on it: one file in the folder rather than two.
        Storage::disk('documents')->assertMissing($was[0]);
        Storage::disk('documents')->assertExists($second->papers[0]);
        $this->assertCount(1, Storage::disk('documents')->allFiles());

        // And still nothing on the card, after two sendings.
        $this->assertSame('Рахимова', $author->refresh()->surname);
        $this->assertSame(0, $author->degrees()->count());

        // Somebody else's waiting request is not touched by it: one per person
        // is what was asked for, not one in the building.
        $other = $this->author();
        $theirs = $this->sent($other);
        $this->sent($author);

        $this->assertSame(SheetChangeRequest::PENDING, $theirs->refresh()->status);
        $this->assertSame(2, SheetChangeRequest::query()->pending()->count());
    }

    /**
     * A request that has been answered is not answered a second time.
     *
     * Two people in the office open the same sheet, and the second presses
     * «Принять» on a page that was true when it was drawn. Without this the
     * payload would be written onto the card twice — the second time over a
     * card the first writing had already changed, and with the lists reconciled
     * against rows that were not there when the sheet was typed — and the
     * author would be told about it twice over. A sheet already sent back is
     * the same question from the other end: accepting it would write a sheet
     * somebody had decided against.
     */
    public function test_a_request_already_answered_is_not_answered_twice()
    {
        $author = $this->author();
        $accepted = $this->sent($author);
        $first = $this->sysadmin();
        $second = $this->personnel();

        $this->actingAs($first)->post("/sheet-requests/{$accepted->id}/accept")->assertRedirect();

        // Pressed again, by other hands: said politely and written nowhere.
        $this->actingAs($second)
            ->post("/sheet-requests/{$accepted->id}/accept")
            ->assertSessionHas('notice', 'Эта заявка уже разобрана.');

        $this->actingAs($second)
            ->post("/sheet-requests/{$accepted->id}/reject", ['comment' => 'Передумали.'])
            ->assertSessionHas('notice', 'Эта заявка уже разобрана.');

        $accepted->refresh();
        $this->assertSame(SheetChangeRequest::ACCEPTED, $accepted->status);
        $this->assertSame($first->id, $accepted->reviewed_by);
        $this->assertNull($accepted->comment);
        // The author was told once, which is the count that says the payload
        // was written once.
        $this->assertSame(1, $author->notifications()->count());

        // And the same from the other end: a sheet sent back stays sent back.
        $rejected = $this->sent($author);

        $this->actingAs($first)
            ->post("/sheet-requests/{$rejected->id}/reject", ['comment' => 'Не тот ИНН.'])
            ->assertRedirect();

        $this->actingAs($second)
            ->post("/sheet-requests/{$rejected->id}/accept")
            ->assertSessionHas('notice', 'Эта заявка уже разобрана.');

        $rejected->refresh();
        $this->assertSame(SheetChangeRequest::REJECTED, $rejected->status);
        $this->assertSame('Не тот ИНН.', $rejected->comment);
        $this->assertSame($first->id, $rejected->reviewed_by);
    }

    /**
     * The office is told when a sheet is filed, and the author when it is
     * answered — with the reason, where there is one.
     *
     * An ask nobody is told about is an ask that sits there: the person who
     * sent it cannot tell whether it was seen, and the office has no list of
     * what is waiting. It goes to everybody who may answer it rather than to
     * one named officer, because there is no queue owner here and a request
     * that waited for somebody on holiday would wait a fortnight.
     *
     * The answers go to the author alone, and the refusal carries the words
     * personnel wrote. A note saying «отклонено» and nothing else is a note
     * that costs a second request identical to the first.
     */
    public function test_the_office_is_told_of_a_sheet_and_the_author_of_the_answer()
    {
        $sysadmin = $this->sysadmin();
        $personnel = $this->personnel();
        $stranger = $this->colleague();
        $gone = $this->personnel();
        $gone->update(['status' => 'fired']);

        $author = $this->author();
        $filed = $this->sent($author);

        // Both halves of the office hold the note, and nobody else does — the
        // author included: they know what they sent.
        foreach ([$sysadmin, $personnel] as $officer) {
            $note = $officer->notifications()->sole();

            $this->assertSame('sheet_request', $note->data['kind']);
            $this->assertSame('Личный листок на проверку', $note->data['title']);
            // Who asked is the note's own field and the column the list reads
            // it in; the sentence says what was asked and leaves the name to it.
            $this->assertSame('Сотрудник просит записать изменения в свой личный листок.', $note->data['body']);
            $this->assertStringContainsString('Рахимова Зарина', $note->data['from']['name']);
            $this->assertSame(route('sheet_requests.show', $filed), $note->data['url']);
            $this->assertSame($author->id, $note->data['employee']['id']);
            $this->assertSame($filed->id, $note->data['request_id']);
            $this->assertNull($note->read_at);
        }

        $this->assertSame(0, $stranger->notifications()->count());
        $this->assertSame(0, $author->notifications()->count());
        // And nobody who has left: a note is work to be done, and an account
        // out of the building does none.
        $this->assertSame(0, $gone->notifications()->count());

        $this->actingAs($sysadmin)->post("/sheet-requests/{$filed->id}/accept")->assertRedirect();

        $note = $author->notifications()->sole();
        $this->assertSame('sheet_request_accepted', $note->data['kind']);
        $this->assertSame('Личный листок принят', $note->data['title']);
        $this->assertSame(route('profile'), $note->data['url']);
        $this->assertSame($filed->id, $note->data['request_id']);

        // The office is told nothing about its own decision.
        $this->assertSame(1, $sysadmin->notifications()->count());

        // And a refusal arrives carrying the reason, so it is read without
        // opening anything.
        $refused = $this->sent($author);

        $this->actingAs($personnel)
            ->post("/sheet-requests/{$refused->id}/reject", ['comment' => 'ИНН из девяти цифр, проверьте.'])
            ->assertRedirect();

        // Picked out by the request it is about rather than by being the latest
        // of the two: the column keeps whole seconds, and a suite files both of
        // these inside one of them.
        $note = $author->notifications()->get()
            ->sole(fn (DatabaseNotification $note) => $note->data['request_id'] === $refused->id);

        $this->assertSame('sheet_request_rejected', $note->data['kind']);
        $this->assertSame('Личный листок отклонён', $note->data['title']);
        $this->assertStringContainsString('ИНН из девяти цифр, проверьте.', $note->data['body']);
        $this->assertSame($refused->id, $note->data['request_id']);
        $this->assertSame(2, $author->notifications()->count());
    }

    /**
     * The author's own card tells them a sheet is waiting, tells them it was
     * sent back and what was said, and says nothing once it was written in.
     *
     * The card is the only place the author can be told any of this, since it
     * is the one page they have. A refusal especially: it has to outlive the
     * moment it was made and stand on the card until a new sheet is sent, or
     * the comment personnel wrote is a sentence that flashed past once.
     * Accepted is the one state that is not shown, because the card itself now
     * says what the request asked for.
     */
    public function test_the_card_shows_its_own_author_the_sheet_that_is_waiting()
    {
        $author = $this->author();
        $filed = $this->sent($author);

        $this->actingAs($author)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sheetRequest.id', $filed->id)
                ->where('sheetRequest.status', SheetChangeRequest::PENDING)
                ->where('sheetRequest.comment', null)
            );

        // The office sees it on the card too, which is how a request is reached
        // from the person rather than from the note about it.
        $this->actingAs($this->sysadmin())
            ->get("/employees/{$author->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sheetRequest.id', $filed->id));

        $this->actingAs($this->personnel())
            ->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Дата выдачи паспорта не совпадает.'])
            ->assertRedirect();

        // Sent back, and the card goes on saying so with the reason on it.
        $this->actingAs($author)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sheetRequest.status', SheetChangeRequest::REJECTED)
                ->where('sheetRequest.comment', 'Дата выдачи паспорта не совпадает.')
            );

        // Sent again and accepted: the card says nothing, because the card now
        // says it.
        $again = $this->sent($author);

        $this->actingAs($this->sysadmin())->post("/sheet-requests/{$again->id}/accept")->assertRedirect();

        $this->actingAs($author)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sheetRequest', null));

        $this->assertSame('Азимова', $author->refresh()->surname);
    }

    /**
     * A paper of a waiting request is handed to whoever may read the request,
     * named by nothing anybody can guess at, and gone once the sheet is sent
     * back.
     *
     * The office has to open the diploma to accept the record that names it, so
     * these files have to be reachable while they belong to no card at all —
     * which is the one moment in their life when nothing on a card is deciding
     * who may see them. So the address carries a fingerprint of the path rather
     * than the path: there is no folder to climb out of, and a name that is not
     * one of this request's own papers is simply not found. The page is given
     * the list without the path for the same reason — a path printed on a page
     * is a folder somebody will try to walk.
     */
    public function test_a_requests_paper_is_handed_over_only_to_whoever_may_read_it()
    {
        Storage::fake('documents');

        $author = $this->author();
        $filed = $this->sent($author, $this->papered());
        $officer = $this->sysadmin();

        $page = $this->actingAs($officer)->get("/sheet-requests/{$filed->id}")->assertOk()->viewData('page');
        $papers = $page['props']['papers'];

        // One row per paper, each said to hang off a line of the sheet and
        // named as the person named it — and not one of them says where the
        // file actually lies.
        // In the order the paper form asks the lines, so that the list reads
        // beside the sheet rather than in the order the keys happen to sit in.
        $this->assertCount(4, $papers);
        $this->assertSame(
            ['Диплом бакалавра.pdf', 'Аттестат доцента.pdf', 'Указ.pdf', 'Военный билет.pdf'],
            array_column($papers, 'name'),
        );
        $this->assertSame([
            'Образование',
            'Учёная степень, звание',
            'Государственные награды, поощрения',
            'Отношение к воинской обязанности',
        ], array_column($papers, 'line'));

        foreach ($papers as $paper) {
            $this->assertArrayNotHasKey('path', $paper);
        }

        $url = collect($papers)->firstWhere('name', 'Диплом бакалавра.pdf')['url'];

        // The office opens it, and so does the author: they may check that the
        // right file went.
        foreach ([$officer, $this->personnel(), $author] as $reader) {
            $handed = $this->actingAs($reader)->get($url)->assertOk();

            $this->assertStringContainsString(
                rawurlencode('Диплом бакалавра.pdf'),
                (string) $handed->headers->get('content-disposition'),
            );
        }

        // Nobody else, and no name of anybody's own invention.
        $this->actingAs($this->colleague())->get($url)->assertForbidden();
        $this->actingAs($officer)
            ->get("/sheet-requests/{$filed->id}/papers/".sha1('employees/1/whatever.pdf'))
            ->assertNotFound();

        // And once the sheet has been sent back the files are off the disk, so
        // the row is left to be read and the links answer nothing.
        $this->actingAs($officer)
            ->post("/sheet-requests/{$filed->id}/reject", ['comment' => 'Диплом не читается, пересканируйте.'])
            ->assertRedirect();

        $this->actingAs($officer)->get($url)->assertNotFound();
    }

    /**
     * A sheet sent back word for word marks no line as changed.
     *
     * The marks are the only thing on the review page that tells the office
     * where to look, and they are worth exactly as much as their false ones are
     * rare: three lines lit up for nothing and the next sheet is accepted
     * without reading any of them. The hard part is that the two columns come
     * from different places — a Carbon off the card against a string somebody
     * typed, a telephone stored in E.164 against one as it was sent, a list the
     * card keeps in its own order against the order a form happened to hold it
     * in — so the comparison is of answers and not of wording.
     *
     * Asked of a sheet that has just been written onto the card through this
     * very door, which is the strongest form of the question: whatever
     * acceptance wrote, reading it back has to come out as the sheet that was
     * sent.
     */
    public function test_a_sheet_sent_back_unchanged_marks_no_line_as_changed()
    {
        Storage::fake('documents');

        $author = $this->author();
        $filed = $this->sent($author, $this->papered());

        $this->actingAs($this->sysadmin())
            ->post("/sheet-requests/{$filed->id}/accept")
            ->assertSessionHasNoErrors();

        $lines = SheetDiff::between($author->fresh(), $filed->refresh()->payload);

        // Every line of the sheet is read, and not one of them is marked.
        //
        // «Дети» and «Состав семьи» are the two worth naming: they are read off
        // the card through a row serialised to an array, which writes a date in
        // UTC, and a day that is the first of its month then falls back into
        // the month before. For a while they lit up on every sheet, including
        // one that changed nothing — see SheetDiff::date(), which now brings
        // both columns back to the clock the company keeps.
        $this->assertNotSame([], $lines);
        $this->assertSame([], array_column(array_filter($lines, fn (array $line) => $line['changed']), 'key'));

        // Both columns of a line say the same thing, and say it the way the
        // card is read everywhere else — the date among them, which is the one
        // value the two sides hold differently.
        $read = collect($lines)->keyBy('key');
        $this->assertSame('17 апр. 1990', $read['birth_date']['was']);
        $this->assertSame('17 апр. 1990', $read['birth_date']['now']);
        $this->assertSame('+992 90 111 22 33', $read['phone']['now']);
    }

    /**
     * And a line the request does not carry at all is not a change either.
     *
     * Accepting writes only what the payload holds, so a line that is not in it
     * is a line the save will leave exactly as it stands. Read any other way,
     * such a line would be shown against a dash — and the office would be
     * offered a blank to accept over a passport nobody asked about.
     */
    public function test_a_line_the_request_does_not_carry_is_not_a_change()
    {
        $employee = $this->author();
        $employee->details()->update([
            'passport_series' => 'A',
            'passport_number' => '7654321',
            'home_address' => 'г. Душанбе, пр. Рудаки 55',
        ]);

        $payload = $this->sheet(['home_address' => 'г. Худжанд, ул. Ленина 1']);
        unset($payload['passport_series'], $payload['passport_number']);

        $lines = collect(SheetDiff::between($employee->fresh(), $payload))->keyBy('key');

        // The passport is read off the card on both sides and marked unchanged.
        $this->assertSame('A 7654321', $lines['passport_number']['was']);
        $this->assertSame('A 7654321', $lines['passport_number']['now']);
        $this->assertFalse($lines['passport_number']['changed']);

        // While the line that did arrive, with another answer on it, is marked
        // — which is what makes the two assertions above worth making.
        $this->assertSame('г. Душанбе, пр. Рудаки 55', $lines['home_address']['was']);
        $this->assertSame('г. Худжанд, ул. Ленина 1', $lines['home_address']['now']);
        $this->assertTrue($lines['home_address']['changed']);
    }
}
