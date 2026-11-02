# VHS records the terminal; PHP runs the demo app inside it, and chrome-headless-shell starts.
FROM ghcr.io/charmbracelet/vhs:v0.12.1

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

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The home of the user of the host, who has no entry in /etc/passwd: the default cache is in it.
RUN mkdir -m 1777 /home/demo

ENTRYPOINT []
