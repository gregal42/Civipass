# -----------------------------------------------------------------------------------------------------------
# Variables
# -----------------------------------------------------------------------------------------------------------
export ARCH := $(shell uname -m)
export CONFIGURATION_DOCKER_DEV_CLASSIC = docker/env/development.yaml
export CONFIGURATION_DOCKER_DEV_APPLE_CHIP = docker/env/development_apple_chip.yaml
export CONFIGURATION_DOCKER_DEV = $(CONFIGURATION_DOCKER_DEV_CLASSIC)
export DOCKER_PHP_CONTAINER_NAME = saas_civipass_php-fpm
export DOCKER_MYSQL_CONTAINER_NAME = saas_civipass_mysql
export TEST_DATABASE_NAME = civipass_test
export STEP = "\\n\\r**************************************************\\n"

# -----------------------------------------------------------------------------------------------------------
# Check if user's machine is an apple ship
# -----------------------------------------------------------------------------------------------------------
ifeq ($(ARCH),arm64)
	CONFIGURATION_DOCKER_DEV = $(CONFIGURATION_DOCKER_DEV_APPLE_CHIP)
endif

# -----------------------------------------------------------------------------------------------------------
# Helpers
# -----------------------------------------------------------------------------------------------------------
help:
	@echo "-- build: build the docker images required to run this project";
	@echo "-- build-update: same as build, but updates images before building";
	@echo "-- up: starts the project";
	@echo "-- stop: stops the project";
	@echo "-- install: installs the project (to run the first time you wan to launch the project)";
	@echo "-- update: updates the project dependencies and DB (to run each time you pull the project)";
	@echo "-- reset-db: resets the db";
	@echo "-- fixture: installs the fixtures";
	@echo "-- remove: stops and removes containers used for this project (can generate data loss)";
	@echo "-- cli: enters PHP container CLI";
	@echo "-- cc: clears and warms up project cache (dev env)";
	@echo "-- logs: displays real time logs of containers used for this project";
	@echo "-- get-url: display the url of the project";
	@echo "-- test-db: provisions the dedicated TEST database (never the development one)";
	@echo "-- test: runs PHPUnit tests";
	@echo "-- phpstan: runs PHPStan static analysis";
	@echo "-- cs-check: checks PHP coding standards";
	@echo "-- cs-fix: automatically fixes PHP coding standards";
	@echo "-- quality: runs all quality checks";

# -----------------------------------------------------------------------------------------------------------
# Commands
# -----------------------------------------------------------------------------------------------------------
base:
	@echo "$(STEP) Starting Docker... $(STEP)"
	@until docker ps > /dev/null 2>&1; do sleep 1; done;
	@echo "$(STEP) Starting Traefik reverse proxy... $(STEP)"
	@docker ps | grep traefik > /dev/null || docker ps -a | grep traefik > /dev/null && docker start traefik > /dev/null;
	@echo "$(STEP) Copying parameter files... $(STEP)"
	@if [ ! -f $(CONFIGURATION_DOCKER_DEV) ]; then cp $(CONFIGURATION_DOCKER_DEV).dist $(CONFIGURATION_DOCKER_DEV); fi

build-update: base
	@echo "$(STEP) Pulling images... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) pull;
	@echo "$(STEP) Building images... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) build --pull;

build: base
	@echo "$(STEP) Building images... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) build;
	@echo "$(STEP) Finished! $(STEP)";

configure-project:
	@if [ ! -f $(CONFIGURATION_DOCKER_DEV) ]; then cp $(CONFIGURATION_DOCKER_DEV).dist $(CONFIGURATION_DOCKER_DEV) && \
        bash -c 'CURRENT_USER=$$(whoami) && echo "$$(tput bold)[?] What is your development URL? ("$$CURRENT_USER".localhost)$$(tput sgr0)" \
        && read url && url=$${url:-"$$CURRENT_USER".localhost} && sed -i.bak s/user.localhost/$$url/g $(CONFIGURATION_DOCKER_DEV) && rm $(CONFIGURATION_DOCKER_DEV).bak \
        && sed -i.bak s/{USER}/$$CURRENT_USER/g $(CONFIGURATION_DOCKER_DEV) && rm $(CONFIGURATION_DOCKER_DEV).bak'; fi

do-up:
	@echo "$(STEP) Starting up containers... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) up -d;
	@echo "$(STEP) Finished! $(STEP)";
	@$(MAKE) get-url;

do-up-update: build-update do-up

up: build do-up

install: configure-project do-up-update
	@if [ ! -f composer.lock ]; then echo "$(STEP) Updating dependencies... $(STEP)" && docker exec -it $(DOCKER_PHP_CONTAINER_NAME) php -d memory_limit=-1 $$(which composer) update --prefer-dist --no-suggest; else echo "$(STEP) Installing dependencies... $(STEP)" && docker exec -it $(DOCKER_PHP_CONTAINER_NAME) composer install --prefer-dist --no-suggest; fi
	@echo "$(STEP) Finished! $(STEP)";
	@$(MAKE) get-url;

update:
	@echo "$(STEP) Updating dependencies... $(STEP)";
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) composer install --prefer-dist --no-suggest;
	@echo "$(STEP) Finished! $(STEP)";
	@$(MAKE) get-url;

reset-db:
	@echo "$(STEP) Reset db... $(STEP)";
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) php bin/console doctrine:database:drop --force -n;
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) php bin/console doctrine:database:create -n;
	@echo "$(STEP) Finished! $(STEP)";

fixture:
	@echo "$(STEP) Running DB fixtures... $(STEP)";
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) php bin/console doctrine:fixtures:load -n --append;
	@echo "$(STEP) Finished! $(STEP)";

stop:
	@echo "$(STEP) Stopping containers... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) stop;
	@echo "$(STEP) Finished! $(STEP)";

cli:
	@echo "$(STEP) Entering bash CLI in $(DOCKER_PHP_CONTAINER_NAME)... $(STEP)";
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) /bin/bash;

cc:
	@echo "$(STEP) Clearing the cache on DEV env... $(STEP)";
	@docker exec -it $(DOCKER_PHP_CONTAINER_NAME) php bin/console cache:clear;
	@echo "$(STEP) Finished! $(STEP)";

logs:
	@echo "$(STEP) Displaying logs... $(STEP)";
	@docker-compose -f $(CONFIGURATION_DOCKER_DEV) logs -f;

get-url:
	$(eval PROJECT_URLS=$(shell cat $(CONFIGURATION_DOCKER_DEV) | sed -n 's/^.*HostRegexp(`\([^`]*\.localhost\)`).*/\1/p'))
	@echo "$(STEP)Project URL:$(STEP)";
	@echo $(PROJECT_URLS) | tr ' ' '\n' | sed 's|^|http://|'

# -----------------------------------------------------------------------------------------------------------
# Quality & Tests
# -----------------------------------------------------------------------------------------------------------

.PHONY: test test-db phpstan cs-check cs-fix quality

# Provisions the dedicated TEST database and applies the migrations to it ONLY (--env=test).
# - The app user only owns the development database, so database creation/grant goes through the MySQL
#   container's root account, whose password is read INSIDE the container (never printed, never in Git).
# - .env.test.local (git-ignored) is derived from .env.local: same server and credentials; doctrine.yaml appends
#   the "_test" suffix in the test environment, so the development database can never be targeted.
test-db:
	@echo "$(STEP) Provisioning test database $(TEST_DATABASE_NAME)... $(STEP)"
	@if [ ! -f .env.test.local ]; then \
		grep '^DATABASE_URL=' .env.local > .env.test.local || { echo "Missing DATABASE_URL in .env.local"; exit 1; }; \
		echo "Created .env.test.local from .env.local (git-ignored)."; \
	fi
	@docker exec $(DOCKER_MYSQL_CONTAINER_NAME) sh -c 'mysql -uroot -p"$$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS $(TEST_DATABASE_NAME) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; GRANT ALL PRIVILEGES ON $(TEST_DATABASE_NAME).* TO \`$$MYSQL_USER\`@\`%\`;"' 2>/dev/null
	@docker exec $(DOCKER_PHP_CONTAINER_NAME) php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

test:
	@echo "$(STEP) Running PHPUnit tests... $(STEP)"
	@docker exec $(DOCKER_PHP_CONTAINER_NAME) php bin/phpunit

phpstan:
	@echo "$(STEP) Running PHPStan... $(STEP)"
	@docker exec $(DOCKER_PHP_CONTAINER_NAME) vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress

cs-check:
	@echo "$(STEP) Checking PHP coding standards... $(STEP)"
	@docker exec $(DOCKER_PHP_CONTAINER_NAME) vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction

cs-fix:
	@echo "$(STEP) Fixing PHP coding standards... $(STEP)"
	@docker exec $(DOCKER_PHP_CONTAINER_NAME) vendor/bin/php-cs-fixer fix --no-interaction

quality: phpstan cs-check test
	@echo "$(STEP) All quality checks passed! $(STEP)"
