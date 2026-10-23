<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rem pengiriman — lihat App\Services\RemPengiriman untuk alasannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_sessions', function (Blueprint $table) {
            // Nomor yang sedang dihitung masa pemanasannya, dan sejak kapan.
            // Disimpan terpisah dari `phone_number` dan `connected_at`: keduanya
            // berubah tiap sambung ulang atau putus tautan, sedangkan umur
            // sebuah nomor di gateway ini tidak boleh ikut kembali ke nol.
            $table->string('warmup_phone', 20)->nullable()->after('disconnect_reason');
            $table->timestamp('warmup_started_at')->nullable()->after('warmup_phone');
            $table->timestamp('warmup_skipped_at')->nullable()->after('warmup_started_at');
            $table->index('warmup_phone');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Kapan pesan yang ditunda masa pemanasan akan dikirim. Kosong untuk
            // pesan yang langsung masuk antrean.
            $table->timestamp('scheduled_at')->nullable()->after('batch_id');
        });

        Schema::create('opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('keyword', 20);
            $table->timestamps();

            $table->unique(['workspace_id', 'phone']);
        });

        Schema::create('warmup_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('wa_session_id')->constrained('wa_sessions')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('sent')->default(0);

            $table->unique(['wa_session_id', 'day']);
        });

        /*
         | Nomor yang sudah tertaut saat rem ini dipasang dibebaskan total dari
         | masa pemanasan — keputusan Ryan (23 Okt 2026): nomor yang sedang
         | berjalan tidak boleh tiba-tiba merasakan pesannya dijadwalkan ulang
         | sesudah deploy. Yang dipanaskan hanya nomor yang ditautkan sesudahnya.
         |
         | Sengaja lewat query builder, bukan model, supaya sesi yang sudah
         | dihapus lunak ikut tercatat: `catatNomorTertaut()` mewarisi status
         | bebas ini kalau nomor yang sama ditautkan ulang di sesi baru.
        */
        DB::table('wa_sessions')
            ->whereNotNull('phone_number')
            ->update([
                'warmup_phone' => DB::raw('phone_number'),
                'warmup_started_at' => DB::raw('created_at'),
                'warmup_skipped_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_counters');
        Schema::dropIfExists('opt_outs');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });

        Schema::table('wa_sessions', function (Blueprint $table) {
            $table->dropIndex(['warmup_phone']);
            $table->dropColumn(['warmup_phone', 'warmup_started_at', 'warmup_skipped_at']);
        });
    }
};
