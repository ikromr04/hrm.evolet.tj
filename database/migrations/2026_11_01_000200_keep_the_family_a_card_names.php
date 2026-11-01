<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Состав семьи» — the half of part 08 of the sheet that had nowhere to be
 * kept: the mother, the father, the brother, with what each of them does.
 *
 * A table of its own, like the children, because there is no saying how many
 * there are; and apart from the children, because a child is a child whoever
 * writes the card down and a relative has to say what they are to this person.
 * The spouse keeps its two columns on the details row rather than joining this
 * list: there is at most one, and the card asks about them beside the marital
 * status, which is where somebody looking for a spouse looks.
 *
 * A year of birth and not a date: the sheet prints the year, and the day a
 * brother was born is not something a personnel department is told. The work or
 * the studying is one line — «Пенсионерка», «Студент, ТТУ им. Осими» — because
 * that is how the sheet carries it and parsing it into a place and a post would
 * invent two answers out of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_relatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What they are to this person: «Мать», «Отец», «Брат». Written out
            // rather than chosen from a list of kinds — a card may name a
            // stepmother or a guardian, and a directory of ways to be related
            // is not a directory a company keeps.
            $table->string('relation', 50);
            $table->string('full_name', 150);

            // Both empty where nobody has asked yet: a card half filled in is
            // the normal state of a card.
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('occupation', 200)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_relatives');
    }
};
