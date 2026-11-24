# SpendCheck — Instruksi Codex

## A. Project context

- Laravel 13, PHP 8.5, MySQL 8; Blade, Tailwind CSS 4, dan Vite.
- Modular monolith untuk satu organisasi; target deployment shared hosting.
- Utamakan solusi gratis, open-source, ringan, dan sederhana. Hindari layanan berbayar, Redis, daemon, worker permanen, Docker, Kubernetes, dan microservices kecuali benar-benar diperlukan dan disetujui.

## B. Source of truth

- `PHASE_3B_DESIGN.md` adalah acuan utama implementasi Phase 3B. `PHASE_3A_DESIGN.md` dan spesifikasi final SpendCheck lain yang tersedia menjadi acuan keputusan bisnis keseluruhan.
- Implementasi repository adalah acuan kondisi teknis aktual.
- Ikuti keputusan bisnis yang sudah tercatat; jangan mengarang aturan atau meminta pengguna mengulang keputusan terdokumentasi.
- Jika dokumen dan kode bertentangan, identifikasi dampaknya sebelum mengubah kode. Untuk konflik bisnis atau risiko data yang belum terselesaikan, berhenti dan laporkan.

## C. Business rules

- Sarpras dan Divisi Keuangan adalah unit berbeda. Phase 3A `cash_movements` adalah satu-satunya ledger kas; saldo dihitung darinya, bukan dari kolom saldo yang dapat diedit.
- Pisahkan arus kas, realisasi pengeluaran, dan pertanggungjawaban. Pencairan bon adalah kas keluar, bukan beban final. Item pertanggungjawaban yang diterima menjadi beban final tanpa movement kas kedua. Hindari double counting.
- Transfer antar rekening bukan pendapatan atau pengeluaran.
- Koreksi transaksi posted memakai reversal dan replacement; pertahankan keputusan final yang sudah ada.

## D. Security and access

- Otorisasi wajib di backend dan mengikuti least privilege. Admin System tidak otomatis memiliki hak finansial; Divisi Keuangan read-only.
- Driver dan Petugas Sarpras hanya boleh mengakses data sesuai kewenangan serta scope miliknya.
- Audit perubahan finansial dan konfigurasi penting.
- Gunakan validasi backend, constraint database, transaksi, locking, dan idempotency sesuai kebutuhan. Jangan mengekspos ID database internal melalui API.

## E. Development safety and conventions

- Jangan mengubah `.env`, menghapus atau mereset data, menjalankan `migrate:fresh`/`migrate:refresh`, atau melakukan operasi destruktif.
- Batasi perubahan pada modul yang diperlukan. Pertahankan perubahan lokal dan file untracked. Jangan commit, push, merge, atau mengganti branch tanpa instruksi eksplisit.
- Jangan menambah dependency jika kebutuhan dapat dipenuhi dengan yang tersedia. Konfirmasi versi package sebelum memakai API yang bergantung pada versi.
- Buat atau ubah dokumentasi hanya jika diminta atau termasuk ruang lingkup tugas.
- Ikuti struktur, pola, komponen, dan gaya kode yang sudah ada. Gunakan Artisan untuk membuat file Laravel, named route untuk tautan, tipe eksplisit dan kurung kurawal di PHP, serta factory yang tersedia dalam test.
- Ikuti skill relevan yang tersedia. Jika Laravel Boost tersedia, utamakan `database-schema`/`database-query` untuk inspeksi database dan `search-docs` untuk API framework yang relevan.
- Hindari abstraksi atau kompleksitas yang belum dibutuhkan. Jangan membuat skrip verifikasi atau memakai Tinker jika test yang ada sudah memadai.

## F. Token efficiency

- Baca instruksi dan dokumen yang relevan dengan tugas; mulai dari file, simbol, route, migration, atau test yang terkait.
- Jangan membaca ulang seluruh repository atau mengulang pemeriksaan yang hasilnya masih berlaku.
- Hindari eksplorasi, refactoring, dan perubahan di luar ruang lingkup. Implementasikan satu subfase sesuai batasnya.
- Jangan mengulang spesifikasi panjang dalam laporan. Jika ketidakpastian berisiko pada data atau aturan bisnis, berhenti dan laporkan.

## G. Testing and verification

- Jalankan test terkait terlebih dahulu dan seluruh suite saat subfase selesai.
- Gunakan database MySQL test terpisah untuk locking/concurrency. Jangan jalankan test yang dapat mengubah database development atau production.
- Setelah mengubah PHP, jalankan `vendor/bin/pint --dirty --format agent`; selalu jalankan `git diff --check`.
- Jalankan build Vite jika frontend berubah.
- Laporkan hitungan passed, failed, dan skipped dengan akurat.

## H. Final report

Batasi laporan akhir pada:

1. Hasil implementasi.
2. File penting yang dibuat atau diubah.
3. Migration dan seeder.
4. Hasil test dan verifikasi.
5. Kendala atau risiko.
6. Konfirmasi tidak ada commit/push.
