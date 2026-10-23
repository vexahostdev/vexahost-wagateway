<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat koneksi per sesi.
     *
     * Sebelum tabel ini, pertanyaan "kenapa nomor saya logout" hanya bisa
     * dijawab dengan menggali log Coolify — yang tidak menyimpan riwayat
     * melewati satu kali deploy. `last_error` cuma memegang kejadian terakhir,
     * jadi pola seperti "putus tiap deploy" atau "diambil alih tiap malam"
     * tidak pernah terlihat.
     */
    public function up(): void
    {
        Schema::create('session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('wa_session_id')->constrained('wa_sessions')->cascadeOnDelete();

            $table->string('event', 32);
            $table->string('reason', 40)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('detail')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['wa_session_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_events');
    }
};
