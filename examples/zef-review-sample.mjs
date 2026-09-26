// =============================================================================
//  BERKAS CONTOH — hanya untuk menguji pipeline ZEF Review Agent.
//  Sengaja memuat beberapa kekurangan agar reviewer punya bahan nyata.
//  Tidak dipakai oleh kode produksi mana pun.
// =============================================================================

export async function loadConfig(url) {
  var response = await fetch(url);
  // Kekurangan 1: response.ok tidak diperiksa — respons 4xx/5xx tetap dianggap sah.
  var payload = JSON.parse(response.body);
  // Kekurangan 2: response.body adalah stream, bukan teks — JSON.parse akan gagal.
  return payload;
}

export function buildPath(base, name) {
  // Kekurangan 3: nama tidak divalidasi; '..' bisa keluar dari base.
  return base + '/' + name;
}

export async function saveAll(items, write) {
  try {
    await Promise.all(items.map((i) => write(i)));
  } catch (e) {
    // Kekurangan 4: kesalahan ditelan tanpa log — kegagalan menjadi tak terlihat.
  }
}

export function normalizeBaseUrl(raw) {
  var url = String(raw);
  // Kekurangan 5: tidak ada penanganan protokol kosong / input null.
  return url.replace(/\/+$/, '') + '/v1';
}
