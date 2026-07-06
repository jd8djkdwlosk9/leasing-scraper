<?php
// ============================================================
//  WUNSCHAUSSTATTUNGS- & FARBFILTER  (Badge + Dashboard-Schalter)
//
//  Pro Angebot wird FARBE + AUSSTATTUNG bestimmt und ein Status
//  vergeben:  match (✓-Badge) | reject | unknown.
//  Es wird NICHTS aussortiert – alle Angebote bleiben sichtbar,
//  die Auswahl "nur Wunsch" ist nur eine Ansicht im Dashboard.
//
//  ── FARBE: woher kommt sie? (zuverlässig statt Wort-Suche) ──
//   • toprate.de        → echter Farbname aus dem Angebot (baseColour)
//   • goleasy.de        → API goleasy.de/api/inserate/{id} (farbeExteriorRaw)
//   • mister-leasing.de → Supabase-Feld exterior_color
//   • sonst (ohne-anzahlung, toprate24, leasingmarkt)
//                       → FARBE AUS DEM FAHRZEUGBILD (GD-Bildanalyse)
//  Die alte Volltext-Suche nach "schwarz" entfällt – sie war
//  unzuverlässig (schwarze Sitze/Felgen bei z.B. blauem Auto).
//
//  Erlaubt: Saphirschwarz / Frozen Deep (Grey) / Schwarz · Carbon IMMER raus.
//  Aus dem Bild lässt sich nur grob "schwarz vs. nicht-schwarz" (blau/rot/
//  weiß/grau) bestimmen – das genügt, um Falschfarben auszusortieren.
//
//  ── AUSSTATTUNG ──
//   Bedingung 1 – mind. EINES: Parking Assistent Professional ODER Innovation Paket
//   Bedingung 2 – ZWINGEND:    Driving Assistent Professional
//   (Nur wo die Detailseite lesbar ist: ohne-anzahlung, toprate, toprate24,
//    leasingmarkt. Bei goleasy/mister sind die Pakete nicht hinterlegt → 'unknown'.)
// ============================================================

// ── Farbnamen-Muster (für strukturierte Farbangaben) ─────────
const WL_RE_CARBON = '/carbon[\s\-]*?(?:schwarz|black)/iu';
const WL_RE_SAPHIR = '/sap(?:ph|h)ire?[\s\-]*?(?:schwarz|black)/iu';
const WL_RE_FROZEN = '/frozen[\s\-]*?deep/iu';
const WL_RE_BLACK  = '/(?<![a-zäöüß])(?:schwarz|black)/iu';

// ── Ausstattungs-Muster ──────────────────────────────────────
const WL_RE_DRIVING = '/driving[\s\-]*assist(?:ent|ant|enz)?\.?[\s\-]*prof(?:essional)?\.?/iu';
const WL_RE_PARKING = '/park(?:ing)?[\s\-]*assist(?:ent|ant|enz)?\.?[\s\-]*prof(?:essional)?\.?/iu';
const WL_RE_INNO    = '/innovations?[\s\-]*(?:paket|package)/iu';
const WL_RE_EQUIP_CONTEXT = '/(?:sonder|serien|standard|werks)?\s*ausstattung|ausstattungsliste|equipment|highlights|fahrzeugdetails/iu';

/**
 * Farbe aus einem bekannten Farbnamen → ['status'=>ok|reject|unknown,'label'=>?].
 */
function wishlist_color_from_name(string $name): array
{
    $n = trim($name);
    if ($n === '')                       return ['status' => 'unknown', 'label' => null];
    if (preg_match(WL_RE_CARBON, $n))    return ['status' => 'reject',  'label' => 'Carbonschwarz'];
    if (preg_match(WL_RE_SAPHIR, $n))    return ['status' => 'ok',      'label' => 'Saphirschwarz'];
    if (preg_match(WL_RE_FROZEN, $n))    return ['status' => 'ok',      'label' => 'Frozen Deep Grey'];
    if (preg_match(WL_RE_BLACK,  $n))    return ['status' => 'ok',      'label' => 'Schwarz'];
    return ['status' => 'reject', 'label' => $n];   // definierter, aber nicht erlaubter Farbname
}

/**
 * Ausstattung aus Text → ['status'=>ok|fail|unknown,'features'=>[]].
 * $trust=false → Quelle belegt die Pakete nicht (goleasy/mister) → 'unknown'.
 */
function wishlist_equip_from_text(string $text, bool $trust): array
{
    $driving = (bool)preg_match(WL_RE_DRIVING, $text);
    $parking = (bool)preg_match(WL_RE_PARKING, $text);
    $inno    = (bool)preg_match(WL_RE_INNO, $text);

    $features = [];
    if ($driving) $features[] = 'Driving Assistent Professional';
    if ($parking) $features[] = 'Parking Assistent Professional';
    if ($inno)    $features[] = 'Innovation Paket';

    if (!$trust) return ['status' => 'unknown', 'features' => []];
    if (($parking || $inno) && $driving) return ['status' => 'ok', 'features' => $features];

    $readable = $driving || $parking || $inno
              || (bool)preg_match(WL_RE_EQUIP_CONTEXT, $text)
              || mb_strlen($text) > 4000;
    return ['status' => $readable ? 'fail' : 'unknown', 'features' => $features];
}

// ── Bild-Farbanalyse (GD) ────────────────────────────────────
function wl_pixel_class(int $r, int $g, int $b): string
{
    // Absolute Buntheit (max-min in 0..255) ist bei dunklen Tönen stabiler
    // als relative Sättigung – verhindert, dass schwarzer Lack mit blauer
    // Himmel-Reflexion fälschlich als "blau" zählt.
    $mxi = max($r, $g, $b); $mni = min($r, $g, $b); $dabs = $mxi - $mni;
    $v = $mxi / 255;
    if ($dabs < 32) {                                  // (nahezu) unbunt
        if ($v < 0.30) return 'black';
        if ($v > 0.72) return 'white';
        return $v < 0.45 ? 'darkgrey' : 'grey';
    }
    if ($v < 0.22) return 'black';                     // sehr dunkel trotz Tönung

    $R = $r / 255; $G = $g / 255; $B = $b / 255;
    $mx = max($R, $G, $B); $mn = min($R, $G, $B); $d = $mx - $mn;
    if ($mx == $R)      $h = fmod(($G - $B) / $d, 6);
    elseif ($mx == $G)  $h = ($B - $R) / $d + 2;
    else                $h = ($R - $G) / $d + 4;
    $h *= 60; if ($h < 0) $h += 360;
    if ($h < 15 || $h >= 345) return 'red';
    if ($h < 45)  return 'orange';
    if ($h < 70)  return 'yellow';
    if ($h < 170) return 'green';
    if ($h < 200) return 'cyan';
    if ($h < 255) return 'blue';
    return 'purple';
}

function wl_hue_label(string $c): string
{
    return ['red'=>'Rot','orange'=>'Orange','yellow'=>'Gelb','green'=>'Grün',
            'cyan'=>'Türkis','blue'=>'Blau','purple'=>'Violett'][$c] ?? ucfirst($c);
}

/**
 * Bestimmt grob die Karosseriefarbe aus dem Fahrzeugbild.
 * @return array{ok:bool,label:?string,is_black:bool}
 */
function wishlist_image_color(string $url): array
{
    if ($url === '' || !function_exists('imagecreatefromstring')) return ['ok' => false];
    $data = http_get($url);
    if ($data === false || $data === '') return ['ok' => false];
    $im = @imagecreatefromstring($data);
    if (!$im) return ['ok' => false];

    $w = imagesx($im); $h = imagesy($im);
    if ($w < 20 || $h < 20) { imagedestroy($im); return ['ok' => false]; }

    // Mittig-untere Bildregion = meist lackierte Karosserie (Türen/Hauben)
    $x0 = (int)($w * 0.22); $x1 = (int)($w * 0.78);
    $y0 = (int)($h * 0.42); $y1 = (int)($h * 0.82);
    $sx = max(1, (int)(($x1 - $x0) / 50));
    $sy = max(1, (int)(($y1 - $y0) / 34));

    $c = array_fill_keys(['black','darkgrey','grey','white','red','orange','yellow','green','cyan','blue','purple'], 0);
    $n = 0;
    for ($y = $y0; $y < $y1; $y += $sy) {
        for ($x = $x0; $x < $x1; $x += $sx) {
            $rgb = imagecolorat($im, $x, $y);
            $c[wl_pixel_class(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255)]++;
            $n++;
        }
    }
    imagedestroy($im);
    if ($n < 30) return ['ok' => false];

    $p = fn($k) => 100.0 * $c[$k] / $n;
    $chroma = ['red','orange','yellow','green','cyan','blue','purple'];
    $sumc = 0; $dom = null; $domv = 0; $sec = 0;
    foreach ($chroma as $k) {
        $v = $p($k); $sumc += $v;
        if ($v > $domv) { $sec = $domv; $domv = $v; $dom = $k; }
        elseif ($v > $sec) { $sec = $v; }
    }
    $white = $p('white'); $black = $p('black'); $dark = $p('darkgrey'); $grey = $p('grey');

    // klar farbig (blau/rot/…)
    if ($domv >= 7 && $domv >= 1.4 * $sec)            return ['ok'=>true,'label'=>wl_hue_label($dom),'is_black'=>false];
    // hell → weiß/silber
    if ($white >= 32 && $white >= $black)            return ['ok'=>true,'label'=>'Weiß','is_black'=>false];
    // schwarz/dunkel, kaum Farbe
    if (($black + $dark) >= 42 && $sumc < 7 && $black >= $grey) return ['ok'=>true,'label'=>'Schwarz','is_black'=>true];
    // grau/silber
    if (($grey + $dark + $black) >= 50 && $sumc < 10) return ['ok'=>true,'label'=>'Grau','is_black'=>false];
    return ['ok'=>true,'label'=>null,'is_black'=>false]; // unklar
}

/**
 * Wandelt rohes HTML (auch Nuxt/Next-JSON in <script>) in durchsuchbaren Text.
 */
function wishlist_html_to_text(string $html): string
{
    $html = str_replace(['\\/', '\\"', '\\u0026'], ['/', '"', '&'], $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return $text ?? '';
}

/**
 * Bestimmt Farbe + Ausstattung eines Angebots.
 * @return array ok=false, falls eine nötige Quelle nicht ladbar war (→ später erneut)
 */
function analyze_offer_equipment(array $offer): array
{
    $source = (string)($offer['source'] ?? '');
    $title  = (string)($offer['title']  ?? '');
    $url    = (string)($offer['url']     ?? '');
    $img    = (string)($offer['image']   ?? '');

    $explicit  = '';     // bekannter Farbname (falls vorhanden)
    $equip_text = $title;
    $trust = false;

    if (str_starts_with($source, 'goleasy')) {
        // Farbe aus der goleasy-API; Ausstattung dort nicht hinterlegt
        if (preg_match('#/forward/([0-9a-f-]{36})/#i', $url, $m)) {
            $json = http_get('https://www.goleasy.de/api/inserate/' . $m[1], ['Accept: application/json']);
            if ($json !== false && $json !== '') {
                $d = json_decode($json, true);
                if (is_array($d)) $explicit = (string)($d['farbeExteriorRaw'] ?? ($d['farbeExterior'] ?? ''));
            }
        }
        $trust = false;
    } elseif (str_starts_with($source, 'mister-leasing')) {
        // Farbe aus Supabase-Feld; Ausstattung nur als Slugs → nicht prüfbar
        $explicit = (string)($offer['color'] ?? '');
        $trust = false;
    } else {
        // toprate / ohne-anzahlung / toprate24 / leasingmarkt: Detailseite für Ausstattung
        $html = $url ? http_get($url, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9',
            'Upgrade-Insecure-Requests: 1',
        ]) : false;
        if ($html === false || $html === '') {
            debug("equipment: Detailseite nicht ladbar: $url");
            return ['ok' => false];
        }
        $equip_text = $title . "\n" . wishlist_html_to_text($html);
        $trust = true;
        // toprate liefert den echten Farbnamen im Angebot mit
        if (str_starts_with($source, 'toprate.de')) $explicit = (string)($offer['color'] ?? '');
    }

    // ── FARBE bestimmen ──
    if (trim($explicit) !== '') {
        $col = wishlist_color_from_name($explicit);
    } else {
        $ic = wishlist_image_color($img);
        if (empty($ic['ok']))                 $col = ['status' => 'unknown', 'label' => null];
        elseif (!empty($ic['is_black']))      $col = ['status' => 'ok',      'label' => $ic['label'] ?? 'Schwarz'];
        elseif (($ic['label'] ?? null) === null) $col = ['status' => 'unknown', 'label' => null];
        else                                  $col = ['status' => 'reject',  'label' => $ic['label']];
    }

    // ── AUSSTATTUNG bestimmen ──
    $eq = wishlist_equip_from_text($equip_text, $trust);

    // ── Gesamt-Status ──
    if ($col['status'] === 'reject' || $eq['status'] === 'fail') {
        $status = 'reject';
    } elseif ($col['status'] === 'ok' && $eq['status'] === 'ok') {
        $status = 'match';
    } else {
        $status = 'unknown';
    }

    return [
        'ok'         => true,
        'status'     => $status,
        'matches'    => ($status === 'match'),
        'color'      => $col['label'],
        'features'   => $eq['features'],
        'checked_at' => date('Y-m-d H:i:s'),
    ];
}

// ── Cache (quelle::id → Bewertung) ───────────────────────────
function load_equipment_cache(): array
{
    if (!defined('WISHLIST_CACHE_FILE') || !file_exists(WISHLIST_CACHE_FILE)) return [];
    return json_decode(file_get_contents(WISHLIST_CACHE_FILE), true) ?? [];
}

function save_equipment_cache(array $cache): void
{
    if (!defined('WISHLIST_CACHE_FILE')) return;
    $dir = dirname(WISHLIST_CACHE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(WISHLIST_CACHE_FILE,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Reichert ALLE Angebote mit Ausstattungsdaten an (für Badge/Schalter).
 * Es wird NICHTS entfernt. Pro Lauf höchstens $budget neue Analysen.
 * @return array  dieselben Angebote, ergänzt um das Feld 'equipment'
 */
function apply_wishlist_filter(array $all_offers, int $budget): array
{
    $cache       = load_equipment_cache();
    $cache_dirty = false;
    $recheck_ttl = 43200; // 'unknown' nach 12 h erneut prüfen
    $out         = [];

    foreach ($all_offers as $offer) {
        $key = ($offer['source'] ?? '') . '::' . ($offer['id'] ?? '');

        $cached = $cache[$key] ?? null;
        $stale_unknown = $cached
            && (($cached['status'] ?? '') === 'unknown')
            && (time() - strtotime($cached['checked_at'] ?? '2000-01-01') > $recheck_ttl);
        $need_fetch = (!$cached || $stale_unknown);

        $equip = $cached;
        if ($need_fetch && $budget > 0) {
            $budget--;
            $res = analyze_offer_equipment($offer);
            if (!empty($res['ok'])) {
                $equip = [
                    'status'     => $res['status'],
                    'matches'    => $res['matches'],
                    'color'      => $res['color'],
                    'features'   => $res['features'],
                    'checked_at' => $res['checked_at'],
                ];
                $cache[$key] = $equip;
                $cache_dirty = true;
                debug("equipment: $key → {$equip['status']} (Farbe: " . ($equip['color'] ?? '?') . ")");
            }
        }

        if ($equip !== null) $offer['equipment'] = $equip;
        $out[] = $offer;   // IMMER behalten – es wird nichts aussortiert
    }

    if ($cache_dirty) save_equipment_cache($cache);
    return $out;
}
