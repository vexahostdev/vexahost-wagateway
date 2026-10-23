import { proto } from '@whiskeysockets/baileys';

/**
 * Isi pesan yang baru dikirim, per sesi, untuk menjawab permintaan kirim ulang.
 *
 * Saat ponsel penerima gagal mendekripsi sebuah pesan, ia meminta pengirimnya
 * mengirim ulang, dan Baileys menanyakan isinya lewat `getMessage`. Baileys
 * punya cache sendiri, tapi hanya lima menit dan hilang tiap sambung ulang;
 * ponsel penerima yang baru online berjam-jam kemudian tidak terlayani, dan di
 * layarnya tertulis "Menunggu pesan ini" selamanya.
 *
 * Dibatasi UKURAN, bukan jumlah. Batas jumlah saja (versi pertama: 1000 pesan)
 * terukur 0,5 MB per sesi untuk notifikasi biasa tapi 62 MB per sesi untuk
 * pesan teks sepanjang batas WhatsApp — dikali `WA_MAX_SESSIONS`, cukup untuk
 * membuat engine dibunuh OOM killer di VPS yang RAM-nya dibagi dengan PHP,
 * MySQL, dan aplikasi lain. Pesannya disimpan sebagai protobuf terkode, bentuk
 * paling ringkas, dan totalnya tidak pernah melewati `batasByte` per sesi.
 *
 * Batas jumlah tetap ada sebagai rem kedua: tiap entri membawa beban ±700 byte
 * di luar isinya, jadi jutaan pesan "OK" yang masing-masing beberapa byte akan
 * lolos dari batas ukuran sementara bebannya yang membengkak.
 *
 * Hanya di memori: permintaan kirim ulang sesudah engine di-deploy ulang
 * memang tidak bisa dilayani dari sini.
 */
export class ArsipKirim {
    #perSesi = new Map();
    #batasByte;
    #maks;
    #umurMs;

    constructor({ batasByte = 1024 * 1024, maks = 2000, umurMs = 24 * 60 * 60 * 1000 } = {}) {
        this.#batasByte = batasByte;
        this.#maks = maks;
        this.#umurMs = umurMs;
    }

    simpan(sessionId, id, pesan) {
        if (!id || !pesan) {
            return;
        }

        let terkode;

        try {
            // Disalin ke ArrayBuffer sendiri: hasil `finish()` yang kecil bisa
            // berupa potongan dari kolam Buffer bersama Node, dan menyimpannya
            // menahan seluruh lempengan 8 KB tempat ia berada.
            terkode = new Uint8Array(proto.Message.encode(pesan).finish());
        } catch {
            // Arsip ini pelengkap; Baileys masih punya cache lima menitnya sendiri.
            return;
        }

        if (terkode.length > this.#batasByte) {
            return;
        }

        let arsip = this.#perSesi.get(sessionId);

        if (!arsip) {
            arsip = { isi: new Map(), byte: 0 };
            this.#perSesi.set(sessionId, arsip);
        }

        this.#buang(arsip, id);
        arsip.isi.set(id, { terkode, waktu: Date.now() });
        arsip.byte += terkode.length;

        // Map menjaga urutan sisip, jadi kunci pertama selalu yang tertua.
        while (arsip.byte > this.#batasByte || arsip.isi.size > this.#maks) {
            this.#buang(arsip, arsip.isi.keys().next().value);
        }
    }

    ambil(sessionId, id) {
        const arsip = this.#perSesi.get(sessionId);
        const isi = id ? arsip?.isi.get(id) : undefined;

        if (!isi) {
            return undefined;
        }

        if (Date.now() - isi.waktu > this.#umurMs) {
            this.#buang(arsip, id);

            return undefined;
        }

        try {
            return proto.Message.decode(isi.terkode);
        } catch {
            return undefined;
        }
    }

    /** Total byte yang ditahan untuk satu sesi. */
    byteTerpakai(sessionId) {
        return this.#perSesi.get(sessionId)?.byte ?? 0;
    }

    lupakan(sessionId) {
        this.#perSesi.delete(sessionId);
    }

    #buang(arsip, id) {
        const isi = arsip.isi.get(id);

        if (isi) {
            arsip.isi.delete(id);
            arsip.byte -= isi.terkode.length;
        }
    }
}
