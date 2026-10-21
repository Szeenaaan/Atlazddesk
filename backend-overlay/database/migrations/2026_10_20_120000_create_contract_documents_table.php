<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amc_contract_id')->constrained('amc_contracts')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('file_name', 200);
            $table->string('file_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_documents');
    }
};
