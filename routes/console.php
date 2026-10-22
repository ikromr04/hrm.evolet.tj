<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
|
| The application lives on ordinary shared hosting: nothing may stay running
| there, so there is no queue worker and no Supervisor to keep one alive. What
| the host does offer is cron, and one line of it drives everything below:
|
|     * * * * * php /home/USER/hrm/artisan schedule:run >/dev/null 2>&1
|
| Every task runs inside that one process, through Artisan::call(), rather than
| as Schedule::command(). The latter starts a child process with proc_open(),
| which such hosts commonly switch off — and the schedule would then fail without
| a word to anybody.
|
*/

// The letters. Every one of them leaves through the queue (see MailQueueTest),
// so once a minute the queue is emptied and the worker goes away again: it stops
// as soon as nothing is waiting, and after 50 seconds at the latest, before the
// next minute starts another.
//
// A letter that fails — the mail server was busy — is tried three times, a
// minute apart, and then set aside among the failed ones, where `queue:failed`
// shows it and `queue:retry all` sends it again.
//
// The lock lasts five minutes rather than the default day: a host that kills the
// process mid-run would otherwise leave the lock behind, and no letter would
// leave until the next morning.
Schedule::call(fn () => Artisan::call('queue:work', [
    '--stop-when-empty' => true,
    // Nothing waiting means done, at once, rather than after the three seconds
    // a worker that stays around would nap for.
    '--sleep' => 0,
    '--max-time' => 50,
    '--tries' => 3,
    '--backoff' => 60,
]))
    ->name('queue:work (drain the queue)')
    ->everyMinute()
    ->withoutOverlapping(5);

// A letter that never left holds whatever it was going to say — a first password
// among other things, encrypted. A month is long enough to notice and resend it.
Schedule::call(fn () => Artisan::call('queue:prune-failed', ['--hours' => 24 * 30]))
    ->name('queue:prune-failed')
    ->dailyAt('03:00');

// Reset links are good for an hour; the rows they leave behind are not cleared
// by anything else.
Schedule::call(fn () => Artisan::call('auth:clear-resets'))
    ->name('auth:clear-resets')
    ->dailyAt('04:00');

// Expired sessions need no line here: the database session driver sweeps them
// on its own, on a share of ordinary requests (session.lottery).

/*
|--------------------------------------------------------------------------
| Daily reminders
|--------------------------------------------------------------------------
|
| Commands that speak to people once a day. Written the same way as the tasks
| above, so they need no proc_open() either. Times are read in the
| application's timezone (APP_TIMEZONE), not the server's.
|
| Daily tasks are set on the hour. Some hosts allow cron no more often than
| every five or fifteen minutes; a task due at 09:10 is simply never run there,
| while one due at 09:00 is met by any such interval.
|
*/
