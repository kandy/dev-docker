#!/bin/sh

#
# Change the author name and/or email of a single commit.
#
# change-author [-f] commit-to-change [branch-to-rewrite [new-name [new-email]]]
#
#     If -f is supplied it is passed to "git filter-branch".
#
#     If <branch-to-rewrite> is not provided or is empty HEAD will be used.
#     Use "--all" or a space separated list (e.g. "master next") to rewrite
#     multiple branches.
#
#     If <new-name> (or <new-email>) is not provided or is empty, the normal
#     user.name (user.email) Git configuration value will be used.
#

force=''
if test "x$1" = "x-f"; then
    force='-f'
    shift
fi

die() {
    printf '%s\n' "$@"
    exit 128
}
targ="$(git rev-parse --verify "$1" 2>/dev/null)" || die "$1 is not a commit"
br="${2:-HEAD}"

TARG_COMMIT="$targ"
TARG_NAME="${3-}"
TARG_EMAIL="${4-}"
export TARG_COMMIT TARG_NAME TARG_EMAIL

filt='

    if test "$GIT_COMMIT" = "$TARG_COMMIT"; then
        if test -n "$TARG_EMAIL"; then
            GIT_AUTHOR_EMAIL="$TARG_EMAIL"
            export GIT_AUTHOR_EMAIL
        else
            unset GIT_AUTHOR_EMAIL
        fi
        if test -n "$TARG_NAME"; then
            GIT_AUTHOR_NAME="$TARG_NAME"
            export GIT_AUTHOR_NAME
        else
            unset GIT_AUTHOR_NAME
        fi
    fi

'

git filter-branch $force --env-filter "$filt" -- $br
