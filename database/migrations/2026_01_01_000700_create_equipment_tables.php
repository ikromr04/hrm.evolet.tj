<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What a row that breaks the one-holder rule is told, in either database. */
    private const ONE_HOLDER = 'Оборудование не может числиться и за сотрудником, и за отделом.';

    /**
     * Company hardware: a directory of categories ("Ноутбуки", "Мониторы") and
     * the units themselves. A unit belongs to the company, not to a person —
     * it is bought, handed out, returned, serviced and eventually written off,
     * so it exists on its own and merely points at whoever holds it now.
     *
     * A category says which fields its units carry, because which of them apply
     * depends on the category: a monitor has a diagonal and no processor, a
     * phone has an IMEI. Even what a unit is called and the number on its
     * sticker are fields, so a category can word them as it likes — a printer's
     * "Наименование" may read "Модель" — and those two keep a role, because the
     * list, the journal, the letters and the search all speak of a unit by them.
     */
    public function up(): void
    {
        Schema::create('equipment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // «Инвентаризация» is read in three — what people work on, the
            // furniture, and what the office itself runs on — but the company
            // owns one stock all the same, so the three are not three tables
            // but this one question asked of a category.
            $table->string('section', 32)->default('equipment');
            // Which of the drawings the interface has stands for this category,
            // picked in the directory. Empty means the plain box.
            $table->string('icon', 30)->nullable();
            // Whether a unit of this category comes with anything: a laptop has a
            // power supply and a bag, a mouse comes on its own. Where it does not,
            // the card and the form say nothing about a "Комплектация" at all.
            $table->boolean('has_accessories')->default(true);
            $table->timestamps();
        });

        // What units of a category are described by. Which field is which is
        // decided in the directory rather than in code — so a new field costs
        // nobody a deployment.
        Schema::create('equipment_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->constrained()->cascadeOnDelete();

            // Which of the two things a unit cannot be without this field is:
            // "title" names the unit, "inventory" carries its number. Null for
            // an ordinary field; one of each role per category.
            $table->string('role', 20)->nullable();

            $table->string('name', 100);
            // text, number, date, boolean, select — what the form shows and what
            // the value is checked against.
            $table->string('type', 20)->default('text');
            // For "select": the values the list offers.
            $table->json('options')->nullable();
            $table->boolean('required')->default(false);
            // The order the card and the form put them in.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // One field per name in a category, or the card would read twice the same.
            $table->unique(['equipment_type_id', 'name']);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_type_id')->constrained()->cascadeOnDelete();

            // The state it was last seen in, and when it is due to be looked at again.
            $table->string('condition', 200)->nullable();
            $table->date('checked_at')->nullable();
            $table->date('next_inventory_at')->nullable();

            // What comes with it: "Блок питания 65 Вт", "Сумка", a bag of labels.
            $table->json('accessories')->nullable();

            $table->enum('status', ['issued', 'stock', 'written_off'])->default('stock')->index();

            // Who holds it now: one colleague, or one department, or nobody at
            // all while it sits on the balance sheet. Some hardware is nobody's
            // in particular — a printer in the accounts office, the router in
            // the ceiling — and there the department answers for it.
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            // This key lets go rather than cascading: a department that is
            // dissolved does not take the company's printers with it. They fall
            // back to holding nobody, which is what the status column beside
            // them has always meant by an empty holder.
            $table->foreignId('holder_department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->date('issued_at')->nullable();
            $table->date('written_off_at')->nullable();

            $table->timestamps();
        });

        // What one unit has in one of its category's fields. Everything is kept
        // as text and read back through the field's type: the questions asked of
        // this table are "what does this unit have" and "what changed", never
        // "sum it up", so one column is enough and the field stays free to
        // change its type without a migration.
        Schema::create('equipment_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['equipment_id', 'equipment_field_id']);
        });

        $this->oneHolder();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop trigger if exists equipment_one_holder_on_insert');
        DB::statement('drop trigger if exists equipment_one_holder_on_update');

        Schema::dropIfExists('equipment_field_values');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('equipment_fields');
        Schema::dropIfExists('equipment_types');
    }

    /**
     * The rule the database keeps itself: a unit is held by a colleague or by a
     * department, and never by both at once.
     *
     * It is the invariant a pair of columns cannot carry, and the one that
     * matters: a unit standing against two names would be counted twice, shown
     * twice and asked back from two people, and no reading of the fleet could
     * say which of the two was wrong. Whether a category's units may be held by
     * a department at all is a rule about meaning, and the model keeps that
     * one; this keeps the shape, so no code path, no seeder and no console
     * session can write a row that belongs to a person and a department
     * together.
     *
     * It is a pair of triggers rather than a check, because MySQL will not have
     * it the other way: a column named in a foreign key that sets it null on
     * delete may not also be named in a check — the key writes that column
     * itself, and the server refuses to be held to a condition over a value it
     * is about to assign. The key is the half worth keeping, so the rule takes
     * the shape the table leaves for it and says what the check would have
     * said, at exactly the moments it would have been asked.
     */
    private function oneHolder(): void
    {
        $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        $held = 'new.holder_user_id is not null and new.holder_department_id is not null';
        $message = self::ONE_HOLDER;

        foreach (['insert', 'update'] as $when) {
            $refuse = $sqlite
                ? "for each row when {$held} begin select raise(abort, '{$message}'); end"
                : "for each row begin if {$held} then signal sqlstate '45000' set message_text = '{$message}'; end if; end";

            DB::statement("create trigger equipment_one_holder_on_{$when} before {$when} on equipment {$refuse}");
        }
    }
};
