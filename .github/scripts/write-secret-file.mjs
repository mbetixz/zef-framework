#!/usr/bin/env node
/**
 * .github/scripts/write-secret-file.mjs
 * ---------------------------------------------------------------------------
 * Menulis nilai rahasia dari ENVIRONMENT ke sebuah berkas di runner, dengan
 * mode 600, tanpa pernah mencetak isinya.
 *
 * KENAPA SKRIP INI ADA
 * GitHub Secrets dan Variables HANYA menyimpan STRING. Tidak ada tipe "file".
 * Jadi private key GitHub App (yang berupa berkas .pem multi-baris) harus
 * disimpan sebagai string di Secret, lalu ditulis ke disk saat runtime bila
 * ada alat yang menuntut path, bukan string.
 *
 * Untuk reviewer ini Anda SEBENARNYA TIDAK PERLU skrip ini:
 * `actions/create-github-app-token` menerima private key langsung sebagai
 * string dan sudah menangani newline yang di-escape. Skrip ini disediakan
 * untuk kasus lain (mis. alat yang menuntut `--key-file`, atau git yang butuh
 * `GIT_SSH_COMMAND`).
 *
 * PEMAKAIAN
 *   node write-secret-file.mjs --env ZEF_REVIEW_APP_PRIVATE_KEY \
 *                              --out "$RUNNER_TEMP/zef-app.pem"
 *
 *   node write-secret-file.mjs --env MY_KNOWN_HOSTS --out ~/.ssh/known_hosts --mode 600
 *
 * JAMINAN
 *   - Nilai hanya dibaca dari environment; tidak pernah masuk argv.
 *   - Isi berkas TIDAK PERNAH dicetak - yang dilaporkan hanya path, jumlah
 *     byte, jumlah baris, dan mode.
 *   - Mode hanya boleh 600 (default) atau 400.
 *   - `::add-mask::` bersifat OPT-IN (--add-mask), karena perintah itu sendiri
 *     menuliskan nilai ke log sebelum penyamaran aktif. Untuk nilai yang diisi
 *     dari `secrets.*`, GitHub sudah menyamarkannya otomatis.
 *
 * Exit code: 0 sukses - 2 argumen salah - 4 env kosong - 5 gagal tulis
 * ---------------------------------------------------------------------------
 */

import { writeFileSync, chmodSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const argv = process.argv.slice(2);

function arg(name, dflt = '') {
  const i = argv.indexOf(`--${name}`);
  return i !== -1 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : dflt;
}

const ENV_NAME = arg('env');
const OUT = arg('out');
const MODE = arg('mode', '600');
const LABEL = arg('label', ENV_NAME || 'rahasia');
// ::add-mask:: menulis nilainya ke log SEKALI sebelum penyamaran aktif.
// Untuk nilai yang berasal dari `secrets.*`, GitHub sudah menyamarkannya
// otomatis - jadi default-nya NONAKTIF.
const ADD_MASK = argv.includes('--add-mask');

if (!ENV_NAME || !OUT) {
  console.error(
    'ERROR: butuh --env <NAMA_VARIABEL> dan --out <path>.\n' +
      '       Contoh: node write-secret-file.mjs --env APP_PRIVATE_KEY --out /tmp/app.pem'
  );
  process.exit(2);
}

if (!/^[0-7]{3,4}$/.test(MODE)) {
  console.error(`ERROR: --mode harus oktal (mis. 600 atau 400), bukan "${MODE}".`);
  process.exit(2);
}
const modeNum = Number.parseInt(MODE, 8);
// Hanya 600 (pemilik saja) dan 400 (pemilik, hanya-baca) yang diterima.
// Apa pun yang memberi akses Grup/Other ditolak - termasuk 640 dan 644.
if (modeNum !== 0o600 && modeNum !== 0o400) {
  console.error(
    `ERROR: mode ${MODE} memberi akses di luar pemilik. Gunakan 600 (normal) atau 400 (hanya-baca).`
  );
  process.exit(2);
}

const value = process.env[ENV_NAME];

// Status saja - nama variabel dan PRESENT/ABSENT. Tidak pernah nilainya.
if (value === undefined || value === null || String(value).trim() === '') {
  console.error(`ERROR: ${ENV_NAME} kosong atau tidak ada (ABSENT).`);
  process.exit(4);
}

const text = String(value);

// Samarkan nilai di seluruh log job ini - HANYA bila diminta.
// Catatan: `::add-mask::<nilai>` menuliskan nilainya ke aliran log SEBELUM
// penyamaran berlaku. Untuk nilai dari `secrets.*` ini tidak perlu.
if (ADD_MASK) process.stdout.write(`::add-mask::${text}\n`);

const abs = resolve(OUT);
try {
  mkdirSync(dirname(abs), { recursive: true });
  writeFileSync(abs, text.endsWith('\n') ? text : `${text}\n`, { mode: modeNum });
  // writeFileSync hanya menerapkan mode saat file BARU dibuat. Pastikan ulang
  // bila berkas sudah ada dari langkah sebelumnya.
  chmodSync(abs, modeNum);
} catch (e) {
  console.error(`ERROR: gagal menulis berkas: ${e.message}`);
  process.exit(5);
}

const bytes = Buffer.byteLength(text, 'utf8');
const lines = text.split('\n').length;

// Yang dilaporkan: metadata, BUKAN isi.
console.log(`[write-secret-file] ${LABEL}: PRESENT`);
console.log(`[write-secret-file] ditulis ke ${abs}`);
console.log(`[write-secret-file] ${bytes} byte, ${lines} baris, mode ${MODE}`);
console.log('[write-secret-file] isi berkas tidak dicetak.');
