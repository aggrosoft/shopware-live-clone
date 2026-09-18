# syntax=docker/dockerfile:1
FROM dockware/shopware-essentials:1.4.0

# Dockware unpacks NVM in its own entrypoint. Import scripts run before that.
ENV BASH_ENV=/dev/null

LABEL org.opencontainers.image.title="Shopware Live Clone" \
      org.opencontainers.image.description="Dockware runtime for disposable copies of existing Shopware shops" \
      org.opencontainers.image.source="https://github.com/aggrosoft/shopware-live-clone"

# Essentials already provides SSH, rsync, MySQL, PHP and a mail catcher.
# Keep clone tooling outside the shop volume so imports cannot overwrite it.
COPY --chmod=0755 scripts/ /opt/shopware-live-clone/

USER root
RUN apt-get update && apt-get install -y --no-install-recommends rclone && rm -rf /var/lib/apt/lists/*
# The clone uses the Compose MariaDB service. Keep Dockware's bundled MySQL
# stopped when its original entrypoint later starts Apache/cron/supervisor.
RUN sed -i '/echo "DOCKWARE: starting MySQL/,/sudo service mysql start;/c\    echo "DOCKWARE: external MariaDB is used; bundled MySQL stays stopped."' /entrypoint.sh \
    && ! grep -Eq '^[[:space:]]*sudo service mysql start' /entrypoint.sh
RUN install -d -m 0700 -o dockware -g www-data /var/lib/shopware-clone
USER dockware

RUN /opt/shopware-live-clone/image-check.sh

ENTRYPOINT ["/bin/bash", "/opt/shopware-live-clone/entrypoint.sh"]

HEALTHCHECK --interval=30s --timeout=15s --start-period=30m --retries=5 CMD /opt/shopware-live-clone/healthcheck.sh
