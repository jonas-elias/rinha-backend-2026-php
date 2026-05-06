# Design — fraud-score-php

## Visão geral

```
                    ┌─────────────────┐
   POST :9999  ────▶│  HAProxy 2.9    │   mode tcp, balance roundrobin
   GET  :9999       │  0.2 cpu / 20MB │   bind UDS /run/sock/api{1,2}.sock
                    └────────┬────────┘
                             │
              ┌──────────────┴──────────────┐
              ▼                             ▼
     ┌──────────────────┐          ┌──────────────────┐
     │ api1 (Swoole)    │          │ api2 (Swoole)    │
     │ 0.4 cpu / 173 MB │          │ 0.4 cpu / 173 MB │
     │ PHP 8.3 + JIT    │          │ PHP 8.3 + JIT    │
     │ /app/server.php  │          │ /app/server.php  │
     └──────────────────┘          └──────────────────┘
              │                             │
              └──── mmap'd ─────────────────┘
                   /app/data/index_q.bin (~45 MB, lido 1x via file_get_contents)
```

## Restrição-chave: PHP não tem SIMD

Todas as alternativas que mitigam isso:

| Técnica                          | Custo PHP                                      |
|----------------------------------|------------------------------------------------|
| `unpack('s*', $blob)`            | aloca 1 zval por valor → 56 B cada → ~2.3 GB para 42 M ints (inviável) |
| `SplFixedArray` típado           | ~16 B por slot → ~672 MB (inviável)            |
| Acesso byte-a-byte com `ord()`   | rápido com JIT tracing (1-2 ns por ord)        |
| `unpack` por bloco no hot-path   | ~5-15 µs por chamada (inviável a 1k rps)       |

**Decisão**: armazenar o índice como **strings binárias** acessadas com `ord()`
e consultar uma **lookup table de distâncias quadradas** pré-computada por
requisição. Isso transforma a distância entre dois bytes (q,c) em uma única
indexação de array — o que o JIT do PHP compila bem.

## Formato do índice em PHP — `IVF8` (índice quantizado int8)

A versão Rust gera `IVF1` (blocos int16, scale 1e-4). Para PHP, fazemos uma
**pré-quantização para int8** durante o build do Docker. Layout:

```
magic            "IVF8"           4 B
n  : u32                          4 B   (vetores válidos)
k  : u32                          4 B   (= 4096)
d  : u32                          4 B   (= 14)
centroids        f32[d * k]       d*k*4 B  (SoA — mantemos float pra distância L2 precisa)
offsets          u32[k+1]         (k+1)*4 B
labels           u8[padded_n]     padded_n B
blocks           u8[padded_n*d]   padded_n*d B  (1 byte/dim, NÃO SoA — AoS por bloco)
```

Total ≈ 230 KB centroides + 16 KB offsets + 3 MB labels + 42 MB blocks = **~45 MB**.

Quantização: `byte = clamp(round((v + 1.0) * 127.5), 0, 255)`. Faixa observada
das 14 features é `[-1, 1]` (todas clampadas nessa faixa em `vectorize`). Isso
nos dá 256 níveis para o intervalo de 2.0, ou seja step ≈ 0.0078. A versão Rust
usa step 0.0001 — ~78× mais grosseira. Aceitamos a perda controlada
(verificamos no smoke contra brute-force de int16; se cair muito, ampliamos
para `u16` AoS, custo 90 MB).

## Hot path — `Knn::score(string $body) : int 0..5`

Tudo em **uma única função** (sem chamadas de método em loop, sem `array_map`,
sem closures). Inlining manual é o que o JIT espera para gerar boas traces.

```
score(body):
  payload  := JsonParser::parse(body)              // ~0.2 ms
  vec[14]  := Vector::vectorize(payload)           // ~0.01 ms
  qb[14]   := quantize_int8(vec)                   // 14 µs

  # 1) Distance LUT for *this* query (256 buckets per dim)
  for d in 0..14:
      qd = qb[d]
      for c in 0..256: lut[d][c] = (qd - c) ** 2

  # 2) Centroid distances (k=4096, float)
  cdist[k] usando centroids float32 SoA
  → top-N (N=8) por insertion-sort

  # 3) Block scan with early termination on partial sum
  top5 = [(INF, 0)] * 5
  worst = 0
  for ci in top_centroids:
      for block in blocks[offsets[ci]..offsets[ci+1]]:
          d = 0
          for dim in 0..14:
              d += lut[dim][ord(block[dim])]
              if dim == 7 && d >= top5[worst][0]: skip block
          if d < top5[worst][0]:
              insert + recompute worst
  count = sum(1 for (_, l) in top5 if l == 1)
  if count in (2, 3): re-scan with nprobe=24
  return count
```

A LUT por requisição é o truque: 14*256 = 3584 inteiros pré-computados →
distância de bloco vira **14 * (ord+lookup+add)** = 14 * ~3 ns = 42 ns puramente
em bytecode JITado. Centroides ficam em float pra preservar precisão na
seleção de probes (impacto direto em acurácia).

## Componentes

### `lib/Json.php`

Port linha-a-linha de `rust-ext/src/json.rs`. Métodos estáticos privados; usa
`strpos` (memchr equivalente em C) e `substr` apenas para extrair MCC e merchant.
Float parsing manual (sem `floatval`, que aceita formatos demais).

### `lib/Vector.php`

Port 1:1 de `vector.rs`. 14 floats com a mesma fórmula. Tabela `mcc_risk` é
um `[int => float]` const. `clamp01` e `round4` são funções `static`.

### `lib/Data.php`

Carrega `INDEX_PATH` (default `/app/data/index_q.bin`) com `file_get_contents`
(uma cópia em memória). Decodifica:
- centroids → `SplFixedArray<float>` (k*14 = 57k slots) ou string binária
  `unpack`ada uma vez (mais rápido para iterar via offset).
- offsets → `SplFixedArray<int>` (k+1).
- labels → string crua (`ord` no hot path).
- blocks → string crua AoS de `padded_n * 14` bytes.

### `lib/Knn.php`

Função `score(string $body): int`. Tudo inline. Warmup chama `score` 500x com
queries pseudo-aleatórias antes de `/ready` virar 200.

### `php/server.php`

Swoole `Http\Server` em UDS, `BASE` mode, `worker_num=1`, `reactor_num=1`,
`enable_coroutine=false`, sem corotinas — handler síncrono direto.

### `php/php.ini`

```
opcache.enable=1
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=128M
opcache.memory_consumption=128
opcache.preload=/app/preload.php  ; carrega Json/Vector/Data/Knn no SHM
opcache.preload_user=root
memory_limit=160M
realpath_cache_size=4M
realpath_cache_ttl=600
```

## Decisões e racionais

- **Por que `BASE` em vez de `PROCESS`?** 1 worker = sem IPC, sem fork; menor
  latência para nosso uso CPU-bound. Cada container já é 1 réplica.
- **Por que `enable_coroutine=false`?** Cooperative scheduling adiciona ~µs
  por hop e não rende nada num handler que não faz I/O.
- **Por que pré-computar LUT por requisição?** É O(14*256) = 3584 ops, ~50 µs.
  Amortiza ~750 chamadas de distância por consulta (~140 µs cada quando
  computadas naïve). Ganho líquido enorme.
- **Por que int8 em vez de int16?** PHP `ord()` é o caminho mais rápido para
  byte. `unpack('s', ...)` aloca array. Dois `ord` + shift custaria o dobro;
  preferimos perder precisão e medir o impacto na acurácia (ajustável).
- **Por que IVF1 → IVF8 em build, e não geração direta?** Construir k-means em
  PHP é inviável (3M × 14 × 25 iter). Usamos uma stage Rust **temporária e
  descartável** no Dockerfile só para gerar `index.bin`; a imagem final tem
  apenas PHP. Spec ainda é "pure PHP runtime".

## Riscos & mitigações

| Risco                                            | Mitigação                                                  |
|--------------------------------------------------|------------------------------------------------------------|
| Centroide float em PHP é lento → estoura p99     | Reduzir k para 2048 ou usar quantização u8 nos centroides também (ajuste em P14) |
| LUT por request adiciona overhead em queries triviais | LUT é trivial e independente; mantemos                  |
| Acurácia degradada com int8                      | Smoke compara com brute-force; fallback nprobe=24 mantém recall |
| OPcache JIT não compila a função (zend_max_allowed_stack) | Quebrar em N pequenas funções `static` chamadas inline pelo JIT |
| Limite 173 MB estourado                          | Centroides em `unpack`-once para float[]; blocos como string binária; `memory_limit=160M` |
