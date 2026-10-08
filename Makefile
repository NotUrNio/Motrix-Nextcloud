.PHONY: all test lint build clean

APP_ID := nddownloader
VERSION := $(shell grep -oPm1 "(?<=<version>)[^<]+" appinfo/info.xml 2>/dev/null || echo "1.0.0")

all: test build

lint:
	@echo "Checking PHP syntax..."
	@find . -type f -name "*.php" -not -path "*/vendor/*" -not -path "*/build/*" -exec php -l {} \; > /dev/null
	@echo "All PHP files passed syntax check."

test: lint
	@echo "Running unit tests..."
	@php tests/run_tests.php

build: clean
	@echo "Building release package for $(APP_ID) v$(VERSION)..."
	@mkdir -p build/$(APP_ID)
	@cp -r appinfo build/$(APP_ID)/
	@cp -r lib build/$(APP_ID)/
	@cp -r templates build/$(APP_ID)/
	@cp -r js build/$(APP_ID)/
	@cp -r css build/$(APP_ID)/
	@cp -r img build/$(APP_ID)/
	@cp -r l10n build/$(APP_ID)/
	@cp LICENSE build/$(APP_ID)/
	@cp THIRD_PARTY_NOTICES.md build/$(APP_ID)/
	@cp CHANGELOG.md build/$(APP_ID)/
	@cp README.md build/$(APP_ID)/
	@cd build && tar -czf $(APP_ID)-$(VERSION).tar.gz $(APP_ID)
	@cd build && cp $(APP_ID)-$(VERSION).tar.gz $(APP_ID).tar.gz
	@echo "Build complete: build/$(APP_ID)-$(VERSION).tar.gz and build/$(APP_ID).tar.gz"

clean:
	@rm -rf build .phpunit.cache
