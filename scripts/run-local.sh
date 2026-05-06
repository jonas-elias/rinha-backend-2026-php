#!/usr/bin/env bash
# Local k6 30s @ 1000 rps using example-payloads.json
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v k6 >/dev/null 2>&1; then
    echo "[run-local] k6 not found; install via brew install k6" >&2
    exit 1
fi

docker compose up -d
trap 'docker compose down -v >/dev/null 2>&1 || true' EXIT

for i in $(seq 1 60); do
    if curl -fsS http://localhost:9999/ready >/dev/null 2>&1; then break; fi
    sleep 1
done

cat > /tmp/k6-rinha.js <<'JS'
import http from 'k6/http';
import { check } from 'k6';
import { SharedArray } from 'k6/data';

const payloads = new SharedArray('payloads', () =>
    JSON.parse(open('./resources/example-payloads.json'))
);

export const options = {
    scenarios: {
        ramp: {
            executor: 'constant-arrival-rate',
            rate: 1000, timeUnit: '1s',
            duration: '30s',
            preAllocatedVUs: 100, maxVUs: 400,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(99)<10'],
    },
};

export default function () {
    const p = payloads[Math.floor(Math.random() * payloads.length)];
    const r = http.post('http://localhost:9999/fraud-score', JSON.stringify(p),
        { headers: { 'Content-Type': 'application/json' } });
    check(r, { '200': (r) => r.status === 200 });
}
JS

k6 run /tmp/k6-rinha.js
