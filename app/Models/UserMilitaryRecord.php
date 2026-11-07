<?php

namespace App\Models;

/**
 * One line of «Отношение к воинской обязанности» and the page of the military
 * book that says it.
 *
 * The third list of its kind. A standing is usually one line, but a card may
 * carry the reserve rank beside the fact of registration — and two answers
 * crammed into one line used to mean one scan for both of them.
 */
class UserMilitaryRecord extends UserPaperRecord
{
    protected $table = 'user_military_records';
}
