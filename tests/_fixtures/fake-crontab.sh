#!/usr/bin/env bash
# Stands in for crontab(1) in tests. The crontab lives in $FAKE_CRONTAB_FILE, and every call's
# arguments are appended to $FAKE_CRONTAB_FILE.calls. Set $FAKE_CRONTAB_FAIL to make every call fail.
set -euo pipefail

file="${FAKE_CRONTAB_FILE:?FAKE_CRONTAB_FILE is not set}"
printf '%s\n' "$*" >> "${file}.calls"

if [[ -n "${FAKE_CRONTAB_FAIL:-}" ]]; then
    echo "crontab: ${FAKE_CRONTAB_FAIL}" >&2
    exit 1
fi

user="tester"
if [[ "${1:-}" == "-u" ]]; then
    user="$2"
    shift 2
fi

case "${1:-}" in
    -l)
        if [[ ! -f "$file" ]]; then
            echo "no crontab for ${user}" >&2
            exit 1
        fi
        cat "$file"
        ;;
    -)
        cat > "$file"
        ;;
    *)
        echo "fake crontab: unsupported arguments: $*" >&2
        exit 2
        ;;
esac
