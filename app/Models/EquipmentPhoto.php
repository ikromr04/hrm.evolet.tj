<?php

namespace App\Models;

use App\Support\Photo;
use App\Support\PhotoStore;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * One photograph of a unit, kept twice: the upload, and a scaled copy for the
 * interface. It hangs off the journal entry it was taken for, so a later check
 * never overwrites what an earlier one saw.
 */
class EquipmentPhoto extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'equipment_id',
        'equipment_event_id',
        'equipment_repair_id',
        'path',
        'preview',
    ];

    /** @var list<string> */
    protected $appends = ['url', 'preview_url'];

    /** How wide the copy shown in the interface may be. */
    private const PREVIEW = 1200;

    /**
     * Keeps an upload twice over: the file as it came, and a copy scaled to
     * fit a screen. A picture from a phone is several megabytes, and a journal
     * that showed every one of them full size would be unusable.
     */
    public static function keep(Equipment $equipment, EquipmentEvent $event, UploadedFile $photo, ?EquipmentRepair $repair = null): self
    {
        $folder = "equipment/{$equipment->id}/photos";
        $name = Str::random(20);

        $original = $photo->storeAs($folder, "{$name}.".$photo->extension(), PhotoStore::DISK);
        $preview = "{$folder}/{$name}_preview.jpg";

        PhotoStore::disk()->put($preview, Photo::fit(PhotoStore::disk()->path($original), self::PREVIEW));

        return $equipment->photos()->create([
            'equipment_event_id' => $event->id,
            'equipment_repair_id' => $repair?->id,
            'path' => $original,
            'preview' => $preview,
        ]);
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EquipmentEvent::class, 'equipment_event_id');
    }

    /** Set when the picture came with a piece of service work. */
    public function repair(): BelongsTo
    {
        return $this->belongsTo(EquipmentRepair::class, 'equipment_repair_id');
    }

    /**
     * The upload, opened when a thumbnail is clicked.
     *
     * An address of the application rather than of a file: the picture is
     * answered by EquipmentPhotoController, which asks whether this unit is the
     * viewer's to open before it hands anything over. Still a plain URL to
     * everything that reads this, which is the whole interface.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => route('equipment.photos.show', [$this->equipment_id, $this->id]));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function previewUrl(): Attribute
    {
        return Attribute::get(fn (): string => route('equipment.photos.preview', [$this->equipment_id, $this->id]));
    }
}
