# SMART RAILWAY LEVEL-CROSSING COUNTDOWN BARRIER

**Nama Kelompok: HaloCake**

## Deskripsi Proyek

**Smar Railway Level-Crossing Countdown Barrier** merupakan proyek prototipe berbasis **Internet of Things (IoT)** yang dirancang untuk mendemonstrasikan sistem peringatan dan palang otomatis pada perlintasan kereta api. Sistem menggunakan dua sensor inframerah (IR) untuk mendeteksi keberadaan atau pergerakan kereta. Ketika kereta terdeteksi, sistem menjalankan mekanisme countdown sebagai bagian dari proses peringatan sebelum palang menutup. Setelah kereta melintas dan kondisi sensor menunjukkan jalur telah aman, palang dapat dibuka kembali.

Sistem menggunakan ESP32 sebagai pengendali perangkat dan mengirimkan informasi kondisi perlintasan melalui MQTT. Data tersebut diterima dan diteruskan oleh Node-RED ke backend berbasis PHP, kemudian disimpan pada MySQL/MariaDB. Dashboard web menampilkan status sistem, kondisi palang, status kedua sensor, jumlah gerbong, statistik perjalanan, grafik pemantauan harian, serta riwayat aktivitas. Dengan demikian, proyek ini menggabungkan demonstrasi mekanisme perlintasan otomatis dengan pemantauan data berbasis web.

## Anggota Kelompok

| Nama | NIM | Kelas |
|---|---|---|
| Sukmawati Kharisma Gati | 123240137 | IF-A |
| Aulya Revalina | 123240141 | IF-C |
| Azzah Fauziya Kamila | 123240168 | IF-A |
| Chatarina Giftadiyana | 123240183 | IF-B |

## Fitur Utama

### Sistem Perlintasan
- **Deteksi kereta:** menggunakan dua sensor inframerah (IR) untuk mendeteksi kondisi di area perlintasan.
- **Countdown:** memberikan hitung mundur sebagai bagian dari proses peringatan sebelum palang menutup.
- **Palang otomatis:** mengatur proses penutupan dan pembukaan palang berdasarkan kondisi deteksi.
- **Indikator kondisi:** menampilkan status proses perlintasan.

### Dashboard Monitoring
- **Monitoring status sistem:** menampilkan kondisi seperti `Idle`, `Warning`, dan `Closed`.
- **Monitoring palang:** menampilkan status `Terbuka`, `Menutup`, `Tertutup`, dan `Membuka`.
- **Pemantauan sensor:** menampilkan kondisi dua sensor pendeteksi kereta.
- **Penghitungan gerbong:** menerima dan menampilkan jumlah gerbong yang terdeteksi selama perjalanan kereta.
- **Statistik perjalanan:** menyajikan jumlah perjalanan kereta, jumlah gerbong, dan rata-rata durasi palang tertutup.
- **Riwayat aktivitas:** menyimpan dan menampilkan perubahan status serta aktivitas sistem.
- **Pencarian dan filter riwayat:** membantu pengguna menelusuri data aktivitas.
- **Ekspor data:** menyediakan ekspor riwayat ke format CSV.
- **Pembaruan dashboard:** mengambil data terbaru secara berkala dari backend.

## Teknologi yang Digunakan

| Teknologi/Komponen | Kegunaan |
|---|---|
| ESP32 | Pengendali perangkat IoT dan pengiriman data |
| Sensor inframerah (IR) | Mendeteksi keberadaan atau pergerakan kereta |
| Mekanisme palang dan indikator countdown | Mendemonstrasikan proses peringatan, penutupan, dan pembukaan palang |
| MQTT | Komunikasi pengiriman data IoT |
| Node-RED | Menerima, memvalidasi, dan meneruskan data MQTT ke backend |
| PHP 8.1+ | Backend dan penyedia API |
| MySQL/MariaDB | Penyimpanan data monitoring |
| HTML, CSS, JavaScript | Pembuatan antarmuka dashboard |
| Laragon | Lingkungan server lokal untuk menjalankan aplikasi web |

## Alur Kerja Sistem

1. Dua sensor IR mendeteksi kondisi kereta di sekitar perlintasan.
2. ESP32 memproses kondisi sensor dan menjalankan alur peringatan, countdown, serta mekanisme palang.
3. ESP32 mengirimkan informasi status perlintasan ke broker MQTT melalui topik `kereta/status`.
4. Node-RED menerima pesan MQTT, memvalidasi data, lalu mengirimkannya ke backend melalui endpoint `api/ingest.php`.
5. Backend PHP memproses data dan menyimpannya ke database MySQL/MariaDB.
6. Dashboard mengambil data melalui API untuk menampilkan kondisi perlintasan, statistik, dan riwayat aktivitas.

**Alur komunikasi data:**

`Sensor IR → ESP32 → MQTT Broker → Node-RED → PHP API → MySQL/MariaDB → Dashboard Monitoring`

## Instalasi Dashboard di Windows dengan Laragon

1. Unduh atau clone repository ini, kemudian letakkan folder `halocake` di direktori `www` milik Laragon, biasanya `C:\laragon\www\halocake\`.
2. Jalankan Laragon dan aktifkan Apache serta MySQL/MariaDB dengan memilih **Start All**.
3. Impor file `database/schema.sql` ke MySQL/MariaDB melalui HeidiSQL atau phpMyAdmin.
4. Sesuaikan konfigurasi koneksi database dan `ingest_key` pada file `config/app.php`. Konfigurasi lokal Laragon yang umum digunakan:

   ```php
   'db_host' => '127.0.0.1',
   'db_port' => 3306,
   'db_name' => 'halocake_monitoring',
   'db_user' => 'root',
   'db_pass' => '',
   ```

   Sesuaikan nilai tersebut apabila konfigurasi MySQL/MariaDB di komputer berbeda.

5. Uji koneksi backend melalui alamat `http://localhost/halocake/api/health.php`. Jika berhasil, respons akan menunjukkan status koneksi berhasil, misalnya `"ok": true` dan `"crossing_configured": true`.
6. Buka dashboard melalui `http://localhost/halocake/`.
7. Untuk menghubungkan aliran data IoT, impor file `nodered/flow-halocake.json` ke Node-RED. Sesuaikan API key dan URL endpoint dengan konfigurasi aplikasi, kemudian klik **Deploy**.

> **Catatan:** Jalankan dashboard melalui web server Laragon, bukan dengan membuka file HTML secara langsung (`file://`) atau menggunakan VS Code Live Server. Dashboard menampilkan status tidak aktif ketika belum menerima data dari perangkat IoT; statistik dan riwayat dapat tetap kosong sampai data masuk.

## Pengujian Tanpa Perangkat IoT

Pengujian backend dapat dilakukan menggunakan script PowerShell yang tersedia di folder proyek. Jalankan PowerShell dari dalam folder `halocake`:

```powershell
.\examples\test-ingest.ps1
```

Jika eksekusi script dibatasi oleh PowerShell, gunakan:

```powershell
powershell -ExecutionPolicy Bypass -File .\examples\test-ingest.ps1
```

Script tersebut mengirim data uji ke backend sehingga dashboard dapat diperiksa tanpa menghubungkan perangkat IoT. Data yang muncul dari proses ini merupakan **data simulasi/pengujian**, bukan data hasil deteksi kereta sungguhan.

## Batasan Sistem

Proyek ini merupakan **prototipe pembelajaran dan demonstrasi IoT**, bukan sistem kendali keselamatan perlintasan kereta api sungguhan. Dashboard web berfungsi untuk memantau data dan tidak menyediakan tombol untuk mengendalikan palang. Keakuratan penghitungan perjalanan dan gerbong bergantung pada data serta transisi status yang dikirimkan oleh perangkat IoT. Sistem ini tidak ditujukan untuk menggantikan perangkat keselamatan dan prosedur operasional perkeretaapian.

---

**SMART RAILWAY LEVEL-CROSSING COUNTDOWN BARRIER**  
Kelompok **HaloCake** · 2026
