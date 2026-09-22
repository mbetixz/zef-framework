#!/usr/bin/env python3
"""Tampilkan escape mutants (file:line, mutator, diff) secara kompak.

Usage: python3 scripts/show_escapes.py <infection-log> [substr-file] [--max N]
"""
import re
import sys


def main() -> int:
    if len(sys.argv) < 2:
        print("usage: show_escapes.py <log> [substr] [--max N]", file=sys.stderr)
        return 2
    log = sys.argv[1]
    substr = sys.argv[2] if len(sys.argv) > 2 and not sys.argv[2].startswith("--") else ""
    maxn = 10000
    if "--max" in sys.argv:
        maxn = int(sys.argv[sys.argv.index("--max") + 1])
    text = open(log, encoding="utf-8", errors="replace").read()
    m = re.search(r"^Escaped mutants:\s*=+\s*(.*?)(?=^\w[\w ]* mutants?:|\Z)", text, flags=re.M | re.S)
    if not m:
        print("(tidak ada escape)", file=sys.stderr)
        return 0
    block = m.group(1)
    entries = re.split(r"\n(?=\d+\) )", block)
    n = 0
    for e in entries:
        hm = re.match(r"\d+\) (.+?):(\d+)\s+\[M\] (\S+)", e)
        if not hm:
            continue
        path, line, mut = hm.group(1), hm.group(2), hm.group(3)
        short = "/".join(path.split("/")[-3:])
        if substr and substr not in path:
            continue
        n += 1
        if n > maxn:
            print(f"... (dipotong, > {maxn})")
            break
        diff = []
        for ln in e.splitlines()[1:]:
            s = ln.strip()
            if s.startswith("@@") or s.startswith("-") or s.startswith("+"):
                diff.append(s[:160])
        print(f"#{n} {short}:{line} [{mut}]")
        for d in diff[:14]:
            print(f"   {d}")
        print()
    if n == 0:
        print("(tidak ada escape cocok)", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
