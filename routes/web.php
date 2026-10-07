<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\Directories;
use App\Http\Controllers\EmployeeAccessController;
use App\Http\Controllers\EmployeeAvatarController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeDetailsController;
use App\Http\Controllers\EmployeeEducationController;
use App\Http\Controllers\EmployeeEquipmentController;
use App\Http\Controllers\EmployeeStatusController;
use App\Http\Controllers\EmployeeWorkExperienceController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentDetailsController;
use App\Http\Controllers\EquipmentJournalController;
use App\Http\Controllers\EquipmentRepairController;
use App\Http\Controllers\EquipmentStatusController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchController;
// The reference lists themselves, under a name of their own: "Directories" here
// already stands for the controllers that serve them.
use App\Support\Directories as DirectoryLists;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('employees', [EmployeeController::class, 'index'])
        ->middleware('can:employees.view')
        ->name('employees.index');
    // Before the profile, or "create" would be read as somebody's id.
    Route::get('employees/create', [EmployeeController::class, 'create'])
        ->middleware('can:employees.create')
        ->name('employees.create');
    // One's own card, at an address of its own: everybody has one and nobody needs
    // a right to read it, so there is no id to guard.
    Route::get('profile', [EmployeeController::class, 'profile'])->name('profile');
    // Everybody reaches their own card, whatever rights they hold.
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])
        ->middleware('can:view,employee')
        ->name('employees.show');
    // Open to everybody: it only ever searches the sections the viewer may see.
    Route::get('search', SearchController::class)->name('search');
    Route::get('equipment', [EquipmentController::class, 'index'])
        ->middleware('can:equipment.view.any')
        ->name('equipment.index');
    // Before the card, or "journal" would be read as a unit's id. What went on
    // over a period is a right of its own.
    Route::get('equipment/journal', EquipmentJournalController::class)
        ->middleware('can:equipment.journal.any')
        ->name('equipment.journal');
    // Before the card too, for the same reason as the journal.
    Route::get('equipment/create', [EquipmentController::class, 'create'])
        ->middleware('can:equipment.create')
        ->name('equipment.create');
    // The card of a unit outside what this person may see is not theirs to
    // open; the gate takes the unit itself and says so.
    Route::get('equipment/{equipment}', [EquipmentController::class, 'show'])
        ->middleware('can:view,equipment')
        ->name('equipment.show');

    // Who works where is nobody's secret: the structure of the company is open
    // to everybody who signs in, and the pages show names and nothing more.
    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');

    // What the bell opens. Everybody has notifications of their own and nobody
    // reads anyone else's, so there is no right to ask for: each address works
    // on the signed-in person's own list.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    // Before the single one, or "read" would be taken for a notification's id.
    Route::post('notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
});

// Putting a new colleague on the books.
Route::post('employees', [EmployeeController::class, 'store'])
    ->middleware(['auth', 'can:employees.create'])
    ->name('employees.store');

// One card of the profile at a time, each form guarded by the block it saves:
// whoever may change a passport is not thereby allowed to rewrite a family.
Route::middleware(['auth'])->prefix('employees/{employee}')->name('employees.')->group(function () {
    // Multipart, so the upload is a POST rather than a PUT.
    Route::post('avatar', [EmployeeAvatarController::class, 'update'])->middleware('can:employees.photo,employee')->name('avatar.update');
    Route::delete('avatar', [EmployeeAvatarController::class, 'destroy'])->middleware('can:employees.photo,employee')->name('avatar.destroy');

    Route::put('personal', [EmployeeDetailsController::class, 'personal'])->middleware('can:employees.edit.block.main,employee')->name('personal');
    Route::put('passport', [EmployeeDetailsController::class, 'passport'])->middleware('can:employees.edit.block.passport,employee')->name('passport');
    Route::put('contacts', [EmployeeDetailsController::class, 'contacts'])->middleware('can:employees.edit.block.contacts,employee')->name('contacts');
    Route::put('languages', [EmployeeDetailsController::class, 'languages'])->middleware('can:employees.edit.block.languages,employee')->name('languages');
    Route::put('employment', [EmployeeDetailsController::class, 'employment'])->middleware('can:employees.edit.block.employment,employee')->name('employment');

    // Education is kept record by record. The controller checks that the record
    // belongs to the employee in the URL, so one person's id cannot reach
    // another's; scoped bindings would not, as "education" has no plural form
    // for Laravel to find the relation by.
    Route::middleware('can:employees.edit.block.education,employee')->group(function () {
        Route::post('educations', [EmployeeEducationController::class, 'store'])->name('educations.store');
        // Several records in one request: the steps of the "new colleague" wizard.
        Route::post('educations/many', [EmployeeEducationController::class, 'storeMany'])->name('educations.many');
        Route::put('educations/{education}', [EmployeeEducationController::class, 'update'])->name('educations.update');
        Route::delete('educations/{education}', [EmployeeEducationController::class, 'destroy'])->name('educations.destroy');
    });

    Route::middleware('can:employees.edit.block.experience,employee')->group(function () {
        Route::post('experiences', [EmployeeWorkExperienceController::class, 'store'])->name('experiences.store');
        Route::post('experiences/many', [EmployeeWorkExperienceController::class, 'storeMany'])->name('experiences.many');
        Route::put('experiences/{experience}', [EmployeeWorkExperienceController::class, 'update'])->name('experiences.update');
        Route::delete('experiences/{experience}', [EmployeeWorkExperienceController::class, 'destroy'])->name('experiences.destroy');
    });

    // Handing units over from a card is still handing units over, so it takes
    // both the block of the card and the right to issue equipment.
    Route::post('equipment', [EmployeeEquipmentController::class, 'store'])
        ->middleware(['can:employees.edit.block.equipment,employee', 'can:equipment.issue'])
        ->name('equipment.store');

    Route::put('family', [EmployeeDetailsController::class, 'family'])->middleware('can:employees.edit.block.family,employee')->name('family');
});

// What is done to a colleague rather than to a line of their card. Moving
// somebody about and letting them go are asked separately: plenty of people
// reassign a department and very few end an employment. Taking somebody back is
// the counterpart of letting them go, so it goes with it.
Route::middleware(['auth'])->prefix('employees/{employee}')->name('employees.')->group(function () {
    Route::post('transfer', [EmployeeStatusController::class, 'transfer'])->middleware('can:employees.transfer')->name('transfer');
    Route::post('fire', [EmployeeStatusController::class, 'fire'])->middleware('can:employees.fire')->name('fire');
    Route::post('restore', [EmployeeStatusController::class, 'restore'])->middleware('can:employees.fire')->name('restore');
});

// Striking the card out of the system altogether, with everything on it.
Route::delete('employees/{employee}', [EmployeeStatusController::class, 'destroy'])
    ->middleware(['auth', 'can:employees.delete'])
    ->name('employees.destroy');

// Putting a new unit on the books: no unit named yet, so this one right stands
// on its own rather than being asked of a row.
Route::post('equipment', [EquipmentController::class, 'store'])
    ->middleware(['auth', 'can:equipment.create'])
    ->name('equipment.store');

// A unit's life: handed out, taken back, written off. Three separate rights,
// each asked of the very unit — a move is refused on a unit one cannot see.
Route::middleware('auth')->prefix('equipment/{equipment}')->name('equipment.')->group(function () {
    Route::post('issue', [EquipmentStatusController::class, 'issue'])->middleware('can:issue,equipment')->name('issue');
    Route::post('take', [EquipmentStatusController::class, 'take'])->middleware('can:take,equipment')->name('take');
    Route::post('write-off', [EquipmentStatusController::class, 'writeOff'])->middleware('can:writeOff,equipment')->name('write-off');
});

// Struck off the books: for a duplicate or a mistake, not for wear, which is
// why it is a right of its own rather than a part of managing the fleet.
Route::delete('equipment/{equipment}', [EquipmentController::class, 'destroy'])
    ->middleware(['auth', 'can:delete,equipment'])
    ->name('equipment.destroy');

// The card and its service records, edited one block at a time — and each block
// is a right of its own, so renaming a unit and keeping its inventory dates are
// not the same permission.
Route::middleware('auth')->prefix('equipment/{equipment}')->name('equipment.')->group(function () {
    Route::put('specs', [EquipmentDetailsController::class, 'specs'])->middleware('can:editSpecs,equipment')->name('specs');
    Route::put('accessories', [EquipmentDetailsController::class, 'accessories'])->middleware('can:editAccessories,equipment')->name('accessories');
    Route::put('state', [EquipmentDetailsController::class, 'state'])->middleware('can:editState,equipment')->name('state');

    // What has been done to it: all four under one right, because a record of
    // repair is opened, corrected and closed by the same hands.
    Route::middleware('can:service,equipment')->group(function () {
        Route::post('repairs', [EquipmentRepairController::class, 'store'])->name('repairs.store');
        Route::put('repairs/{repair}', [EquipmentRepairController::class, 'update'])->name('repairs.update');
        Route::delete('repairs/{repair}', [EquipmentRepairController::class, 'destroy'])->name('repairs.destroy');
    });
});

// Directories: roles ("Позиция"), positions ("Должность"), departments, languages, citizenships and equipment categories.
Route::middleware(['auth'])->prefix('directories')->name('directories.')->group(function () {
    // The section opens on the first list this person may read rather than always
    // on the positions, which not everybody who keeps a directory may see.
    Route::get('/', function (Request $request) {
        $first = DirectoryLists::firstFor($request->user());

        abort_if($first === null, 403);

        return redirect("/directories/{$first}");
    });

    // Lists kept by different people, so each is a right of its own — reading it,
    // and adding to it and renaming in it. "Доступы" is one of them too, further
    // down: it is a page of its own rather than a resource.
    $lists = [
        'roles' => Directories\RoleController::class,
        'positions' => Directories\PositionController::class,
        'departments' => Directories\DepartmentController::class,
        'languages' => Directories\LanguageController::class,
        'citizenships' => Directories\CitizenshipController::class,
        'equipment' => Directories\EquipmentTypeController::class,
    ];

    foreach ($lists as $list => $controller) {
        Route::resource($list, $controller)
            ->only(['index'])
            ->middleware('can:'.DirectoryLists::viewPermission($list));

        // Changing a list takes reading it too, so the gate asks for the pair
        // rather than the bare right.
        Route::resource($list, $controller)
            ->only(['store', 'update', 'destroy'])
            ->middleware("can:directories.manage.{$list}");
    }

    // Who may do what: a list of the section like any other, read and kept by its
    // own pair of rights. The two access roles are still not editable in it —
    // they answer yes to everything whatever the table says.
    Route::get('access', [Directories\AccessController::class, 'index'])
        ->middleware('can:'.DirectoryLists::viewPermission('access'))
        ->name('access.index');
    Route::put('access/{role}', [Directories\AccessController::class, 'update'])
        ->middleware('can:directories.manage.access')
        ->name('access.update');
});

// A right given to, or taken from, one colleague in particular. The same duty as
// the access table, so the same right: an exception on a card and a tick in the
// table are two ways of doing one thing.
Route::put('employees/{employee}/access', EmployeeAccessController::class)
    ->middleware(['auth', 'can:directories.manage.access'])
    ->name('employees.access');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// Anything else. A route rather than only an exception handler, because an
// address that matches nothing is refused before the session is even started, and
// the page would then have no idea who is looking at it. Last in the file, as a
// fallback must be.
// Registered for every method, not only GET: with a GET-only catch-all a stray
// POST to a wrong address would be told "method not allowed", as if the page were
// there and merely fussy about how it is asked.
Route::any('{fallbackPlaceholder}', function (Request $request) {
    // A page is for a person, and for a GET; anything else — data asked for, a
    // form posted into the void — is answered by the plain 404.
    abort_if($request->expectsJson() || ! $request->isMethod('GET'), 404);

    return Inertia::render('errors/404')->toResponse($request)->setStatusCode(404);
})->where('fallbackPlaceholder', '.*')->fallback();
