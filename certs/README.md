# Local dev only: stage TLS interception CA certs (e.g. ZScaler) here so the
# Docker builder stages can reach crates.io / packages.sury.org through a
# corporate proxy. *.crt files are gitignored. The real submission build does
# not need any of these — the directory may be empty.
