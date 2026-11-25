/*
  HaloCake + 7-segment 2 digit custom + buzzer tersinkron countdown.
  CATATAN: display diasumsikan COMMON CATHODE dengan resistor per segmen.
  Pemetaan segmen kiri hanya untuk digit "1"; cek foto wiring fisik.
  Perbaikan: sensor direarm setelah kedua sensor kosong stabil 0,5 detik.
  Countdown hanya dimulai oleh pembacaan aktif stabil 50 ms.
  Prototipe edukasi, BUKAN kontrol perlintasan nyata.
*/
/*
  HaloCake - ESP32 Palang Kereta (prototipe edukasi)
  ESP32 -> Wi-Fi -> MQTT -> Node-RED -> PHP Laragon -> MySQL -> dashboard.

  Library Arduino IDE:
  - ESP32Servo
  - PubSubClient by Nick O'Leary
  (WiFi.h sudah termasuk ESP32 board package)

  UBAH WIFI_SSID dan WIFI_PASSWORD, lalu samakan MQTT_TOPIC
  dengan MQTT-in di Node-RED.
*/
#include <Arduino.h>
#include <WiFi.h>
#include <PubSubClient.h>
#include <ESP32Servo.h>

// ========== KONFIGURASI JARINGAN ==========
const char* WIFI_SSID     = "cihuy";
const char* WIFI_PASSWORD = "123456789";
const char* MQTT_HOST     = "broker.hivemq.com";
const uint16_t MQTT_PORT  = 1883;
// Topik yang sama persis harus dipakai di node MQTT-in Node-RED.
const char* MQTT_TOPIC    = "halocake/demo-7d82/esp32-01/status";
const unsigned long WIFI_RETRY_MS = 10000;
const unsigned long MQTT_RETRY_MS = 8000;
const unsigned long HEARTBEAT_MS  = 10000;

// ========== PIN ESP32 (TETAP SESUAI PROYEK) ==========
#define SENSOR_A    22
#define SENSOR_B    4
#define SERVO_PIN   25
#define BUZZER_PIN  16
#define LED_HIJAU   14
#define LED_KUNING  13
#define LED_MERAH   27

// ========== 7-SEGMENT CUSTOM (COMMON CATHODE) ==========
// Pin yang sebelumnya bentrok sudah DIPINDAH:
// B tetap GPIO 18 (buzzer sekarang GPIO 16).
// C pindah dari GPIO 12 ke GPIO 27 (GPIO 12 untuk LED merah).
// E pindah dari GPIO 2 ke GPIO 32 (GPIO 2 untuk sensor A).
// Common kanan tetap GPIO 19, Common kiri tetap GPIO 26.
#define SEG_A 5
#define SEG_B 18
#define SEG_C 12
#define SEG_D 23
#define SEG_E 2
#define SEG_F 21
#define SEG_G 17
#define LEFT_B 33
#define LEFT_C 15
#define COMMON_LEFT 26
#define COMMON_RIGHT 19
#define DETECTED    LOW

// ========== SERVO ==========
const int SUDUT_BUKA  = 0;
const int SUDUT_TUTUP = 90;
const unsigned long KECEPATAN_SERVO_MS = 50;
int sudutSekarang = SUDUT_BUKA;
int targetSudut   = SUDUT_BUKA;
unsigned long lastServoMove = 0;

// ========== WAKTU SISTEM ==========
const unsigned long COUNTDOWN_TUTUP_MS = 10000;
const unsigned long MULAI_SERVO_MS     = 5000;
const unsigned long JEDA_GERBONG_MS    = 3000;
const unsigned long COUNTDOWN_BUKA_MS  = 5000;
// Servo baru mulai membuka ketika angka countdown mencapai 1 (detik ke-4).
const unsigned long MULAI_BUKA_SAAT_ANGKA_1_MS = 4000;
// Saat pertama nyala / kembali ke Idle, abaikan sensor selama 5 detik.
const unsigned long JEDA_SENSOR_IDLE_MS = 5000;
// Filter anti-trigger palsu: setelah idle, kedua sensor harus kosong
// stabil dulu, lalu salah satu harus aktif terus untuk memulai countdown.
const unsigned long SENSOR_KOSONG_STABIL_MS = 500;
const unsigned long SENSOR_AKTIF_STABIL_MS  = 50;
const unsigned long LOG_SENSOR_MS = 1000;
unsigned long terakhirLogSensor = 0;
// Jika sensor modul berlogika LOW=terdeteksi, gunakan INPUT_PULLUP.
// Untuk modul push-pull aktif HIGH, ubah DETECTED dan mode pin sesuai modul.

Servo palang;

WiFiClient wifiClient;
PubSubClient mqtt(wifiClient);
unsigned long lastWifiAttempt = 0;
unsigned long lastMqttAttempt = 0;
unsigned long lastHeartbeat   = 0;

// ========== MESIN STATUS ==========
enum State { IDLE, COUNTDOWN, CLOSED, OPEN_CD };
State state = IDLE;
int pinKedatangan = SENSOR_A;
int pinKepergian  = SENSOR_B;
int gerbong = 0;
bool prevDep = false;
unsigned long tStart = 0;
unsigned long lastDetect = 0;
// Timer Idle. Pembacaan sensor baru diizinkan setelah masa tunggu.
unsigned long mulaiIdle = 0;
bool infoSensorAktif = false;
// Persenjataan ulang sensor saat idle. Tidak pernah memicu countdown
// dari sensor yang sudah aktif saat masa jeda berakhir.
bool sensorSiap = false;
unsigned long sejakKosong = 0;
unsigned long sejakAktifA = 0;
unsigned long sejakAktifB = 0;
bool calonA = false;
bool calonB = false;

void resetFilterSensor() {
  sensorSiap = false;
  sejakKosong = 0;
  sejakAktifA = 0;
  sejakAktifB = 0;
  calonA = false;
  calonB = false;
}

// Hanya memanggil fungsi ini di IDLE setelah jeda awal.
// Mengembalikan GPIO sumber kereta, atau -1 bila tidak ada objek valid.
int deteksiKeretaValid(unsigned long now) {
  bool a = digitalRead(SENSOR_A) == DETECTED;
  bool b = digitalRead(SENSOR_B) == DETECTED;

  if (now - terakhirLogSensor >= LOG_SENSOR_MS) {
    terakhirLogSensor = now;
    Serial.printf("[SENSOR] A=%d B=%d siap=%d (aktif=%d)\n",
                  digitalRead(SENSOR_A), digitalRead(SENSOR_B), sensorSiap, DETECTED);
  }

  if (!sensorSiap) {
    // Kedua sensor wajib kosong tanpa jeda selama 2,5 detik.
    if (!a && !b) {
      if (sejakKosong == 0) sejakKosong = now;
      if (now - sejakKosong >= SENSOR_KOSONG_STABIL_MS) {
        sensorSiap = true;
        Serial.println("[SENSOR] Kedua sensor stabil kosong; siap mendeteksi kereta baru.");
      }
    } else {
      sejakKosong = 0;
    }
    return -1;
  }

  // Debounce 50 ms: masih tahan glitch singkat namun cukup responsif.
  if (a) {
    if (!calonA) { calonA = true; sejakAktifA = now; }
  } else {
    calonA = false;
  }
  if (b) {
    if (!calonB) { calonB = true; sejakAktifB = now; }
  } else {
    calonB = false;
  }

  if (calonA && now - sejakAktifA >= SENSOR_AKTIF_STABIL_MS) return SENSOR_A;
  if (calonB && now - sejakAktifB >= SENSOR_AKTIF_STABIL_MS) return SENSOR_B;
  return -1;
}
int lastShown = -99;
int lastSent = -99;

// Selama periode Idle 5 detik, pembacaan sensor diabaikan.
// Fungsi ini juga digunakan saat membuat JSON untuk dashboard.
bool aktif(int pin) {
  if (state == IDLE && millis() - mulaiIdle < JEDA_SENSOR_IDLE_MS) {
    return false;
  }
  return digitalRead(pin) == DETECTED;
}

void gerakServoPelan(unsigned long now) {
  if (sudutSekarang == targetSudut) return;
  if (now - lastServoMove >= KECEPATAN_SERVO_MS) {
    lastServoMove = now;
    if (sudutSekarang < targetSudut) sudutSekarang++;
    else if (sudutSekarang > targetSudut) sudutSekarang--;
    palang.write(sudutSekarang);
  }
}

void setLED(bool g, bool y, bool r) {
  digitalWrite(LED_HIJAU, g);
  digitalWrite(LED_KUNING, y);
  digitalWrite(LED_MERAH, r);
}

// Pola segmen kanan, urutan: A B C D E F G
const uint8_t rightPins[7] = {SEG_A, SEG_B, SEG_C, SEG_D, SEG_E, SEG_F, SEG_G};
const uint8_t polaAngka[10][7] = {
  {1,1,1,1,1,1,0}, // 0
  {0,1,1,0,0,0,0}, // 1
  {1,1,0,1,1,0,1}, // 2
  {1,1,1,1,0,0,1}, // 3
  {0,1,1,0,0,1,1}, // 4
  {1,0,1,1,0,1,1}, // 5
  {1,0,1,1,1,1,1}, // 6
  {1,1,1,0,0,0,0}, // 7
  {1,1,1,1,1,1,1}, // 8
  {1,1,1,1,0,1,1}  // 9
};

void initDisplay() {
  // Common dimatikan lebih dahulu, supaya tidak berkedip ketika boot.
  pinMode(COMMON_LEFT, OUTPUT);
  pinMode(COMMON_RIGHT, OUTPUT);
  digitalWrite(COMMON_LEFT, HIGH);
  digitalWrite(COMMON_RIGHT, HIGH);
  for (int i = 0; i < 7; i++) {
    pinMode(rightPins[i], OUTPUT);
    digitalWrite(rightPins[i], LOW);
  }
  pinMode(LEFT_B, OUTPUT);
  pinMode(LEFT_C, OUTPUT);
  digitalWrite(LEFT_B, LOW);
  digitalWrite(LEFT_C, LOW);
}

void tampil(int angka) {
  if (angka == lastShown) return;
  lastShown = angka;

  // Common cathode: HIGH=nonaktif, LOW=aktif.
  digitalWrite(COMMON_LEFT, HIGH);
  digitalWrite(COMMON_RIGHT, HIGH);
  for (int i = 0; i < 7; i++) digitalWrite(rightPins[i], LOW);
  digitalWrite(LEFT_B, LOW);
  digitalWrite(LEFT_C, LOW);

  if (angka < 0) return; // Blank ketika IDLE
  // Dengan koneksi yang diberikan, digit kiri hanya bisa menampilkan '1'.
  // Display hanya digunakan untuk countdown, bukan jumlah gerbong.
  int digitKanan = angka % 10;
  for (int i = 0; i < 7; i++) {
    digitalWrite(rightPins[i], polaAngka[digitKanan][i] ? HIGH : LOW);
  }
  digitalWrite(COMMON_RIGHT, LOW);
  if (angka >= 10 && angka < 20) {
    digitalWrite(LEFT_B, HIGH);
    digitalWrite(LEFT_C, HIGH);
    digitalWrite(COMMON_LEFT, LOW);
  }
}

// Bunyi tepat saat angka countdown berubah, tanpa delay blocking.
// Angka 10-6: 120 ms, angka 5-1: 300 ms.
int lastBeepNumber = -99;
unsigned long beepMulai = 0;
bool beepAktif = false;

void resetBuzzerCountdown() {
  noTone(BUZZER_PIN);
  lastBeepNumber = -99;
  beepAktif = false;
}

void bunyiCountdown(int angka, unsigned long now) {
  if (angka >= 1 && angka != lastBeepNumber) {
    lastBeepNumber = angka;
    beepMulai = now;
    beepAktif = true;
    tone(BUZZER_PIN, angka <= 5 ? 1200 : 800);
  }
  if (beepAktif && now - beepMulai >= (lastBeepNumber <= 5 ? 300UL : 120UL)) {
    noTone(BUZZER_PIN);
    beepAktif = false;
  }
}

// Nama status yang valid di API HaloCake: Idle, Warning, Closed.
// OPEN_CD tetap Closed sampai palang benar-benar selesai membuka.
const char* sistemSekarang() {
  if (state == IDLE) return "idle";
  if (state == COUNTDOWN) return "warning";
  return "closed";
}

const char* palangSekarang() {
  if (targetSudut == SUDUT_TUTUP && sudutSekarang != SUDUT_TUTUP) return "menutup";
  if (targetSudut == SUDUT_BUKA && sudutSekarang != SUDUT_BUKA) return "membuka";
  return (sudutSekarang == SUDUT_TUTUP) ? "tertutup" : "terbuka";
}

int countdownSekarang(unsigned long now) {
  if (state == COUNTDOWN) {
    long sisa = 10L - (long)((now - tStart) / 1000UL);
    return sisa > 0 ? (int)sisa : 0;
  }
  if (state == OPEN_CD) {
    long sisa = 5L - (long)((now - tStart) / 1000UL);
    return sisa > 0 ? (int)sisa : 0;
  }
  return 0;
}

// Cetak JSON ke Serial dan publish ke MQTT bila koneksi tersedia.
// Nama field PERSIS sesuai api/ingest.php (field countdown/uptime diabaikan API).
void kirim(const char* sistem, const char* pal, int cd) {
  char buf[256];
  int count = snprintf(buf, sizeof(buf),
    "{\"statusSistem\":\"%s\",\"statusPalang\":\"%s\","
    "\"sensor1\":%s,\"sensor2\":%s,\"jumlahGerbong\":%d,"
    "\"countdown\":%d,\"uptime\":%lu}",
    sistem, pal,
    aktif(SENSOR_A) ? "true" : "false",
    aktif(SENSOR_B) ? "true" : "false",
    gerbong, cd, (unsigned long)(millis() / 1000UL)
  );
  if (count <= 0 || count >= (int)sizeof(buf)) {
    Serial.println("[JSON] Buffer tidak cukup");
    return;
  }

  Serial.print("[DATA] ");
  Serial.println(buf);
  if (mqtt.connected()) {
    bool ok = mqtt.publish(MQTT_TOPIC, buf, false); // retain = false
    Serial.println(ok ? "[MQTT] Publish OK" : "[MQTT] Publish GAGAL");
  } else {
    Serial.println("[MQTT] Offline; data ini tidak diantrekan");
  }
}

void kirimSnapshot() {
  kirim(sistemSekarang(), palangSekarang(), countdownSekarang(millis()));
  lastHeartbeat = millis();
}

// Koneksi ulang tanpa delay()/while() untuk WiFi; koneksi MQTT memakai
// PubSubClient yang bersifat sinkron. Tidak cocok untuk kendali safety-critical.
void jagaKoneksi(unsigned long now) {
  if (WiFi.status() != WL_CONNECTED) {
    if (now - lastWifiAttempt >= WIFI_RETRY_MS) {
      lastWifiAttempt = now;
      Serial.println("[WiFi] Mencoba koneksi ulang...");
      WiFi.reconnect();
    }
    return;
  }

  if (mqtt.connected()) {
    mqtt.loop();
    return;
  }

  // Jangan mulai dial broker saat servo masih bergerak.
  if (sudutSekarang != targetSudut) return;
  if (now - lastMqttAttempt < MQTT_RETRY_MS) return;
  lastMqttAttempt = now;

  String clientId = "HaloCake-ESP32-" + String((uint32_t)(ESP.getEfuseMac() & 0xFFFFFFFF), HEX);
  Serial.print("[MQTT] Menghubungi broker...");
  if (mqtt.connect(clientId.c_str())) {
    Serial.println(" terhubung!");
    // Kirim ulang snapshot terkini, termasuk angka gerbong (0 diperbolehkan).
    kirimSnapshot();
  } else {
    Serial.print(" gagal, state=");
    Serial.println(mqtt.state());
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(SENSOR_A, INPUT_PULLUP);
  pinMode(SENSOR_B, INPUT_PULLUP);
  // Jika modul push-pull aktif HIGH, sesuaikan DETECTED menjadi HIGH.
  pinMode(LED_HIJAU, OUTPUT);
  pinMode(LED_KUNING, OUTPUT);
  pinMode(LED_MERAH, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);

  palang.setPeriodHertz(50);
  palang.attach(SERVO_PIN, 500, 2400);
  palang.write(SUDUT_BUKA);

  initDisplay();

  setLED(1, 0, 0); // Hijau menyala sejak awal.
  noTone(BUZZER_PIN);
  tampil(-1);

  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setBufferSize(512); // topik + JSON + header MQTT harus muat
  mqtt.setSocketTimeout(2);
  mqtt.setKeepAlive(15);

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  // Izinkan percobaan MQTT secepat WiFi tersambung.
  lastMqttAttempt = millis() - MQTT_RETRY_MS;
  lastWifiAttempt = millis();

  mulaiIdle = millis(); // Jeda 5 detik dimulai SETELAH setup selesai.
  infoSensorAktif = false;
  resetFilterSensor();
  Serial.println("Sistem Palang Kereta + HaloCake MQTT siap");
  Serial.println("[IDLE] Palang TERBUKA, lampu HIJAU ON. Sensor menunggu 5 detik.");
  kirim("idle", "terbuka", 0);
}

void loop() {
  unsigned long now = millis();

  gerakServoPelan(now);
  jagaKoneksi(now);
  if (mqtt.connected() && now - lastHeartbeat >= HEARTBEAT_MS) {
    kirimSnapshot();
  }

  // ========== 1. IDLE ==========
  if (state == IDLE) {
    // Hindari pembacaan sensor dan transisi status selama 5 detik.
    if (now - mulaiIdle < JEDA_SENSOR_IDLE_MS) {
      return; // WiFi/MQTT/servo telah diproses di atas.
    }
    if (!infoSensorAktif) {
      Serial.println("[IDLE] 5 detik selesai. Menunggu kedua sensor kosong stabil 0,5 detik sebelum siap.");
      infoSensorAktif = true;
    }
    int pinValid = deteksiKeretaValid(now);
    if (pinValid != -1) {
      pinKedatangan = pinValid;
      pinKepergian = (pinValid == SENSOR_A) ? SENSOR_B : SENSOR_A;
      gerbong = 0;
      resetFilterSensor();

      Serial.println(pinValid == SENSOR_A ? "Kereta datang dari KIRI" : "Kereta datang dari KANAN");
      state = COUNTDOWN;
      tStart = now;
      lastShown = -99;
      lastSent = -99;
      setLED(0, 1, 0);
      resetBuzzerCountdown();
      tampil(10);
      bunyiCountdown(10, now);
      kirim("warning", "terbuka", 10);
    }
  }

  // ========== 2. COUNTDOWN PENUTUPAN ==========
  else if (state == COUNTDOWN) {
    unsigned long el = now - tStart;
    int sisa = 10 - (int)(el / 1000);
    if (sisa < 0) sisa = 0;
    tampil(sisa > 0 ? sisa : -1);
    bunyiCountdown(sisa, now);

    // Servo mulai menutup setelah 5 detik, seperti kode asli.
    if (el >= MULAI_SERVO_MS) targetSudut = SUDUT_TUTUP;

    if (sisa != lastSent) {
      lastSent = sisa;
      kirim("warning", el >= MULAI_SERVO_MS ? "menutup" : "terbuka", sisa);
    }

    if (el >= COUNTDOWN_TUTUP_MS && sudutSekarang == SUDUT_TUTUP) {
      resetBuzzerCountdown();
      state = CLOSED;
      prevDep = false;
      lastDetect = now;
      lastShown = -99;
      setLED(0, 0, 1);
      tampil(-1); // Display mati setelah countdown penutupan
      Serial.println("Palang tertutup");
      kirim("closed", "tertutup", 0);
    }
  }

  // ========== 3. PALANG TERTUTUP ==========
  else if (state == CLOSED) {
    tone(BUZZER_PIN, 1000);
    bool dep = aktif(pinKepergian);

    if (dep && !prevDep) {
      gerbong++;
      Serial.print("Gerbong: ");
      Serial.println(gerbong);
      tampil(-1); // Jumlah gerbong tetap dihitung, tidak ditampilkan
      kirim("closed", "tertutup", 0);
    }
    if (dep) lastDetect = now;
    prevDep = dep;

    if (gerbong > 0 && !dep && now - lastDetect > JEDA_GERBONG_MS) {
      Serial.println("Kereta sudah lewat");
      state = OPEN_CD;
      tStart = now;
      lastShown = -99;
      lastSent = -99;
      // Pertahankan palang tertutup selama hitungan 5, 4, 3, dan 2.
      targetSudut = SUDUT_TUTUP;
      resetBuzzerCountdown();
      kirim("closed", "tertutup", 5);
    }
  }

  // ========== 4. COUNTDOWN PEMBUKAAN ==========
  else if (state == OPEN_CD) {

    if (aktif(pinKepergian)) {
      targetSudut = SUDUT_TUTUP;
      resetBuzzerCountdown();
      state = CLOSED;
      prevDep = true; // Hindari menghitung pulsa sensor yang sama dua kali.
      lastDetect = now;
      lastShown = -99;
      tampil(-1); // Jumlah gerbong tetap dihitung, tidak ditampilkan
      kirim("closed", "menutup", 0);
      return;
    }

    int sisa = 5 - (int)((now - tStart) / 1000);
    if (sisa < 0) sisa = 0;
    tampil(sisa > 0 ? sisa : -1);
    bunyiCountdown(sisa, now);

    // Ketika angka 1 muncul (setelah 4 detik), baru mulai buka palang.
    // Angka 5 sampai 2: palang tetap tertutup.
    if (now - tStart >= MULAI_BUKA_SAAT_ANGKA_1_MS) {
      targetSudut = SUDUT_BUKA;
    } else {
      targetSudut = SUDUT_TUTUP;
    }

    if (sisa != lastSent) {
      lastSent = sisa;
      // Tetap Closed pada backend sampai servo selesai membuka.
      kirim("closed", (targetSudut == SUDUT_BUKA ? "membuka" : "tertutup"), sisa);
    }

    if (now - tStart >= COUNTDOWN_BUKA_MS && sudutSekarang == SUDUT_BUKA) {
      resetBuzzerCountdown();
      setLED(1, 0, 0);
      state = IDLE;
      mulaiIdle = now; // Setiap kembali ke Idle, mulai jeda 5 detik lagi.
      infoSensorAktif = false;
      resetFilterSensor();
      lastShown = -99;
      tampil(-1);
      Serial.println("Palang terbuka. Lampu hijau ON, sensor jeda 5 detik.");
      kirim("idle", "terbuka", 0);
    }
  }
}
