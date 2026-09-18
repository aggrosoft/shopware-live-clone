#!/usr/bin/env bash
# Sourced by the importer/startup; their EXIT traps stop the heartbeat.
clone_progress_script=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/progress.php
clone_progress_pid=''
clone_progress_stop() {
    if [[ -n $clone_progress_pid ]]; then
        # This stateless, owned timer needs no cleanup; avoid PHP startup signal races.
        kill -KILL "$clone_progress_pid" 2>/dev/null || true
        wait "$clone_progress_pid" 2>/dev/null || true
        clone_progress_pid=''
    fi
}
clone_progress_start() {
    clone_progress_stop
    clone_progress_label=$1
    clone_progress_started=$SECONDS
    printf '%s...\n' "$clone_progress_label"
    php "$clone_progress_script" heartbeat "$clone_progress_label" &
    clone_progress_pid=$!
}
clone_progress_done() {
    clone_progress_stop
    printf '%s: done in %ss\n' "$clone_progress_label" "$((SECONDS - clone_progress_started))"
}
