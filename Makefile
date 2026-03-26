.PHONY: install build worker

COMPOSER ?= composer

install:
	$(COMPOSER) install

build:
	$(COMPOSER) dump-autoload -o

worker:
	docker compose build app
	docker compose run --rm app php bin/worker.php

