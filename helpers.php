<?php
// ============================================================
//  LEASING TRACKER – Hilfsfunktionen (cURL + History)
// ============================================================

// Ein zufälliger, echter Browser-User-Agent – EINMAL pro Lauf festgelegt
// (innerhalb eines Laufs konsistent, damit es wie ein echter Browser wirkt;
// über die Läufe hinweg wechselnd, damit die Herkunft schwerer fingerprintbar ist).
function wl_user_agent(): string
{
    static $ua = null;
    if ($ua !== null) return $ua;
    $pool = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    ];
    $ua = $pool[array_rand($pool)];
    return $ua;
}

function http_get(string $url, array $extra_headers = []): string|false
{
    if (!function_exists('curl_init')) {
        echo "[FEHLER] cURL nicht verfügbar!\n";
        return false;
    }
    $default_headers = [
        'User-Agent: ' . wl_user_agent(),
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: de-DE,de;q=0.9',
        'Cache-Control: no-cache',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => array_merge($default_headers, $extra_headers),
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno || $body === false || $code >= 400) {
        debug("HTTP Fehler $code / errno $errno: $url");
        return false;
    }
    return $body;
}

function send_telegram(string $message): void
{
    $url  = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage';
    $data = http_build_query([
        'chat_id'                  => TELEGRAM_CHAT_ID,
        'text'                     => $message,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => false,
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $errno  = curl_errno($ch);
    curl_close($ch);
    if ($errno || !$result) {
        echo "[FEHLER] Telegram fehlgeschlagen (errno $errno)\n";
    } else {
        $resp = json_decode($result, true);
        if (!($resp['ok'] ?? false)) {
            echo "[FEHLER] Telegram API: " . ($resp['description'] ?? '?') . "\n";
        }
    }
}

function send_telegram_photo(string $photo_url, string $caption): void
{
    $url  = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendPhoto';
    $data = http_build_query([
        'chat_id'    => TELEGRAM_CHAT_ID,
        'photo'      => $photo_url,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    $resp = $result ? json_decode($result, true) : null;
    if (!($resp['ok'] ?? false)) send_telegram($caption);
}

// ============================================================
//  JSON-Datenverwaltung mit History + Status
// ============================================================

function load_known_offers(): array
{
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?? [];
}

function save_known_offers(array $offers): void
{
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(DATA_FILE, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Favoriten (vom Nutzer im Dashboard per Stern markiert) ───
// Datei: data/favorites.json  – Objekt { "quelle::id": {"added": "..."} }
function favorites_file(): string
{
    return dirname(DATA_FILE) . '/favorites.json';
}
function load_favorites(): array
{
    $f = favorites_file();
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?: [];
}
function save_favorites(array $favs): void
{
    $f = favorites_file();
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true);
    file_put_contents($f, json_encode($favs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Effektives Preislimit einer Quelle: globales Limit hat Vorrang, sonst Einzel-Limit.
function price_cap(string $const_name, int $fallback): int
{
    if (defined('PRICE_MAX_GLOBAL') && PRICE_MAX_GLOBAL > 0) return (int)PRICE_MAX_GLOBAL;
    return defined($const_name) ? (int)constant($const_name) : $fallback;
}

// Für Push-Benachrichtigungen relevante Varianten: nur iX45, iX60, M70
// (iX40 / iX50 / M60 lösen KEINE Pushes aus).
function is_notify_variant(string $title): bool
{
    $t = strtolower($title);
    return str_contains($t, 'xdrive45') || str_contains($t, 'ix45')
        || str_contains($t, 'xdrive60') || str_contains($t, 'ix60')
        || str_contains($t, 'm70');
}

function parse_price(string $price_str): float
{
    // "789,01 €/mtl." → 789.01
    preg_match('/(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{2})?)/', $price_str, $m);
    if (!isset($m[1])) return 0.0;
    $clean = preg_replace('/\.(?=\d{3})/', '', $m[1]); // Tausenderpunkt weg
    return (float)str_replace(',', '.', $clean);
}

function process_offer(array &$known, array $offer): void
{
    $key       = $offer['source'] . '::' . $offer['id'];
    $price_num = parse_price($offer['price']);
    $now       = date('Y-m-d H:i:s');

    if (!isset($known[$key])) {
        // Neues Angebot
        $known[$key] = [
            'price'      => $price_num,
            'title'      => $offer['title'],
            'url'        => $offer['url'],
            'image'      => $offer['image'] ?? '',
            'source'     => $offer['source'],
            'status'     => 'aktiv',
            'first_seen' => $now,
            'last_seen'  => $now,
            'history'    => [
                ['date' => $now, 'price' => $price_num, 'event' => 'erstmals gesehen'],
            ],
        ];
        if (isset($offer['equipment'])) $known[$key]['equipment'] = $offer['equipment'];
        if (!empty($offer['list_price'])) $known[$key]['list_price'] = $offer['list_price'];
        // notify in check.php
    } else {
        $old_price = (float)$known[$key]['price'];
        $known[$key]['last_seen'] = $now;
        $known[$key]['status']    = 'aktiv';
        $known[$key]['misses']    = 0;   // wieder gefunden → Fehl-Zähler zurücksetzen
        $known[$key]['title']     = $offer['title'] ?? $known[$key]['title'] ?? '';  // Titel/Kategorie auffrischen
        $known[$key]['image']     = $offer['image'] ?? $known[$key]['image'] ?? '';
        $known[$key]['url']       = $offer['url'];
        if (isset($offer['equipment'])) $known[$key]['equipment'] = $offer['equipment'];
        if (!empty($offer['list_price'])) $known[$key]['list_price'] = $offer['list_price'];

        if (abs($old_price - $price_num) >= 1.0) {
            $known[$key]['price'] = $price_num;
            $known[$key]['history'][] = [
                'date'  => $now,
                'price' => $price_num,
                'event' => $price_num < $old_price ? 'preissenkung' : 'preiserhoehung',
            ];
            // notify in check.php
        } else {
            debug("Unverändert: $key @ {$price_num} €");
        }
    }
}

function mark_deleted(array &$known, string $key): void
{
    if (!isset($known[$key])) return;
    if (($known[$key]['status'] ?? '') === 'geloescht') return;

    $now = date('Y-m-d H:i:s');
    $known[$key]['status']     = 'geloescht';
    $known[$key]['deleted_at'] = $now;
    $known[$key]['history'][]  = [
        'date'  => $now,
        'price' => (float)$known[$key]['price'],
        'event' => 'geloescht',
    ];

    $title = $known[$key]['title'] ?? $key;
    $msg   = "🗑 <b>Angebot nicht mehr verfügbar</b>\n\n"
           . "📍 <b>Quelle:</b> {$known[$key]['source']}\n"
           . "🏷️ <b>Modell:</b> $title\n"
           . "💶 <b>Letzter Preis:</b> " . number_format((float)$known[$key]['price'], 2, ',', '.') . " €\n"
           . "🕐 <b>Erstmals gesehen:</b> {$known[$key]['first_seen']}\n"
           . "🗑 <b>Gelöscht am:</b> $now";
    if (empty($GLOBALS['WL_SILENT'])) {
        if (!empty($GLOBALS['WL_FAVS'][$key])) {
            // Favorit: IMMER melden, egal welche Variante.
            $fmsg  = "⭐ 🗑 <b>Favorit – nicht mehr verfügbar</b>\n\n"
                   . "🏷️ <b>Modell:</b> $title\n"
                   . "📍 <b>Quelle:</b> {$known[$key]['source']}\n"
                   . "💶 <b>Letzter Preis:</b> " . number_format((float)$known[$key]['price'], 2, ',', '.') . " €\n"
                   . "🕐 <b>Erstmals gesehen:</b> " . ($known[$key]['first_seen'] ?? '?') . "\n";
            if (!empty($known[$key]['url']))   $fmsg .= "🔗 <a href=\"{$known[$key]['url']}\">Zuletzt gesehen</a>\n";
            if (!empty($known[$key]['image'])) send_telegram_photo($known[$key]['image'], $fmsg);
            else send_telegram($fmsg);
        } elseif (is_notify_variant($title)) {
            send_telegram($msg);
        }
    }
    echo "[GELÖSCHT] $key\n";
}

function notify_new(array $offer): void
{
    if (!empty($GLOBALS['WL_SILENT'])) { echo "[STILL] neu: {$offer['source']} – {$offer['title']}\n"; return; }
    $msg  = "🚗 <b>Neues Leasing-Angebot!</b>\n\n";
    $msg .= "📍 <b>Quelle:</b> {$offer['source']}\n";
    $msg .= "🏷️ <b>Modell:</b> {$offer['title']}\n";
    $msg .= "💶 <b>Preis:</b> {$offer['price']}\n";
    if (!empty($offer['equipment'])) {
        $eq = $offer['equipment'];
        $msg .= "✅ <b>Wunschausstattung</b>\n";
        if (!empty($eq['color']))    $msg .= "🎨 <b>Farbe:</b> {$eq['color']}\n";
        if (!empty($eq['features'])) $msg .= "🛠️ " . implode(', ', $eq['features']) . "\n";
    }
    if (!empty($offer['url'])) $msg .= "🔗 <a href=\"{$offer['url']}\">Zum Angebot</a>\n";
    if (!empty($offer['image'])) send_telegram_photo($offer['image'], $msg);
    else send_telegram($msg);
    echo "[NEU] {$offer['source']} – {$offer['title']} – {$offer['price']}\n";
}

function notify_price_change(array $offer, float $old, float $new): void
{
    if (!empty($GLOBALS['WL_SILENT'])) { echo "[STILL] preis: {$offer['source']} – {$offer['title']}\n"; return; }
    $diff  = $new - $old;
    $arrow = $diff < 0 ? '📉' : '📈';
    $msg   = "{$arrow} <b>Preisänderung!</b>\n\n";
    $msg  .= "📍 <b>Quelle:</b> {$offer['source']}\n";
    $msg  .= "🏷️ <b>Modell:</b> {$offer['title']}\n";
    $msg  .= "💶 <b>Neu:</b> {$offer['price']} (" . sprintf('%+.2f', $diff) . " €)\n";
    $msg  .= "📊 <b>Vorher:</b> " . number_format($old, 2, ',', '.') . " €\n";
    if (!empty($offer['url'])) $msg .= "🔗 <a href=\"{$offer['url']}\">Zum Angebot</a>\n";
    if (!empty($offer['image'])) send_telegram_photo($offer['image'], $msg);
    else send_telegram($msg);
    echo "[PREIS] {$offer['source']} – {$offer['title']} – {$old} → {$new}\n";
}

// Favoriten-Sofortmeldung – wird IMMER gesendet (unabhängig von iX45/60/M70).
//   $type = 'price'  → Preisänderung ($old/$new nötig)
//   $type = 'back'   → war gelöscht, ist wieder da
function notify_favorite(array $offer, string $type, ?float $old = null, ?float $new = null): void
{
    if (!empty($GLOBALS['WL_SILENT'])) { echo "[STILL] favorit ($type): {$offer['title']}\n"; return; }
    $title  = $offer['title']  ?? 'BMW iX';
    $source = $offer['source'] ?? '';

    if ($type === 'price') {
        $diff  = (float)$new - (float)$old;
        $arrow = $diff < 0 ? '📉' : '📈';
        $msg   = "⭐ $arrow <b>Favorit – Preisänderung!</b>\n\n";
        $msg  .= "🏷️ <b>Modell:</b> $title\n";
        $msg  .= "📍 <b>Quelle:</b> $source\n";
        $msg  .= "💶 <b>Neu:</b> " . number_format((float)$new, 2, ',', '.') . " € (" . sprintf('%+.2f', $diff) . " €)\n";
        $msg  .= "📊 <b>Vorher:</b> " . number_format((float)$old, 2, ',', '.') . " €\n";
    } else { // 'back'
        $msg  = "⭐ 🔄 <b>Favorit – wieder verfügbar!</b>\n\n";
        $msg .= "🏷️ <b>Modell:</b> $title\n";
        $msg .= "📍 <b>Quelle:</b> $source\n";
        if (!empty($offer['price'])) $msg .= "💶 <b>Preis:</b> {$offer['price']}\n";
    }
    if (!empty($offer['url']))   $msg .= "🔗 <a href=\"{$offer['url']}\">Zum Angebot</a>\n";
    if (!empty($offer['image'])) send_telegram_photo($offer['image'], $msg);
    else send_telegram($msg);
    echo "[FAVORIT-$type] $source – $title\n";
}

function debug(string $msg): void
{
    if (defined('DEBUG') && DEBUG) echo "[DEBUG] $msg\n";
}
