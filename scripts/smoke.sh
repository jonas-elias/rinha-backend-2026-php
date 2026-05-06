#!/usr/bin/env bash
# E2E smoke: build & start, send each example-payloads, compare with brute-force.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "[smoke] docker compose build..."
docker compose build

echo "[smoke] computing expected counts (brute force)..."
if [ ! -f data/expected.json ]; then
    echo "[smoke] data/expected.json missing — generate via 'php scripts/expected.php' first" >&2
    exit 1
fi

echo "[smoke] docker compose up -d..."
docker compose up -d

cleanup() { docker compose down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[smoke] waiting for /ready..."
for i in $(seq 1 60); do
    if curl -fsS http://localhost:9999/ready >/dev/null 2>&1; then
        echo "[smoke]   ready after ${i}s"
        break
    fi
    sleep 1
    if [ "$i" = "60" ]; then
        echo "[smoke] timeout waiting for /ready" >&2
        docker compose logs --tail=50
        exit 1
    fi
done

mismatch=0
total=0
while IFS= read -r line; do
    id=$(echo "$line" | jq -r '.id')
    exp=$(echo "$line" | jq -r '.expected_count')
    payload=$(jq --arg id "$id" '.[] | select(.id==$id)' resources/example-payloads.json)
    resp=$(curl -fsS -X POST http://localhost:9999/fraud-score \
        -H 'Content-Type: application/json' \
        --data "$payload")
    score=$(echo "$resp" | jq -r '.fraud_score')
    got_count=$(awk -v s="$score" 'BEGIN { printf "%d", (s*5)+0.5 }')
    total=$((total+1))
    if [ "$got_count" != "$exp" ]; then
        mismatch=$((mismatch+1))
        echo "[smoke] $id: expected=$exp got=$got_count"
    fi
done < <(jq -c '.[]' data/expected.json)

echo "[smoke] $mismatch / $total mismatches"
pct=$(awk -v m="$mismatch" -v t="$total" 'BEGIN { if (t==0) print 0; else printf "%.2f", (m*100.0)/t }')
echo "[smoke] mismatch rate: ${pct}%"

# Aceita até 10% de mismatch (quantização int16 não é bit-exata vs brute-force).
limit=$(awk -v t="$total" 'BEGIN { printf "%d", (t*0.10)+0.999 }')
if [ "$mismatch" -gt "$limit" ]; then
    echo "[smoke] FAIL: mismatch > 10%" >&2
    exit 2
fi
echo "[smoke] OK"
