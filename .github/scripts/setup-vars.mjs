#!/usr/bin/env node
/**
 * .github/scripts/setup-vars.mjs
 * ---------------------------------------------------------------------------
 * Mengisi SEMUA GitHub Variables dan Secrets untuk ZEF Review Agent lewat
 * `gh` CLI, sehingga tidak perlu klik satu per satu di UI.
 *
 * Jaminan keamanan:
 *   - Nilai rahasia TIDAK PERNAH lewat argv (tidak muncul di `ps`/history).
 *     Selalu dikirim lewat stdin pipe ke `gh secret set`.
 *   - Skrip hanya mencetak NAMA variabel dan status, tidak pernah nilainya.
 *
 * Contoh pemakaian:
 *   node setup-vars.mjs --list
 *   node setup-vars.mjs --dry-run
 *   node setup-vars.mjs --repo mbetixz/zef-framework
 *   node setup-vars.mjs --repo mbetixz/zef-framework \
 *        --api-token-file /secure/gh.token \
 *        --app-id 5078468 --app-client-id Iv23li... \
 *        --agent-id <agent-id-agent> \
 *        --api-key-file /secure/agent-ai-api-key.txt \
 *        --app-key-file /secure/zef-agent.private-key.pem \
 *        --prompt-file ./reviewer-prompt.md
 *
 * Prompt — dua cara:
 *   --prompt-file <berkas>       isi prompt dibaca dari berkas lokal, dikirim
 *                                lewat stdin (cocok untuk prompt pendek-menengah)
 *   --prompt-repo-path <path>    mengisi Variable penunjuk berkas prompt yang
 *                                DIKOMIT ke repo (disarankan untuk prompt panjang)
 *
 * TIGA FASE LANJUTAN (v3) — ketiganya diisi dengan nilai AMAN (MATI).
 * Skrip ini sengaja menuliskan saklar-saklarnya sebagai "false" supaya
 * perilaku bot sama dengan v2 sampai Anda menyalakannya satu per satu:
 *   Fase A  ZEF_REVIEW_ENABLE_UPDATE_BRANCH         sinkronkan branch tertinggal
 *   Fase B  ZEF_REVIEW_ENABLE_BLOCK_ON_FINDINGS     check run yang bisa memblokir
 *   Fase C  ZEF_REVIEW_ENABLE_AUTO_MERGE            antrekan merge otomatis
 *
 * Untuk MENYALAKAN satu fase, jalankan ulang skrip dengan --enable-<fase>,
 * mis. `--enable-update-branch`. Tanpa flag itu, nilainya tetap "false"
 * sehingga konfigurasi lama Anda tidak berubah diam-diam.
 *
 * Exit code: 0 sukses · 2 argumen salah · 3 gh tidak tersedia · 4 berkas tidak ada
 *            5 perintah gh gagal
 * ---------------------------------------------------------------------------
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';

/* ───────────────────────────── argumen CLI ─────────────────────────────── */

const argv = process.argv.slice(2);

function arg(name, dflt = '') {
  const i = argv.indexOf(`--${name}`);
  return i !== -1 && argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[i + 1] : dflt;
}
const has = (name) => argv.includes(`--${name}`);

if (has('help') || has('h')) {
  console.log(readFileSync(new URL(import.meta.url), 'utf8').split('*/')[0].replace(/^#!.*\n/, ''));
  process.exit(0);
}

const REPO = arg('repo', 'mbetixz/zef-framework');
const DRY = has('dry-run');
const ONLY_LIST = has('list');
const ORG = arg('org');

const TOKEN = arg('api-token-file');
const APP_KEY_FILE = arg('app-key-file');
const API_KEY_FILE = arg('api-key-file');

/* ──────────────────── definisi Variables (non-rahasia) ─────────────────── */

const DEFAULTS = [
  ['ZEF_REVIEW_AGENT_NAME', 'ZEF Review Agent'],
  ['ZEF_REVIEW_ENABLED', 'true'],
  ['ZEF_REVIEW_LANGUAGE', 'id'],
  ['ZEF_REVIEW_MARKER', '<!-- zef-review-agent -->'],
  ['ZEF_REVIEW_TRIGGER_MODE', 'auto'],
  ['ZEF_REVIEW_MENTION', '@zef-agent'],
  ['ZEF_REVIEW_MENTION_TRUST', 'OWNER,MEMBER,COLLABORATOR'],
  ['ZEF_REVIEW_AGENT_BASE_URL', 'https://teamily.ai'],
  ['ZEF_REVIEW_AGENT_AGENT_ID', ''],
  ['ZEF_REVIEW_API_STYLE', 'agents'],
  ['ZEF_REVIEW_MODEL', ''],
  ['ZEF_REVIEW_TIMEOUT_MS', '120000'],
  ['ZEF_REVIEW_MAX_DIFF_BYTES', '120000'],
  ['ZEF_REVIEW_MAX_FILES', '40'],
  [
    'ZEF_REVIEW_EXCLUDE_PATHS',
    '*.lock,composer.lock,package-lock.json,yarn.lock,*.min.js,*.min.css,vendor/**,node_modules/**,dist/**,build/**,*.snap',
  ],
  ['ZEF_REVIEW_MIN_SEVERITY', 'low'],
  ['ZEF_REVIEW_MAX_FINDINGS', '12'],
  ['ZEF_REVIEW_ALLOW_REQUEST_CHANGES', 'false'],
  ['ZEF_REVIEW_INLINE_COMMENTS', 'false'],
  ['ZEF_REVIEW_DRY_RUN', 'true'],
  // Prompt: biarkan KOSONG untuk memakai prompt default di skrip.
  // Isi salah satu (atau keduanya) lewat --prompt-file / --prompt-repo-path.
  ['ZEF_REVIEW_SYSTEM_PROMPT', ''],
  ['ZEF_REVIEW_SYSTEM_PROMPT_FILE', ''],
  ['ZEF_REVIEW_PROMPT_MAX_BYTES', '32768'],
  ['ZEF_REVIEW_EXTRA_INSTRUCTIONS', ''],
  ['ZEF_REVIEW_JOB_TIMEOUT_MINUTES', '15'],

  /* ── FASE A — sinkronisasi branch (DEFAULT MATI) ──────────────────────
   * Menyalakan ini butuh permission GitHub App "Contents: write" pada repo,
   * karena endpoint update-branch menulis ke branch kepala PR.
   * ALLOW_FORK tetap false: branch fork bukan milik repo ini, jadi kita tidak
   * pernah menulis ke sana. REQUIRE_STRICT (opsional) membatasi aksi hanya
   * pada base yang benar-benar mensyaratkan branch up-to-date.
   */
  ['ZEF_REVIEW_ENABLE_UPDATE_BRANCH', 'false'],
  ['ZEF_REVIEW_UPDATE_BRANCH_ALLOW_FORK', 'false'],
  ['ZEF_REVIEW_UPDATE_BRANCH_REQUIRE_STRICT', 'false'],

  /* ── FASE B — check run yang bisa memblokir merge (DEFAULT MATI) ───────
   * Menyalakan ini butuh permission "Checks: write". Memblokir merge TIDAK
   * terjadi otomatis: nama check di ZEF_REVIEW_CHECK_NAME harus didaftarkan
   * dulu sebagai required status check di ruleset/branch protection.
   * BLOCK_SEVERITY = ambang terendah yang membuat check gagal.
   */
  ['ZEF_REVIEW_ENABLE_BLOCK_ON_FINDINGS', 'false'],
  ['ZEF_REVIEW_CHECK_NAME', 'ZEF Review Agent'],
  ['ZEF_REVIEW_BLOCK_SEVERITY', 'critical'],

  /* ── FASE C — auto-merge (DEFAULT MATI) ────────────────────────────────
   * Menyalakan ini butuh permission "Contents: write" DAN "Pull requests:
   * write", serta "Allow auto-merge" harus dinyalakan admin di level repo.
   * AutoMergeBlockSeverity mencegah pengantrean merge saat masih ada temuan
   * temuan serius. Auto-merge tetap menunggu review wajib manusia.
   */
  ['ZEF_REVIEW_ENABLE_AUTO_MERGE', 'false'],
  ['ZEF_REVIEW_AUTO_MERGE_METHOD', 'SQUASH'],
  ['ZEF_REVIEW_AUTO_MERGE_BLOCK_SEVERITY', 'critical'],
];

/* ───────────────────────────── util gh ─────────────────────────────────── */

function ghAvailable() {
  const r = spawnSync('gh', ['--version'], { encoding: 'utf8' });
  return r.status === 0;
}

const repoArgs = () => (ORG ? ['--org', ORG] : ['--repo', REPO]);

/** Set satu Variable. Nilai lewat stdin supaya tidak muncul di argv. */
function setVariable(name, value) {
  if (DRY) {
    console.log(`  [dry-run] variable ${name} (${String(value).length} byte)`);
    return true;
  }
  const r = spawnSync('gh', ['variable', 'set', name, ...repoArgs()], {
    input: String(value),
    encoding: 'utf8',
    env: { ...process.env, GH_TOKEN: envToken() },
  });
  if (r.status !== 0) {
    console.error(`  ✗ variable ${name}: ${(r.stderr || r.stdout || '').trim().slice(0, 200)}`);
    return false;
  }
  console.log(`  ✓ variable ${name}`);
  return true;
}

/** Set satu Secret. Nilai HANYA lewat stdin, tidak pernah argv. */
function setSecret(name, value) {
  if (DRY) {
    console.log(`  [dry-run] secret   ${name} (${String(value).length} byte, nilai tidak dicetak)`);
    return true;
  }
  const r = spawnSync('gh', ['secret', 'set', name, ...repoArgs()], {
    input: String(value),
    encoding: 'utf8',
    env: { ...process.env, GH_TOKEN: envToken() },
  });
  if (r.status !== 0) {
    console.error(`  ✗ secret   ${name}: ${(r.stderr || r.stdout || '').trim().slice(0, 200)}`);
    return false;
  }
  console.log(`  ✓ secret   ${name}`);
  return true;
}

/** Mode oktal sebuah berkas, mis. "600". String kosong bila tidak bisa dibaca. */
function fileMode(p) {
  try {
    return (statSync(p).mode & 0o777).toString(8);
  } catch {
    return '';
  }
}

/**
 * Token gh dari berkas ber-permission ketat. Tanpa --api-token-file,
 * jatuh ke GH_TOKEN yang sudah ada di environment.
 *
 * Berkas yang bisa dibaca grup/orang lain DITOLAK — token yang bocor ke
 * proses lain lebih buruk daripada gagal cepat.
 */
function envToken() {
  if (TOKEN) {
    if (!existsSync(TOKEN)) {
      console.error(`ERROR: berkas token tidak ditemukan: ${TOKEN}`);
      process.exit(4);
    }
    const mode = fileMode(TOKEN);
    if (mode !== '600' && mode !== '400') {
      console.error(
        `ERROR: ${TOKEN} bermode ${mode || '?'}, seharusnya 600.\n` +
          `       Perbaiki dengan: chmod 600 ${TOKEN}`
      );
      process.exit(4);
    }
    return readFileSync(TOKEN, 'utf8').trim();
  }
  return process.env.GH_TOKEN || '';
}

async function listAll() {
  console.log(`\n== Variables terpasang di ${REPO} ==`);
  const v = spawnSync('gh', ['variable', 'list', ...repoArgs()], {
    encoding: 'utf8',
    env: { ...process.env, GH_TOKEN: envToken() },
  });
  console.log((v.stdout || v.stderr || '(tidak ada)').trim());

  console.log(`\n== Secrets terpasang di ${REPO} (nama saja) ==`);
  const s = spawnSync('gh', ['secret', 'list', ...repoArgs()], {
    encoding: 'utf8',
    env: { ...process.env, GH_TOKEN: envToken() },
  });
  console.log((s.stdout || s.stderr || '(tidak ada)').trim());
}

/* ──────────────────────────────── alur utama ───────────────────────────── */

/** Validasi berkas rahasia SEBELUM menyentuh apa pun. */
function assertReadableSecretFile(p, label) {
  if (!existsSync(p)) {
    console.error(`ERROR: ${label} tidak ditemukan: ${p}`);
    process.exit(4);
  }
  const mode = fileMode(p);
  if (mode !== '600' && mode !== '400') {
    console.error(
      `ERROR: ${label} (${p}) bermode ${mode || '?'}, seharusnya 600.\n` +
        `       Perbaiki dengan: chmod 600 ${p}`
    );
    process.exit(4);
  }
}

async function main() {
  if (!ghAvailable()) {
    console.error('ERROR: `gh` CLI tidak ditemukan. Pasang dari https://cli.github.com');
    process.exit(3);
  }

  if (ONLY_LIST) {
    await listAll();
    process.exit(0);
  }

  const agentId = arg('agent-id');
  const appId = arg('app-id');
  const appClientId = arg('app-client-id');
  const promptFile = arg('prompt-file');
  const promptRepoPath = arg('prompt-repo-path');

  // Prompt inline dibaca dari berkas lokal (lewat stdin nanti — tidak pernah argv).
  let promptInline = '';
  if (promptFile) {
    if (!existsSync(promptFile)) {
      console.error(`ERROR: berkas prompt tidak ditemukan: ${promptFile}`);
      process.exit(4);
    }
    promptInline = readFileSync(promptFile, 'utf8');
    console.log(`Prompt inline akan diisi dari berkas ${promptFile} (${promptInline.length} karakter).`);
  }
  if (promptFile && promptRepoPath) {
    console.error(
      'ERROR: pilih SALAH SATU — --prompt-file (inline) atau --prompt-repo-path (berkas di repo).\n' +
        '       Kalau keduanya diisi, berkas di repo menang dan nilai inline diabaikan.'
    );
    process.exit(2);
  }

  const overrides = new Map();
  if (agentId) overrides.set('ZEF_REVIEW_AGENT_AGENT_ID', agentId);
  if (promptInline) overrides.set('ZEF_REVIEW_SYSTEM_PROMPT', promptInline);
  if (promptRepoPath) overrides.set('ZEF_REVIEW_SYSTEM_PROMPT_FILE', promptRepoPath);

  /* ── Saklar tiga fase lanjutan ──────────────────────────────────────────
   * Tanpa flag ini, tiap fase ditulis sebagai "false" — perilaku bot tetap
   * sama seperti v2. Menyalakan fase harus SENGAJA, dan hanya fase yang
   * diminta yang berubah; fase lain tetap mati walau sudah pernah dinyalakan
   * sebelumnya, supaya keadaan repository selalu tercermin dari perintah
   * yang dijalankan, bukan dari sisa konfigurasi lama.
   */
  const PHASE_FLAGS = [
    ['enable-update-branch', 'ZEF_REVIEW_ENABLE_UPDATE_BRANCH', 'Fase A (sinkronisasi branch)'],
    ['enable-block-on-findings', 'ZEF_REVIEW_ENABLE_BLOCK_ON_FINDINGS', 'Fase B (check run pemblokir)'],
    ['enable-auto-merge', 'ZEF_REVIEW_ENABLE_AUTO_MERGE', 'Fase C (auto-merge)'],
    ['enable-all-phases', '*', 'KETIGA fase lanjutan'],
  ];
  const enabledPhases = [];
  for (const [flag, key, label] of PHASE_FLAGS) {
    if (!has(flag)) continue;
    if (key === '*') {
      for (const [, k] of PHASE_FLAGS) if (k !== '*') overrides.set(k, 'true');
      enabledPhases.push('Fase A + B + C');
    } else {
      overrides.set(key, 'true');
      enabledPhases.push(label);
    }
  }
  if (enabledPhases.length) {
    console.warn(
      `\n⚠  PERINGATAN: menyalakan ${enabledPhases.join(', ')} membutuhkan permission ` +
        'GitHub App tambahan (Contents: write dan/atau Checks: write).\n' +
        '   Pastikan permission itu sudah diberikan ke App 5078468 SEBELUM menyalakannya,\n' +
        '   kalau tidak langkah pembuatan token akan gagal dengan 422.\n'
    );
  }

  const variables = DEFAULTS.map(([k, v]) => [k, overrides.has(k) ? overrides.get(k) : v]).filter(
    ([k, v]) => {
      // Jangan kirim Variable kosong: nilai kosong sama saja dengan tidak diisi,
      // dan mengirimnya hanya menambah baris di daftar Settings.
      if (k === 'ZEF_REVIEW_SYSTEM_PROMPT' && !v && !overrides.has(k)) return false;
      if (k === 'ZEF_REVIEW_SYSTEM_PROMPT_FILE' && !v && !overrides.has(k)) return false;
      return true;
    }
  );

  if (appId) variables.unshift(['ZEF_REVIEW_APP_ID', appId]);
  if (appClientId) variables.unshift(['ZEF_REVIEW_APP_CLIENT_ID', appClientId]);

  // Peringatkan bila prompt inline mendekati batas aman antarmuka Settings.
  if (promptInline.length > 60000) {
    console.warn(
      'PERINGATAN: prompt inline > 60.000 karakter. Nilai variabel per-tombol di UI\n' +
        '           punya batas praktis; pakai --prompt-repo-path untuk prompt sebesar ini.'
    );
  }

  console.log(`\nRepo target : ${REPO}`);
  console.log(`Mode        : ${DRY ? 'DRY-RUN (tidak ada perubahan)' : 'TERAPKAN'}`);
  console.log(`Variables   : ${variables.length} entri`);

  // Verifikasi lebih awal: tanpa kredensial yang bisa dipakai, hentikan sebelum
  // menyentuh separuh konfigurasi.
  const token = envToken();
  if (!DRY && !token) {
    console.error(
      'ERROR: tidak ada kredensial gh.\n' +
        '       Pakai --api-token-file <berkas 0600>, atau export GH_TOKEN lebih dulu.\n' +
        '       Token tidak pernah boleh diketik langsung di baris perintah.'
    );
    process.exit(4);
  }

  console.log('\n== Mengisi Variables (non-rahasia) ==');
  let ok = true;
  for (const [name, value] of variables) ok = setVariable(name, value) && ok;

  console.log('\n== Mengisi Secrets (nilai tidak pernah dicetak) ==');

  const secretsToSet = [];
  if (API_KEY_FILE) {
    assertReadableSecretFile(API_KEY_FILE, 'berkas API key');
    secretsToSet.push(['AGENT_AI_API_KEY', readFileSync(API_KEY_FILE, 'utf8').trim()]);
  } else {
    console.log('  - AGENT_AI_API_KEY : dilewati (tidak ada --api-key-file)');
  }

  if (APP_KEY_FILE) {
    assertReadableSecretFile(APP_KEY_FILE, 'berkas private key App');
    secretsToSet.push(['ZEF_REVIEW_APP_PRIVATE_KEY', readFileSync(APP_KEY_FILE, 'utf8').trim()]);
  } else {
    console.log('  - ZEF_REVIEW_APP_PRIVATE_KEY : dilewati (tidak ada --app-key-file)');
  }

  for (const [name, value] of secretsToSet) ok = setSecret(name, value) && ok;

  console.log('\n== Ringkasan ==');
  console.log(DRY ? 'DRY-RUN selesai — tidak ada yang diubah.' : ok ? 'Selesai tanpa error.' : 'Selesai DENGAN error (lihat baris ✗ di atas).');
  console.log('\nLangkah berikutnya:');
  console.log(`  1. Pastikan Variables sudah benar:  node setup-vars.mjs --repo ${REPO} --list`);
  console.log('  2. Buka Actions → "ZEF Review Agent" → Run workflow, isi nomor PR uji + dry_run=true');
  console.log('  3. Kalau hasilnya bagus, ubah Variable ZEF_REVIEW_DRY_RUN menjadi false');
  console.log('  4. Fase lanjutan (opsional, default MATI) dinyalakan satu per satu:');
  console.log('       --enable-update-branch       Fase A (butuh Contents: write)');
  console.log('       --enable-block-on-findings   Fase B (butuh Checks: write)');
  console.log('       --enable-auto-merge          Fase C (butuh Contents+PR: write)');

  process.exit(ok ? 0 : 5);
}

main().catch((e) => {
  console.error('ERROR:', e?.message || e);
  process.exit(14);
});
