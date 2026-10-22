<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * «Уведомления» — a page that says it is still being built.
 *
 * The bell and the menu both lead here, so neither leads nowhere while the
 * section is put aside. Nothing is read: the table the notifications were kept
 * in has been dropped, and whatever replaces it will be written from scratch.
 */
class NotificationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('notifications');
    }
}
