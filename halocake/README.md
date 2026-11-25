# HaloCake — Monitoring Perlintasan Kereta Api

Proyek *full-stack* lokal berbasis **PHP 8.1+, MySQL/MariaDB (Laragon), HTML/CSS/JavaScript, dan MQTT → Node-RED**. Tampilan didasarkan pada `Proyek-Akhir.html` yang diberikan. Data dashboard **tidak lagi di-hardcode**: status sistem, palang, kedua sensor, jumlah gerbong, grafik harian, rekapitulasi, dan riwayat dibaca dari MySQL.

## 1. Cara instalasi di Windows dengan Laragon

1. Ekstrak file ZIP. Salin folder **`halocake`** (beserta seluruh isinya) ke:

   `C:\laragon\www\halocake\`

2. Buka **Laragon** lalu klik **Start All**. Pastikan web server (Apache) dan MySQL/MariaDB aktif. Jika Laragon berada di lokasi lain, gunakan folder `www` pada instalasi Laragon tersebut.
3. Klik **Database** di Laragon untuk membuka **HeidiSQL** (atau database manager yang terpasang). Sambungkan ke MySQL/MariaDB lokal, buka menu **File → Load SQL file**, lalu pilih:

   `C:\laragon\www\halocake\database\schema.sql`

   Jalankan seluruh SQL (tombol **Run** atau **F9** di HeidiSQL). Ini membuat database `halocake_monitoring`, tabel, dan satu perlintasan contoh. **Database tidak berisi aktivitas/angka fiktif.** Jika kamu sudah memasang phpMyAdmin, file SQL yang sama dapat diimpor lewat tab **Import**.
4. Cek `config/app.php`. Pengaturan lokal yang lazim pada Laragon adalah:

   ```php
   'db_host' => '127.0.0.1',
   'db_port' => 3306,
   'db_name' => 'halocake_monitoring',
   'db_user' => 'root',
   'db_pass' => '',
   ```

   Jika MySQL menggunakan password atau port berbeda, sesuaikan.
5. Klik **Reload** di Laragon bila ingin memakai alamat virtual host. Uji API di **http://localhost/halocake/api/health.php**. Jika berhasil, tampil `"ok":true` dan `"crossing_configured":true`. Jika fitur *Auto Virtual Hosts* aktif, kamu juga bisa memakai **http://halocake.test/api/health.php**.
6. Buka dashboard **http://localhost/halocake/** (atau **http://halocake.test/** jika virtual host aktif). Saat belum ada data MQTT, indikator seharusnya menampilkan **Data IoT tidak aktif** (ini benar). Statistik masih nol dan riwayat kosong.

> **Jangan buka HTML dengan `file:///...`**. Harus melalui web server Laragon (`http://localhost/halocake/` atau `http://halocake.test/`) supaya API PHP dapat diakses. URL `halocake.test` hanya tersedia bila *Auto Virtual Hosts* aktif dan proyek sudah di-*Reload*.

## 2. Menghubungkan Node-RED / ESP32 lewat MQTT

Alur: **ESP32 → MQTT `broker.hivemq.com`, topik `kereta/status` → Node-RED → `POST /api/ingest.php` → MySQL → dashboard**.

1. Jalankan Node-RED. Buka editor, biasanya **http://127.0.0.1:1880/**.
2. Menu kanan atas **Import → select a file**, pilih `nodered/flow-halocake.json`.
3. Buka node **Validasi JSON + header API**. Sesuaikan `API_KEY` agar sama dengan `ingest_key` di `config/app.php`, serta `API_URL` dengan alamat Apache. Jika Node-RED berjalan di komputer berbeda, `127.0.0.1` harus diganti ke **IP LAN komputer Laragon**, misalnya `http://192.168.1.10/halocake/api/ingest.php` (IP hanya contoh).
4. Klik **Deploy**, pastikan MQTT input node terhubung ke broker. Kirim JSON dari ESP32/Node-RED ke `kereta/status`.
5. Buka debug Node-RED; respons `"ok":true` membuktikan backend menerima data. Di MySQL, cek tabel `crossing_states`, `activities`, dan `train_passages`.

**Contoh JSON yang dikirim ke topik MQTT `kereta/status`:**

```json
{
  "statusSistem": "Closed",
  "statusPalang": "Tertutup",
  "sensor1": true,
  "sensor2": false,
  "jumlahGerbong": 8
}
```

Field boleh parsial (mis. hanya `jumlahGerbong`), tetapi untuk pembentukan **satu perjalanan kereta** yang benar, kirim status kondisi pada setiap transisi, dan akhiri dengan status **`Idle` / `Terbuka` / `sensor1=false` / `sensor2=false`**. Set kembali `jumlahGerbong` ke `0` sebelum mendeteksi kereta berikutnya. Jika ada status gerakan servo, `statusPalang` boleh `Menutup` atau `Membuka`.

Field yang diterima:

| JSON | Tipe | Nilai |
| --- | --- | --- |
| `statusSistem` | string | `Idle`, `Warning`, `Closed` |
| `statusPalang` | string | `Terbuka`, `Menutup`, `Tertutup`, `Membuka` |
| `sensor1` / `sensorA` | boolean | `true` atau `false` |
| `sensor2` / `sensorB` | boolean | `true` atau `false` |
| `jumlahGerbong` | integer | 0–1.000.000 |
| `aktivitas` | string (opsional) | Uraian event maksimal 240 karakter |

`ingest.php` juga menerima `snake_case` untuk beberapa field. Data valid tersimpan di database; aktivitas otomatis dibuat saat ada perubahan (bukan setiap heartbeat, supaya tidak dipenuhi entri duplikat).

## 3. Uji tanpa perangkat, hanya Laragon

Jalankan PowerShell dari dalam folder `halocake`:

```powershell
.\examples\test-ingest.ps1
```

Jika PowerShell membatasi eksekusi script, jalankan:

```powershell
powershell -ExecutionPolicy Bypass -File .\examples\test-ingest.ps1
```

Script mengirim urutan `Idle → Warning → Menutup → Tertutup → hitung 3 gerbong → Terbuka → Idle`, lalu menunggu 1 detik di antara event. Setelah itu dashboard menunjukkan **1 perjalanan kereta**, **3 gerbong**, dan riwayat aktivitas. Itu adalah **data uji yang baru disimpan**, bukan data asli; hapus secara manual dari phpMyAdmin bila ingin kembali kosong.

Uji langsung satu payload dengan cURL (mis. terminal PowerShell menggunakan `curl.exe`):

```powershell
curl.exe -X POST "http://localhost/halocake/api/ingest.php" -H "Content-Type: application/json" -H "X-API-Key: halocake-dev-2026-GANTI-DENGAN-KUNCI-RAHASIA" -d '{"statusSistem":"Warning","sensor1":true,"jumlahGerbong":0}'
```

> Di Windows PowerShell, tanda kutip untuk cURL dapat berbeda antar-shell. Script PowerShell di atas adalah metode uji yang direkomendasikan.

## 4. File penting

| File | Fungsi |
| --- | --- |
| `index.html` | Dashboard; tetap mengikuti desain HTML awal |
| `assets/app.js` | AJAX, refresh live 2 detik, statistik, filter, pagination, ekspor |
| `config/app.php` | Host, port, database, password, kunci API |
| `database/schema.sql` | Membuat tabel database |
| `api/ingest.php` | Menerima data dari Node-RED/ESP32 (POST dengan API key) |
| `api/dashboard.php` | Data monitoring dan statistik 7 hari (GET) |
| `api/history.php` | Riwayat, pencarian, filter, pagination (GET) |
| `api/export.php` | Export CSV berdasarkan filter (GET) |
| `api/health.php` | Uji kesehatan koneksi database (GET) |
| `nodered/flow-halocake.json` | Alur Node-RED siap import |
| `examples/test-ingest.ps1` | Simulasi tanpa ESP32 |

## 5. Bagaimana statistik dihitung?

- **Kereta melintas:** satu entri `train_passages` yang dimulai ketika sensor aktif, palang bergerak/tertutup, atau sistem berstatus Warning/Closed; berakhir saat sistem Idle, palang Terbuka, dan kedua sensor tidak mendeteksi.
- **Gerbong:** angka tertinggi `jumlahGerbong` selama satu perjalanan; penjumlahan semua perjalanan yang dimulai pada hari tersebut.
- **Rata-rata palang menutup:** waktu (detik) antara perubahan menjadi `Tertutup` dan kembali ke `Terbuka` pada satu perjalanan; ditampilkan sebagai MM:SS. Perjalanan tanpa pasangan waktu tutup/buka tidak ikut dihitung.
- **Tersambung/tidak aktif:** berdasarkan kapan backend terakhir menerima data (`last_seen_at`). Jika tidak ada pembaruan lebih dari 30 detik, indikator menjadi tidak aktif. Itu bukan bukti koneksi WebSocket browser ke broker, tetapi bukti data telah mencapai backend.
- Zona waktu aplikasi dan database saat koneksi: **Asia/Jakarta / WIB (+07:00)**.

## 6. Keamanan dan batasan

- Ini adalah **prototipe pemantauan** untuk tugas akhir/IoT, **bukan sistem kendali keselamatan perlintasan sungguhan**. Tidak ada tombol mengendalikan palang dari web.
- Ganti `ingest_key` di `config/app.php` dengan kunci panjang acak dan ubah di node Node-RED sebelum dipakai bersama orang lain.
- Topik MQTT `kereta/status` di broker publik dapat digunakan orang lain; gunakan topik **unik** untuk pengujian pribadi, atau broker lokal/TLS terautentikasi. Samakan topik di ESP32 dan Node-RED.
- API baca dan dashboard belum dilengkapi akun/login. Gunakan di **localhost atau LAN tepercaya**; jangan dipublikasikan di internet tanpa HTTPS, autentikasi, pembatasan akses, dan konfigurasi server yang layak.
- Jika membutuhkan penghitungan kereta yang akurat untuk penerapan nyata, kirim identitas perjalanan unik (`train_id`) serta logika deteksi yang tervalidasi; penghitungan saat ini adalah *state-transition heuristic* dari payload yang tersedia.
- PHP dijalankan dari Apache/PHP; menjalankan `index.html` tanpa server tidak menjalankan backend.

## 7. Catatan khusus Laragon

- **Document Root:** biasanya `C:\laragon\www`, bukan `C:\xampp\htdocs`. Struktur akhirnya harus `C:\laragon\www\halocake\index.html`, bukan folder `halocake\halocake`.
- **SQL Import:** gunakan tombol **Database** → HeidiSQL → **File → Load SQL file** → **Run/F9**. Jika tombol Database membuka phpMyAdmin, boleh gunakan tab Import.
- **MySQL/MariaDB:** username lokal lazimnya `root` dengan password kosong, tetapi beberapa instalasi dikonfigurasi berbeda. Lihat kredensial di Laragon/HeidiSQL dan sesuaikan `config/app.php`. Port default 3306, bisa berbeda bila sudah diubah.
- **Pretty URL:** `http://halocake.test/` hanya jika Auto Virtual Hosts aktif. URL `http://localhost/halocake/` adalah alternatif yang lebih sederhana. Jika Apache dipindah ke port 8080, gunakan `http://localhost:8080/halocake/` dan ubah URL di Node-RED/PowerShell test.
- **PDO MySQL:** PHP Laragon harus memiliki ekstensi `pdo_mysql` aktif. Biasanya sudah aktif. Jika API gagal dengan pesan driver, cek `Menu → PHP → Extensions`.
- **Node-RED:** flow bawaan POST ke `http://127.0.0.1/halocake/api/ingest.php` saat Laragon berjalan di port 80. Untuk port lain/virtual host atau Node-RED di PC lain, sesuaikan `API_URL` dalam node fungsi.
- **Jangan jalankan MySQL dari Laragon dan XAMPP bersamaan** bila keduanya memakai port 3306.


## Mengatasi error `Unexpected token '<', "<?php decl"`

Error ini berarti PHP tampil sebagai teks mentah, **sebelum** aplikasi sempat memeriksa MySQL. Perbaiki server terlebih dahulu:

1. Pastikan folder tepat di `C:\laragon\www\halocake\index.html` (bukan `halocake\halocake`).
2. Jalankan **Start All** di Laragon. Untuk instalasi berbasis Apache, pastikan Apache dan MySQL aktif.
3. **Jangan** memakai ekstensi VS Code *Live Server*, URL `file://`, atau URL pada port 5500. Gunakan `http://localhost/halocake/` (atau `http://halocake.test/` jika virtual host Laragon aktif).
4. Tes `http://localhost/halocake/api/php-check.php`. Hasil benar: `{"ok":true,"php":"running"}`.
5. Jika halaman tes masih menampilkan teks `<?php`, PHP tidak dieksekusi pada host/port itu. Periksa layanan Apache/Nginx dan konfigurasi PHP handler Laragon, lalu restart layanan. Jangan mengakses proyek dari jaringan lain sebelum ini diperbaiki, karena kode sumber PHP termasuk konfigurasi bisa terekspos.
6. Jika tes PHP berhasil, buka `http://localhost/halocake/api/health.php`. Hasil benar: `{"ok":true,"database":"halocake_monitoring","crossing_configured":true,...}`.
7. Jika health gagal, impor `database/schema.sql` via HeidiSQL; periksa `config/app.php` (port, user, password MySQL).
