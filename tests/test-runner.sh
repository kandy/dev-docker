#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ "${1:-}" == --php ]]; then
    cd "$ROOT"
    {
        cat etc/tests/test-select.php
        sed '1d; /^declare(strict_types=1);$/d' etc/tests/test-selection.php
    } | docker compose exec -T app php
    exit
fi

FIXTURE="$(mktemp -d "${TMPDIR:-/tmp}/mdev-test-runner.XXXXXX")"
export FIXTURE
cleanup() {
    # This directory was created above and contains only generated test fixtures.
    rm -rf -- "$FIXTURE"
}
trap cleanup EXIT
mkdir -p "$FIXTURE/src"
git -C "$FIXTURE/src" init -q
mkdir -p "$FIXTURE/src/app/code/Magento/Example/Model" \
    "$FIXTURE/src/app/code/Magento/Example/Test/Unit/Model" \
    "$FIXTURE/src/dev/tests/integration/testsuite/Magento/Example/Model"
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Model/Subject.php"
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Test/Unit/Model/SubjectTest.php"
printf '<?php\n' > "$FIXTURE/src/dev/tests/integration/testsuite/Magento/Example/Model/SubjectTest.php"
git -C "$FIXTURE/src" add .
git -C "$FIXTURE/src" -c user.name=Tests -c user.email=tests@example.invalid commit -qm fixture

run() {
    MDEV_TEST_BASE=HEAD MDEV_TEST_SELECTION=focused RUNNER="$ROOT/bin/test" bash -e -c '
        export MDEV_DIR="$FIXTURE"
        export U_ID=501
        m_assumeDocker() { :; }
        m_fatal() { printf "%s\n" "$*" >&2; exit 1; }
        docker() {
            if [[ -n "${COMMAND_LOG:-}" ]]; then
                printf "%s\n" "$*" >> "$COMMAND_LOG"
            fi
            if [[ "${CAPTURE_COMMAND:-0}" == 1 && "$*" == *"php /magento/vendor/bin/phpunit"* ]]; then
                printf "%s\n" "$*"
            fi
            if [[ "$*" == "compose ps -q app" ]]; then
                printf "fixture\n"
            elif [[ "$*" == *"php /dev/stdin /magento"* ]]; then
                if [[ "${FAIL_SELECTION:-0}" == 1 ]]; then
                    return 8
                fi
                printf "%s\n" "${SURFACE_SELECTION:-}"
            elif [[ "$*" == *--no-configuration* && "${FAIL_STATIC:-0}" == 1 ]]; then
                printf "synthetic static failure\n"
                return 7
            elif [[ -n "${FAIL_API_SUITE:-}" && "$*" == *"phpunit_${FAIL_API_SUITE}.xml"* ]]; then
                printf "synthetic %s failure\n" "$FAIL_API_SUITE"
                return 9
            elif [[ "${FAIL_RESTORE:-0}" == 1 && "$*" == *"exec mariadb -uroot"* ]]; then
                printf "synthetic database restore failure\n"
                return 11
            elif [[ "${EXHAUST_AFTER_RESTORE:-0}" == 1 && "$*" == *"exec mariadb -uroot"* ]]; then
                SECONDS=$((SECONDS + 301))
            fi
        }
        source "$RUNNER"
    ' -- "$@"
}

output="$(CAPTURE_COMMAND=1 run --dry-run)"
[[ "$output" == *'php /magento/vendor/bin/phpunit --dry-run'* ]]

if output="$(run unit --dry-run 2>&1)"; then
    printf 'An empty change must not silently run an entire suite.\n' >&2
    exit 1
fi
[[ "$output" == *'No unit test targets matched'* ]]

printf '// change\n' >> "$FIXTURE/src/app/code/Magento/Example/Model/Subject.php"
output="$(run unit --dry-run)"
[[ "$output" == *"/magento/app/code/Magento/Example/Test/Unit/Model/SubjectTest.php"* ]]
[[ "$output" != *$'  /magento/app/code/Magento/Example/Test/Unit\n'* ]]
output="$(run integration --dry-run)"
[[ "$output" == *"/magento/dev/tests/integration/testsuite/Magento/Example/Model/SubjectTest.php"* ]]
[[ "$(run intgration --dry-run)" == "$output" ]]

mkdir -p "$FIXTURE/src/app/code/Magento/Example/Test/Unit/_files"
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Test/Unit/_files/fixture.php"
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Test/Unit/_files/FixtureTest.php"
output="$(run unit --dry-run)"
[[ "$output" != *fixture.php* ]]
[[ "$output" != *FixtureTest.php* ]]
[[ "$output" != *dev/tests/integration* ]]
printf '// changed test\n' >> "$FIXTURE/src/dev/tests/integration/testsuite/Magento/Example/Model/SubjectTest.php"
output="$(run unit --dry-run)"
[[ "$output" != *dev/tests/integration* ]]

printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Test/Unit/Model/NewTest.php"
output="$(run unit --dry-run)"
[[ "$output" == *NewTest.php* ]]
[[ "$(printf '%s\n' "$output" | grep -c '/SubjectTest.php')" == 1 ]]
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Model/Unmapped.php"
output="$(run unit --dry-run 2>&1)"
[[ "$output" == *'review its regression coverage'* ]]
[[ "$output" != *$'  /magento/app/code/Magento/Example/Test/Unit\n'* ]]
output="$(run unit --dry-run /magento/app/code/Magento/Example/Test/Unit/Model/SubjectTest.php)"
[[ "$output" == /magento/app/code/Magento/Example/Test/Unit/Model/SubjectTest.php ]]
mkdir -p "$FIXTURE/etc" "$FIXTURE/src/dev/tests/static/testsuite/Magento/Test/_files"
ln -s "$ROOT/etc/tests" "$FIXTURE/etc/tests"
rm "$FIXTURE/src/dev/tests/integration/testsuite/Magento/Example/Model/SubjectTest.php"
output="$(run fast)"
[[ "$output" == *'unit: passed'* && "$output" == *'static: passed'* ]]
[[ ! -d "$FIXTURE/.docker/tests/run.lock" ]]
if output="$(FAIL_STATIC=1 run fast 2>&1)"; then
    printf 'A failed static worker was reported as success.\n' >&2
    exit 1
fi
[[ "$output" == *'static: failed'* && "$output" == *'synthetic static failure'* ]]
[[ ! -d "$FIXTURE/.docker/tests/run.lock" ]]
mkdir "$FIXTURE/.docker/tests/run.lock"
if output="$(run fast 2>&1)"; then
    printf 'Concurrent fast runs must not share the database.\n' >&2
    exit 1
fi
[[ "$output" == *'already active'* ]]
rmdir "$FIXTURE/.docker/tests/run.lock"
if output="$(MDEV_TEST_TIMEOUT=invalid run fast 2>&1)"; then
    printf 'An invalid budget was accepted.\n' >&2
    exit 1
fi
[[ "$output" == *'must be an integer'* ]]
if output="$(run fast --profile 2>&1)"; then
    printf 'Removed profiling option was still accepted.\n' >&2
    exit 1
fi
[[ "$output" == *'Usage: mdev test fast [--dry-run]'* ]]
if output="$(FAIL_SELECTION=1 run fast 2>&1)"; then
    printf 'Failed surface selection must not silently skip API coverage.\n' >&2
    exit 1
fi
[[ "$output" == *'Cannot select integrity and API tests'* ]]
selection="$(printf 'integrity\tdev/tests/integration/testsuite/Magento/Test/Integrity/Modular/DiConfigFilesTest.php\nrest\tdev/tests/api-functional/testsuite/Magento/Example/Api/SubjectRepositoryTest.php\ngraphql\tdev/tests/api-functional/testsuite/Magento/GraphQl/Example/SubjectTest.php')"
commands="$FIXTURE/commands.log"
output="$(SURFACE_SELECTION="$selection" COMMAND_LOG="$commands" run fast)"
[[ "$output" == *'integration + integrity: passed'* && "$output" == *'rest: passed'* && "$output" == *'graphql: passed'* ]]
[[ "$(grep -c 'exec mariadb -uroot' "$commands")" == 2 ]]
[[ "$(grep -c 'exec mariadb-dump' "$commands")" == 1 ]]
[[ ! -d "$FIXTURE/.docker/tests/run.lock" ]]
block_selection="$(printf '%s\nintegrity\tdev/tests/integration/testsuite/Magento/Test/Integrity/Modular/BlockInstantiationTest.php\nblock\tMagento_Example\nmodule\tMagento_Example' "$selection")"
output="$(SURFACE_SELECTION="$block_selection" COMMAND_LOG="$FIXTURE/block-commands.log" run fast)"
[[ "$output" == *'Block instantiation scope: Magento_Example'* ]]
grep -qF '/mdev-tests/BlockInstantiationTest.php' "$FIXTURE/block-commands.log"
grep -qF -- '-e MDEV_TEST_BLOCK_MODULES' "$FIXTURE/block-commands.log"
grep -qF '/mdev-tests/DiConfigFilesTest.php' "$FIXTURE/block-commands.log"
grep -qF -- '-e MDEV_TEST_INTEGRITY_MODULES' "$FIXTURE/block-commands.log"
grep -qF '/mdev-unused/ee /mdev-unused/b2b' "$FIXTURE/block-commands.log"
mkdir -p "$FIXTURE/src/ee/app/code" "$FIXTURE/src/b2b/app/code"
output="$(SURFACE_SELECTION="$block_selection" COMMAND_LOG="$FIXTURE/edition-commands.log" run fast)"
grep -qF '/magento/ee/app/code /magento/b2b/app/code' "$FIXTURE/edition-commands.log"
if output="$(SURFACE_SELECTION="$selection" FAIL_API_SUITE=rest COMMAND_LOG="$FIXTURE/rest-failure-commands.log" run fast 2>&1)"; then
    printf 'A failed REST suite was reported as success.\n' >&2
    exit 1
fi
[[ "$output" == *'rest: failed'* && "$output" == *'synthetic rest failure'* ]]
grep -qF 'rm -f /magento/dev/tests/integration/tmp/mdev-fast/code-key' "$FIXTURE/rest-failure-commands.log"
if output="$(SURFACE_SELECTION="$selection" FAIL_API_SUITE=graphql run fast 2>&1)"; then
    printf 'A failed GraphQL suite was reported as success.\n' >&2
    exit 1
fi
[[ "$output" == *'graphql: failed'* && "$output" == *'synthetic graphql failure'* ]]
if output="$(SURFACE_SELECTION="$selection" FAIL_RESTORE=1 run fast 2>&1)"; then
    printf 'A failed API database restore was reported as success.\n' >&2
    exit 1
fi
[[ "$output" == *'synthetic database restore failure'* ]]
rest_selection="${selection%$'\n'*}"
if output="$(SURFACE_SELECTION="$rest_selection" EXHAUST_AFTER_RESTORE=1 run fast 2>&1)"; then
    printf 'An over-budget API restore was reported as success.\n' >&2
    exit 1
fi
[[ "$output" == *'rest: passed'* && "$output" == *'Fast test budget (300s) exceeded, including cleanup.'* ]]
[[ ! -d "$FIXTURE/.docker/tests/run.lock" ]]

fingerprints() {
    MDEV_DIR="$ROOT" SRC_DIR="$FIXTURE/src" bash -e -c '
        source "$MDEV_DIR/etc/tests/test-fast.sh"
        mdev_test_install_fingerprint fixture-database
        mdev_test_code_fingerprint
    '
}
before="$(fingerprints)"
printf '// another code change\n' >> "$FIXTURE/src/app/code/Magento/Example/Model/Subject.php"
after="$(fingerprints)"
[[ "${before%%$'\n'*}" == "${after%%$'\n'*}" ]]
[[ "${before#*$'\n'}" != "${after#*$'\n'}" ]]
before="$after"
printf '<layout/>\n' > "$FIXTURE/src/app/code/Magento/Example/view.xml"
after="$(fingerprints)"
[[ "${before%%$'\n'*}" == "${after%%$'\n'*}" ]]
[[ "${before#*$'\n'}" != "${after#*$'\n'}" ]]
mkdir -p "$FIXTURE/src/app/code/Magento/Example/Setup"
printf '<?php\n' > "$FIXTURE/src/app/code/Magento/Example/Setup/InstallData.php"
before="$after"
after="$(fingerprints)"
[[ "${before%%$'\n'*}" != "${after%%$'\n'*}" ]]
printf 'Test selection regression checks passed.\n'
