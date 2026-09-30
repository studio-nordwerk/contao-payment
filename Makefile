.PHONY: reset check e2e artifact
reset:
	docker compose down
	docker compose up -d --build
	docker compose exec -T php composer install --no-interaction
	docker compose exec -T php php vendor/bin/contao-setup --no-interaction
	docker compose exec -T php php vendor/bin/contao-console contao:migrate --no-interaction --no-backup
	./scripts/seed.sh
	vp install --frozen-lockfile
	vp exec playwright install chromium

check:
	python3 scripts/check-package.py
	docker compose exec -T php vendor/bin/ecs check --config=/workspace/ecs.php --no-progress-bar
	docker compose exec -T php vendor/bin/twig-cs-fixer lint /workspace/templates /workspace/Resources/contao/templates
	docker compose exec -T php composer validate --strict /workspace/composer.json
	docker compose exec -T php composer normalize --dry-run /workspace/composer.json
	docker compose exec -T php php vendor/bin/contao-console lint:twig /workspace/templates /workspace/Resources/contao/templates
	docker compose exec -T php php vendor/bin/contao-console lint:yaml /workspace/config /workspace/app/config /workspace/.github
	docker compose exec -T php php vendor/bin/contao-console lint:container
	docker compose exec -T -w /workspace php app/vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
	docker compose exec -T -w /workspace php app/vendor/bin/phpunit --configuration=phpunit.xml.dist
	$(MAKE) e2e

e2e:
	vp check
	vp exec playwright test

OUTPUT ?= dist/contao-payment-$(VERSION).zip
artifact:
	python3 scripts/build-artifact.py "$(VERSION)" "$(OUTPUT)"
