DOCKER-APP-EXEC = docker exec -it laravel-clickhouse-migrations-app /bin/sh -c

ssh: ## Connect to containers via SSH
	docker exec -it laravel-clickhouse-migrations-app /bin/sh

setup-dev: ## Setup project for development
	make start
	make composer-install

start: ## Start application silently
	docker-compose up -d

stop: ## Stop application
	docker-compose down

restart: ## Restart the application
	make stop
	make start

composer-install: ## Install composer dependencies
	$(DOCKER-APP-EXEC) 'composer install'

composer-dump: ## Dump composer dependencies
	$(DOCKER-APP-EXEC) 'composer dump'

composer-update: ## Update composer dependencies
	$(DOCKER-APP-EXEC) 'composer update $(filter-out $@,$(MAKECMDGOALS))'

phpunit-test: ## Test app
	$(DOCKER-APP-EXEC) 'php vendor/bin/phpunit'

cleanup-docker: ## Remove old docker images
	docker rmi $$(docker images --filter "dangling=true" -q --no-trunc)

run: ## Run command in the container
	$(DOCKER-APP-EXEC) '$(filter-out $@,$(MAKECMDGOALS))'

help: # Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
