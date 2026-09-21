# Shopware Live Clone

Wegwerf-Testkopien bestehender Shopware-Shops auf Basis von Dockware Essentials 1.4.0.

## Verwendung in Coolify

1. Neue **Docker Compose**-Ressource anlegen und `compose.yaml` aus diesem Repository einfügen.
2. Das öffentliche Image `ghcr.io/aggrosoft/shopware-live-clone:main` verwenden; ein GHCR-Registry-Zugang ist nicht nötig.
3. Die folgenden Variablen setzen. Private Keys nicht ins Repository oder als Build-Argument speichern.
4. Eine Testdomain für den Service `shop`, Port 80, vergeben. Coolify stellt den Host über `SERVICE_FQDN_SHOP_80` bereit; daraus wird die externe HTTPS-URL ohne den internen Zielport gebildet.
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
| `SSH_PASSWORD` | Passwort für den Benutzer `dockware` im geklonten Shop |

Der Shop-Service trägt die vom vorhandenen SSH-Piper verwendeten Labels. Als externer SSH-Piper-Benutzername dient `SERVICE_FQDN_SHOP_80`, im Container wird auf `dockware:22` weitergeleitet. Der Aufruf entspricht damit den normalen Dev-Shops: `ssh <Clone-FQDN>@<SSH-Piper-Host>`. Das Passwort kommt ausschließlich aus `SSH_PASSWORD` der Coolify-Ressource und wird nicht ins Image geschrieben.

Ohne `SOURCE_SSH_KNOWN_HOSTS` wird der SSH-Hostschlüssel beim ersten Kontakt automatisch akzeptiert (`accept-new`) und unter `/var/lib/shopware-clone/ssh/known_hosts` im Volume `clone_data` gespeichert. Weitere Verbindungen verwenden diesen gespeicherten Schlüssel; ein geänderter Schlüssel wird abgelehnt. Beim ersten Kontakt findet keine unabhängige Identitätsprüfung statt. Mit neuen Volumes beginnt auch die Vertrauensprüfung von vorn. Eine explizite ENV-Vorgabe hat Vorrang und verwendet weiterhin strikte Prüfung.

Die Testdomain muss von live abweichen. Zusätzliche Verkaufskanäle und HTTP-/HTTPS-Aliase bekommen eindeutige Pfade unter `/__clone/...`. Sprachpfade des Hauptshops bleiben erhalten. Die Zuordnung steht privat unter `/var/lib/shopware-clone/domain-map.json`.

Die aktuelle Automatisierung unterstützt Shopware 6.6/6.7 mit PHP 8.2–8.5. Sonderkonfigurationen können einen Abbruch mit privatem Fehlerlog erfordern; es werden keine Shopware- oder Plugin-Updates ausgeführt.

## Ablauf

- Beim ersten Start: Quelle per SSH untersuchen, Dateien inklusive Plugins, Themes, vendor und Medien kopieren; konsistenten InnoDB-Dump lokal importieren.
- Quellkonfiguration sichern, DB auf den MariaDB-Dienst und Redis-Verbindungen auf lokalen Redis umstellen. Mailer-ENV und Shopware-Mailer-Auswahl werden auf den lokalen Mailfänger gelenkt.
- Verkaufskanal-Domains ersetzen und die kopierten wartenden Nachrichten entfernen. Als laufend/queued markierte Scheduled Tasks werden zurückgesetzt.
- Cache, Assets und Themes vorbereiten; lokale Suchindizes bei aktivierter Suche synchron neu aufbauen.
- Erst danach Dockware, Cron und zwei Queue-Worker starten. Worker verarbeiten `async` und `low_priority`, nicht automatisch die Fehlerqueue.
- Bei Neustarts: bestehenden Stand starten, ohne Import, Domain-Rewrite, Queue-Leerung oder Index-Neuaufbau. Die Quelle muss dann nicht erreichbar sein.

Plugins bleiben aktiv. Übernommene Plugin-/App-/Payment-/ERP-Zugangsdaten können weiterhin Live-Systeme ansprechen, auch durch Worker. Dieses Template ist keine vollständige Netzwerk-Sandbox. Testshops mit kopierten Kundendaten über Coolify-Zugriffsschutz/VPN absichern.

Die Mailoberfläche ist über `/mailcatcher` erreichbar. Dockware bringt auch `/adminer` und `/logs` mit; der Zugangsschutz muss die gesamte Testdomain abdecken. Datenbank, Redis und Suchserver werden nicht durch Hostports veröffentlicht.

## Lokale Dienste

| Dienst | Verwendung |
|---|---|
| MariaDB 11.4 | Eigene Datenbank `shopware_clone`; passend zu den MariaDB-Quellen |
| Redis 7.4 | Cache/Redis-Verbindungen; getrennte logische DBs für unterschiedliche Quell-DSNs |
| OpenSearch 2.19.4 | Lokale Suche für alle Kopien mit aktivierter Suche |
| Mailfänger in Dockware | Standard-Mailversand der Kopie |

Die Vorlage enthält nur OpenSearch als Suchdienst, mit 512 MB Java-Heap. Der Container wird mit dem Stack gestartet; der Shop verwendet ihn und baut Indizes nur auf, wenn die Quelle Suche aktiviert hat. Eine Auswahl anhand des PHP-Client-Pakets entfällt. Redis und OpenSearch liegen im internen Netzwerk des Stacks und haben eigene Volumes. Der Docker-Host benötigt für OpenSearch `vm.max_map_count >= 262144` und ausreichend RAM.

## Speicher und Wiederholungen

| Volume | Inhalt |
|---|---|
| `clone_data` | Shop unter `source/`, Konfigurationssicherungen, Status und private Logs |
| `clone_db` | MariaDB-Daten |
| `clone_redis` | Lokaler Redis |
| `clone_opensearch` | Lokale Suchindizes |

Keine festen externen Volumenamen und keine gemeinsamen Bind-Mounts zwischen Shops verwenden. Für eine frische Kopie eine neue Ressource mit neuen Volumes anlegen; alte Wegwerfkopien samt zugehörigen Volumes anschließend bewusst löschen. Nach einem Abbruch während der Dateikopie kann der nächste Start vorhandene Dateien weiterverwenden, solange noch kein Dump und keine lokale Clone-Datenbank vorhanden sind. Spätere fehlgeschlagene Rohimporte benötigen frische Volumes. Nach Fehlern während der lokalen Konfiguration bleibt der Shop gestoppt; Details stehen in den privaten Logs. Nach einem Fehler beim Cache-/Theme-/Index-Aufbau kann ein Neustart diese Vorbereitungen erneut versuchen, ohne neu zu importieren.

## Grenzen des Imports

- Der private SSH-Key und eine explizite Hostschlüssel-Vorgabe werden nur temporär geschrieben und nach dem Transfer entfernt. Automatisch akzeptierte Hostschlüssel bleiben im Clone-Volume gespeichert. Keine Passwörter/DSNs in normalen Logs.
- Quell-PHP-Konfiguration wird als Daten geparst; Symfony-Standard-`.env.local.php` wird nicht ausgeführt. Unterstützt sind literale dotenv-Werte und einfache Variablenreferenzen. Unbekannte Ausdrücke oder DB-Verbindungsoptionen führen zum Abbruch.
- Die Quelle benötigt PHP CLI ab 7.4, SSH, rsync sowie MariaDB und `mariadb-dump`/`mysqldump` aus der MariaDB-Distribution. Der Dump wird unverändert in MariaDB 11.4 eingespielt; es gibt keine SQL-Rewrites.
- Nicht-InnoDB-Tabellen werden abgelehnt. Während des Imports keine Schemaänderungen/Deployments auf live durchführen. Dateien und DB sind kein gemeinsamer atomarer Snapshot.
- Der komprimierte Dump wird lokal zwischengespeichert. Platz für Shopdateien, Dump und Ziel-DB vorsehen. Cache, Logs, Sessions, .git und node_modules werden ausgelassen.
- Symlink-Ziele werden als echte Dateien/Verzeichnisse mitkopiert, einschließlich externer Plugin- und Medienverzeichnisse. Bereits auf der Quelle kaputte Links werden mit Warnung übersprungen. Unlesbare Dateien und andere Transferfehler bleiben Fehler. Mehrere DB-Verbindungen und andere externe Storage-Adapter als Shopwares `amazon-s3` sind noch nicht automatisch unterstützt. Es wird nicht stillschweigend auf die Live-DB oder Live-Buckets zurückgeschrieben.
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

GitHub Actions prüft Shell/PHP, Konfigurationsfälle und Compose, baut das Image, testet einen künstlichen SSH-Import und führt einen vollständigen Smoke-Test mit MariaDB 11.4 und einem offiziellen Shopware-6.7.10.0-Demoshop aus. Dieser umfasst OpenSearch, Redis, Storefront, Mailfänger, Worker und Neustart bei abgeschalteter Quelle. Erst danach wird das Image nach GHCR veröffentlicht.

Tags: `main`, `sha-<commit>` und gegebenenfalls Release-Tags. Plattform zunächst linux/amd64. Keine Live-Daten oder Zugangsdaten gelangen in den Image-Build. Es wurde noch kein echter Hetzner-Kundenshop getestet.

Quellen: [Dockware](https://github.com/dockware/shopware), [Shopware](https://github.com/shopware/shopware).

### Import progress

Container logs show seven setup phases. File copying reports bytes, average throughput, percentage and an approximate remaining time every 15 seconds. Rsync first scans the complete file list to make its percentage meaningful. Estimates cover file copying only, not the complete clone startup. Database dump and restore use direct SSH/gzip/mysql pipelines without a progress filter; a separate heartbeat reports elapsed time. The gzip checksum is checked during restore without an additional full decompression pass. Validation, configuration, cache/theme compilation and search indexing also emit a heartbeat every 15 seconds. Detailed command output stays in the private log files. Updates apply to new containers; let an already running import finish before deploying a new image.

## Einfache Anonymisierung

Vor dem ersten Start ersetzt der Klon automatisch Namen und E-Mail-Adressen in `customer` und `order_customer` sowie Namen und Anschriften in `customer_address` und `order_address`. Das umfasst auch Gastbestellungen und gespeicherte Bestellversionen. E-Mails werden zu eindeutigen `kunde-<ID>@example.invalid`-Adressen; verknüpfte Kunden und Bestellungen erhalten dieselbe Adresse. Anschriften werden zu `Teststrasse 1`, `12345 Teststadt`. Firmen, Telefon, Titel, Abteilungen, Adresszusätze, USt-IDs, Geburtstage und Kunden-IP-Adressen werden in diesen Tabellen geleert, soweit vorhanden. Länder/Bundesländer, IDs, Zuordnungen, Bestellpositionen und Beträge bleiben erhalten.

Der Schritt greift ausschließlich auf die lokale Datenbank `shopware_clone` zu. Er läuft einmal pro Kopie (`anonymized-v1.json`), damit spätere Teständerungen bei Neustarts erhalten bleiben. Bestehende Kopien werden beim ersten Start mit dem neuen Image ebenfalls bearbeitet; Cache und lokale Suchindizes werden anschließend neu aufgebaut. Ein laufender Import muss dafür nicht abgebrochen werden.

Dies ist eine gezielte Bereinigung der Standardfelder, keine vollständige Anonymisierung sämtlicher Shopdaten. Dokumente/PDFs, Freitext, Custom Fields und Plugin-Daten können weiterhin personenbezogene Angaben enthalten.

Beim Aktualisieren einer bestehenden Coolify-Ressource auch die Compose-Konfiguration anpassen: den Service `elasticsearch`, dessen Eintrag unter `shop.depends_on` und die Deklaration `clone_elasticsearch` entfernen. Ein Image-Pull allein ändert die Compose-Konfiguration nicht. Bereits konfigurierte Kopien behalten ihre gespeicherten Einstellungen; wenn eine alte Kopie tatsächlich Elasticsearch verwendet, muss ihre lokale Suchkonfiguration vor dem Entfernen dieses Dienstes auf OpenSearch umgestellt werden.

Bei Fehlern während Import oder Vorbereitung bleibt der Container für das Coolify-Terminal erreichbar. Shop, Cron und Worker werden nicht gestartet; der Healthcheck bleibt negativ. Im Terminal können `cat /var/lib/shopware-clone/import-error.log` bzw. die unter `*error.log` und `setup.log` genannten Details gelesen werden. MariaDB- und gzip-Fehler werden vor dem Entfernen temporärer Dateien gesichert. Ein Neustart versucht den normalen Ablauf erneut; unvollständige Datenbank-Restores werden weiterhin nicht still überschrieben.

Seit der Umstellung von Dockwares eingebautem MySQL auf MariaDB muss auch die aktuelle `compose.yaml` übernommen werden. Ein Image-Pull allein fügt den Datenbankdienst nicht hinzu. Alte `clone_db`-Volumes mit MySQL-8-Daten sind nicht weiterverwendbar; für diese Wegwerfkopien neue Volumes anlegen.

## S3 und CDN in Kopien

Die Standard-Flysystem-Bereiche `public`, `private`, `temp`, `theme`, `asset` und `sitemap` werden vollständig auf lokale Verzeichnisse umgestellt. Externe URLs und S3-Konfigurationsblöcke werden aus der aktiven YAML-Konfiguration entfernt; referenzierte Storage-ENV-Werte und der Standard-Fastly-API-Key werden für den Klon geleert. Originalkonfigurationen bleiben ausschließlich zur Diagnose in der privaten Sicherung erhalten.

Bei `amazon-s3` liest der Importer Bucket, Region, Endpoint, Prefix (`root`) und Credentials aus der kopierten Standardkonfiguration. Er kopiert `media/` und `thumbnail/` aus dem öffentlichen Speicher sowie den privaten Speicher über `rclone copy` in das Clone-Volume. Dabei werden nur LIST/HEAD/GET-Anfragen an die Quelle benötigt; der Importer synchronisiert oder löscht dort nichts. Temporäre S3-Zugangsdaten werden danach entfernt. Bei einem CDN vor lokalen Dateien reicht die vorhandene SSH-Kopie; die CDN-URL wird entfernt. Themes und Assets werden anschließend lokal aufgebaut, Sitemaps können durch den lokalen Scheduler erzeugt werden.

Der S3-Schritt verlängert den ersten Import um die Übertragung der externen Dateien. Details stehen in `storage-import.log`. Unterstützt ist die normale Shopware-YAML-Konfiguration mit einfachen ENV-Referenzen; direkt durch Plugins konfigurierte S3-Clients, individuelle Flysystem-Service-Ersetzungen und beliebige CDN-Purge-Plugins sind nicht automatisch abgedeckt. Bestehende fertig konfigurierte Kopien werden durch ein Image-Update allein nicht neu auf Storage umkonfiguriert; dafür eine neue Kopie verwenden.
