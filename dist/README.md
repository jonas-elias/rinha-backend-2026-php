# Pre-built Docker image — chunks

A imagem `rinha-2026-php:dev` (266 MB gzip / 694 MB tar) foi dividida em chunks
de 90 MB para caber no limite de 100 MB do git. ZScaler corporativo bloqueia
GitHub Releases / LFS via API, então split via SSH é a única rota disponível.

## Restore

```bash
cd dist
cat rinha-2026-php_dev.tar.gz.part-* > rinha-2026-php_dev.tar.gz
shasum -a 256 -c sha256.txt   # opcional: valida integridade
gunzip -c rinha-2026-php_dev.tar.gz | docker load
# Imagem carregada como: rinha-2026-php:dev
```

## Re-tag para usar localmente

```bash
docker tag rinha-2026-php:dev jonaselias/rinha-2026-php:latest
docker push jonaselias/rinha-2026-php:latest   # opcional, se quiser publicar
```

## Conteúdo

- Build PHP-only (sem Rust/C), 2 estágios
- Índice IVF6 (k=2048, n=3M) já materializado em `/app/var/index.bin`
- Validação smoke: 0/50 mismatches vs brute force
