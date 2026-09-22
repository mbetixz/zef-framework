#!/usr/bin/env python3
"""Mine escaped mutants dari Infection text log.

Usage: python3 scripts/mine_escapes.py <infection-log-file> [label]
Output: daftar escape per file kelas, diurutkan dari escape terbanyak:
  - Path/To/File.php: N escape [Mutator x K, ...]
"""
import re
import sys
from collections import defaultdict

def main() -> int:
    if len(sys.argv) < 2:
        print("usage: mine_escapes.py <infection-log-file> [label]", file=sys.stderr)
        return 2
    log_path = sys.argv[1]
    label = sys.argv[2] if len(sys.argv) > 2 else "escapes"
    text = open(log_path, encoding="utf-8", errors="replace").read()

    m = re.search(r"^Escaped mutants:$(.*?)^[A-Za-z ]+ mutants?:$", text, flags=re.M | re.S)
    if m:
        esc_block = m.group(1)
    else:
        # fallback: ambil setelah 'Escaped mutants:' sampai akhir
        m2 = re.search(r"^Escaped mutants:$(.*)", text, flags=re.M | re.S)
        esc_block = m2.group(1) if m2 else ""

    per_file = defaultdict(lambda: defaultdict(int))
    mut_by_file = defaultdict(lambda: defaultdict(int))
    total = 0
    # Dua format baris entri:
    #  A) "1) /path/File.php:31    [M] Mutator [ID] hash"
    #  B) "1) /path/File.php:31    <--- original"
    entries = re.findall(r"^\s*\d+\)\s+(.*?\.php):(\d+)\s+(?:\[M\]\s+([A-Za-z0-9_]+))?", esc_block, flags=re.M)
    for path, _line, mutator in entries:
        norm = path.replace("/home/z/my-project/download/zef-framework/", "")
        rel = norm
        for pfx in ("src/", "app/", "lib/"):
            if rel.startswith(pfx):
                rel = rel[len(pfx):]
                break
        per_file[rel]["total"] += 1
        total += 1
        if mutator:
            mut_by_file[rel][mutator] += 1

    print(f"## {label} — {total} escape total")
    for rel, d in sorted(per_file.items(), key=lambda kv: -kv[1]["total"]):
        muts = ", ".join(f"{k} x{v}" for k, v in sorted(mut_by_file[rel].items(), key=lambda kv: -kv[1]))
        print(f"- {rel}: {d['total']} escape  [{muts}]")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
