# Shopware Live Clone

Wiederverwendbare Dockware-Basis für Wegwerf-Testkopien von Shopware-Shops auf klassischem Hosting mit SSH-Zugang.

## Aktueller Stand: Schritt 3 — Dateikopie und Datenbank-Import

Das Repository baut ein gemeinsames Image für alle Shops:

`ghcr.io/aggrosoft/shopware-live-clone:main`

Basis ist `dockware/shopware-essentials:1.4.0`: Dockware mit MySQL, Mailfänger und PHP 8.2, 8.3, 8.4 und 8.5, ohne vorinstallierten Shop. Der Build prüft die benötigten Werkzeuge und PHP-Erweiterungen. Die Shopware-Version wird später aus der Quelle übernommen und ist unabhängig vom Image-Tag.

**Erkennung und Rohimport sind implementiert; automatische lokale Konfiguration und Shopstart fehlen noch.** Der normale Containerstart beendet sich daher ausdrücklich mit Status 78. Dieses Image noch nicht als fertigen Testshop in Coolify deployen. Mit `docker run --rm ghcr.io/aggrosoft/shopware-live-clone:main --check-image` lassen sich die Image-Voraussetzungen prüfen. Dies ist keine Prüfung des späteren Shop-Betriebs.

## Build und Wiederverwendung

GitHub Actions baut bei einem Push nach `main`, bei `v*`-Tags sowie bei manuellem Start. Pull Requests werden gebaut und geprüft, aber nicht veröffentlicht. Erst nach erfolgreichen Prüfungen wird das Image nach GHCR hochgeladen. Tags sind `main`, `sha-<commit>` und gegebenenfalls der Release-Tag. Zielplattform ist zunächst `linux/amd64` für unseren Hetzner-Server.

Der Build verwendet ausschließlich `GITHUB_TOKEN`; es sind keine Live-Zugangsdaten und kein eigener Build-Server erforderlich. Falls die Organisation Actions oder das Erstellen von Packages einschränkt, muss die entsprechende Repository-Berechtigung freigeschaltet werden. Das Package bleibt zunächst privat. Für den späteren Abruf aus Coolify wird ein Registry-Zugang benötigt.

## Vereinbarter Ablauf für die nächsten Schritte

1. SSH-Verbindung und Quellpfad prüfen; PHP-Zuordnung aus `.htaccess` (gegebenenfalls übergeordneten Verzeichnissen), ansonsten CLI, sowie Shopware und Dienste erkennen. Nicht unterstützte Versionen ausdrücklich melden.
2. Shopdateien inklusive `vendor`, Plugins, Themes und Medien übernehmen; konsistenten SQL-Dump importieren. Keine Installation oder Updates. Die Quelle wird nicht durch Shopware-CLI-Befehle verändert.
3. Ziel-DB, Testdomains und lokale Dienste konfigurieren. Redis beziehungsweise kompatibles Elasticsearch/OpenSearch lokal anbinden und Suchindizes neu aufbauen; normale Shop-Mails in den Mailfänger lenken.
4. Erst nach abgeschlossenem Import Webzugriff, Cron und Worker starten. Kopierte wartende Live-Jobs nicht ausführen. Plugins bleiben aktiv; deren übernommene Zugangsdaten können weiterhin Live-Systeme ansprechen. Keine vollständige Sandbox versprechen.
5. Erfolgreichen Import persistent markieren. Normale Neustarts behalten den Teststand. Eine neue Kopie entsteht durch eine neue Instanz mit neuen Volumes. Kein Rücksync.

Die Compose-Vorlage, lokale Konfiguration und der automatische Ablauf beim Start folgen im nächsten Schritt. Die bisherige Leershop-Vorlage bleibt separat.

## Rohimport (noch kein laufender Shop)

`--import-source` nutzt dieselben SSH-Variablen wie `--detect-source`. Es benötigt zwei eigene, frische Docker-Volumes:

| Mount | Inhalt |
|---|---|
| `/var/lib/shopware-clone` | Kopierte Shopdateien unter `source/`, redigierter Quellbericht, Importstatus |
| `/var/lib/mysql` | Dockwares lokale MySQL-Daten, Ziel-DB `shopware_clone` |

Der Import läuft ausschließlich vor dem Dockware-Start. Er prüft SSH/PHP/vendor, startet nur die lokale DB, kopiert Dateien inklusive Plugins, Themes, vendor und Medien und überträgt einen komprimierten SQL-Dump über SSH. Cache, Logs, Sessions, `.git` und `node_modules` werden ausgelassen. Symlinks müssen innerhalb der kopierten Verzeichnisstruktur auflösbar sein; externe/beschädigte Links führen zum Abbruch. Es wird nichts auf der Quelle gelöscht oder installiert. Shopware, Plugin-Code und Composer-Autoloader werden nicht ausgeführt.

DB-Zugänge werden auf der Quelle aus `DATABASE_URL` ermittelt: Server-ENV hat Vorrang, ansonsten werden `.env`-Dateien mit einfachen Variablenreferenzen oder eine statisch geparste Symfony-`.env.local.php` verwendet. Komplexe Ausdrücke, unbekannte DB-Verbindungsoptionen und abweichende APP_ENV-Konfiguration führen zum Abbruch. Es wird keine Quell-PHP-Konfiguration ausgeführt. Das DB-Passwort wird nur in der Umgebung des kurzlebigen DB-Clientprozesses verwendet, nicht als Befehlsargument und nicht im Log. Die Webserver-Konfiguration kann von der SSH-Umgebung abweichen; solche Sonderfälle müssen vor dem ersten echten Import geprüft werden.

Der Dump liest nur die Quelldatenbank, mit `--single-transaction`, `--quick` und ohne Tabellensperren. Nicht-InnoDB-Tabellen werden vorher erkannt und abgelehnt. Während des Imports keine Deployments/Schemaänderungen auf live durchführen. Dateien und Datenbank sind kein gemeinsamer atomarer Snapshot; laufende Dateiänderungen können vom DB-Zeitpunkt abweichen. Der Dump wird komprimiert lokal zwischengespeichert und erst nach erfolgreicher Übertragung restauriert. Speicherplatz für Dateien, komprimierten Dump und Ziel-DB ist nötig. Nach erfolgreichem Restore wird die Dumpdatei entfernt. MySQL/MariaDB-spezifische Collations oder SQL können inkompatibel sein; es findet keine stille Konvertierung statt.

Die Zielverbindung ist fest auf den lokalen MySQL-Socket verdrahtet; die Live-ENV kann sie nicht umleiten. Der Import benötigt eine noch nicht vorhandene lokale DB `shopware_clone`. Ein Lock verhindert parallele Importe. Erfolg wird als `phase: imported, ready: false` markiert. Ein erneuter Aufruf lässt bestehende Daten unverändert. Nach einem Abbruch ist ein neuer Versuch mit frischen Zielvolumes nötig; vorhandene Teilimporte werden nicht automatisch gelöscht. Ein Wechsel der SSH-Quelle überschreibt einen schon importierten Stand ebenfalls nicht.

Der kopierte Shop liegt vorerst außerhalb des Webroots. Cron, Worker und Webserver bleiben in diesem Rohimport-Schritt aus. Das ist keine Änderung des vereinbarten Zielverhaltens: Im fertigen Template starten alle nach lokaler Konfiguration. Live-Queues und Zugangsdaten sind jetzt noch Teil der Rohkopie und dürfen noch nicht ausgeführt werden.

CI: Ein kurzlebiger Container stellt einen synthetischen SSH-Quellshop und eine MySQL-Quelldatenbank bereit. Geprüft werden Hostkey-Ablehnung, Dateikopie (auch Pfade mit Leerzeichen), DB-Restore, unveränderte Quelldaten und der Erhalt späterer Teständerungen bei erneutem Import. Es werden keine echten Shops oder Zugangsdaten verwendet.

## Quelle prüfen

Mit den Laufzeitvariablen aus `.env.example` kann das Image jetzt eine Quelle untersuchen:

```bash
docker run --rm \
  --env SOURCE_SSH_HOST --env SOURCE_SSH_PORT --env SOURCE_SSH_USER \
  --env SOURCE_SHOP_PATH --env SOURCE_SSH_PRIVATE_KEY --env SOURCE_SSH_KNOWN_HOSTS \
  ghcr.io/aggrosoft/shopware-live-clone:main --detect-source
```

Die Werte müssen zuvor in der aufrufenden Umgebung gesetzt sein; in Coolify später als ENV/Secrets. `SOURCE_SHOP_PATH` zeigt auf den Projektordner mit `composer.lock` und `bin/console`, nicht auf `public`. SSH-Key und geprüfter `known_hosts`-Eintrag dürfen mehrzeilig sein. Der Schlüssel muss ohne interaktive Passphrase verwendbar sein. Der Importer akzeptiert keine unbekannten Hostschlüssel automatisch; bei abweichendem Port ist das bekannte SSH-Format `[host]:port` nötig.

Die Prüfung liest über SSH ein PHP-Skript von stdin; keine Skriptdatei wird auf dem Hosting abgelegt. Sie startet weder Shopware noch den Composer-Autoloader und führt keine DB-Abfragen aus. Benötigt wird zunächst PHP CLI ab 7.4. Das Ergebnis ist JSON mit Shopware-Version, erkannter PHP-Zuordnung, verfügbaren Werkzeugen und Hinweisen auf Dienste. DSNs, Passwörter und private Schlüssel werden nicht ausgegeben. Temporäre SSH-Dateien werden nach Abschluss entfernt. SSH-/PHP-Fehler werden bewusst ohne ungefilterte Remote-Ausgabe gemeldet.

Dies ist eine statische Vorprüfung, keine vollständige Auflösung der Symfony-Konfiguration: literale Werte aus `.env`, `.env.local` und den umgebungsspezifischen Dateien werden berücksichtigt; Interpolation, Multiline-Werte und `.env.local.php` werden als ungeklärt gemeldet. YAML/PHP/XML-Konfiguration wird nur auf Dienstverweise untersucht. `configured: null` bedeutet unbekannt, nicht ausgeschaltet. Ob Elasticsearch/OpenSearch aktiv ist und welche Serverversion läuft, wird erst vor dem eigentlichen Import verifiziert. Server-ENV und in der DB gespeicherte Plugin-Konfiguration bleiben hier ungeprüft. PHP-Handler in bedingten Apache-Blöcken und Overrides im Hosting-Panel können vom statisch gefundenen Wert abweichen.

Die CI prüft die Erkennung mit synthetischen Shopdateien: PHP-Vererbung und Fallback, Konfigurationspriorität, unbekannte Werte, nicht unterstützte PHP-Versionen, Geheimnisfreiheit und das Nicht-Ausführen von Live-Code. Es wurde noch kein echter Hosting-Zugang getestet.

## Eingaben im fertigen Template

Geplant: `SOURCE_SSH_HOST`, `SOURCE_SSH_PORT` (Standard 22), `SOURCE_SSH_USER`, `SOURCE_SSH_PRIVATE_KEY`, `SOURCE_SHOP_PATH` und `SOURCE_URL`. SSH-Hostschlüssel müssen verifiziert werden. Die Testdomain kommt aus Coolify. DB-Zugangsdaten werden aus der wirksamen Quellkonfiguration gelesen, nicht zusätzlich von Hand eingetragen.

SSH-Zugänge und Live-Daten werden erst zur Laufzeit verwendet und gehören weder ins Repository noch in Image-Layer. `.dockerignore` lässt ausschließlich Dockerfile und unsere Skripte in den Build-Kontext.

## Dockware-Integration

Der eigene Einstiegspunkt liegt außerhalb von `/var/www/html`. Das Essentials-Image vermeidet das Entpacken eines vorinstallierten Shops über eine importierte Kopie. Dockwares Original-Entrypoint liegt weiterhin unter `/entrypoint.sh`.

Beim Import müssen Erkennung und Konfiguration vor dem Start der Dienste erfolgen. `boot_start.sh` wird von Dockware als eigener `sh`-Prozess ausgeführt: dort gesetzte ENV-Werte werden nicht automatisch an den übergeordneten Entrypoint zurückgegeben. `boot_end.sh` läuft bereits nach dem Start der Dienste und ist deshalb für den ersten Import zu spät. Die eigentliche Orchestrierung gehört in unseren Einstiegspunkt, nicht in einen späten Boot-Hook.

Quellen: [Dockware Shopware](https://github.com/dockware/shopware), [Essentials 1.4.0](https://github.com/dockware/shopware/releases/tag/essentials-1.4.0).
