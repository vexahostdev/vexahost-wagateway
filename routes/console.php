<?php

use App\Jobs\BersihkanEksporJob;
use App\Jobs\BillingCycleJob;
use App\Jobs\EksekusiPenghapusanAkunJob;
use App\Jobs\KabariDaftarTungguJob;
use App\Jobs\PantauKesehatanJob;
use App\Jobs\PantauNomorJob;
use App\Jobs\PruneOldRecordsJob;
use App\Jobs\RekamStatusJob;
use App\Jobs\SyncSessionStatusJob;
use Illuminate\Support\Facades\Schedule;

// Menutup celah callback: kalau container engine mati mendadak, tidak ada
// event `disconnected` yang terkirim, sehingga dashboard akan terus menampilkan
// sesi sebagai terhubung padahal sudah tidak.
Schedule::job(new SyncSessionStatusJob)->everyMinute()->withoutOverlapping();

Schedule::job(new PruneOldRecordsJob)->dailyAt('03:15');

// Seluruh siklus penagihan: menutup tagihan lewat tempo, menerbitkan tagihan
// perpanjangan, mengirim pengingat, menghentikan pengiriman yang lewat jatuh
// tempo, dan melepas sesi yang masa tenggangnya habis.
//
// Pagi hari dengan sengaja. Pelanggan yang membaca pengingat pukul sembilan
// masih punya sehari penuh untuk membayar; pengingat tengah malam terbaca
// keesokan paginya bersamaan dengan layanan yang sudah berhenti.
Schedule::job(new BillingCycleJob)->dailyAt('08:00')->withoutOverlapping();

/*
| Pengawas kesehatan, tiap jam.
|
| Tiap jam dan bukan tiap menit: seluruh keadaan yang diawasinya berlangsung
| berjam-jam kalau terjadi, dan memeriksanya tiap menit cuma menambah beban
| pada server yang RAM-nya justru sedang diperebutkan. Penanda hariannya
| membuat satu keadaan menghasilkan satu kabar per hari, berapa kali pun job
| ini berjalan.
*/
Schedule::job(new PantauKesehatanJob)->hourly()->withoutOverlapping();

// Peringatan dini nomor yang mulai dibatasi WhatsApp — lihat PantauNomorJob.
Schedule::job(new PantauNomorJob)->hourly()->withoutOverlapping();

/*
| Mengabari daftar tunggu kapasitas, tiap jam.
|
| Halaman penolakan checkout menjanjikan "kami mengabari Anda begitu slotnya
| tersedia, tanpa perlu menekan apa pun lagi". Job inilah seluruh isi janji itu;
| tanpa ia, daftar tunggu cuma tabel yang tidak pernah dibaca siapa pun dan
| kalimatnya kembali jadi sopan-tapi-bohong.
|
| Tiap jam dan bukan tiap menit: kapasitas bebas ketika langganan berakhir atau
| workspace dihentikan, dan keduanya peristiwa harian.
*/
Schedule::job(new KabariDaftarTungguJob)->hourly()->withoutOverlapping();

/*
| Perekam status + denyut ke pemantau luar, tiap menit.
|
| Tiap menit dan bukan tiap jam karena inilah yang mengukur ketersediaan yang
| dijanjikan SLA. Sampel per jam berarti gangguan 40 menit bisa terlewat
| seluruhnya, dan angka uptime yang dihitung dari sampel sejarang itu bukan
| angka yang bisa dipertanggungjawabkan saat pelanggan mengajukan klaim kredit.
|
| `withoutOverlapping` penting di sini: pemeriksaan engine punya batas waktu
| sendiri, dan pemeriksaan yang menumpuk saat engine lambat akan menghitung
| menit yang sama berkali-kali.
*/
Schedule::job(new RekamStatusJob)->everyMinute()->withoutOverlapping();

/*
| Menghapus akun yang masa tunggunya habis, dan membuang berkas ekspor yang
| kedaluwarsa.
|
| Dini hari supaya penghapusan permanen — yang memutus sesi WhatsApp dan
| menyentuh banyak baris sekaligus — tidak berbarengan dengan jam tersibuk.
*/
Schedule::job(new EksekusiPenghapusanAkunJob)->dailyAt('03:40')->withoutOverlapping();
Schedule::job(new BersihkanEksporJob)->dailyAt('03:50')->withoutOverlapping();

/*
| Perubahan akun yang belum sampai ke aplikasi vexahost dikirim ulang tiap menit.
| Percobaan pertamanya seketika lewat antrean; yang ini menangkap sisanya saat
| aplikasi seberang sedang deploy — kalau tidak, kata sandi yang diganti tepat
| saat itu tidak pernah sampai dan satu orang berakhir dengan dua kata sandi.
*/
Schedule::command('akun-tertaut:kirim')->everyMinute()->withoutOverlapping();
