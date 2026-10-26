# contao-payment

Anbieterneutrale Zahlungen (Vorkasse, Stripe Checkout, Bezahlung vor Ort) als Contao-5.7-Bundle `nordwerk/contao-payment-bundle`. Mini-Shop (und künftig Seminar) nutzen `PaymentSettings`, `PaymentService`, `PayableResolverInterface` und die Webhook-Route.
Lokal: `.env.example` nach `.env` kopieren (Zugangsdaten und APP_SECRET setzen), dann `make reset && make check`. Backend unter `http://127.0.0.1:8084/contao`, Fake-Stripe auf 8095, Mailpit auf 8029.
Manager-ZIP: `make artifact VERSION=x.y.z` (aus HEAD, geprüft von `scripts/check-package.py`).

## Zusammenarbeit

- Aufgaben stehen als Issues in [studio-nordwerk/contao-pakete](https://github.com/studio-nordwerk/contao-pakete/issues). Vor dem Start im Issue kurz schreiben, wer es übernimmt, damit nichts doppelt gebaut wird.
- Immer auf einem eigenen Branch arbeiten (`git switch -c <thema>`), nie auf `main`. `main` und `v*`-Tags sind per Ruleset gesperrt; nur Arne merged, taggt und veröffentlicht.
- Vor dem Pull Request lokal `make check` laufen lassen, bis alles grün ist. Auf GitHub laufen die langen Checks absichtlich nicht automatisch (Build-Minuten). Im PR kurz das Ergebnis nennen.
- Pull Request mit `gh pr create`, im Text `Closes studio-nordwerk/contao-pakete#<nr>`. Was geändert wurde, warum, und wie man es im Backend ausprobiert.
- Rechtlich relevante Texte (Widerruf, Pflichtangaben, Preise, Mails, Rechnungen) im PR ausdrücklich markieren. Sie werden vor dem Merge juristisch gegengelesen.
- Neue Abhängigkeiten zu anderen Nordwerk-Paketen oder Packagist-Paketen im PR nennen; Versionen in `composer.json` mit `^`-Bedingung.
- Nicht anfassen: Tags, GitHub-Releases, `.github/workflows/`, Secrets und Tokens, Uploads nach downloads.nordwerk.studio, die Fly-Demos. Das macht Arne.

## Release (nur Arne)

1. Changelog-Eintrag in `contao-pakete` und GitHub-Release-Notes **vor** dem Tag anlegen: Die Update-Mails an Lizenznehmer holen die Notizen von dort.
2. Tag `vX.Y.Z` pushen. Der Workflow veröffentlicht in den Kanal **stable** und löst Update-Mails aus. Jeder Merge nach `main` landet im Kanal **preview**.
3. Danach die Demos aktualisieren (`demo/update.sh` in `contao-themes`).

## Was Kundinnen und Kunden bekommen

Kunden erhalten nie das Repository, sondern pro Release ein Contao-Manager-ZIP (`make artifact`). Es enthält nur den Bundle-Code, `README.md`, `LICENSE` und die Installationsanleitung. Entwicklungsinterna (`app/`, `e2e/`, `docs/`, `scripts/`, Tests, Docker, Berichte) bleiben draußen; gesteuert über `export-ignore` in `.gitattributes` und das Build-Skript. Wer neue Entwicklungsdateien anlegt, ergänzt `export-ignore`. Keine internen Notizen, Kundendaten oder Zugangsdaten in den Bundle-Code.

## Contao-Konventionen (Pflicht)

Ziel: Wer Contao kennt, findet sich ohne unsere Doku zurecht und kann alles mit Bordmitteln pflegen. Abweichungen nur mit Begründung im PR.

- **Contao 5 und Symfony-Weg:** Inhaltselemente und Frontend-Module als Fragment-Controller mit `#[AsContentElement]` / `#[AsFrontendModule]`, Hooks und Callbacks per Attribut, Services per Autowiring. Keine Legacy-`$GLOBALS['TL_CTE']`/`TL_HOOKS`-Registrierung für neuen Code.
- **Templates:** Twig unter `contao/templates/` mit Standard-Namespace (`@Contao/...`), Contao-Namenskonventionen (`content_element/<typ>`, `frontend_module/<typ>`) und Blöcken, damit sie im Template Studio und in `templates/` überschreibbar bleiben. Keine Logik, die Overrides verhindert; keine fest verdrahteten Texte, sondern Übersetzungen.
- **Theme und Layout:** Gestaltung über `tl_theme`/Seitenlayouts im Theme-Manager. Kopf, Fuß und Navigation sind normale Frontend-Module oder Inhaltselemente in Layout-Bereichen, nicht hart im Template.
- **Datenmodell:** DCA-Erweiterungen unter `contao/dca/`, Standardfelder und -paletten (Überschrift, CSS-ID/Klasse, Unsichtbar, Anzeigen ab/bis, Zugriffsschutz) erhalten. Bilder über den Dateimanager (UUID) und Bildgrößen aus `tl_image_size`. Keine eigenen Tabellen, wo Contao-Tabellen reichen.
- **Backend:** Backend-Module im Standardmenü, Rechte über Benutzergruppen (neue Felder und Module in den Gruppenrechten freigebbar). Übersetzungen unter `contao/languages/de|en` bzw. `translations/`.
- **Assistenten** legen ausschließlich normale Contao-Datensätze an (Seiten, Artikel, Module, Layouts), die danach ohne Assistent pflegbar bleiben. Kein verstecktes Konfigurationsformat.
- **Migrationen** über `MigrationInterface`/`AbstractMigration`, idempotent, nie Daten der Nutzer überschreiben.
- **Frontend:** cachefreundlich (keine Session auf cachebaren Seiten), funktioniert ohne JavaScript, Contao-Suchindex, Sitemap, Canonical und `robots` bleiben intakt. CSS/JS als öffentliche Bundle-Assets (`public/`), überschreibbar über CSS-Variablen.
- **Qualität:** ECS, PHPStan, PHPUnit und Playwright über `make check`; neue Funktionen bekommen Tests, Bugfixes einen Test, der den Fehler zeigt.

## Hinweise für dieses Repo

- Bundle-Struktur: Contao-Ressourcen liegen unter `contao/` im Paketroot (`NordwerkPaymentBundle::getPath()` zeigt auf den Repo-Root). Kein `Resources/contao/` mehr anlegen.
- Einstellungen: `tl_nw_payment_settings` ist ein regulärer `DC_Table`-Datensatz mit `id = 1` (Singleton, versioniert). Das Modul `nw_payment_settings` leitet per `onload_callback` auf `act=edit&id=1` um (`src/Backend/SettingsDataContainer.php`). Der Modulname bleibt, der Mini-Shop verlinkt ihn.
- Alle Regeln (Modus und Schlüssel, Stripe nur mit beiden Secrets, Verschlüsselung, Zurücksetzen der Webhook-Daten) stehen in `PaymentSettings::applyChanges()`; das Formular ruft sie im `onbeforesubmit_callback` auf, `save()` ebenso. Neue Regeln nur dort ergänzen.
- Secrets sind write-only: `load_callback` liefert immer `''`, `doNotSaveEmpty` behält den alten Wert, `hideInput` versteckt sie in Detailansicht und Versionsvergleich, `versionize => false` hält sie aus `tl_version`. Nicht auf Maskierung umstellen: Auch verschlüsselte Altwerte gehören nicht in die Versionen.
- Rechte: Modulrecht plus Contao-5.7-Tabellenrecht `update` (`permissions => ['update']`) plus Feldrechte. Kein `isAdmin` im Code. Webhook-Ansicht (`key=webhook`) prüft zusätzlich das Feldrecht für `webhookSecret`.
- `PaymentSettings::stored()` liefert weiterhin das Array mit den Schlüsseln des früheren JSON-Formats (Schalter als bool, leere Werte fehlen). Nachbarpakete sollen das statt eigener SQL-Abfragen auf die Tabelle nutzen.
- Die Spalte `data` (früherer JSON-Blob) wird nur noch von `SettingsColumnsMigration` einmal gelesen (Marker `legacyMigrated`) und im nächsten Minor-Release entfernt.
- Docker-Projekt heißt fest `nordwerk-payment` (Ports 8084/8095/8029); Admin-Sprache der Demo ist Deutsch.
