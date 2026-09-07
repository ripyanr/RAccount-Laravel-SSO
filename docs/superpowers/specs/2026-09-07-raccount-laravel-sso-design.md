# Design Spec: `raccount/laravel-sso`

**Tanggal**: 2026-09-07
**Status**: Disetujui (brainstorming selesai)
**Kontrak acuan**: `raccount/docs/integration-guide.md` (PRD §10) + `raccount/docs/openapi/openapi.yaml`

## 1. Latar Belakang & Tujuan

Raccount (`https://account.reducates.id`, internal: `/home/ripyanr/Developments/EN-COLLABORATE/raccount/`) adalah SSO server berbasis Laravel 13 + Passport 13 yang menyediakan OAuth 2.0 (bukan OIDC). Aplikasi klien — reducates, reducates-archives, aimage (Daiz), dan proyek lain — saat ini harus mengimplementasikan integrasi secara manual.

SDK ini bertujuan menyediakan package Laravel siap pakai yang:

1. Menangani seluruh flow OAuth 2.0 authorization code + PKCE, refresh rotation, revocation, dan userinfo.
2. Memenuhi kewajiban klien sesuai integration guide (state validation, PKCE, penanganan error, webhook verification).
3. Mudah diinstal via Composer/Packagist dan dikonfigurasi per aplikasi.

SDK untuk React/Next.js **di luar lingkup** proyek ini (akan menjadi package TypeScript terpisah; tidak ada kode yang dibagikan).

## 2. Keputusan Tercatat (dari sesi brainstorming)

| Keputusan | Pilihan |
|---|---|
| Posisi SSO vs auth lokal | Konfigurasi per aplikasi: mode `optional` atau `exclusive` |
| Nama package | `raccount/laravel-sso` (GitHub + Packagist) |
| Dukungan versi | Laravel 13 saja, PHP `^8.3` |
| Lingkup v1 | Flow inti + webhook receiver + directory sync (M2M) + `raccount:check` |
| Linking akun existing | Auto-link hanya jika `email_verified=true`; JIT provisioning bila tidak ada; default menolak email belum terverifikasi |
| Arsitektur | Full-service custom OAuth client di atas Laravel HTTP Client — tanpa Socialite, tanpa core framework-agnostic |
| Lisensi | MIT |
| Skeleton | Spatie `package-skeleton-laravel` |

**Alasan penolakan alternatif**: Socialite tidak mengenal refresh rotation, introspection, revocation, webhook, atau token storage — semua fitur inti tetap harus ditulis manual sehingga hanya menambah dependensi. Core framework-agnostic tidak punya konsumen (SDK React/Next.js akan ditulis dalam TypeScript, tidak berbagi kode PHP).

## 3. Fakta Server Raccount yang Membatasi Desain

Dari eksplorasi kode sumber server:

- **OAuth 2.0 via Passport 13, bukan OIDC**: tidak ada ID token, discovery document, JWKS, userinfo di `/oauth/userinfo`, maupun RP-initiated logout. URL endpoint harus dikonfigurasi eksplisit di SDK.
- **Grant**: authorization code (PKCE S256 wajib untuk public client, opsional tapi diperbolehkan untuk confidential client) + refresh token + client credentials. Password/implicit tidak diaktifkan.
- **Endpoint**: `POST /oauth/token`, `POST /oauth/introspect`, `POST /oauth/revoke`, `GET|POST|DELETE /oauth/authorize`, `GET /api/v1/userinfo`, `GET /api/v1/ping`, `GET /api/v1/directory/users` (M2M, scope `sync:read`, cursor pagination).
- **Token**: access JWT RS256 TTL 15 menit (per-client 1–60 menit), refresh opaque TTL 30 hari dirotasi setiap pemakaian (reuse → seluruh family direvokasi), auth code single-use 10 menit.
- **Scope**: `profile` (default), `email`, `sync:read`. Klaim userinfo: `sub`, `name`, `picture`, `updated_at` (scope `profile`); `email`, `email_verified` (scope `email`). Klaim yang tidak di-grant absen (bukan null).
- **Error**: RFC 6749 JSON di `/oauth/*`; RFC 9457 Problem Details di `/api/*`; `429` menyertakan `Retry-After`; semua respons membawa `X-Request-Id`.
- **Webhook**: `POST` ke endpoint klien dengan header `X-RAccount-Signature: sha256=<HMAC-SHA256 raw body>`; `X-RAccount-Event-Id` (ULID), `X-RAccount-Timestamp` (±5 menit). Event: `user.created`, `user.updated`, `user.suspended`, `user.reactivated`, `user.deleted`.
- **Logout**: tidak ada back-channel/front-channel logout dari server. Revokasi segera hanya bisa dilakukan klien sendiri via `/oauth/revoke`. Access token tetap valid sampai expiry alami setelah logout SSO.
- **Registrasi klien**: admin UI di server (client_id/client_secret 64 hex, redirect URI exact-match HTTPS).

## 4. Identitas Package

- **Nama Composer**: `raccount/laravel-sso`
- **Namespace PSR-4**: `Raccount\Sso\`
- **Config**: `config/raccount-sso.php`, prefix env `RACCOUNT_SSO_*`
- **Dependensi runtime**: hanya `illuminate/*` via `laravel/framework ^13.0`; PHP `^8.3`
- **Facade**: `Facades\Raccount` (service id `raccount-sso.client`)

## 5. Arsitektur Komponen

Modul-modul terpisah dengan tanggung jawab tunggal; masing-masing bisa diuji dan dikembangkan independen.

### 5.1 Client (transport)

`RaccountClient` — service utama, di-bound sebagai singleton container:

- `authorizationUrl(string $state, string $codeChallenge, array $scopes, ?string $prompt): string`
- `exchangeCode(string $code, string $codeVerifier): TokenPair`
- `refresh(string $refreshToken): TokenPair`
- `userinfo(string $accessToken): UserInfo`
- `introspect(string $token): IntrospectionResult`
- `revoke(string $token, bool $isRefresh): bool`
- `directoryUsers(?DateTimeInterface $updatedSince): LazyCollection` (di Directory module)
- `ping(): void`

DTO: `TokenPair` (accessToken, refreshToken, expiresAt, scopes), `UserInfo` (sub, name, email, emailVerified, picture, updatedAt), `DirectoryUser`, `IntrospectionResult`.

Perilaku HTTP:

- HTTPS wajib di production; `http://` hanya boleh bila `app.env=local` **dan** config `allow_insecure=true`.
- Timeout konektivitas/total configurable (default 10s/20s mengikuti pola server).
- Retry maksimal 2x dengan exponential backoff + jitter **hanya** pada connection error dan 5xx; **tidak pernah** retry 4xx termasuk `invalid_grant`.
- Menghormati header `Retry-After` pada 429 (exception `RateLimited` menyertakan nilai retry-after).
- Parser error ganda: RFC 6749 (`{"error","error_description"}`) untuk `/oauth/*`; RFC 9457 Problem Details untuk `/api/*`.

Exception hierarchy (semua menyertakan `X-Request-Id` bila ada, token selalu di-redact dari pesan):

```
RaccountException (base, interface Throwable)
├── ConfigurationInvalid      — config hilang/tidak valid
├── RequestFailed             — network/5xx setelah retry
├── RateLimited               — 429 (+ retryAfter)
├── TokenExchangeFailed       — error RFC 6749 dari /oauth/token
│   └── InvalidGrant          — subkelas khusus: sinyal sesi mati, WAJIT force re-login
├── UserInfoFailed            — error dari /api/v1/userinfo
└── WebhookSignatureInvalid   — verifikasi webhook gagal
```

### 5.2 Flow (browser)

Route (nama & path configurable):

| Route name | Method | Path default | Controller |
|---|---|---|---|
| `raccount.login` | GET | `/raccount/redirect` | `RedirectController` |
| `raccount.callback` | GET | `/raccount/callback` | `CallbackController` |
| `raccount.logout` | GET | `/raccount/logout` | `LogoutController` (opsional via config) |

Semua di middleware `web` (session) — bukan `auth`, agar guest bisa memulai login.

**RedirectController**: generate state 256-bit acak + PKCE verifier (43–128 char), simpan keduanya di session, redirect 302 ke authorization URL dengan `response_type=code`, `client_id`, `redirect_uri`, `scope`, `state`, `code_challenge` (S256), dan `prompt` bila dikonfigurasi.

**CallbackController** (urutan eksplisit):
1. Parameter `error` ada → map error redirect OAuth (mis. `access_denied`) ke respons ramah + redirect ke login dengan flash message.
2. Validasi `state`: `hash_equals` terhadap session, lalu **hapus dari session** (single-use). Gagal → `InvalidState` → 419-style halaman error.
3. `exchangeCode(code, verifier)` → `TokenPair`.
4. `userinfo(accessToken)` → `UserInfo`.
5. `UserResolver->resolve(userInfo)` → `Authenticatable` (lihat 5.4).
6. Simpan/update `RaccountAccount` (token encrypted) secara transaksional.
7. `Auth::login($user)` + `session()->regenerate()`.
8. `redirect()->intended(config('redirects.after_login'))`.

**LogoutController**: revoke refresh token dan access token via `/oauth/revoke` (best-effort, kegagalan dicatat di log tetapi tidak memblokir logout), `Auth::logout()`, `session()->invalidate()` + `regenerateToken()`, redirect ke `redirects.after_logout`. Tidak ada redirect ke server Raccount (tidak ada RP-initiated logout).

### 5.3 Mode Operasi

- **`optional`** (default): semua route SSO tersedia; aplikasi menambahkan tombol/tautan sendiri, atau memakai komponen Blade opsional `<x-raccount::button>` (label configurable). Auth lokal tidak disentuh.
- **`exclusive`**: SDK menyediakan middleware alias `raccount.exclusive` — request yang menuju route name dalam config `exclusive.routes` (default: `login`, `register`, `password.request`, `password.reset`, `password.update`) dari guest dialihkan ke `raccount.login`. Middleware ini ditambahkan aplikasi ke grup `web`; tidak mengubah kode auth lokal apa pun.

### 5.4 Resolver User Lokal

Interface:

```php
interface UserResolver
{
    /** @throws AccountLinkageDenied */
    public function resolve(UserInfo $user): Authenticatable;
}
```

`DefaultUserResolver` (configurable via `user.resolver`):

1. Cari `RaccountAccount` by `raccount_sub` → jika ditemukan dan user induk masih ada (tidak soft-deleted) → login; perbarui snapshot `name`/`email`/`picture_url` bila berubah.
2. Tidak ada link:
   - Jika `require_verified_email=true` (default) dan `email_verified=false` → tolak (`EmailNotVerified` exception, render halaman penjelasan).
   - Jika `auto_link_verified_email=true` dan `email_verified=true` → cari user lokal berdasarkan email (lowercase, unique) → ditemukan: buat link. Tidak ditemukan: JIT provisioning user baru via config `user.attributes` (closure/array mapping `UserInfo` → atribut model, default `name`/`email`).
3. Aplikasi dengan model user non-standar (username wajib, enum status, dst.) meng-override resolver di config — satu method, tidak ada asumsi skema.

### 5.5 Storage

**Migrasi `create_raccount_accounts_table`** — kolom:

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | ULID PK | |
| `user_type` + `user_id` | morph, index gabungan | user lokal morph |
| `raccount_sub` | UUID, **unique** | `sub` dari server |
| `email` | string, index | snapshot terakhir |
| `name` | string nullable | snapshot |
| `picture_url` | text nullable | snapshot |
| `scopes` | json | scope yang di-grant |
| `access_token` | text, **encrypted cast** | |
| `refresh_token` | text, **encrypted cast** | |
| `access_expires_at` | timestamp nullable | |
| `status` | enum `active\|suspended\|deleted` | mirror webhook |
| `last_login_at` | timestamp nullable | |
| timestamps | | |

Model `RaccountAccount`: encrypted casts untuk kedua token (AES-256-GCM via APP_KEY), relasi `morphTo user`, helper `isActive()`.

**Migrasi `create_raccount_webhook_events_table`** — `event_id` ULID **unique** (constraint DB = dedupe atomik), `event_type`, `payload` json, `received_at`, `processed_at`.

### 5.6 Webhooks

Route `POST /raccount/webhook` (path configurable), CSRF-exempt, `throttle:60,1`.

`Webhooks\Verifier` memproses berurutan — gagal di langkah mana pun = respons 200 dengan body kosong (server webhook tidak perlu tahu detail kegagalan; dicatat di log aplikasi):

1. Baca raw body (controller mengambil content sebelum parsing).
2. HMAC-SHA256 dengan **setiap** secret dari config array `webhooks.secrets` (dukungan rotasi) → `hash_equals` constant-time vs header `X-RAccount-Signature` (`sha256=<hex>`).
3. `X-RAccount-Timestamp` dalam ±`webhooks.tolerance` detik (default 300).
4. Insert-or-ignore `event_id` ke `raccount_webhook_events` — insert gagal (duplikat) = berhenti (sudah diproses).
5. Dispatch event Laravel: `WebhookReceived` (generik, payload mentah) + event spesifik `UserCreated` / `UserUpdated` / `UserSuspended` / `UserReactivated` / `UserDeleted` (payload DTO).

Listener default SDK `UpdateAccountStatus` (register otomatis, bisa dimatikan): mencocokkan `raccount_sub` → memperbarui `status`, snapshot, dan `processed_at`. Aplikasi bereaksi dengan listener sendiri (mis. mengubah status user lokal, mengirim email).

**Invalidasi sesi**: middleware alias `raccount.active` — bila user terautentikasi memiliki `RaccountAccount` dengan status bukan `active` → logout paksa + flash + redirect login. Pengecekan satu query per request (atau cache request-scope). Aktif via config `middleware.enforce_status` (default `false`, direkomendasikan `true` saat mode exclusive). Catatan: karena server tidak punya back-channel logout, mekanisme inilah satu-satunya cara mematikan sesi lokal saat user di-suspend/dihapus (latensi maksimum = waktu sampai request berikutnya).

Command `raccount:prune-webhooks --days=30` untuk housekeeping.

### 5.7 Directory Sync (M2M)

- `ClientCredentialsManager`: mint token `client_credentials` (scope `sync:read`), disimpan di cache store Laravel dengan TTL = expiry − 60 detik; refresh otomatis.
- `DirectorySyncService::users(?DateTimeInterface $updatedSince): LazyCollection` — cursor pagination otomatis hingga `next_cursor === null`; per item mem-emit event `DirectoryUserRetrieved` (aplikasi mendaftarkan listener untuk provisioning). Ini menjaga SDK tetap netral terhadap skema user lokal.
- Command `raccount:directory:sync {--since=}` — menjalankan service, menampilkan progres (jumlah user, jumlah halaman, durasi); exit code non-zero bila gagal.

### 5.8 Diagnostik

Command `raccount:check` memeriksa dan melaporkan (format tabel, ✓/✗ + saran):

1. Kelengkapan config (server URL, client id/secret, redirect URI).
2. Format HTTPS (dan penolakan http di production).
3. `GET /api/v1/ping` reachable + latency.
4. Autentikasi client (`/oauth/token` client_credentials bila directory diaktifkan).
5. Route `raccount.callback` ada dan cocok dengan `redirect_uri` config.
6. Migrasi sudah dipublikasikan & tabel ada.
7. Webhook secret terkonfigurasi bila route webhook aktif.

Exit code non-zero bila ada kegagalan — bisa dipakai sebagai smoke test post-deploy.

### 5.9 ServiceProvider

- `mergeConfigFrom` + `publishes` (config, migrations).
- Register singleton `raccount-sso.client` → `RaccountClient`; bind `UserResolver` dari config.
- Load route & middleware alias & commands & event listener (semua conditional pada config).
- **Octane-safe**: tanpa property static mutable; HTTP client dibangun per-request dari config; state hanya di session/cache/store Laravel.

## 6. Alur End-to-End

```
[Login]
Browser → GET /raccount/redirect → (state+PKCE ke session) → 302 /oauth/authorize
Raccount (login+consent) → 302 /raccount/callback?code&state
  → validasi state (hash_equals, single-use)
  → POST /oauth/token (Basic auth, code, verifier) → TokenPair
  → GET /api/v1/userinfo → UserInfo
  → UserResolver → user lokal (+link baru bila perlu)
  → Auth::login() + regenerate → simpan token encrypted → redirect intended

[Refresh token] (dipicu aplikasi/SDK helper saat butuh userinfo segar)
POST /oauth/token grant=refresh_token → TokenPair baru (rotasi)
  → simpan transaksional (token lama dibuang)
  → InvalidGrant → hapus link token + logout (family revocation di server; JANGAN retry)

[Webhook]
Raccount → POST /raccount/webhook (HMAC+timestamp+ULID)
  → verifikasi → dedupe → dispatch events → listener SDK update status
  → middleware raccount.active memblokir sesi user suspended/deleted

[Logout]
GET /raccount/logout → POST /oauth/revoke (best-effort) → destroy session lokal
```

## 7. Keamanan (pemetaan requirement → mekanisme)

| Ancaman | Mekanisme |
|---|---|
| CSRF pada flow login | `state` 256-bit acak terikat session, `hash_equals`, single-use |
| Authorization code interception | PKCE S256 selalu dikirim (S256, bukan plain) |
| MITM | HTTPS wajib (kecuali local dev eksplisit); semua pertukaran token server-side; token tidak pernah menyentuh browser |
| Replay webhook | Window timestamp ±5 menit + unique constraint `event_id` (dedupe atomik di DB) |
| Replay refresh token | Rotasi transaksional; `invalid_grant` = logout paksa, tidak retry |
| Kebocoran data at-rest | Encrypted casts untuk access/refresh token; secret hanya di env; log/exception di-sanitasi |
| Webhook spoofing | HMAC-SHA256 constant-time, multi-secret untuk rotasi |
| Brute force webhook | Throttle 60/menit |
| Open redirect | `redirect_uri` exact-match di server; callback path fixed; `redirect()->intended()` hanya path internal |
| Rate limit abuse | Hormati `Retry-After`; backoff+jitter; tidak retry `invalid_grant` |

## 8. Testing

- **Framework**: Pest 4 + orchestra/testbench (versi untuk Laravel 13), `Http::fake` untuk semua endpoint server.
- **Feature tests per modul**:
  - Redirect: state & PKCE hadir di URL, panjang/format benar, session terisi.
  - Callback: happy path; state salah/terpakai ulang; `error=access_denied`; kegagalan exchange; matriks resolver (link existing / verified-email link / JIT create / reject unverified).
  - Refresh: rotasi tersimpan; `invalid_grant` → logout & tanpa retry; 429 dengan `Retry-After`.
  - Webhook: signature valid/invalid/kedaluwarsa; replay event-id ditolak; rotasi secret (lama+baru sama-sama valid); listener mengubah status.
  - Middleware: `raccount.active` memblokir suspended/deleted; `raccount.exclusive` mengalihkan route auth lokal.
  - Directory: cursor pagination multi-halaman; event per user; penanganan `next_cursor`.
  - Commands: `raccount:check` (pass/fail), `raccount:directory:sync`, `raccount:prune-webhooks`.
- **Smoke test integrasi** (opsional, terpisah dari suite utama): dijalankan manual terhadap server dev, gated env `RACCOUNT_INTEGRATION=1`.
- **CI GitHub Actions**: matrix PHP 8.3/8.4 × Laravel 13 × stability (`--prefer-dist` / `--prefer-lowest`), plus job Pint dan Larastan (level ≥ 6).

## 9. Dokumentasi & Distribusi

- `README.md`: badge CI/Packagist, deskripsi, quickstart 5 menit (install → env → migrate → route → tombol), link dokumentasi lengkap.
- `docs/`: `installation.md`, `configuration.md` (semua key + env), `login-flow.md`, `webhooks.md`, `directory-sync.md`, `security.md` (model ancaman & mitigasi), `octane.md`, `upgrading.md`.
- `CHANGELOG.md` (Keep a Changelog), `CONTRIBUTING.md`, `LICENSE` (MIT), issue/PR templates dari skeleton Spatie.
- Distribusi: GitHub → Packagist `raccount/laravel-sso`, semantic versioning mulai `v1.0.0`.

## 10. Di Luar Lingkup v1

- SDK React/Next.js (package TypeScript terpisah, dijadwalkan lain waktu).
- Silent renew / `prompt=none` iframe flow (tidak dibutuhkan aplikasi session-based; dapat ditambah nanti via `prompt` config yang sudah disiapkan).
- Validasi JWT RS256 lokal dengan public key terpasang (opsional di masa depan; introspection + userinfo sudah memadai untuk aplikasi session-based).
- Back-channel logout dari server (tidak ada dukungan di server; digantikan webhook + middleware `raccount.active`).
