<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The employee's photo: a square thumbnail for the interface and the upload
 * itself, kept so the photo can be opened at full size.
 */
class EmployeeAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class]);
        Storage::fake('photos');
        // And the disk photographs used to be kept on, so that while the
        // transition fallback is still looking there nothing in these tests
        // reaches the real one.
        Storage::fake('public');
    }

    /**
     * The one account that passes every check, whatever the rights say: these
     * tests are not about what a position may do.
     */
    private function sysadmin(): User
    {
        return User::factory()->create()->assignRole('sysadmin');
    }

    /** The raw column holds a path; the model hands out a URL. */
    private function paths(User $employee): array
    {
        $employee->refresh();

        return [$employee->getRawOriginal('avatar'), $employee->getRawOriginal('avatar_original')];
    }

    public function test_an_admin_uploads_a_photo_and_gets_a_square_thumbnail()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/avatar", [
                // Deliberately not square: the thumbnail must still come out so.
                'avatar' => UploadedFile::fake()->image('photo.jpg', 900, 600),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        [$thumbnail, $original] = $this->paths($employee);
        $this->assertNotNull($thumbnail);
        $this->assertNotNull($original);
        Storage::disk('photos')->assertExists([$thumbnail, $original]);

        [$width, $height] = getimagesizefromstring(Storage::disk('photos')->get($thumbnail));
        $this->assertSame([240, 240], [$width, $height]);
    }

    public function test_the_original_is_kept_beside_the_thumbnail()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())
            ->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg', 900, 600)]);

        [$thumbnail, $original] = $this->paths($employee);
        $this->assertNotSame($thumbnail, $original);

        // The upload keeps its own size, so it can be opened properly.
        [$width, $height] = getimagesizefromstring(Storage::disk('photos')->get($original));
        $this->assertSame([900, 600], [$width, $height]);
    }

    public function test_replacing_a_photo_removes_the_files_it_replaces()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('first.jpg')]);
        $before = $this->paths($employee);

        $this->actingAs($admin)->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('second.jpg')]);
        $after = $this->paths($employee);

        Storage::disk('photos')->assertMissing($before);
        Storage::disk('photos')->assertExists($after);
    }

    public function test_an_admin_deletes_the_photo_and_its_files()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg')]);
        $files = $this->paths($employee);

        $this->actingAs($admin)
            ->delete("/employees/{$employee->id}/avatar")
            ->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->paths($employee));
        Storage::disk('photos')->assertMissing($files);
    }

    public function test_the_model_hands_out_urls_not_paths()
    {
        $employee = $this->colleague();

        $this->actingAs($this->sysadmin())->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg')]);

        // An address of the application, which asks who is looking before it
        // answers — and not a static file, which would be read by anybody who
        // ever came by the link.
        $employee->refresh();
        [$thumbnail, $original] = $this->paths($employee);

        $this->assertStringEndsWith("/employees/{$employee->id}/avatar/".basename($thumbnail), $employee->avatar);
        $this->assertStringEndsWith("/employees/{$employee->id}/avatar/".basename($original), $employee->avatar_original);
    }

    public function test_anything_that_is_not_an_image_is_rejected()
    {
        $employee = $this->colleague();
        $admin = $this->sysadmin();

        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->create('resume.pdf', 20, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        // A photo straight from a camera can still be too heavy.
        $this->actingAs($admin)
            ->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('huge.jpg')->size(9000)])
            ->assertSessionHasErrors('avatar');

        $this->assertSame([null, null], $this->paths($employee));
    }

    public function test_an_employee_cannot_change_anyones_photo()
    {
        $employee = $this->colleague();

        // Not even their own: the photo is managed by HR.
        $this->actingAs($employee);
        $this->post("/employees/{$employee->id}/avatar", ['avatar' => UploadedFile::fake()->image('photo.jpg')])->assertForbidden();
        $this->delete("/employees/{$employee->id}/avatar")->assertForbidden();
    }
}
