# TYPO3 Extension nkc_event

Die Extension stellt PlugIns bereit, um Inhalte der Nordkirche API (Veranstaltungen) auf einer Website darzustellen.

* Listenansicht
* Kalender (Monatsansicht)
* Detailansicht (Visitenkarte)
* Suchformular

* Kartendarstellung
  * mit Liste unter der Karte
  * alleinstehend ohne Liste


## Abhängigkeiten

* TYPO3 13.4 LTS oder 14.3 LTS, PHP 8.2 bis 8.5
* nordkirche/nkc-base ^13.0
* nordkirche/nk-google-map ^13.0

## Installation

    composer req nordkirche/nkc-event

Binden Sie das Site Set **Nordkirche Veranstaltungen** (`nordkirche/nkc-event`) in die
Site-Konfiguration ein; es bringt nkc_base und nk_google_map mit. Alternativ steht das
statische TypoScript-Template zur Verfügung.

Die Plugins finden Sie im Inhaltselement-Assistenten in der Gruppe **Nordkirche (NAPI)**.

### Site Settings / Konstanten

| Einstellung | Bedeutung |
| --- | --- |
| `plugin.tx_nkcevent.settings.pidEventSingle`, `pidEventList` | Seiten für Detail- und Listenansicht |
| `plugin.tx_nkcevent.settings.eventShortLink` | Kurzlink auf die Seite mit dem Umleitungs-Plugin (Info-Fenster der Karte) |
| `plugin.tx_nkcevent.settings.eventIconName` | Karten-Icons |
| `plugin.tx_nkcevent.settings.digitalEventCategoryId`, `hybridEventCategoryId` | NAPI-Kategorien für digitale bzw. hybride Veranstaltungen |
| `plugin.tx_nkcevent.settings.siteInstitutions` | Institution(en) der Site (NAPI-IDs), für „Nur Veranstaltungen der Institution dieser Site“ |
| `plugin.tx_nkcevent.settings.templateLayout` | Layout der Listen, wenn im Plugin keins gewählt ist |

Diese Werte gelten auch beim Aufwärmen des Karten-Caches auf der Kommandozeile und für die
Info-Fenster der Karten-Marker.

### Zusammenspiel mit nkc_address

Ist nkc_event installiert, zeigt die Institutions-Visitenkarte von nkc_address die Anzahl
der kommenden Veranstaltungen (Partial `NkcAddress/Institution/Events.html`).

## Konfiguration

Bitte beachten Sie die Dokumentation von nordkirche/nkc-base, um Zugriffe auf die NAPI zu ermöglichen.

Es gibt im statischen TypoScript umfangreiche Konfigurationen, die für die eigenen Bedürfnisse angepasst werden können und müssen (z.B. Pfade Icons für die Kartendarstellung) Für TYPO3 Integratoren sollten sich die meisten Dinge von selbst erklären.

Grunsätzlich ist es so, dass Konfigurationen teilweise sowohl in TypoScript als auch in den Plug-Ins möglich sind. Hier zu beachten, dass Plug-In Konfigurationen TypoScript überschreiben, wenn sie einen Wert haben.

Die Templates der Extension haben ein sehr rudimentäres Markup, um die Möglichkeiten der Extension zu zeigen. Die darzustellenen Inhalte sind so komplex, dass ein Standard-Layout wenig Sinn ergeben hätte.

## Plugins

### Filter der Liste, des Kalenders und der Karten

Im Reiter „Funktion“ werden die Veranstaltungen eingeschränkt. Alle Bedingungen gelten zusammen.

| Feld | Wirkung |
| --- | --- |
| Veranstaltungstypen, Zielgruppen | NAPI-Typen bzw. -Zielgruppen |
| Institutionen | Veranstalter (`filter[organizers]`) |
| **Nur Veranstaltungen der Institution dieser Site** | Veranstalter aus `settings.siteInstitutions`, ersetzt die Institutionen des Plugins. Ohne Wert gelten die Institutionen des Plugins. |
| **Unterinstitutionen einbeziehen** | Veranstaltungen der Institutionen und aller Einrichtungen darunter (NAPI-Filter `organizers_with_parents`, alle Ebenen) |
| Veranstaltungsorte | Veranstaltungsorte oder Institutionen, mehrere möglich (NAPI-Filter `location` mit ihren Namen) |
| Orte, Postleitzahlen | Ortsnamen und PLZ, durch Komma getrennt |
| **Handlungsraum** | NAPI-Kategorie mit `is_church_association`, oder „Handlungsraum der Institution dieser Site“ (aus den Kategorien der Site-Institution; ohne Handlungsraum keine Treffer) |
| Kategorien, **Unterkategorien einbeziehen** | NAPI-Kategorien, auf Wunsch mit allen Unterkategorien (aus `sys_category`, siehe `nkc_base:napi_sync`) |
| Zeitraum, die folgenden x Tage, Umkreis | wie bisher |

Die Institution der Site kommt aus TypoScript, z. B. pro Gemeinde-Teilbaum:

    plugin.tx_nkcevent.settings.siteInstitutions = 2658

Steht sie nur im TypoScript der Seite (z. B. aus der Rootline aufgelöst), überspringt
`nkc_base:map_cache` die Karten dieser Plugins; die Marker entstehen beim ersten Aufruf der
Seite. Als Site-Setting hinterlegt, kann auch die Kommandozeile die Marker aufbauen.

### Ausgabe der Liste

* **Layout der Liste:** „Standard“ (Partial `Event/Teaser`) und „Kompakt“ (`Event/Teaser/Compact`).
  Eigene Layouts per Page TSconfig, mit einem Partial `Event/Teaser/<Layout>.html`:

        tx_nkcevent.templateLayouts {
            NoImage = Ohne Bild
        }

  Fehlt das Partial, wird `Event/Teaser` verwendet. Im Template stehen `templateLayout` und
  `teaserPartial` zur Verfügung, die Liste hat die Klasse `event-list--<layout>`.
* **Bereits angezeigte Veranstaltungen nicht darstellen:** Die Liste lässt Veranstaltungen
  weg, die eine Liste weiter oben auf derselben Seite schon zeigt, und füllt mit den nächsten
  auf. Nur ohne Paginierung, da sich sonst die Seiten verschieben würden.

### Suchformular

In der Liste („Nutzer sollen die Liste filtern können“) und im Plugin „Suchformular“:

* **Rubriken:** Auswahl von Veranstaltungstypen (Feld `eventType` der Suche). Besucher können
  nur innerhalb der Typen des Plugins einschränken.
* **Vorbelegung:** Ort/PLZ und Rubrik, solange Besucher nichts anderes suchen.
* **Suchbegriffe vorschlagen:** Titel passender Veranstaltungen als Vorschläge im Suchfeld
  (`<datalist>`, Action `autocomplete` über den AJAX-Seitentyp, mit den Filtern des Plugins).

### Kalender

Das Plugin „Veranstaltungskalender“ zeigt einen Monat (Montag bis Sonntag) mit den
Veranstaltungen jedes Tages; mehrtägige Veranstaltungen stehen an jedem ihrer Tage. Die Filter
sind dieselben wie in der Liste. Geblättert wird ohne Neuladen (`calendar.js`, AJAX-Seitentyp),
die Links funktionieren auch ohne JavaScript: `/<seite>/2026/11`. Auf schmalen Bildschirmen
erscheinen nur die Tage mit Veranstaltungen untereinander.

## PSR-14 Events
Es gibt PSR-14 Events, um die NAPI Queries und die Ausgabe der Daten anzupassen:

| Wo | Event | Daten holen | Daten überschreiben |
|----|-------|-------------|---------------------|
| Liste (`listAction`) | `ModifyEventQueryEvent` | `getEventQuery()` | `setEventQuery()` |
| Liste (`listAction`) | `ModifyAssignedListValuesEvent` | `getAssignedListValues()` | `setAssignedListValues()` |
| Detailansicht (`showAction`) | `ModifyAssignedValuesEvent` | `getAssignedValues()` | `setAssignedValues()` |
| Karten und Karte der Liste | `ModifyMapEventQueryEvent` | `getEventQuery()`, `getContext()`, `getSearchRequest()` | `setEventQuery()` |
| Suchformular und Liste (Filterwerte) | `ModifySearchFilterValuesEvent` | `getFilterValues()` | `setFilterValues()` |
| iCal-Export (`exportAction`) | `ModifyCalendarExportEvent` | `getEvent()`, `getCalendar()`, `getFileName()` | `setCalendar()`, `setFileName()` |

`ModifyMapEventQueryEvent` wird für die Plugins „Karte“ und „Karte und Liste“ (Kontext `map`)
und für die Karte im Listen-Plugin (Kontext `list`, mit der Suche des Besuchers) ausgelöst –
auch beim Aufbau des Marker-Caches über `nkc_base:map_cache`. Es gibt dort keinen Request.
Damit Liste und Karte dieselben Termine zeigen, sollte ein Listener auf `ModifyEventQueryEvent`
**und** `ModifyMapEventQueryEvent` hören:

```php
#[AsEventListener(event: ModifyEventQueryEvent::class)]
#[AsEventListener(event: ModifyMapEventQueryEvent::class)]
public function __invoke(ModifyEventQueryEvent|ModifyMapEventQueryEvent $event): void
{
    $event->getEventQuery()->setTargetGroups(['4']);
}
```

Nach Änderungen an einem solchen Listener den Marker-Cache neu aufbauen
(`vendor/bin/typo3 nkc_base:map_cache --force`).

`ModifySearchFilterValuesEvent` enthält die Schlüssel `dates` (Datums-Presets, `FilterDate`),
`cities` und `categories`.

## Karten

Die Marker der Plugins „Karte“, „Karte und Liste“ und der Karte in der Liste werden im
Marker-Cache von nkc_base gespeichert (`EventMapMarkerProvider`) und können mit
`vendor/bin/typo3 nkc_base:map_cache` vorab aufgebaut werden. Suchen der Besucher werden
nicht gecacht. Die Info-Fenster liefert `EventInfoProvider` (`e`) mit dem Template
`Event/AsyncMapInfo`.

## iCal-Export

Die Detailansicht bietet einen Kalender-Export (`exportAction`) als RFC-5545-konforme
`.ics`-Datei.

## Sprechende URLs

Das Site Set bringt die Routen mit (`Configuration/Sets/NkcEvent/route-enhancers.yaml`),
in der Site-Konfiguration ist nichts einzutragen:

| Plugin | URL |
|---|---|
| Veranstaltung | `/<seite>/titel-der-veranstaltung`, iCal: `/<seite>/titel-der-veranstaltung/ical` |
| Liste | `/<seite>/seite/2`, Suche: `/<seite>/suche` |
| Kalender | `/<seite>/2026/11` |
| Karte und Liste | `/<seite>/seite/2` |

Veranstaltungen mit gleichem Titel bekommen `-1`, `-2` usw. angehängt. Überschreiben lässt
sich jeder Wert in der Site-Konfiguration unter demselben Schlüssel (`NkcEventShow`,
`NkcEventList`, `NkcEventMapList`, `NkcEventCalendar`), siehe readme von nkc_base.

## Upgrade auf Version 13

* Die Plugins sind eigene Inhaltstypen (CType). Der Upgrade-Wizard
  „nkc_event: Plugins auf eigene Inhaltstypen umstellen“ migriert vorhandene Inhalte und
  die Berechtigungen der Backend-Gruppen. Er muss **vor** dem Update auf TYPO3 14 laufen.
* fluidtypo3/vhs wird nicht mehr benötigt; nkc_event braucht keine Templates von nkc_address mehr.
* Suchformular, Links und TypoScript verwenden die Plugin-Namespaces `tx_nkcevent_list`
  bzw. `tx_nkcevent_show` statt des nicht mehr existierenden `tx_nkcevent_main`.
  Eigene Templates sollten entsprechend angepasst werden.
* Das nicht eingebundene JavaScript in `Resources/Public/Javascript/` (jQuery UI, jsTree,
  Datepicker …) wurde entfernt.
* `EventController::buildCache()`, `retrieveMarkerInfo()` und `MapController::buildCache()`
  wurden entfernt (siehe Abschnitt Karten). `EventRealUrlService` wurde entfernt.
* Das nie verwendete und fehlerhafte Template `Event/MapInfoList.html` wurde entfernt.
* Die globale Einstellung `pageNotFoundOnCHashError = 0` wird nicht mehr gesetzt.
  Die Suchformulare senden deshalb per **POST** an eine Action-URL mit cHash (statt
  `action="/"` mit `id`/`no_cache`); eigene Formular-Templates müssen das übernehmen,
  sonst antwortet TYPO3 mit 404 („cHash empty“).
* Die Datumsfelder der Suche sind `<input type="date">` (Datepicker des Browsers). Werte und
  die Attribute `data-value-min`/`data-value-max` der Datums-Schnellauswahl sind deshalb im
  Format `JJJJ-MM-TT`. Eigenes JavaScript, das diese Felder befüllt, muss das Format übernehmen.
  Der Server akzeptiert weiterhin auch `TT.MM.JJJJ`.
* Die Datums-Presets („Diese Woche“ usw.) funktionieren jetzt ohne Theme-JavaScript:
  `Resources/Public/JavaScript/searchForm.js` füllt Von/Bis und startet die Suche, der Eintrag
  „Datum“ setzt den Datumsfilter zurück. Ein Theme kann das übernehmen, indem sein Klick-Handler
  vorher `event.preventDefault()` aufruft.
* Auch die vordefinierten Orte, Ortsteile und Rubriken funktionieren ohne Theme-JavaScript
  (`searchFilter.js` aus nkc_base, Listen mit `data-search-field`). Ein Ortsteil setzt auch den Ort.

## Tests

    composer install
    composer test:unit
    composer test:functional    # SQLite, keine Datenbank nötig

Die Functional Tests laufen gegen TYPO3 13.4 und 14.3 (testing-framework ^9.2).

## Fehler gefunden?
Bitte melden Sie Fehler via github
https://github.com/Nordkirche/nkc-event
