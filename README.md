# Shopware Live Clone

Wiederverwendbare Dockware-Basis für Wegwerf-Testkopien von Shopware-Shops auf klassischem Hosting mit SSH-Zugang.

## Aktueller Stand: Schritt 1 — Image und Build

Das Repository baut ein gemeinsames Image für alle Shops:

`ghcr.io/aggrosoft/shopware-live-clone:main`

Basis ist `dockware/shopware-essentials:1.4.0`: Dockware mit MySQL, Mailfänger und PHP 8.2, 8.3, 8.4 und 8.5, ohne vorinstallierten Shop. Der Build prüft die benötigten Werkzeuge und PHP-Erweiterungen. Die Shopware-Version wird später aus der Quelle übernommen und ist unabhängig vom Image-Tag.

**Der Live-Import ist noch nicht implementiert.** Der normale Containerstart beendet sich daher ausdrücklich mit Status 78. Dieses Image noch nicht als fertigen Testshop in Coolify deployen. Mit `docker run --rm ghcr.io/aggrosoft/shopware-live-clone:main --check-image` lassen sich die Image-Voraussetzungen prüfen. Dies ist keine Prüfung des späteren Shop-Betriebs.

## Build und Wiederverwendung

GitHub Actions baut bei einem Push nach `main`, bei `v*`-Tags sowie bei manuellem Start. Pull Requests werden gebaut und geprüft, aber nicht veröffentlicht. Erst nach erfolgreichen Prüfungen wird das Image nach GHCR hochgeladen. Tags sind `main`, `sha-<commit>` und gegebenenfalls der Release-Tag. Zielplattform ist zunächst `linux/amd64` für unseren Hetzner-Server.

Der Build verwendet ausschließlich `GITHUB_TOKEN`; es sind keine Live-Zugangsdaten und kein eigener Build-Server erforderlich. Falls die Organisation Actions oder das Erstellen von Packages einschränkt, muss die entsprechende Repository-Berechtigung freigeschaltet werden. Das Package bleibt zunächst privat. Für den späteren Abruf aus Coolify wird ein Registry-Zugang benötigt.

## Vereinbarter Ablauf für die nächsten Schritte

1. SSH-Verbindung und Quellpfad prüfen; PHP-Zuordnung aus `.htaccess` (gegebenenfalls übergeordneten Verzeichnissen), ansonsten CLI, sowie Shopware und Dienste erkennen. Nicht unterstützte Versionen ausdrücklich melden.
2. Shopdateien inklusive `vendor`, Plugins, Themes und Medien übernehmen; konsistenten SQL-Dump importieren. Keine Installation oder Updates. Die Quelle wird nicht durch Shopware-CLI-Befehle verändert.
3. Ziel-DB, Testdomains und lokale Dienste konfigurieren. Redis beziehungsweise kompatibles Elasticsearch/OpenSearch lokal anbinden und Suchindizes neu aufbauen; normale Shop-Mails in den Mailfänger lenken.
4. Erst nach abgeschlossenem Import Webzugriff, Cron und Worker starten. Kopierte wartende Live-Jobs nicht ausführen. Plugins bleiben aktiv; deren übernommene Zugangsdaten können weiterhin Live-Systeme ansprechen. Keine vollständige Sandbox versprechen.
5. Erfolgreichen Import persistent markieren. Normale Neustarts behalten den Teststand. Eine neue Kopie entsteht durch eine neue Instanz mit neuen Volumes. Kein Rücksync.

Die Compose-Vorlage und der automatische Import folgen im nächsten Schritt. Die bisherige Leershop-Vorlage bleibt separat.

## Eingaben im fertigen Template

Geplant: `SOURCE_SSH_HOST`, `SOURCE_SSH_PORT` (Standard 22), `SOURCE_SSH_USER`, `SOURCE_SSH_PRIVATE_KEY`, `SOURCE_SHOP_PATH` und `SOURCE_URL`. SSH-Hostschlüssel müssen verifiziert werden. Die Testdomain kommt aus Coolify. DB-Zugangsdaten werden aus der wirksamen Quellkonfiguration gelesen, nicht zusätzlich von Hand eingetragen.

SSH-Zugänge und Live-Daten werden erst zur Laufzeit verwendet und gehören weder ins Repository noch in Image-Layer. `.dockerignore` lässt ausschließlich Dockerfile und unsere Skripte in den Build-Kontext.

## Dockware-Integration

Der eigene Einstiegspunkt liegt außerhalb von `/var/www/html`. Das Essentials-Image vermeidet das Entpacken eines vorinstallierten Shops über eine importierte Kopie. Dockwares Original-Entrypoint liegt weiterhin unter `/entrypoint.sh`.

Beim Import müssen Erkennung und Konfiguration vor dem Start der Dienste erfolgen. `boot_start.sh` wird von Dockware als eigener `sh`-Prozess ausgeführt: dort gesetzte ENV-Werte werden nicht automatisch an den übergeordneten Entrypoint zurückgegeben. `boot_end.sh` läuft bereits nach dem Start der Dienste und ist deshalb für den ersten Import zu spät. Die eigentliche Orchestrierung gehört in unseren Einstiegspunkt, nicht in einen späten Boot-Hook.

Quellen: [Dockware Shopware](https://github.com/dockware/shopware), [Essentials 1.4.0](https://github.com/dockware/shopware/releases/tag/essentials-1.4.0).
