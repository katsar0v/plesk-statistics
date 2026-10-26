VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' src/meta.xml)
RELEASE := $(shell sed -n 's:.*<release>\(.*\)</release>.*:\1:p' src/meta.xml)
PACKAGE := dist/live-traffic-monitor-$(VERSION)-$(RELEASE).zip

.PHONY: check lint analyse test package dev clean

check: lint analyse test

lint:
	@find src tests dev -name '*.php' -o -name '*.phtml' | xargs -n1 php -l | grep -v '^No syntax errors' || true
	@! find src tests dev -name '*.php' -o -name '*.phtml' | xargs -n1 php -l 2>&1 | grep -q -v '^No syntax errors'

analyse:
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

test:
	vendor/bin/phpunit

# The zip has the extension files at its root, as `plesk bin extension --install` expects.
package:
	rm -f $(PACKAGE)
	mkdir -p dist
	cd src && zip -q -r -X ../$(PACKAGE) . -x '.*'
	@echo $(PACKAGE)

dev:
	php -S 127.0.0.1:8099 dev/router.php

clean:
	rm -rf dist
