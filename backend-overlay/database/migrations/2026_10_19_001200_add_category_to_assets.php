<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('assets', function (Blueprint $table) {
            // Existing business records require deliberate classification, never guessed by type/name.
            $table->foreignId('category_id')->nullable()->constrained('ticket_categories')->restrictOnDelete();
            $table->index(['customer_id', 'category_id']);
        });
    }
    public function down(): void {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'category_id']);
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
