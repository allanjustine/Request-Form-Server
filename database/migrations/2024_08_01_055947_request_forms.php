<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('form_type'); // Store the type of form, e.g., 'cash_advance'
            $table->json('form_data'); // Store form data in a JSON column
            $table->string('currency')->default('PHP')->change();
            $table->string('status')->default('Pending');
            $table->json('attachment');
            $table->json('noted_by');
            $table->string('currency')->default('PHP');
            $table->json('approved_by');
            $table->string('branch_code');
            $table->string('request_code');
            $table->string('completed_code')->nullable();
            $table->string('kind_of_request')->nullable();
            $table->string('cancelation_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            //$table->foreign('approvers_id')->references('id')->on('custom_approvers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};