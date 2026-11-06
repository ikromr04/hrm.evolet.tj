<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The papers a company keeps on file for every employee, and what each person
 * has handed in.
 *
 * A personnel department asks the same things of everybody — a copy of the
 * passport, of the tax number, of the diploma — and what it asks for is not
 * fixed: a new rule arrives and one more paper joins the list. So the list is a
 * reference list like the languages and the citizenships, kept by the people
 * who keep the rest of them, and a card answers it rather than carrying columns
 * of its own that a migration would have to add every time.
 *
 * Three tables, because a document is three things at once: what is being asked
 * for (the type), the one answer a person has to it, and the files that answer
 * is made of. A copy of a passport may be one scan, or a photograph of each
 * page, or several of both — the paper does not decide, the office does, and it
 * says so on the type.
 *
 * A document handed in by the person is waiting to be looked at; one put there
 * by the office is already looked at, by the person who put it there. That is
 * the whole of what the status says, and why the row remembers who handed it in
 * as well as who passed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // What exactly is wanted — «Обе стороны, разворот с пропиской» —
            // written once here rather than explained to each person in turn.
            $table->string('hint')->nullable();
            // How many files one answer is made of: how few will do, and how
            // many are too many. One apiece is the usual case; a passport wants
            // several, and «две фотографии» wants exactly two.
            $table->unsignedTinyInteger('min_files')->default(1);
            // Empty where there is no number that is too many: a list of
            // certificates is as long as somebody's courses were.
            $table->unsignedTinyInteger('max_files')->nullable()->default(1);
            // And how they may arrive. A scan of a diploma is chosen from a
            // disk; a page of a work book is likelier to be photographed on the
            // spot, and a type says which of the two it will take.
            $table->boolean('accepts_files')->default(true);
            $table->boolean('accepts_camera')->default(false);
            // Whether it is wanted from everybody, or only from whoever happens
            // to have one. Both are listed on a card; only the first is
            // something the office goes and asks for.
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            // pending | approved | rejected. One answer per person per type:
            // the card shows what stands now, and a paper that was turned down
            // is replaced rather than filed beside the one that replaced it.
            $table->string('status', 20);
            // Why it was turned down, in the words of whoever turned it down.
            // There is no refusing without one: a document sent back with no
            // reason is a document somebody will hand in again unchanged.
            $table->text('comment')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'document_type_id']);
        });

        Schema::create('employee_document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_document_id')->constrained()->cascadeOnDelete();
            // Where it lies on the private disk, and what it was called when it
            // arrived: the name is shown, the path is never sent anywhere.
            $table->string('path');
            $table->string('name');
            $table->unsignedInteger('size');
            $table->string('mime', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_document_files');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('document_types');
    }
};
