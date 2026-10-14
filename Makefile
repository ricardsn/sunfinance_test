DC = docker-compose
PHP = $(DC) exec php

.PHONY: up down build test stan migrate consume shell logs

# --renew-anon-volumes refreshes the vendor/ volume from the freshly built
# image, so a dependency change does not leave a stale vendor behind
up:
	$(DC) up -d --build --renew-anon-volumes
down:
	$(DC) down
build:
	$(DC) build --no-cache
shell:
	$(PHP) sh
logs:
	$(DC) logs -f php worker
migrate:
	$(PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
test:
	$(PHP) vendor/bin/phpunit
stan:
	$(PHP) vendor/bin/phpstan analyse -c phpstan.dist.neon
consume:
	$(PHP) bin/console messenger:consume async -vv
