#!/usr/bin/env bash
# Usage: scripts/infection_chunk.sh <chunk-name> <filter> [min-msi]
#   filter: koma-separated path relatif, mis. src/Application/Job
# Wajib: < /dev/null agar tidak hang; threads=2 (2 CPU); foreground <= ~9 menit.
set -eu
export PATH="$HOME/.local/bin:$PATH"
cd /home/z/my-project/download/zef-framework || exit 1

NAME="$1"
FILTER="$2"
MINMSI="${3:-0}"

CONF="infection-chunk-${NAME}.json5"
trap 'rm -f "$CONF"' EXIT
python3 - "$NAME" "$FILTER" > "$CONF" <<'PY'
import json, sys
name, filt = sys.argv[1], sys.argv[2]
paths = [d for d in filt.split(",") if d]
# source.directories = direktori induk unik; --filter CLI menyaring file/dir
seen = []
for p in paths:
    parent = p.rsplit("/", 1)[0] if "/" in p else "."
    if parent not in seen:
        seen.append(parent)
cfg = {
    "source": {"directories": seen},
    "timeout": 90,
    "logs": {
        "text": f"build/infection-{name}.log",
        "summary": f"build/infection-summary-{name}.json",
    },
    "mutators": {"@default": True},
}
print(json.dumps(cfg, indent=2))
PY

php vendor/bin/infection \
  --configuration="$CONF" \
  --threads=2 \
  --no-progress \
  --min-msi="$MINMSI" \
  --min-covered-msi=0 \
  --filter="$FILTER" \
  < /dev/null

echo "== done: ${NAME} (log: build/infection-${NAME}.log) =="
