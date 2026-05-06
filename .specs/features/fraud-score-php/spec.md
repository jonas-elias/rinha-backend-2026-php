# Spec — fraud-score-php (pure PHP)

## Contexto

Este é um sub-projeto da Rinha de Backend 2026, gêmeo de `rinha-2026-php-rust`,
mas restrito a **PHP puro**. Não é permitido criar novas extensões em C/Rust
nem usar FFI. Ferramentas que já vêm em distribuições padrão (Swoole, OPcache,
ds, mbstring, sodium, etc.) são permitidas.

A submissão `rinha-2026-php-rust` (PHP+Swoole chamando uma extensão Rust nativa)
fechou **5817.66 pontos** com p99 1.52ms e zero falhas. Esta versão pure-PHP
é o experimento de quão perto chegamos só com o que o PHP entrega hoje.

## User stories

1. Como avaliador da Rinha, quero `POST /fraud-score` retornar `{approved,
   fraud_score}` em < 5ms p99 sob 1k rps, com 0% de falhas.
2. Como avaliador, quero `GET /ready` retornar 200 só depois do índice estar
   carregado e aquecido (sem tail spike na primeira requisição).
3. Como mantenedor, quero o algoritmo, scoring e formato de payload **idênticos**
   ao `rinha-2026-php-rust` para reaproveitar smoke / brute-force / k6.

## Requisitos funcionais

R1. **POST /fraud-score** aceita JSON com a forma do `example-payloads.json` da
    Rinha. Resposta: `application/json` com 6 corpos pré-formatados (idx 0..5),
    onde idx == count_de_fraudes_entre_5_NN.

R2. **GET /ready** → 200 quando índice está pronto.

R3. **Algoritmo**: IVF k-NN (k=4096 centroides, 25 iterações de k-means, seed
    `0xdeadbeef_cafebabe`) com 14 features (`vectorize`), top-5 NN entre os
    `nprobe` centroides mais próximos, fallback para nprobe=24 quando o count
    parcial é 2 ou 3 — equivalência byte-a-byte com a versão Rust em casos
    estáveis.

R4. **Container**: respeita os limites da submissão (api: 0.4 cpu / 173 MB,
    lb: 0.2 cpu / 20 MB).

## Requisitos não-funcionais

NF1. **Pure PHP runtime**: server.php carrega `swoole.so` e `opcache.so` (já
     inclusos na imagem) e nada mais. Sem FFI, sem .so customizado.

NF2. **Performance alvo**: p99 < 5ms a 1000 rps, p50 < 1ms.

NF3. **Memória**: índice quantizado em ≤ 50 MB (cabe em 173 MB com folga pra
     Swoole, OPcache, php-fpm overhead).

NF4. **Acurácia**: ≥ 99% de match contra brute-force k=5 sobre os 50 payloads
     do `example-payloads.json` (mesma tolerância usada na versão Rust).

## Não-objetivos (out of scope)

- Recriar o builder de índice em PHP puro (lento e sem ganho — geramos o
  index.bin via tooling Rust no build do Docker e quantizamos em PHP).
- Suporte a outras dimensões / outros formatos de payload.
- HTTP/2, TLS, autenticação.

## Critérios de aceitação

- [ ] `bash scripts/smoke.sh` reporta `≤ 1 mismatch / 50` (tolerância 2 %).
- [ ] `bash scripts/run-local.sh` (k6 30s @ 1k rps) reporta 0 erros e p99 < 5ms.
- [ ] `docker compose ps` mostra api1+api2 dentro dos limites de mem/cpu.
- [ ] `php -m | grep -E '^(swoole|Zend OPcache)$'` no container; nenhum `.so`
      não-padrão é carregado.
- [ ] Nenhum arquivo `.rs`, `.c`, `.cpp` ou `.so` customizado no commit final
      (índice gerado em build-stage temporário descartado).
