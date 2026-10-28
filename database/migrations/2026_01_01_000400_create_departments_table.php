<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Departments form a tree: any department can sit under any other, and one
     * with no parent is a department of the company itself.
     *
     * Who belongs to which department is not filed here by hand. Somebody
     * belongs to a department because they hold one of its positions, and they
     * lead it because that position is a leading one of it, so department_user
     * is the same question answered in advance — derived from the positions,
     * written by App\Support\Membership and by nothing else. It is kept all the
     * same, because equipment access reads it, the chart counts with it and the
     * staff lists join on it.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Departments are known around the company by their short form —
            // «ДУЧР» rather than «Департамент управления человеческими
            // ресурсами» — and that is what most pages name them by. Empty is
            // allowed, and then the full name is used. Fifty characters: an
            // abbreviation is a handful of letters, sometimes with a qualifier
            // after it («ОУР Тдж»), and the limit is there to keep a second full
            // name out of the field rather than to be reached.
            $table->string('abbreviation', 50)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A department can have several heads, all of them its members: two
            // of them are led by two people today, and a company that works
            // that way is not an error to be refused.
            $table->boolean('is_head')->default(false);
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
