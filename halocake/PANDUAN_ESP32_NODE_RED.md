# Integrasi HaloCake — ESP32 → MQTT → Node-RED → PHP/MySQL Laragon

Prototipe edukasi saja, **bukan sistem pengaman perlintasan kereta sungguhan**. Servo, sensor, dan WiFi bukan komponen fail-safe.

## Isi paket
- `index.html`, `assets/app.js`, `api/`, `config/`, `database/` — proyek dashboard/backend asal.
- `Arduino/HaloCake_ESP32_MQTT/HaloCake_ESP32_MQTT.ino` — firmware ESP32 sudah dilengkapi WiFi, PubSubClient, MQTT publish dan heartbeat 10 detik.
- `nodered/flow-halocake-esp32.json` — flow subscribe ke topik firmware yang sama, meneruskan JSON ke PHP.

## 1. Laragon
1. Backup folder HaloCake dan database `halocake_monitoring` yang sudah aktif.
2. Ekstrak folder `halocake` ke `C:\laragon\www\halocake` (gabungkan file, jangan sampai `halocake\halocake`). **Jangan timpa `config/app.php` milikmu jika sudah disesuaikan**. Database yang sudah berhasil tidak perlu diimpor ulang.
3. Klik Start All dan uji `http://localhost/halocake/api/health.php` (ok:true) dan `http://localhost/halocake/api/dashboard.php` (ok:true).

## 2. ESP32 Arduino IDE
1. Install: **PubSubClient by Nick O'Leary**, **ESP32Servo**, **Adafruit GFX Library**, **Adafruit LED Backpack Library**. `WiFi.h` berasal dari paket board ESP32.
2. Pilih Board **ESP32 Dev Module** dan port COM yang tepat.
3. Buka `.ino`, ubah `WIFI_SSID` dan `WIFI_PASSWORD` dengan WiFi 2.4 GHz yang digunakan ESP32. Jangan kirim password ke publik.
4. `MQTT_HOST=broker.hivemq.com`, `MQTT_PORT=1883`, `MQTT_TOPIC=halocake/demo-7d82/esp32-01/status` harus sama persis dengan MQTT-in Node-RED.
5. Upload dan buka Serial Monitor **115200 baud**. Saat WiFi tersambung dan MQTT berhasil, akan terlihat `[MQTT] Menghubungi broker... terhubung!` dan `[MQTT] Publish OK`.

## 3. Node-RED
1. Jalankan Laragon (Apache/MySQL) dan Node-RED (`node-red` dari CMD). Buka `http://localhost:1880`.
2. Nonaktifkan flow MQTT lama yang subscribe topik sama agar data tidak ditulis ganda; pilih Menu → Import → select a file → `nodered/flow-halocake-esp32.json`.
3. Double-click function **JSON ke API Laragon**: ganti `ISI_INGEST_KEY_DARI_CONFIG_APP_PHP` sesuai `ingest_key` di `config/app.php` aktif. JANGAN publikasi kunci API.
4. Jika Node-RED dan Laragon di PC yang sama, API URL: `http://127.0.0.1/halocake/api/ingest.php`. Jika berbeda, pakai alamat IP lokal PC Laragon yang dapat dicapai Node-RED.
5. Klik **Done → Deploy**. Pastikan MQTT-in menunjukkan `connected` dan Debug **Pesan MQTT dari ESP32** muncul sekitar setiap 10 detik; **Respons MySQL/PHP** menampilkan `statusCode: 200`, `payload.ok: true`.

## 4. Buka web dan verifikasi
- `http://localhost/halocake/` — status berubah saat sensor aktif.
- `http://localhost/halocake/api/dashboard.php` — `live.data_recent: true` dan `last_seen_at` berubah dalam 30 detik terakhir saat ESP32 online.
- `http://localhost/halocake/api/history.php` — aktivitas sensor/gerbong/status tersimpan.
- Rekap kereta bertambah saat siklus palang kembali ke Idle. `jumlahGerbong` dapat tetap menampilkan nilai akhir hingga siklus berikutnya (sesuai kode perangkat).

## Data JSON yang dikirim ESP32
```json
{"statusSistem":"Warning","statusPalang":"Menutup","sensor1":true,"sensor2":false,"jumlahGerbong":0,"countdown":5,"uptime":120}
```
API hanya menyimpan lima field status/sensor/jumlah. `countdown` dan `uptime` dikirim untuk diagnostik namun tidak ditampilkan di dashboard.

## Troubleshooting
- **Tidak ada log MQTT:** pastikan firmware baru berhasil Upload, Serial Monitor 115200, lalu reset ESP32. Tambahkan log `WiFi.status()` bila jaringan gagal.
- **MQTT connected tapi Debug kosong:** cek kesamaan topik, broker, port 1883 dan node deployed, dan flow lama yang duplikat.
- **401:** perbaiki API key Node-RED agar cocok dengan `config/app.php`.
- **404:** sesuaikan API URL dan document root Laragon.
- **200 tapi Data IoT tidak aktif:** pastikan ESP32 menerbitkan heartbeat setiap 10 detik, Node-RED menerima dan PHP sukses setiap heartbeat.
- **Sensor terdeteksi terus:** kode menggunakan sensor aktif-LOW dengan INPUT; beberapa modul butuh pull-up/penyesuaian sesuai spesifikasi sensor. Hati-hati GPIO2 dan GPIO4 yang terkait bootstrapping pada sejumlah ESP32.
- **Servo tidak bergerak/stabil:** jangan ambil daya servo langsung dari 3.3V ESP32; gunakan catu daya servo yang sesuai dan ground bersama.

Keamanan: broker publik tidak menjamin kerahasiaan/integritas; jangan digunakan untuk kontrol palang nyata. Gunakan broker privat + TLS/ACL untuk penggunaan selain demo lokal. Rotasi password WiFi jika pernah dibagikan.
