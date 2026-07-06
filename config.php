<?php
// ============================================================
//  LEASING SCRAPER (GitHub Actions) – Konfiguration
//  BEWUSST OHNE Geheimnisse: kein Telegram-Token, kein Gemini-Key,
//  kein Login. Diese laufen nur auf ap86.de. Hier wird ausschließlich
//  gescrapt und offers.json erzeugt.
// ============================================================

// Secrets absichtlich leer (werden hier nicht benutzt):
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID',   '');
define('TOPRATE_EMAIL',      '');
define('TOPRATE_PASSWORD',   '');
define('GEMINI_API_KEY',     '');

// ── Globales Preislimit (0 = aus → Einzel-Limits gelten) ──
define('PRICE_MAX_GLOBAL', 0);

// ── Preisfilter pro Quelle (max. €/mtl.) ── (muss zu ap86 passen)
define('PRICE_MAX_OHNE_ANZAHLUNG', 900);
define('PRICE_MAX_TOPRATE24',      1100);
define('PRICE_MAX_GOLEASY',        800);
define('PRICE_MAX_LEASINGMARKT',   900);
define('PRICE_MAX_TOPRATE_JG',     750);
define('PRICE_MAX_TOPRATE_NW',     800);
define('PRICE_MAX_BMW',            900);
define('PRICE_MAX_MISTER',         900);

// ── Wunschausstattung (Farbe + Ausstattung anreichern) ──
define('WISHLIST_FILTER_ENABLED',      true);
define('WISHLIST_MAX_FETCHES_PER_RUN', 40);
define('WISHLIST_CACHE_FILE', __DIR__ . '/data/equipment_cache.json');

// ── Sonstiges ──
define('DATA_FILE',       __DIR__ . '/data/known_offers.json');   // hier ungenutzt, aber referenziert
define('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36');
define('HTTP_TIMEOUT',    30);
define('DEBUG',           false);
