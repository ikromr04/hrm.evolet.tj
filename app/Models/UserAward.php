<?php

namespace App\Models;

/**
 * One line of «Государственные награды, поощрения» and the paper behind it —
 * the decree, the certificate, the letter of thanks.
 *
 * Kept as a list for the reason the degrees are: a medal and a commendation
 * are two answers, and a card that held one of them held the other nowhere.
 */
class UserAward extends UserPaperRecord
{
    protected $table = 'user_awards';
}
