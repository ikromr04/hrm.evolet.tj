<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentPhoto;
use App\Support\PhotoStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A picture out of a unit's journal, handed to somebody who may read that unit.
 *
 * The same reason as the employee's photograph: a file under the web root is
 * read by whoever holds its address and nobody is ever asked. What a laptop
 * looked like when it came back is a fact about a unit, so it is open to exactly
 * whoever may open that unit's card — the gate on the routes says so, and it is
 * the very gate the card itself is opened with.
 */
class EquipmentPhotoController extends Controller
{
    /** The upload, opened when a thumbnail is clicked. */
    public function show(Request $request, Equipment $equipment, EquipmentPhoto $photo): BinaryFileResponse
    {
        return $this->answer($request, $photo->path);
    }

    /** The scaled copy the journal shows in its strip of thumbnails. */
    public function preview(Request $request, Equipment $equipment, EquipmentPhoto $photo): BinaryFileResponse
    {
        return $this->answer($request, $photo->preview);
    }

    /**
     * Which unit the picture hangs off is settled by the binding before either
     * of those is reached — the address names both, and the photograph is looked
     * for among that unit's own — so all that is left is to find the file.
     */
    private function answer(Request $request, string $path): BinaryFileResponse
    {
        $disk = PhotoStore::holding($path);

        // The row outlived the file: an address that leads to nothing rather
        // than something gone wrong.
        abort_if($disk === null, 404);

        return PhotoStore::answer($request, $disk, $path);
    }
}
