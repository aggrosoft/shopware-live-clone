# syntax=docker/dockerfile:1
FROM dockware/shopware-essentials:1.4.0

LABEL org.opencontainers.image.title="Shopware Live Clone" \
      org.opencontainers.image.description="Dockware runtime for disposable copies of existing Shopware shops" \
      org.opencontainers.image.source="https://github.com/aggrosoft/shopware-live-clone"

# Essentials already provides SSH, rsync, MySQL, PHP and a mail catcher.
# Keep clone tooling outside the shop volume so imports cannot overwrite it.
COPY --chmod=0755 scripts/ /opt/shopware-live-clone/

RUN /opt/shopware-live-clone/image-check.sh

ENTRYPOINT ["/bin/bash", "/opt/shopware-live-clone/entrypoint.sh"]
