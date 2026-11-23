# VHS records the terminal; PHP runs the demo app inside it, and chrome-headless-shell starts.
# Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM ghcr.io/charmbracelet/vhs:v0.12.1@sha256:ea49a6a1c529be83153e88321892b5585964418f0b9055e8c1e0d732194234a3

# The packages after php-mbstring are the list that `vendor/bin/cdp-browser deps` installs as
# root: `make preflight` checks that each one is in the image.
RUN apt-get update \
 && apt-get install -y --no-install-recommends php-cli php-mbstring \
    ca-certificates curl unzip \
    fonts-liberation libasound2t64 libatk-bridge2.0-0t64 libatk1.0-0t64 libatspi2.0-0t64 \
    libcairo2 libcups2t64 libdbus-1-3 libdrm2 libgbm1 libglib2.0-0t64 libnspr4 libnss3 \
    libpango-1.0-0 libx11-6 libxcb1 libxcomposite1 libxdamage1 libxext6 libxfixes3 \
    libxkbcommon0 libxrandr2 \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# The home of the user of the host, who has no entry in /etc/passwd: the default cache is in it.
# /ci holds the cache of demo.tape, a short path that keeps the refusal in the GIF readable.
RUN mkdir -m 1777 /home/demo /ci

ENTRYPOINT []
