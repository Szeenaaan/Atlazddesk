<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amc_mail_settings', fn (Blueprint $table) => $table->string('notification_email')->nullable());
        Schema::create('amc_ticket_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('ticket_comments')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_category', 40)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amc_ticket_mail_deliveries');
        Schema::table('amc_mail_settings', fn (Blueprint $table) => $table->dropColumn('notification_email'));
    }
};
