# SPDX-FileCopyrightText: 2026 Johannes
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name=$(notdir $(CURDIR))

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build
sign_dir=$(build_dir)/sign
cert_dir=$(HOME)/.nextcloud/certificates

# Node / npm
npm=$(shell which npm 2> /dev/null)
composer=$(shell which composer 2> /dev/null)

all: dev-setup build

# ── Dev Setup ──────────────────────────────────────────────────────

.PHONY: dev-setup
dev-setup: composer-install npm-install

.PHONY: composer-install
composer-install:
ifdef composer
	$(composer) install --prefer-dist --no-dev
else
	@echo "composer not found — skipping PHP dependencies"
endif

.PHONY: npm-install
npm-install:
ifdef npm
	$(npm) ci
else
	@echo "npm not found — skipping JS dependencies"
endif

# ── Build ──────────────────────────────────────────────────────────

.PHONY: build
build: npm-install
	$(npm) run build

.PHONY: watch
watch: npm-install
	$(npm) run watch

# ── Lint ───────────────────────────────────────────────────────────

.PHONY: lint
lint: lint-php lint-js lint-css

.PHONY: lint-php
lint-php:
	find lib/ -name '*.php' -exec php -l {} \;

.PHONY: lint-js
lint-js:
	$(npm) run lint

.PHONY: lint-css
lint-css:
	$(npm) run stylelint

.PHONY: lint-fix
lint-fix:
	$(npm) run lint:fix
	$(npm) run stylelint:fix

# ── Test ───────────────────────────────────────────────────────────

.PHONY: test
test: test-php

.PHONY: test-php
test-php:
	./vendor/bin/phpunit --configuration phpunit.xml

# ── Clean ──────────────────────────────────────────────────────────

.PHONY: clean
clean:
	rm -rf js/
	rm -rf node_modules/
	rm -rf vendor/
	rm -rf build/

# ── Package for App Store ──────────────────────────────────────────

.PHONY: appstore
appstore: clean dev-setup build
	mkdir -p $(sign_dir)/$(app_name)
	rsync -a \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='.vscode' \
		--exclude='build' \
		--exclude='node_modules' \
		--exclude='src' \
		--exclude='tests' \
		--exclude='.eslintrc.js' \
		--exclude='babel.config.js' \
		--exclude='stylelint.config.js' \
		--exclude='webpack.config.js' \
		--exclude='package.json' \
		--exclude='package-lock.json' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='phpunit.xml' \
		--exclude='Makefile' \
		--exclude='*.md' \
		$(project_dir)/ $(sign_dir)/$(app_name)/
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing app…"; \
		php ../../occ integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key \
			--certificate=$(cert_dir)/$(app_name).crt \
			--path=$(sign_dir)/$(app_name); \
	fi
	cd $(sign_dir) && tar czf $(build_dir)/$(app_name).tar.gz $(app_name)
	@echo "Package ready: $(build_dir)/$(app_name).tar.gz"
