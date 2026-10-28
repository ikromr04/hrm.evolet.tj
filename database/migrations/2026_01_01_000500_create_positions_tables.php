<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Positions are what people do and are public; access is governed by roles
     * separately. An employee can hold several of them, and holding one is the
     * only way into a department.
     *
     * A position belongs to one department rather than to the company as a
     * whole: the work of an «Аналитик» in one department is not the work of an
     * «Аналитик» in another, so the pair is the real thing and the name only
     * has to be unique inside a department.
     *
     * What a position answers for is a list of duties, in the order they were
     * entered, and a person holding the position carries some of that list
     * rather than all of it — in a department of six analysts "everybody
     * carries everything" was never true.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            // Empty is allowed, because a position nobody holds yet may not know
            // where it belongs; the forms, not the column, are where a department
            // is required. A department that is deleted leaves its positions
            // behind rather than taking them: a position is a directory entry
            // with holders and duties of its own, and it reads as unplaced until
            // somebody re-files it, the same way a sub-department comes loose
            // when its parent goes.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // A leading position of its department: whoever holds it heads the
            // department. The flag is on the job rather than on the person,
            // because somebody leads a department by holding its leading job and
            // stops leading it when the job is taken away.
            $table->boolean('is_head')->default(false);
            $table->timestamps();

            // «Аналитик» may exist in two departments, never twice in one.
            $table->unique(['department_id', 'name']);
        });

        Schema::create('position_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'user_id']);
        });

        Schema::create('position_duties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            // Three hundred characters is what the form accepts for one duty.
            $table->string('name', 300);
            // The order they were entered in, which is the order they are read
            // in. Called "order" rather than "position", which this table
            // already uses for the position the duty belongs to.
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('position_duty_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_duty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_duty_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('position_duty_user');
        Schema::dropIfExists('position_duties');
        Schema::dropIfExists('position_user');
        Schema::dropIfExists('positions');
    }
};
