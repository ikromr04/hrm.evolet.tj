<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Access;
use App\Support\Directories;
use App\Support\EmployeeFields;
use App\Support\EquipmentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // In a Codespace the application sits behind a proxy that speaks HTTPS to
        // the browser and plain HTTP to us, so links are written the way the page
        // is read rather than the way the request arrived.
        if (env('CODESPACES') === 'true') {
            URL::forceScheme('https');
        }

        // The same on the company's server, for the same reason. Shared hosting
        // tends to end HTTPS at a proxy in front of PHP (the host's own, or
        // Cloudflare), so the request looks like plain HTTP from here — and the
        // links in a page and the redirect after a form would be written http://,
        // which a browser on an https page blocks. A proxy that says so in a
        // header is believed (trustProxies in bootstrap/app.php); this is for the
        // one that says nothing. APP_URL is what the owner sets either way, so
        // an https address there settles it.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // `migrate:fresh`, `migrate:reset`, `migrate:refresh` and `db:wipe` empty
        // the database. That is how the demo is rebuilt during development, and
        // typed on the company's server out of habit it would take every card
        // with it, so there they refuse to run even with --force.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // What «Password::defaults()» means. Three forms ask for it — the page a
        // reset letter leads to, the password page in the settings, and the
        // command that makes the first account — and unconfigured it means eight
        // characters and nothing else, which is the one thing it should not mean.
        //
        // Length is what is asked for here, rather than a capital letter and a
        // punctuation mark and a digit in prescribed proportions. Twelve
        // characters of two ordinary words beat eight with an exclamation mark
        // wedged on the end, and a rule demanding a zoo of symbols is answered
        // with «Parol123!» and a note under the keyboard — which is the attack
        // this is meant to be about. Letters and digits are still both asked for,
        // since twelve characters are otherwise free to be a telephone number or
        // a date of birth, and those are the first things anybody tries.
        Password::defaults(function () {
            $password = Password::min(12)->letters()->numbers();

            // ->uncompromised() asks api.pwnedpasswords.com whether this password
            // turns up in a published leak. That is a request over the network
            // for every password typed, so it belongs only where passwords are
            // real: in the test suite it would be one call per assertion, and on
            // a developer's machine — often with no internet at all — a wait and
            // then nothing, since the verifier counts a failed request as "not
            // leaked". It fails open on the company's shared hosting too, which
            // is a reason to expect little of it rather than to leave it out.
            return $this->app->isProduction() ? $password->uncompromised() : $password;
        });

        // One account passes every authorization check, including rights added
        // later: the system administrator. There is exactly one of them, and
        // somebody has to be able to reach everything — not least the page where
        // rights are handed out.
        //
        // «Администратор» is not that: it is an ordinary position whose rights are
        // ticked in the access table like any other, so what an administrator may
        // do is what somebody decided they may do. What only a system
        // administrator can do — appoint an administrator, or take the role away —
        // is guarded where roles are assigned, since it is a rule about roles
        // rather than an ability.
        Gate::before(fn (User $user) => $user->hasRole(Access::SOLE_ROLE) ? true : null);

        // Every other right is a permission from App\Support\Access, carried by
        // a position or given to one person on their card. Spatie answers "can"
        // for those out of the permissions table, and a personal exception is
        // applied in User::hasPermissionTo().

        // The exception is a form that saves a whole block of a card: it asks one
        // question — "may this person change anything in here?" — which is an
        // answer derived from the lines of that block rather than a right of its
        // own. Defined so a route can name it.
        foreach (array_keys(EmployeeFields::GROUPS) as $group) {
            Gate::define(
                EmployeeFields::blockGate($group),
                // editableBy() answers every part of the question: whose card this
                // is, which of its lines are readable — nobody retypes what they
                // cannot see — and which of those are theirs to change.
                fn (User $user, ?User $employee = null) => array_intersect(
                    EmployeeFields::ofGroup($group),
                    EmployeeFields::editableBy($user, $employee),
                ) !== [],
            );
        }

        // The equipment section is open to whoever sees any part of the fleet,
        // and its journal to whoever reads any part of that.
        Gate::define('equipment.view.any', fn (User $user) => EquipmentAccess::sees($user));
        Gate::define('equipment.journal.any', fn (User $user) => EquipmentAccess::readsJournal($user));

        // The directories are five lists behind one door: the door opens for
        // whoever may read any of them, and each list then answers for itself.
        Gate::define('directories.view.any', fn (User $user) => Directories::sees($user));

        // Changing a list takes reading it as well. The pair is a gate of its own
        // because the bare right would pass on its own — a permission answers
        // before any gate of the same name would.
        foreach (array_keys(Directories::LISTS) as $list) {
            Gate::define("directories.manage.{$list}", fn (User $user) => Directories::canEdit($user, $list));
        }

        // The photograph is a line of the card, and the line has a right of its
        // own. The gate is needed because the right alone cannot tell whose card it
        // is: one's own photograph is a different right from a colleague's, and a
        // permission answers before any gate of the same name would.
        Gate::define(
            'employees.photo',
            fn (User $user, ?User $employee = null) => in_array('avatar', EmployeeFields::editableBy($user, $employee), true),
        );
    }
}
