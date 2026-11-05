#!/usr/bin/env bash

mdev_test_fast() {
    local suite path pid status=0 started=$SECONDS remaining selection
    local timeout="${MDEV_TEST_TIMEOUT:-300}"
    local state="$MDEV_DIR/.docker/tests"
    local -a pids=() suites=() unit_targets=() integration_targets=() integrity_targets=() rest_targets=() graphql_targets=()
    local -a block_modules=() integrity_modules=()
    export MDEV_TEST_SELECTION=focused

    [[ $# == 0 ]] || m_fatal 'Usage: mdev test fast [--dry-run]. Use MDEV_TEST_BASE to select the change.'
    [[ "$timeout" =~ ^[1-9][0-9]*$ ]] && [[ "$timeout" -le 86400 ]] \
        || m_fatal 'MDEV_TEST_TIMEOUT must be an integer between 1 and 86400 seconds.'
    [[ "${MDEV_TEST_REBUILD:-0}" == 0 || "${MDEV_TEST_REBUILD:-0}" == 1 ]] \
        || m_fatal 'MDEV_TEST_REBUILD must be 0 or 1.'
    export MDEV_TEST_COMMAND_TIMEOUT="$timeout"

    mdev_test_resolve_merge_base
    mdev_test_load_changed_files
    mdev_test_collect_targets unit
    unit_targets=("${TEST_TARGETS[@]}")
    mdev_test_collect_targets integration
    for path in "${TEST_TARGETS[@]}"; do
        [[ "$path" == dev/tests/integration/testsuite/Magento/Test/Integrity/* ]] || integration_targets+=("$path")
    done
    selection="$(docker compose exec -T -w /magento app php /dev/stdin /magento "${CHANGED_FILES[@]}" \
        < "$MDEV_DIR/etc/tests/test-select.php")" || m_fatal 'Cannot select integrity and API tests.'
    while IFS=$'\t' read -r suite path; do
        case "$suite" in
            integrity) integrity_targets+=("$path") ;;
            rest) rest_targets+=("$path") ;;
            graphql) graphql_targets+=("$path") ;;
            block) block_modules+=("$path") ;;
            module) integrity_modules+=("$path") ;;
            warning) printf '  %s\n' "$path" >&2 ;;
            '') ;;
            *) m_fatal "Unexpected test selection suite: $suite" ;;
        esac
    done <<< "$selection"
    MDEV_TEST_BLOCK_MODULES="$(IFS=,; printf '%s' "${block_modules[*]}")"
    MDEV_TEST_INTEGRITY_MODULES="$(IFS=,; printf '%s' "${integrity_modules[*]}")"
    export MDEV_TEST_BLOCK_MODULES MDEV_TEST_INTEGRITY_MODULES
    if [[ $(( ${#rest_targets[@]} + ${#graphql_targets[@]} )) -gt 0 \
        && $(( ${#integration_targets[@]} + ${#integrity_targets[@]} )) == 0 ]]; then
        path=dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/DiConfigFilesTest.php
        [[ -f "$SRC_DIR/$path" ]] || m_fatal 'API tests require the native DI integrity bootstrap test.'
        integrity_targets+=("$path")
    fi
    for suite in unit integration integrity rest graphql; do
        printf 'Focused %s targets (base %s):\n' "$suite" "$TEST_BASE_LABEL"
        case "$suite" in
            unit) printf '  %s\n' "${unit_targets[@]}" ;;
            integration) printf '  %s\n' "${integration_targets[@]}" ;;
            integrity) printf '  %s\n' "${integrity_targets[@]}" ;;
            rest) printf '  %s\n' "${rest_targets[@]}" ;;
            graphql) printf '  %s\n' "${graphql_targets[@]}" ;;
        esac
        if [[ "$suite" == integrity && ${#block_modules[@]} -gt 0 ]]; then
            printf '  Block instantiation scope: %s\n' "$MDEV_TEST_BLOCK_MODULES"
        fi
        if [[ "$suite" == integrity && ${#integrity_modules[@]} -gt 0 ]]; then
            printf '  DI file scope: %s (all native global merges retained)\n' "$MDEV_TEST_INTEGRITY_MODULES"
        fi
    done
    integration_targets+=("${integrity_targets[@]}")
    [[ "$DRY_RUN" != 1 ]] || return 0
    [[ ${#unit_targets[@]} -gt 0 || ${#integration_targets[@]} -gt 0 ]] \
        || m_fatal 'No focused test targets. Pass explicit paths to mdev test unit/integration or choose another MDEV_TEST_BASE.'

    mkdir -p "$state"
    chmod 700 "$state"
    mkdir "$state/run.lock" 2>/dev/null \
        || m_fatal 'A fast test run is already active. If it was interrupted, remove .docker/tests/run.lock after checking its owner.'
    printf '%s\n' "$$" > "$state/run.lock/pid"
    trap 'rm -f "$MDEV_DIR/.docker/tests/run.lock/pid"; rmdir "$MDEV_DIR/.docker/tests/run.lock"' EXIT
    trap 'trap - EXIT; printf "Interrupted; keep run.lock until container test processes have stopped.\n" >&2; exit 130' INT
    trap 'trap - EXIT; printf "Terminated; keep run.lock until container test processes have stopped.\n" >&2; exit 143' TERM

    if [[ ! -s "$state/db-password" ]]; then
        (umask 077; openssl rand -hex 24 > "$state/db-password")
    fi
    IFS= read -r MDEV_TEST_DB_PASSWORD < "$state/db-password"
    export MDEV_TEST_DB_PASSWORD
    local run
    run="$state/$(date +%Y%m%d-%H%M%S)-$$"
    mkdir "$run"
    printf 'Fast test logs: %s\n' "$run"
    printf 'suite,seconds,status\n' > "$run/timings.csv"

    # Static and unit tests share no database; integration uses a dedicated sandbox.
    for suite in static unit; do
        if [[ "$suite" == unit && ${#unit_targets[@]} == 0 ]]; then
            continue
        fi
        (
            local suite_started=$SECONDS code=0
            if [[ "$suite" == static ]]; then
                mdev_test_run_static || code=$?
            else
                local -a targets=()
                for path in "${unit_targets[@]}"; do targets+=("/magento/$path"); done
                docker compose exec -T -w /magento app timeout "$timeout" \
                    php "$PHPUNIT" --no-extensions -c /magento/dev/tests/unit/phpunit.xml.dist \
                    "${targets[@]}" || code=$?
            fi
            printf '%s,%s,%s\n' "$suite" "$((SECONDS - suite_started))" "$code" >> "$run/timings.csv"
            exit "$code"
        ) > "$run/$suite.log" 2>&1 &
        pids+=("$!")
        suites+=("$suite")
    done
    for pid in "${!pids[@]}"; do
        if wait "${pids[$pid]}"; then
            printf '%s: passed\n' "${suites[$pid]}"
        else
            status=1
            printf '%s: failed\n' "${suites[$pid]}" >&2
            tail -n 35 "$run/${suites[$pid]}.log" >&2
        fi
    done

    if [[ ${#integration_targets[@]} -gt 0 ]]; then
        remaining=$((timeout - SECONDS + started))
        [[ "$remaining" -gt 0 ]] || m_fatal "Fast test budget (${timeout}s) exhausted before integration."
        local integration_started=$SECONDS runtime fingerprint code_key
        export MDEV_TEST_EE_CODE_TARGET=/mdev-unused/ee MDEV_TEST_B2B_CODE_TARGET=/mdev-unused/b2b
        [[ ! -d "$SRC_DIR/ee/app/code" ]] || MDEV_TEST_EE_CODE_TARGET=/magento/ee/app/code
        [[ ! -d "$SRC_DIR/b2b/app/code" ]] || MDEV_TEST_B2B_CODE_TARGET=/magento/b2b/app/code
        local -a compose=(docker compose -f "$MDEV_DIR/docker-compose.yml" -f "$MDEV_DIR/compose/tests.yaml")
        "${compose[@]}" up -d --no-deps --wait --wait-timeout "$remaining" test-db test-app > "$run/database.log" 2>&1 \
            || { cat "$run/database.log" >&2; return 1; }
        runtime="$(docker inspect --format '{{.Id}} {{.State.StartedAt}}' "$("${compose[@]}" ps -q test-db)")"
        fingerprint="$(mdev_test_install_fingerprint "$runtime")"
        code_key="$(mdev_test_code_fingerprint)"
        "${compose[@]}" exec -T -u root test-app chown "$U_ID" \
            /magento/dev/tests/integration/tmp/mdev-fast /magento/generated /magento/app/code \
            /magento/app/etc /magento/var /magento/pub/media /magento/pub/static \
            "$MDEV_TEST_EE_CODE_TARGET" "$MDEV_TEST_B2B_CODE_TARGET"
        "${compose[@]}" exec -T -w /magento -e MDEV_TEST_DB_PASSWORD test-app \
            php /dev/stdin "$fingerprint" "${MDEV_TEST_REBUILD:-0}" "$code_key" \
            < "$MDEV_DIR/etc/tests/test-config.php" \
            > "$run/prepare.log" 2>&1 || { cat "$run/prepare.log" >&2; return 1; }
        local -a targets=()
        for path in "${integration_targets[@]}"; do
            if [[ "$path" == dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/BlockInstantiationTest.php \
                && ${#block_modules[@]} -gt 0 ]]; then
                targets+=(/mdev-tests/BlockInstantiationTest.php)
            elif [[ "$path" == dev/tests/integration/testsuite/Magento/Test/Integrity/Modular/DiConfigFilesTest.php \
                && ${#integrity_modules[@]} -gt 0 ]]; then
                targets+=(/mdev-tests/DiConfigFilesTest.php)
            else
                targets+=("/magento/$path")
            fi
        done
        remaining=$((timeout - SECONDS + started))
        [[ "$remaining" -gt 0 ]] || m_fatal "Fast test budget (${timeout}s) exhausted preparing integration."
        local code=0
        "${compose[@]}" exec -T -w /magento -e MDEV_TEST_DB_PASSWORD -e MDEV_TEST_BLOCK_MODULES \
            -e MDEV_TEST_INTEGRITY_MODULES test-app \
            timeout "$remaining" php "$PHPUNIT" \
            -c "/magento/dev/tests/integration/tmp/mdev-fast/$fingerprint/phpunit.xml" \
            "${targets[@]}" > "$run/integration.log" 2>&1 || code=$?
        printf 'integration,%s,%s\n' "$((SECONDS - integration_started))" "$code" >> "$run/timings.csv"
        if [[ "$code" == 0 ]]; then
            printf 'integration + integrity: passed\n'
        else
            status=1
            printf 'integration + integrity: failed (exit %s)\n' "$code" >&2
            sed "s/$MDEV_TEST_DB_PASSWORD/[redacted]/g" "$run/integration.log" | tail -n 40 >&2
            "${compose[@]}" exec -T test-app rm -f /magento/dev/tests/integration/tmp/mdev-fast/active
        fi
        if [[ "$code" == 0 && $(( ${#rest_targets[@]} + ${#graphql_targets[@]} )) -gt 0 ]]; then
            remaining=$((timeout - SECONDS + started))
            [[ "$remaining" -gt 0 ]] || m_fatal "Fast test budget (${timeout}s) exhausted before API tests."
            "${compose[@]}" exec -T test-db timeout "$remaining" sh -c \
                "MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" exec mariadb-dump -uroot --single-transaction --skip-comments --add-drop-database --databases mdev_fast" \
                > "$run/api-database.sql" 2> "$run/api-database.log" \
                || { cat "$run/api-database.log" >&2; return 1; }
            local api_started
            for suite in rest graphql; do
                targets=()
                if [[ "$suite" == rest ]]; then
                    for path in "${rest_targets[@]}"; do targets+=("/magento/$path"); done
                else
                    for path in "${graphql_targets[@]}"; do targets+=("/magento/$path"); done
                fi
                [[ ${#targets[@]} -gt 0 ]] || continue
                api_started=$SECONDS
                code=0
                remaining=$((timeout - SECONDS + started))
                if [[ "$remaining" -le 0 ]]; then
                    code=124
                    printf 'Time budget exhausted before %s.\n' "$suite" > "$run/$suite.log"
                else
                    "${compose[@]}" exec -T -w /magento test-app timeout "$remaining" \
                        php /dev/stdin "$fingerprint" < "$MDEV_DIR/etc/tests/test-api-config.php" \
                        > "$run/$suite.log" 2>&1 || code=$?
                    remaining=$((timeout - SECONDS + started))
                    if [[ "$code" == 0 && "$remaining" -gt 0 ]]; then
                        local -a request=(http://test-app:8080/rest/all/V1/directory/countries)
                        if [[ "$suite" == graphql ]]; then
                            request=(-H 'Content-Type: application/json' --data '{"query":"{__typename}"}' \
                                http://test-app:8080/graphql)
                        fi
                        "${compose[@]}" exec -T test-app curl --fail --silent --show-error --max-time "$remaining" \
                            --output /dev/null "${request[@]}" >> "$run/$suite.log" 2>&1 || code=$?
                        if [[ "$code" == 0 ]]; then
                            remaining=$((timeout - SECONDS + started))
                            if [[ "$remaining" -gt 0 ]]; then
                                "${compose[@]}" exec -T -w /magento -e MDEV_TEST_DB_PASSWORD test-app \
                                    timeout "$remaining" php "$PHPUNIT" \
                                    -c "/magento/dev/tests/integration/tmp/mdev-fast/$fingerprint/phpunit_$suite.xml" \
                                    "${targets[@]}" >> "$run/$suite.log" 2>&1 || code=$?
                            else
                                code=124
                            fi
                        fi
                    elif [[ "$code" == 0 ]]; then
                        code=124
                    fi
                fi
                "${compose[@]}" exec -T test-db timeout 60 sh -c \
                    "MYSQL_PWD=\"\$MARIADB_ROOT_PASSWORD\" exec mariadb -uroot" \
                    < "$run/api-database.sql" >> "$run/api-database.log" 2>&1 \
                    || {
                        cat "$run/api-database.log" >&2
                        "${compose[@]}" exec -T test-app rm -f /magento/dev/tests/integration/tmp/mdev-fast/active
                        return 1
                    }
                printf '%s\n' "$fingerprint" | "${compose[@]}" exec -T test-app sh -c \
                    'cat > /magento/dev/tests/integration/tmp/mdev-fast/active' || return 1
                printf '%s,%s,%s\n' "$suite" "$((SECONDS - api_started))" "$code" >> "$run/timings.csv"
                if [[ "$code" == 0 ]]; then
                    printf '%s: passed\n' "$suite"
                else
                    status=1
                    printf '%s: failed (exit %s)\n' "$suite" "$code" >&2
                    sed "s/$MDEV_TEST_DB_PASSWORD/[redacted]/g" "$run/$suite.log" | tail -n 40 >&2
                    "${compose[@]}" exec -T test-app rm -f /magento/dev/tests/integration/tmp/mdev-fast/code-key
                fi
            done
            rm -f "$run/api-database.sql"
        fi
    fi
    if [[ $((SECONDS - started)) -gt "$timeout" ]]; then
        status=1
        printf 'Fast test budget (%ss) exceeded, including cleanup.\n' "$timeout" >&2
    fi
    printf 'Fast tests finished in %ss (budget %ss). Timings: %s/timings.csv\n' \
        "$((SECONDS - started))" "$timeout" "$run"
    return "$status"
}

mdev_test_install_fingerprint() {
    set -e -o pipefail
    local runtime="$1" checkout
    local -a inputs=(composer.json composer.lock app/etc dev/tests/integration/framework
        dev/tests/integration/etc dev/tests/integration/_files
        ':(glob)**/Test/_files/Magento/**' ':(glob)**/Setup/**' ':(glob)**/etc/db_schema*.xml'
        ':(glob)**/etc/module.xml' ':(glob)**/etc/di.xml' ':(glob)**/etc/config.xml')
    {
        printf '%s\n' "$runtime"
        cat "$MDEV_DIR/etc/tests/test-config.php"
        for checkout in "$SRC_DIR" "$SRC_DIR/ee" "$SRC_DIR/b2b"; do
            [[ -d "$checkout/.git" || -f "$checkout/.git" ]] || continue
            (
                cd "$checkout" || exit 1
                git ls-files -s -- "${inputs[@]}"
                git diff --binary HEAD -- "${inputs[@]}"
                git ls-files --others --exclude-standard -- "${inputs[@]}"
                git ls-files --others --exclude-standard -- "${inputs[@]}" | git hash-object --stdin-paths
            )
        done
    } | shasum -a 256 | cut -c 1-24
}

mdev_test_code_fingerprint() {
    set -e -o pipefail
    local checkout
    {
        for checkout in "$SRC_DIR" "$SRC_DIR/ee" "$SRC_DIR/b2b"; do
            [[ -d "$checkout/.git" || -f "$checkout/.git" ]] || continue
            (
                cd "$checkout" || exit 1
                git rev-parse HEAD
                git diff --binary HEAD -- app/code '*.php'
                git ls-files --others --exclude-standard -- app/code '*.php' | git hash-object --stdin-paths
            )
        done
    } | shasum -a 256 | cut -c 1-24
}
