.PHONY: dev prod staging stop logs test behat lint build deploy backup rollback certs help

SERVER_USER ?= root
SERVER_HOST ?= droplet-programel

COMPOSE_DEV = docker compose -f docker-compose.dev.yml
COMPOSE_PROD = docker compose -f docker-compose.prod.yml
COMPOSE_STAGING = docker compose -f docker-compose.staging.yml
API_EXEC = $(COMPOSE_DEV) exec api
FRONT_EXEC = $(COMPOSE_DEV) exec frontend

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- Development ---

dev: ## Start development environment
	$(COMPOSE_DEV) up -d --build
	@echo "\n✓ Dev environment running at https://programel.local"

stop: ## Stop all containers
	$(COMPOSE_DEV) down
	$(COMPOSE_STAGING) down 2>/dev/null || true

logs: ## Tail logs (usage: make logs or make logs s=api)
	$(COMPOSE_DEV) logs -f $(s)

# --- Production ---

prod: ## Start production environment
	$(COMPOSE_PROD) up -d

staging: ## Start staging environment
	$(COMPOSE_STAGING) up -d --build

# --- Testing ---

test: ## Run all tests (API + frontend)
	$(API_EXEC) bin/phpunit
	$(FRONT_EXEC) npm test

behat: ## Run Behat integration tests
	$(API_EXEC) vendor/bin/behat

lint: ## Run linters (API + frontend)
	$(API_EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(API_EXEC) vendor/bin/phpstan analyse
	$(FRONT_EXEC) npm run lint
	$(FRONT_EXEC) npx tsc --noEmit

lint-fix: ## Run linters and fix issues
	$(API_EXEC) vendor/bin/php-cs-fixer fix
	$(FRONT_EXEC) npm run lint -- --fix

# --- Database ---

migrate: ## Run database migrations
	$(API_EXEC) bin/console doctrine:migrations:migrate --no-interaction

fixtures: ## Load database fixtures
	$(API_EXEC) bin/console doctrine:fixtures:load --purge-with-truncate --no-interaction

# --- Build & Deploy ---

build: ## Build Docker images for production
	docker build --target prod -t $(DOCKER_REGISTRY)/api:$(IMAGE_TAG) -f docker/api/Dockerfile .
	docker build --target prod -t $(DOCKER_REGISTRY)/frontend:$(IMAGE_TAG) -f docker/frontend/Dockerfile .

push: ## Push images to registry
	docker push $(DOCKER_REGISTRY)/api:$(IMAGE_TAG)
	docker push $(DOCKER_REGISTRY)/frontend:$(IMAGE_TAG)

deploy: ## Deploy to production server
	scp docker-compose.prod.yml $(SERVER_USER)@$(SERVER_HOST):/opt/programel/docker-compose.prod.yml
	ssh $(SERVER_USER)@$(SERVER_HOST) '\
		cd /opt/programel && \
		docker compose -f docker-compose.prod.yml pull && \
		docker compose -f docker-compose.prod.yml exec api bin/console doctrine:migrations:migrate --no-interaction && \
		docker compose -f docker-compose.prod.yml up -d && \
		echo "$$(date -Iseconds) $(IMAGE_TAG)" >> .deploy-history \
	'

rollback: ## Rollback to previous version (usage: make rollback or make rollback TAG=abc123)
ifdef TAG
	ssh $(SERVER_USER)@$(SERVER_HOST) '\
		cd /opt/programel && \
		IMAGE_TAG=$(TAG) docker compose -f docker-compose.prod.yml pull && \
		IMAGE_TAG=$(TAG) docker compose -f docker-compose.prod.yml up -d \
	'
else
	ssh $(SERVER_USER)@$(SERVER_HOST) '\
		cd /opt/programel && \
		PREV_TAG=$$(tail -2 .deploy-history | head -1 | awk "{print \$$2}") && \
		IMAGE_TAG=$$PREV_TAG docker compose -f docker-compose.prod.yml pull && \
		IMAGE_TAG=$$PREV_TAG docker compose -f docker-compose.prod.yml up -d \
	'
endif

# --- Operations ---

backup: ## Run database backup
	ssh $(SERVER_USER)@$(SERVER_HOST) '/opt/programel/docker/scripts/backup.sh'

certs: ## Generate local SSL certificates via mkcert
	mkcert -install
	mkcert -cert-file docker/nginx/ssl/dev/cert.pem -key-file docker/nginx/ssl/dev/key.pem \
		programel.local "*.programel.local" localhost 127.0.0.1 ::1
	@echo "\n✓ Certificates generated in docker/nginx/ssl/dev/"

hosts: ## Show /etc/hosts entries needed
	@echo "Add these to /etc/hosts:"
	@echo "127.0.0.1  programel.local test.programel.local lebenslauf.programel.local olcha.programel.local"
