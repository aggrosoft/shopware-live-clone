#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "--check-image" && "$#" -eq 1 ]]; then
    exec /opt/shopware-live-clone/image-check.sh
fi

if [[ "${1:-}" == "--detect-source" && "$#" -eq 1 ]]; then
    exec /opt/shopware-live-clone/detect-source.sh
fi

if [[ "${1:-}" == "--import-source" && "$#" -eq 1 ]]; then
    exec /opt/shopware-live-clone/import-source.sh
fi

if [[ $# -ne 0 ]]; then
    printf '%s\n' 'Unknown argument. Use --check-image, --detect-source, --import-source, or no arguments for automatic startup.' >&2
    exit 64
fi
exec /opt/shopware-live-clone/start-clone.sh
