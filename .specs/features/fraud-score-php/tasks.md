# Tasks — fraud-score-php

| ID  | Tarefa                                                          | Status |
|-----|-----------------------------------------------------------------|--------|
| P01 | TLC SDD: spec.md, design.md, tasks.md                            | done   |
| P02 | Index re-quantization (Rust IVF1 int16 → int8 IVF8)              | pending|
| P03 | lib/Json.php — manual byte-level parser (sem json_decode)        | pending|
| P04 | lib/Vector.php — 14-dim feature builder (port vector.rs 1:1)     | pending|
| P05 | lib/Data.php — load index, decode centroids/offsets/labels/blocks| pending|
| P06 | lib/Knn.php — IVF top-N + block scan com int8 distance LUT       | pending|
| P07 | php/server.php — Swoole UDS HTTP, static bodies                  | pending|
| P08 | php/php.ini — opcache.jit=tracing, preload, memory_limit         | pending|
| P09 | Dockerfile — multi-stage (rust idx-gen *temporária* + PHP runtime)| pending|
| P10 | docker-compose.yml + haproxy.cfg                                 | pending|
| P11 | scripts/smoke.sh — E2E vs brute-force expected.json              | pending|
| P12 | scripts/run-local.sh — k6 1k rps perf                            | pending|
| P13 | .github/workflows/docker-publish.yml mirrored                    | pending|
| P14 | Run smoke + k6 + tune nprobe / quant scale                       | pending|
| P15 | Init git, push to jonas-elias/rinha-backend-2026-php             | pending|

## Critérios de "done" por task

- **P02**: `index_q.bin` gerado, magic `IVF8`, validação `unpack('a4', ...) === "IVF8"`.
- **P03**: extrai todos campos do `example-payloads.json`; round-trip vs Rust
  produz vetor idêntico (`abs(diff) < 1e-4`) em 50/50 amostras.
- **P04**: vetor 14-dim bate float-a-float com Rust em 50/50 amostras.
- **P05**: `php -r 'require...; var_dump(strlen(Data::$blocks))'` mostra `padded_n*14`.
- **P06**: smoke 0/50 mismatches vs brute-force.
- **P07**: `curl --unix-socket .../ready` → 200 após warmup.
- **P08**: `php -r 'opcache_get_status()'` mostra `jit.on=true`, `jit.kind=tracing`.
- **P09**: `docker images rinha-2026-php` ≤ 200 MB; `docker run ... php -m` não
  lista `.so` customizado.
- **P10**: `docker compose up -d` saudável dentro de 5s.
- **P11**: `bash scripts/smoke.sh` retorna `0` (mismatches < 2 %).
- **P12**: k6 30s @ 1k rps, p99 < 5 ms, 0 erros.
- **P13**: workflow_dispatch faz push no Docker Hub e o `verify` job passa.
- **P14**: relatório no STATE.md com p50/p99/score e qualquer ajuste.
- **P15**: `gh repo view jonas-elias/rinha-backend-2026-php` → existe; `main` no remoto.
