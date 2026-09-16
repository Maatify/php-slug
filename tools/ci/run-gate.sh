#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required command is unavailable: $1" >&2
        exit 1
    fi
}

require_database_environment() {
    for variable in SLUG_TEST_DB_HOST SLUG_TEST_DB_PORT SLUG_TEST_DB_NAME SLUG_TEST_DB_USER SLUG_TEST_DB_PASSWORD; do
        if [[ -z "${!variable:-}" ]]; then
            echo "Required database environment variable is missing: $variable" >&2
            exit 1
        fi
    done
    if [[ "$SLUG_TEST_DB_NAME" != *_test ]]; then
        echo "SLUG_TEST_DB_NAME must end with _test." >&2
        exit 1
    fi
}

latest_dependencies() {
    require_command composer
    composer validate --strict
    composer update --no-interaction --prefer-dist --no-progress
    composer dump-autoload --optimize --strict-psr
    composer check-platform-reqs
}

lowest_dependencies() {
    require_command composer
    composer validate --strict
    composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist --no-progress
    composer dump-autoload --optimize --strict-psr
    composer check-platform-reqs
}

syntax() {
    require_command php
    bash -n tools/ci/run-gate.sh
    local files=()
    while IFS= read -r -d '' file; do
        files+=("$file")
    done < <(find src tests tools -type f -name '*.php' -print0)
    if ((${#files[@]} == 0)); then
        echo 'No package-owned PHP files were found for syntax verification.' >&2
        exit 1
    fi
    for file in "${files[@]}"; do
        php -l "$file" >/dev/null
    done
    if [[ -f .php-cs-fixer.php ]]; then
        php -l .php-cs-fixer.php >/dev/null
    fi
}

phpstan() {
    require_command php
    if [[ ! -x vendor/bin/phpstan ]]; then
        echo 'PHPStan is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/phpstan analyse src tests --level=max --memory-limit=512M
}

phpunit() {
    require_command php
    if [[ ! -x vendor/bin/phpunit ]]; then
        echo 'PHPUnit is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    if [[ ! -f .env.test ]]; then
        echo 'Full PHPUnit requires a local or CI-generated .env.test; use integration-env first.' >&2
        exit 1
    fi
    vendor/bin/phpunit --configuration phpunit.xml.dist tests --do-not-cache-result
}

style() {
    if [[ ! -x vendor/bin/php-cs-fixer ]]; then
        echo 'PHP CS Fixer is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes --sequential
}

audit() {
    require_command composer
    composer audit --no-interaction --abandoned=fail
}

schema_contract() {
    if [[ ! -f schema/mysql/001_slug_rc1.sql ]]; then
        echo 'The required package schema is missing: schema/mysql/001_slug_rc1.sql' >&2
        exit 1
    fi
}

integration_env() {
    require_database_environment
    umask 077
    printf '%s\n' \
        "SLUG_TEST_DB_HOST=$SLUG_TEST_DB_HOST" \
        "SLUG_TEST_DB_PORT=$SLUG_TEST_DB_PORT" \
        "SLUG_TEST_DB_NAME=$SLUG_TEST_DB_NAME" \
        "SLUG_TEST_DB_USER=$SLUG_TEST_DB_USER" \
        "SLUG_TEST_DB_PASSWORD=$SLUG_TEST_DB_PASSWORD" > .env.test
}

wait_mysql() {
    require_command php
    require_database_environment
    for attempt in $(seq 1 60); do
        if php -r '
            if (!extension_loaded("pdo_mysql")) { exit(2); }
            $host = getenv("SLUG_TEST_DB_HOST");
            $port = getenv("SLUG_TEST_DB_PORT");
            $name = getenv("SLUG_TEST_DB_NAME");
            $user = getenv("SLUG_TEST_DB_USER");
            $password = getenv("SLUG_TEST_DB_PASSWORD");
            try {
                $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->query("SELECT 1");
            } catch (Throwable) {
                exit(1);
            }
        '; then
            echo "MySQL-compatible target is ready after attempt $attempt."
            return
        fi
        sleep 2
    done
    echo 'MySQL-compatible target did not become ready within 120 seconds.' >&2
    exit 1
}

workflow_lint() {
    local workflow_files=()
    while IFS= read -r file; do
        workflow_files+=("$file")
    done < <(find .github/workflows -type f \( -name '*.yml' -o -name '*.yaml' \) -print | sort)
    if ((${#workflow_files[@]} == 0)); then
        echo 'No workflow files were found for workflow lint.' >&2
        exit 1
    fi
    require_command docker
    docker run --rm --network none -v "$repo_root:/repo:ro" -w /repo docker.io/rhysd/actionlint@sha256:887a259a5a534f3c4f36cb02dca341673c6089431057242cdc931e9f133147e9 "${workflow_files[@]}"
}

whitespace() {
    local comparison_range="${1:-}"
    if [[ "$comparison_range" != *...* ]]; then
        echo 'Whitespace verification requires a committed comparison range such as BASE_SHA...HEAD_SHA.' >&2
        exit 1
    fi
    local base_ref="${comparison_range%%...*}"
    local head_ref="${comparison_range##*...}"
    git rev-parse --verify "$base_ref^{commit}" >/dev/null
    git rev-parse --verify "$head_ref^{commit}" >/dev/null
    git diff --check "$base_ref...$head_ref"
}

latest_quality() {
    latest_dependencies
    syntax
    phpstan
    style
    audit
}

latest_tests() {
    latest_dependencies
    schema_contract
    wait_mysql
    integration_env
    phpunit
}

lowest_tests() {
    lowest_dependencies
    schema_contract
    wait_mysql
    integration_env
    phpunit
}

consumer() {
    require_command php
    require_command composer
    require_database_environment
    schema_contract
    php tests/Consumer/run.php
}

usage() {
    cat >&2 <<'USAGE'
Usage: tools/ci/run-gate.sh <gate> [argument]

Gates:
  latest-quality   Latest dependency resolution, syntax, PHPStan max, style, audit
  latest-tests     Latest dependency resolution, real MySQL, full PHPUnit
  lowest-tests     Lowest dependency resolution on PHP 8.4, real MySQL, full PHPUnit
  syntax           PHP syntax for src/, tests/, tools/, and PHP tool configuration
  phpstan          PHPStan max for src/ and tests/
  phpunit          Full PHPUnit suite; requires .env.test
  style            PHP CS Fixer dry-run
  audit            Composer security and abandoned-package audit
  schema           Verify the package-owned schema path exists
  integration-env  Write the ignored CI/local .env.test from database environment variables
  wait-mysql       Deterministic pdo_mysql readiness check
  workflow-lint    actionlint for every .github/workflows/*.yml|*.yaml
  whitespace RANGE Git-aware whitespace check for an explicit BASE...HEAD committed range
  consumer         Consumer Verification Harness clean run x2
USAGE
    exit 2
}

gate="${1:-}"
case "$gate" in
    latest-quality) latest_quality ;;
    latest-tests) latest_tests ;;
    lowest-tests) lowest_tests ;;
    syntax) syntax ;;
    phpstan) phpstan ;;
    phpunit) phpunit ;;
    style) style ;;
    audit) audit ;;
    schema) schema_contract ;;
    integration-env) integration_env ;;
    wait-mysql) wait_mysql ;;
    workflow-lint) workflow_lint ;;
    whitespace) whitespace "${2:-}" ;;
    consumer) consumer ;;
    *) usage ;;
esac
