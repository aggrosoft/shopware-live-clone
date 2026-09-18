# Shopware Live Clone

Wegwerf-Testkopien bestehender Shopware-Shops auf Basis von Dockware Essentials 1.4.0.

## Verwendung in Coolify

1. Neue **Docker Compose**-Ressource anlegen und `compose.yaml` aus diesem Repository einfügen.
2. Das öffentliche Image `ghcr.io/aggrosoft/shopware-live-clone:main` verwenden; ein GHCR-Registry-Zugang ist nicht nötig.
3. Die folgenden Variablen setzen. Private Keys nicht ins Repository oder als Build-Argument speichern.
4. Eine Testdomain für den Service `shop`, Port 80, vergeben. Coolify stellt sie über `SERVICE_URL_SHOP_80` bereit; daraus wird `CLONE_URL`.
5. Deployen und die Phasen im Containerlog verfolgen. Der erste Import kann bei großen Shops lange dauern.

| Variable | Inhalt |
|---|---|
| `SOURCE_SSH_HOST` | Hetzner SSH-Hostname |
| `SOURCE_SSH_PORT` | Optional, Standard 22 |
| `SOURCE_SSH_USER` | Hosting-Benutzer |
| `SOURCE_SSH_PRIVATE_KEY` | Mehrzeiliger privater Schlüssel ohne interaktive Passphrase |
| `SOURCE_SSH_KNOWN_HOSTS` | Optional: verifizierter SSH-Hostschlüsseleintrag für strikte Prüfung; bei anderem Port im Format `[host]:port` |
| `SOURCE_SHOP_PATH` | Absoluter Projektordner mit `composer.lock` und `bin/console`, nicht `public` |
| `SOURCE_URL` | Exakte Haupt-Verkaufskanal-URL der Quelle, einschließlich http/https und gegebenenfalls Unterpfad |
| `CLONE_URL` | Wird in der Compose-Vorlage aus der Coolify-Domain übernommen |

Ohne `SOURCE_SSH_KNOWN_HOSTS` wird der SSH-Hostschlüssel beim ersten Kontakt automatisch akzeptiert (`accept-new`) und unter `/var/lib/shopware-clone/ssh/known_hosts` im Volume `clone_data` gespeichert. Weitere Verbindungen verwenden diesen gespeicherten Schlüssel; ein geänderter Schlüssel wird abgelehnt. Beim ersten Kontakt findet keine unabhängige Identitätsprüfung statt. Mit neuen Volumes beginnt auch die Vertrauensprüfung von vorn. Eine explizite ENV-Vorgabe hat Vorrang und verwendet weiterhin strikte Prüfung.

Die Testdomain muss von live abweichen. Zusätzliche Verkaufskanäle und HTTP-/HTTPS-Aliase bekommen eindeutige Pfade unter `/__clone/...`. Sprachpfade des Hauptshops bleiben erhalten. Die Zuordnung steht privat unter `/var/lib/shopware-clone/domain-map.json`.

Die aktuelle Automatisierung unterstützt Shopware 6.6/6.7 mit PHP 8.2–8.5. Sonderkonfigurationen können einen Abbruch mit privatem Fehlerlog erfordern; es werden keine Shopware- oder Plugin-Updates ausgeführt.

## Ablauf

- Beim ersten Start: Quelle per SSH untersuchen, Dateien inklusive Plugins, Themes, vendor und Medien kopieren; konsistenten InnoDB-Dump lokal importieren.
- Quellkonfiguration sichern, DB auf den lokalen Socket/Server und Redis-Verbindungen auf lokalen Redis umstellen. Mailer-ENV und Shopware-Mailer-Auswahl werden auf den lokalen Mailfänger gelenkt.
- Verkaufskanal-Domains ersetzen und die kopierten wartenden Nachrichten entfernen. Als laufend/queued markierte Scheduled Tasks werden zurückgesetzt.
- Cache, Assets und Themes vorbereiten; lokale Suchindizes bei aktivierter Suche synchron neu aufbauen.
- Erst danach Dockware, Cron und zwei Queue-Worker starten. Worker verarbeiten `async` und `low_priority`, nicht automatisch die Fehlerqueue.
- Bei Neustarts: bestehenden Stand starten, ohne Import, Domain-Rewrite, Queue-Leerung oder Index-Neuaufbau. Die Quelle muss dann nicht erreichbar sein.

Plugins bleiben aktiv. Übernommene Plugin-/App-/Payment-/ERP-Zugangsdaten können weiterhin Live-Systeme ansprechen, auch durch Worker. Dieses Template ist keine vollständige Netzwerk-Sandbox. Testshops mit kopierten Kundendaten über Coolify-Zugriffsschutz/VPN absichern.

Die Mailoberfläche ist über `/mailcatcher` erreichbar. Dockware bringt auch `/adminer` und `/logs` mit; der Zugangsschutz muss die gesamte Testdomain abdecken. Datenbank, Redis und Suchserver werden nicht durch Hostports veröffentlicht.

## Lokale Dienste

| Dienst | Verwendung |
|---|---|
| MySQL in Dockware | Eigene Datenbank `shopware_clone` |
| Redis 7.4 | Cache/Redis-Verbindungen; getrennte logische DBs für unterschiedliche Quell-DSNs |
| OpenSearch 2.19.4 | Shops mit OpenSearch-PHP-Client |
| Elasticsearch 7.17.28 | Unterstützter Elasticsearch-7-PHP-Client |
| Mailfänger in Dockware | Standard-Mailversand der Kopie |

Beide Suchserver laufen in dieser ersten Compose-Version mit je 512 MB Java-Heap, damit die Quelle erst zur Laufzeit ausgewählt werden kann. Ohne aktive Suche baut der Shop keine Indizes auf. Die Auswahl richtet sich nach dem mitkopierten PHP-Client, nicht nach einer Behauptung, dass alle Serverversionen austauschbar wären. Die drei Zusatzdienste liegen in einem internen Netzwerk pro Stack und haben eigene Volumes. Der Docker-Host benötigt für die Suchserver `vm.max_map_count >= 262144` und ausreichend RAM.

## Speicher und Wiederholungen

| Volume | Inhalt |
|---|---|
| `clone_data` | Shop unter `source/`, Konfigurationssicherungen, Status und private Logs |
| `clone_db` | MySQL-Daten |
| `clone_redis` | Lokaler Redis |
| `clone_opensearch`, `clone_elasticsearch` | Lokale Indizes |

Keine festen externen Volumenamen und keine gemeinsamen Bind-Mounts zwischen Shops verwenden. Für eine frische Kopie eine neue Ressource mit neuen Volumes anlegen; alte Wegwerfkopien samt zugehörigen Volumes anschließend bewusst löschen. Nach einem Abbruch während der Dateikopie kann der nächste Start vorhandene Dateien weiterverwenden, solange noch kein Dump und keine lokale Clone-Datenbank vorhanden sind. Spätere fehlgeschlagene Rohimporte benötigen frische Volumes. Nach Fehlern während der lokalen Konfiguration bleibt der Shop gestoppt; Details stehen in den privaten Logs. Nach einem Fehler beim Cache-/Theme-/Index-Aufbau kann ein Neustart diese Vorbereitungen erneut versuchen, ohne neu zu importieren.

## Grenzen des Imports

- Der private SSH-Key und eine explizite Hostschlüssel-Vorgabe werden nur temporär geschrieben und nach dem Transfer entfernt. Automatisch akzeptierte Hostschlüssel bleiben im Clone-Volume gespeichert. Keine Passwörter/DSNs in normalen Logs.
- Quell-PHP-Konfiguration wird als Daten geparst; Symfony-Standard-`.env.local.php` wird nicht ausgeführt. Unterstützt sind literale dotenv-Werte und einfache Variablenreferenzen. Unbekannte Ausdrücke oder DB-Verbindungsoptionen führen zum Abbruch.
- Die Quelle benötigt PHP CLI ab 7.4, SSH, rsync sowie mysql/mariadb und das passende Dump-Programm.
- Nicht-InnoDB-Tabellen werden abgelehnt. Während des Imports keine Schemaänderungen/Deployments auf live durchführen. Dateien und DB sind kein gemeinsamer atomarer Snapshot.
- Der komprimierte Dump wird lokal zwischengespeichert. Platz für Shopdateien, Dump und Ziel-DB vorsehen. Cache, Logs, Sessions, .git und node_modules werden ausgelassen.
- Symlink-Ziele werden als echte Dateien/Verzeichnisse mitkopiert, einschließlich externer Plugin- und Medienverzeichnisse. Bereits auf der Quelle kaputte Links werden mit Warnung übersprungen. Unlesbare Dateien und andere Transferfehler bleiben Fehler. Mehrere DB-Verbindungen und externes Medien-Storage (z. B. S3) sind noch nicht automatisch unterstützt. Es wird nicht stillschweigend auf die Live-DB oder Live-Buckets zurückgeschrieben.
- Komplexe PHP/XML-Konfiguration, ungewöhnliche Service-Definitionen, domainabhängige Apps/Lizenzen und Plugins können zusätzliche Anpassungen benötigen. Datenbank-Collations werden nicht still konvertiert.
- PHP wird aus .htaccess oder der CLI ermittelt. Bedingte Apache-Regeln und Einstellungen ausschließlich im Hosting-Panel können eine andere Web-PHP-Version ergeben.
- Lokale Konfiguration verarbeitet die üblichen YAML-Paketdateien. Kein allgemeines Versprechen für beliebige Plugin-eigene Datenbank- oder SMTP-Clients.

## Diagnose

`--check-image` prüft Werkzeuge/PHP, `--detect-source` liefert einen redigierten Quellbericht und `--import-source` führt nur den Rohimport aus. Ohne Argumente läuft der vollständige Ablauf.

Private Dateien im Container:

- `/var/lib/shopware-clone/source-report.json`
- `/var/lib/shopware-clone/state.json`
- `/var/lib/shopware-clone/configure-error.log`
- `/var/lib/shopware-clone/setup.log`
- `/var/lib/shopware-clone/worker.log`
- `/var/lib/shopware-clone/scheduler.log`

Original-Konfiguration unter `original-config/` und detaillierte Fehlerlogs können Live-Zugangsdaten enthalten und liegen außerhalb des Webroots. Nicht ungeprüft weitergeben.

## Build und Tests

GitHub Actions prüft Shell/PHP, Konfigurationsfälle und Compose, baut das Image, testet einen künstlichen SSH/MySQL-Import und führt einen vollständigen Smoke-Test mit einem offiziellen Shopware-6.7.10.0-Demoshop aus. Dieser umfasst OpenSearch, Redis, Storefront, Mailfänger, Worker und Neustart bei abgeschalteter Quelle. Erst danach wird das Image nach GHCR veröffentlicht.

Tags: `main`, `sha-<commit>` und gegebenenfalls Release-Tags. Plattform zunächst linux/amd64. Keine Live-Daten oder Zugangsdaten gelangen in den Image-Build. Es wurde noch kein echter Hetzner-Kundenshop getestet.

Quellen: [Dockware](https://github.com/dockware/shopware), [Shopware](https://github.com/shopware/shopware).

### Import progress

Container logs show seven setup phases. File copying and SQL restore report bytes, average throughput, percentage and an approximate remaining time every 15 seconds. Rsync first scans the complete file list to make its percentage meaningful. Estimates cover the current phase only, not the complete clone startup. SQL restore progress measures SQL delivered to MySQL; final execution may take longer. Database dumps have no known total size and show bytes and elapsed time. Validation, configuration, cache/theme compilation and search indexing emit a heartbeat every 15 seconds. Detailed command output stays in the private log files. Updates apply to new containers; let an already running import finish before deploying a new image.
