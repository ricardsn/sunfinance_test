# the Compose plugin ("docker compose") where it exists, the standalone v1
# binary otherwise
DC := $(shell docker compose version >/dev/null 2>&1 && echo docker compose || echo docker-compose)
PHP = $(DC) exec php

# the php and worker containers run as the host user, so files they write
# through the bind mount (var/, migrations) stay writable on the host
export UID := $(shell id -u)
export GID := $(shell id -g)

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
# the integration tests need the app_test schema, so bring it up to date first.
# the cache:clear is not ceremony: a config edit left a stale compiled container
# behind once, and the retry test then asserted against the old wiring
test:
	$(PHP) bin/console --env=test cache:clear -q
	$(PHP) bin/console --env=test doctrine:database:create --if-not-exists -q
	$(PHP) bin/console --env=test doctrine:migrations:migrate --no-interaction --allow-no-migration -q
	$(PHP) bin/console --env=test messenger:setup-transports -q
	$(PHP) vendor/bin/phpunit
stan:
	$(PHP) vendor/bin/phpstan analyse -c phpstan.dist.neon
consume:
	$(PHP) bin/console messenger:consume async -vv
