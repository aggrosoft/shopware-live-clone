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

# Import exists, but domains and service connections are not rewritten yet.
printf '%s\n' 'Shopware Live Clone: use --detect-source or --import-source. Automatic local configuration is not implemented yet.' >&2
exit 78
