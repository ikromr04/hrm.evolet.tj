<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentPhoto;
use App\Models\EquipmentType;
use App\Models\User;
use App\Support\EmployeeFields;
use Database\Seeders\EquipmentTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may read a photograph.
 *
 * Every photograph used to be a static file under the web root, so the address
 * was the whole of the security: whoever held one read the file for as long as
 * it existed, and no right was ever consulted — a colleague who had left, the
 * person a link was forwarded to, anything that logged the address. These are
 * the questions that are asked now instead, and the two behaviours that carry
 * the company's files across the change: the fallback that finds a photograph
 * still lying where it used to, and the command that moves it.
 */
class PhotoAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PositionSeeder::class, EquipmentTypeSeeder::class]);
        Storage::fake('photos');
        // And the disk photographs used to be kept on, which the transition
        // fallback still looks at: faked here so no test reaches the real one.
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

    /**
     * A card with a photograph on it, written straight to the disk: how the two
     * files came to be there is EmployeeAvatarTest's business, not this one's.
     *
     * @return array{User, string, string}
     */
    private function photographed(): array
    {
        $employee = $this->colleague();

        $thumbnail = "avatars/{$employee->id}/KSnJvPqrtu_240.jpg";
        $original = "avatars/{$employee->id}/KSnJvPqrtu.jpg";

        Storage::disk('photos')->put($thumbnail, $this->jpeg());
        Storage::disk('photos')->put($original, $this->jpeg());

        $employee->forceFill(['avatar' => $thumbnail, 'avatar_original' => $original])->save();

        return [$employee, $thumbnail, $original];
    }

    /** A real JPEG rather than a few bytes calling itself one, so the answer carries the type it should. */
    private function jpeg(): string
    {
        return UploadedFile::fake()->image('photo.jpg', 240, 240)->getContent();
    }

    private function unit(?int $holder = null): Equipment
    {
        $factory = Equipment::factory()->ofType(EquipmentType::firstWhere('name', 'Ноутбуки'));

        return ($holder === null ? $factory : $factory->issuedTo($holder))->create();
    }

    /**
     * A picture in a unit's journal, taken the way the interface takes one.
     */
    private function pictured(Equipment $unit): EquipmentPhoto
    {
        $this->actingAs($this->sysadmin())
            ->put("/equipment/{$unit->id}/state", [
                'condition' => 'Рабочее',
                'photos' => [UploadedFile::fake()->image('IMG_0421.jpg', 1200, 900)],
            ])
            ->assertSessionHasNoErrors();

        return $unit->events()->where('kind', 'condition')->sole()->photos()->sole();
    }

    public function test_a_photograph_is_refused_to_somebody_who_is_not_signed_in(): void
    {
        [$employee, $thumbnail] = $this->photographed();

        // The whole point of the change: holding the address is no longer
        // holding the photograph.
        $this->get("/employees/{$employee->id}/avatar/".basename($thumbnail))
            ->assertRedirect(route('login'));
    }

    public function test_a_photograph_is_refused_to_somebody_who_may_not_see_the_card(): void
    {
        [$employee, $thumbnail] = $this->photographed();

        $stranger = $this->colleague();
        $stranger->revokePermissionTo(EmployeeFields::permission('avatar'));

        $this->actingAs($stranger)
            ->get("/employees/{$employee->id}/avatar/".basename($thumbnail))
            ->assertForbidden();
    }

    public function test_a_photograph_is_served_to_somebody_who_may_see_the_card(): void
    {
        [$employee, $thumbnail] = $this->photographed();

        $response = $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}/avatar/".basename($thumbnail));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame($this->jpeg(), $response->streamedContent());
    }

    public function test_a_person_reads_their_own_photograph(): void
    {
        [$employee, , $original] = $this->photographed();

        // Both files, the thumbnail the interface draws and the upload behind
        // it: opening one's own photograph full size is the same right.
        $this->actingAs($employee)
            ->get("/employees/{$employee->id}/avatar/".basename($original))
            ->assertOk();
    }

    /**
     * And what makes the address safe: the card itself is the only index of
     * which files are its photograph.
     */
    public function test_one_card_cannot_hand_out_another_cards_photograph(): void
    {
        [, $thumbnail] = $this->photographed();
        $another = $this->colleague();

        $this->actingAs($this->colleague())
            ->get("/employees/{$another->id}/avatar/".basename($thumbnail))
            ->assertNotFound();
    }

    /**
     * A page of a hundred faces asks for a hundred files, and the second time it
     * is drawn it must not fetch a single one of them again.
     */
    public function test_a_photograph_asks_a_shared_cache_to_keep_nothing_and_the_browser_to_revalidate(): void
    {
        [$employee, $thumbnail] = $this->photographed();
        $address = "/employees/{$employee->id}/avatar/".basename($thumbnail);
        $viewer = $this->colleague();

        $response = $this->actingAs($viewer)->get($address);

        // `private` above all: a proxy keeping a copy of a colleague's face to
        // hand to the next person asking for the same address would reopen the
        // hole from the other end.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('ETag'));

        $this->actingAs($viewer)
            ->get($address, ['If-None-Match' => $response->headers->get('ETag')])
            ->assertStatus(304);
    }

    public function test_a_units_photograph_follows_that_units_own_visibility(): void
    {
        $holder = $this->colleague();
        $unit = $this->unit($holder->id);
        $photo = $this->pictured($unit);

        // Every position is created seeing its own equipment and no more, so a
        // colleague the laptop was never issued to may not open its card — nor,
        // therefore, a picture of it.
        $this->actingAs($this->colleague())->get($photo->url)->assertForbidden();
        $this->actingAs($this->colleague())->get($photo->preview_url)->assertForbidden();

        $this->actingAs($holder)->get($photo->url)->assertOk();
        $this->actingAs($holder)->get($photo->preview_url)->assertOk();
    }

    /**
     * A picture is only ever looked for among the photographs of the unit named
     * in the address, so seeing one unit is no way into another's journal.
     */
    public function test_a_unit_cannot_hand_out_another_units_photograph(): void
    {
        $holder = $this->colleague();
        $photo = $this->pictured($this->unit());

        $this->actingAs($holder)
            ->get("/equipment/{$this->unit($holder->id)->id}/photos/{$photo->id}")
            ->assertNotFound();
    }

    /**
     * The server is updated by hand, and the command that moves the
     * photographs can only be run once the new code is up there. Between those
     * two moments every photograph is a file the new code would not find.
     *
     * Delete this test with the fallback it watches.
     */
    public function test_the_transition_still_finds_a_photograph_on_the_old_disk(): void
    {
        [$employee, $thumbnail] = $this->photographed();

        // Exactly the state a server is in the minute the files are uploaded:
        // the row names the file, and the file is only where it used to be.
        Storage::disk('photos')->delete($thumbnail);
        Storage::disk('public')->put($thumbnail, $this->jpeg());

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}/avatar/".basename($thumbnail))
            ->assertOk();
    }

    public function test_the_move_command_carries_the_photographs_over_and_is_safe_to_run_twice(): void
    {
        [$employee, $thumbnail, $original] = $this->photographed();
        $photo = $this->pictured($this->unit());

        // Back where every one of them started.
        foreach ([$thumbnail, $original, $photo->path, $photo->preview] as $path) {
            Storage::disk('public')->put($path, Storage::disk('photos')->get($path));
            Storage::disk('photos')->delete($path);
        }

        $this->artisan('hrm:photos-private')->assertSuccessful();

        Storage::disk('photos')->assertExists([$thumbnail, $original, $photo->path, $photo->preview]);
        Storage::disk('public')->assertMissing([$thumbnail, $original, $photo->path, $photo->preview]);

        // And again: nothing left to carry, nothing broken by saying so, and the
        // photographs still exactly where the first run put them.
        $this->artisan('hrm:photos-private')->assertSuccessful();

        Storage::disk('photos')->assertExists([$thumbnail, $original, $photo->path, $photo->preview]);

        $this->actingAs($this->colleague())
            ->get("/employees/{$employee->id}/avatar/".basename($thumbnail))
            ->assertOk();
    }
}
