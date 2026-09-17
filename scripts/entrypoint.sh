#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" == "--check-image" && "$#" -eq 1 ]]; then
    exec /opt/shopware-live-clone/image-check.sh
fi

# Step 1 only supplies the reusable image and build pipeline.
# Do not launch Dockware against unprocessed live data until the importer exists.
printf '%s\n' 'Shopware Live Clone: image foundation is ready; live import is not implemented yet.' >&2
exit 78
