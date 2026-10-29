<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\User;
use App\Rules\ReadableImage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What may be handed in as a photograph.
 *
 * Everything that takes a picture cuts a smaller copy of it with GD, and GD
 * reads fewer formats than a browser will offer. A phone is the usual source of
 * trouble: an iPhone records HEIC, hands it over without a word, and nothing in
 * the chain can open it — so it is refused at the door, with a message that says
 * what to do instead of that something went wrong.
 */
class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('photos');
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    private function unit(): Equipment
    {
        return Equipment::factory()->create(['equipment_type_id' => EquipmentType::create(['name' => 'Ноутбуки'])->id]);
    }

    public function test_a_photograph_from_an_iphone_is_refused_with_a_message_that_helps()
    {
        $unit = $this->unit();

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее',
                'photos' => [UploadedFile::fake()->create('IMG_0421.heic', 600, 'image/heic')],
            ])
            ->assertSessionHasErrors(['photos.0' => ReadableImage::MESSAGE]);

        $this->assertSame(0, $unit->events()->where('kind', 'condition')->count());
    }

    public function test_a_file_that_only_calls_itself_a_photograph_is_refused_rather_than_breaking_the_save()
    {
        $unit = $this->unit();

        // Passes the format rules — it is called .jpg and says image/jpeg — and
        // has nothing inside that GD could open. Before the check at the door
        // this reached the resizing and answered with a blank error page.
        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее',
                'photos' => [UploadedFile::fake()->create('IMG_0421.jpg', 20, 'image/jpeg')],
            ])
            ->assertSessionHasErrors(['photos.0' => ReadableImage::MESSAGE]);
    }

    public function test_a_real_photograph_is_taken_and_kept_with_its_preview()
    {
        $unit = $this->unit();

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее, следы эксплуатации',
                'photos' => [UploadedFile::fake()->image('IMG_0421.jpg', 1200, 900)],
            ])
            ->assertSessionHasNoErrors();

        $photo = $unit->events()->where('kind', 'condition')->sole()->photos()->sole();

        Storage::disk('photos')->assertExists($photo->path);
        Storage::disk('photos')->assertExists($photo->preview);
    }

    public function test_an_avatar_goes_by_the_same_rules()
    {
        $employee = User::factory()->create();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->create('me.heic', 600, 'image/heic')])
            ->assertSessionHasErrors(['avatar' => ReadableImage::MESSAGE]);

        $this->assertNull($employee->fresh()->getRawOriginal('avatar'));

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('me.jpg', 800, 800)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($employee->fresh()->getRawOriginal('avatar'));
    }

    /**
     * A picture that weighs nothing and claims to be enormous.
     *
     * This is the shape of the thing the limit is for: the file passes every
     * rule about weight and format, and opening it would ask the server for
     * gigabytes. The eight-megabyte cap on the upload cannot see it, because
     * the file really is small — it is the open picture that is not.
     */
    public function test_a_picture_claiming_more_points_than_can_be_opened_is_refused(): void
    {
        $unit = $this->unit();

        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее',
                'photos' => [$this->claiming(30_000, 30_000)],
            ])
            ->assertSessionHasErrors(['photos.0' => ReadableImage::TOO_MANY_POINTS]);

        $this->assertSame(0, $unit->events()->where('kind', 'condition')->count());
    }

    public function test_an_avatar_claiming_the_same_is_refused_too(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/avatar", ['avatar' => $this->claiming(20_000, 20_000)])
            ->assertSessionHasErrors(['avatar' => ReadableImage::TOO_MANY_POINTS]);

        $this->assertNull($employee->fresh()->getRawOriginal('avatar'));
    }

    /**
     * The count is read off the header and nothing is opened to get it — which
     * is the only way the question can be asked at all, since opening the
     * picture is the thing being guarded against.
     *
     * Both files below hold a header and not one row of pixels after it. The
     * one within the ceiling passes, and it could only pass unopened.
     */
    public function test_the_count_is_read_off_the_header_rather_than_by_opening_the_picture(): void
    {
        $said = [];
        $fail = function (string $message) use (&$said) {
            $said[] = $message;
        };

        (new ReadableImage)->validate('avatar', $this->claiming(5_000, 5_000), $fail);

        $this->assertSame([], $said, 'Двадцать пять миллионов точек — ровно потолок, и он должен проходить.');

        (new ReadableImage)->validate('avatar', $this->claiming(5_000, 5_001), $fail);

        $this->assertSame([ReadableImage::TOO_MANY_POINTS], $said);
    }

    /**
     * A PNG that says how large it is and holds nothing else: the eight-byte
     * signature and one IHDR chunk, which is all getimagesize() reads.
     */
    private function claiming(int $width, int $height): UploadedFile
    {
        $header = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
        $chunk = 'IHDR'.$header;
        $png = "\x89PNG\r\n\x1a\n".pack('N', strlen($header)).$chunk.pack('N', crc32($chunk));

        $path = tempnam(sys_get_temp_dir(), 'claiming').'.png';
        file_put_contents($path, $png);

        return new UploadedFile($path, 'IMG_0001.png', 'image/png', null, true);
    }
}
