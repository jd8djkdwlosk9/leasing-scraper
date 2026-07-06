# leasing-scraper

Externer Scraper für den BMW-iX-Leasing-Tracker. Läuft per GitHub Actions
(~2× pro Stunde, Nachtruhe 20–7 Uhr Berlin), scrapt 6 Quellen inkl. Farbe/
Ausstattung und schreibt das Ergebnis nach **`offers.json`**.

Der eigentliche Dienst (Telegram, Favoriten, Dashboard) läuft auf ap86.de und
holt sich nur diese `offers.json` — so sehen die Leasing-Seiten nie ap86, nur GitHub.

- `scrape_export.php` – Treiber (erzeugt offers.json)
- `scrapers/` – die 6 Quell-Scraper
- `helpers.php`, `equipment.php` – Hilfsfunktionen, Farb-/Ausstattungsanalyse
- `.github/workflows/scrape.yml` – Zeitplan + Ausführung

Manuell auslösen: Tab **Actions → Scrape Leasing → Run workflow**.
