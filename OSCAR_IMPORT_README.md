# Oscar-Import Dokumentation

## Installation

### 1. Migration ausführen
```bash
# Öffne folgende URL im Browser um die Datenbanktabelle zu aktualisieren:
http://localhost/movies/scripts/migrate_add_oscar_data.php
```

Dies fügt folgende Spalten zur `movies` Tabelle hinzu:
- `oscar_winner` (BOOLEAN): Hat den Oscar gewonnen?
- `oscar_nominations` (INT): Anzahl der Nominierungen
- `oscar_year` (INT): Jahr der Gewinn/Nominierung
- `oscar_category` (VARCHAR): Kategorie (z.B. "Best Picture")

### 2. Oscar-Daten importieren
```bash
# Basis-Import (initial mode)
http://localhost/movies/mod/import_oscars.php?mode=initial&limit=1000

# Mit ausführlicher Ausgabe
http://localhost/movies/mod/import_oscars.php?mode=initial&limit=1000&verbose=1

# Mit Statistiken
http://localhost/movies/mod/import_oscars.php?mode=initial&stats=1

# Refresh-Modus (aktualisiert bestehende Einträge)
http://localhost/movies/mod/import_oscars.php?mode=refresh&limit=1000
```

## Features

### In der Filme-Tabelle
- 🏆 Symbol für Oscar-Gewinner (mit Hover-Tooltip für das Jahr)
- 📋 Symbol für nominierte Filme (mit Anzahl der Nominierungen)

### Datenquellen (ausbaubar)
- Manuell erfasste Best Picture Gewinner und Nominierte
- Kann mit Wikipedia/IMDb Scraper erweitert werden

## Daten erweitern

Um weitere Oscar-Daten hinzuzufügen, bearbeite die `$oscarData` Array in `mod/import_oscars.php`:

```php
$oscarData = [
    'tt1205489' => [
        'winner' => false,
        'year' => 2025,
        'category' => 'Best Picture Nominee',
        'nominations' => 7
    ],
    // ... weitere Einträge
];
```

## Automatischer Import (Optional)

Zum automatischen Aktualisieren der Oscar-Daten bei der wöchentlichen Wartung kann folgende Zeile zu einem Cron-Job hinzugefügt werden:

```bash
0 2 * * 0 curl -s "http://localhost/movies/mod/import_oscars.php?mode=refresh&limit=100" > /dev/null
```

## Statistiken abrufen

Die Seite zeigt automatisch Statistiken wenn der Parameter `?stats=1` hinzugefügt wird:
- Anzahl der Filme mit Oscar-Daten
- Anzahl der Oscar-Gewinner
- Anzahl der nominierten Filme
- Durchschnittliche Nominierungen

## Geplante Erweiterungen

- [ ] Wikipedia-Scraper für historische Daten
- [ ] IMDb-API Integration für Oscar-Daten
- [ ] Automatischer Import bei neuen Filmen
- [ ] Oscar-Filterung in der Film-Suche
- [ ] Oscar-Leaderboard/Rankings
- [ ] Kategorieweise Anzeige (Best Director, Best Actor, etc.)
