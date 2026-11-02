# Records the GIF of the README and the social preview in Docker: no local PHP and no VHS needed.
# `make -C demo all` from the root of the package; needs a network. `make -C demo record` writes
# the text of the demo on this machine.

.DEFAULT_GOAL := help

ROOT     := $(abspath $(CURDIR)/..)
IMAGE    := cdp-browser-installer-demo
VERSION  := 156.0.8078.80
OUTPUT   ?= $(CURDIR)/output/demo.txt
# linux-arm64 on each host: demo.tape names its paths.
PLATFORM := --platform linux/arm64
# As the user of the host: files the container writes stay the user's on Linux too.
RUN      := docker run --rm --cpus 2 $(PLATFORM) --user $(shell id -u):$(shell id -g) -e HOME=/home/demo -v "$(ROOT):/app" -w /app/demo/app $(IMAGE)
# The demo app with the package from this checkout.
COMPOSER := rm -rf vendor composer.lock out-* && composer install --no-interaction --no-progress -q

.PHONY: help build install preflight gif png record all clean

help: ## Show the targets
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

build: ## Build the image: VHS with PHP, Composer and the libraries of chrome-headless-shell
	docker build -q $(PLATFORM) -t $(IMAGE) $(CURDIR)

# `pin` downloads the four archives, 445 MiB, so a pin file of $(VERSION) stays until `make clean`.
install: build ## Install the demo app with the package from this checkout, and pin 156.0.8078.80
	$(RUN) sh -c '$(COMPOSER) && { grep -qs "\"$(VERSION)\"" .chrome-version || vendor/bin/cdp-browser pin $(VERSION) > /dev/null; }'

preflight: install ## Check that each command of demo.tape prints what the GIF must show
	$(RUN) php preflight.php

gif: preflight ## Record output/demo.gif
	mkdir -p output
	$(RUN) vhs ../demo.tape

png: preflight ## Record output/social.png
	mkdir -p output
	$(RUN) vhs ../social.tape

all: gif png ## Record both

# On this machine, not in Docker: in a container, Chrome finds no sandbox and the page of the demo
# fails. Needs PHP 8.4, Composer, curl and unzip. A failed step leaves output/demo.txt as it was:
# the output goes to a file of its own first.
record: clean ## Run app/demo.php on this machine and write its output to output/demo.txt
	cd app && composer install --no-interaction --no-progress -q
	mkdir -p $(dir $(OUTPUT))
	cd app && php demo.php > $(OUTPUT).part; status=$$?; cat $(OUTPUT).part; \
		if [ $$status -ne 0 ]; then rm -f $(OUTPUT).part; exit 1; fi; mv $(OUTPUT).part $(OUTPUT)

clean: ## Remove the installed demo app, its pin file and its caches
	rm -rf app/vendor app/composer.lock app/.cache app/.chrome-version app/out-*
