<?php

namespace App\Models;

/**
 * One line of «Учёная степень, звание» and the diploma behind it.
 *
 * A card may name several: a degree and a title are two answers, and the list
 * is what lets the second one be written down with its own paper. What a
 * record holds is UserPaperRecord's business, which the awards and the military
 * records share.
 */
class UserDegree extends UserPaperRecord
{
    protected $table = 'user_degrees';
}
