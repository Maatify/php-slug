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
    composer audit --no-interaction --abandoned=fail
}

syntax() {
    require_command php
    local shell_files=()
    while IFS= read -r -d '' file; do
        shell_files+=("$file")
    done < <(find tools/ci -type f -name '*.sh' -print0)
    if ((${#shell_files[@]} == 0)); then
        echo 'No package-owned shell scripts were found for syntax verification.' >&2
        exit 1
    fi
    for file in "${shell_files[@]}"; do
        bash -n "$file"
    done
    local files=()
    while IFS= read -r -d '' file; do
        files+=("$file")
    done < <(find src tests tools examples -type f -name '*.php' -print0)
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
    vendor/bin/phpstan analyse --memory-limit=512M
}

test_unit() {
    require_command php
    if [[ ! -x vendor/bin/phpunit ]]; then
        echo 'PHPUnit is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite unit --do-not-cache-result
}

style() {
    if [[ ! -x vendor/bin/php-cs-fixer ]]; then
        echo 'PHP CS Fixer is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes --sequential
    php tools/ci/test-per-cs31.php
    php tools/ci/verify-per-cs31.php
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
    test
}

lowest_tests() {
    lowest_dependencies
    test
}

test_integration() {
    bash tools/ci/run-integration.sh integration
}

test_system() {
    bash tools/ci/run-integration.sh system
}

test() {
    test_unit
    bash tools/ci/run-integration.sh full
}

consumer() {
    require_command php
    require_command composer
    bash tools/ci/run-integration.sh consumer
}

examples_smoke() {
    latest_dependencies
    php examples/canonicalization.php
    bash tools/ci/run-integration.sh examples
}

usage() {
    cat >&2 <<'USAGE'
Usage: tools/ci/run-gate.sh <gate> [argument]

Gates:
  latest-quality   Latest dependency resolution, syntax, PHPStan max, style, audit
  latest-tests     Latest dependency resolution, then the complete maintained suite
  lowest-tests     Lowest dependency resolution on PHP 8.4, then the complete maintained suite
  test             Unit, Integration, and System suites
  test-unit        Unit suite only; Docker is not required
  test-integration Integration suite through the canonical Compose lifecycle
  test-system      System suite through the canonical Compose lifecycle
  syntax           PHP syntax for src/, tests/, tools/, examples/, and PHP tool configuration
  phpstan          PHPStan max using phpstan.neon
  style            Composite PER-CS 3.1 verification (PHP CS Fixer + supplemental verifier)
  audit            Composer security and abandoned-package audit
  schema           Verify the package-owned schema path exists
  workflow-lint    actionlint for every .github/workflows/*.yml|*.yaml
  whitespace RANGE Git-aware whitespace check for an explicit BASE...HEAD committed range
  consumer         Consumer Verification Harness clean run x2
  examples-smoke   Stateless and persisted examples through the canonical Compose lifecycle
USAGE
    exit 2
}

gate="${1:-}"
case "$gate" in
    latest-quality) latest_quality ;;
    latest-tests) latest_tests ;;
    lowest-tests) lowest_tests ;;
    test) test ;;
    test-unit) test_unit ;;
    test-integration) test_integration ;;
    test-system) test_system ;;
    syntax) syntax ;;
    phpstan) phpstan ;;
    style) style ;;
    audit) audit ;;
    schema) schema_contract ;;
    workflow-lint) workflow_lint ;;
    whitespace) whitespace "${2:-}" ;;
    consumer) consumer ;;
    examples-smoke) examples_smoke ;;
    *) usage ;;
esac
