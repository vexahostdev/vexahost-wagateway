<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alasan putus terakhir yang dilaporkan engine (lihat App\Support\AlasanPutus).
     *
     * Disimpan sebagai keadaan tersendiri, bukan dititipkan pada
     * `connect_failures`: penjadwal dan bootstrap perlu tahu "jangan dicoba
     * otomatis" untuk nomor yang diblokir atau diambil alih, dan menandainya
     * lewat penghitung percobaan membuat penjadwal mengumumkan "berhenti
     * setelah delapan percobaan" untuk nomor yang tidak pernah dicoba sekali pun.
     */
    public function up(): void
    {
        Schema::table('wa_sessions', function (Blueprint $table) {
            $table->string('disconnect_reason', 40)->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('wa_sessions', function (Blueprint $table) {
            $table->dropColumn('disconnect_reason');
        });
    }
};
