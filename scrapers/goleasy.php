<?php
// ============================================================
//  SCRAPER: goleasy.de  (Nuxt 3 SSR / __NUXT_DATA__)
//
//  Verfahren: Das __NUXT_DATA__ ist ein flaches Array, in dem
//  identische Strings nur EINMAL gespeichert und sonst per
//  Index-Nummer referenziert werden. Wir arbeiten direkt auf
//  dem Array und lösen diese Referenzen auf (resolve()).
//
//  Pro Angebot (eingeleitet durch ein Objekt mit Schlüssel
//  "effectiveLeasingfaktor") lesen wir das direkt folgende
//  Element als Nettorate und suchen in den ~20 Folge-Elementen
//  Forward-URL (Pflicht), Titel und Bild.
//
//  Preis: Nettorate × 1.19 = Bruttorate (inkl. MwSt.)
//  Filter: iX1/iX2/iX3 ausschließen (echte iX: iX40/45/50/60/M70)
// ============================================================

function scrape_goleasy(): array
{
    $price_max = price_cap('PRICE_MAX_GOLEASY', 800);
    // URL-Grenze ist NETTO; bei globalem Limit aus Brutto zurückrechnen (+Puffer)
    $gl_net = (defined('PRICE_MAX_GLOBAL') && PRICE_MAX_GLOBAL > 0)
            ? (int)ceil((PRICE_MAX_GLOBAL + 60) / 1.19) : 700;

    $url = 'https://www.goleasy.de/inserate?modelle=BMW,iX&sort=preis-aufsteigend'
         . '&vertragstyp=privat&max_laufzeit=36&bonus_exclude=e-auto-foerderung'
         . '&bonus_exclude=inzahlungnahme&bonus_exclude=behinderung'
         . '&max_laufleistung=70000&max_leasingrate=' . $gl_net;

    $html = http_get($url, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: de-DE,de;q=0.9',
        'Cache-Control: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ]);

    if (!$html) {
        echo "[WARNUNG] goleasy.de: Seite nicht erreichbar\n";
        return [];
    }

    if (!preg_match('/<script\b[^>]*\bid=["\']__NUXT_DATA__["\'][^>]*>([\s\S]*?)<\/script>/i', $html, $m)) {
        echo "[INFO] goleasy.de: Kein __NUXT_DATA__ im HTML\n";
        return [];
    }

    $flat = json_decode($m[1], true);
    if (!is_array($flat)) {
        echo "[INFO] goleasy.de: __NUXT_DATA__ Parse-Fehler\n";
        return [];
    }

    $N = count($flat);

    // ── Referenz-Resolver: Integer-Index → echter Wert ───────────
    $resolve = function ($v, int $depth = 0) use (&$resolve, $flat, $N) {
        if ($depth > 5 || is_bool($v)) return $v;
        if (is_int($v) && $v >= 0 && $v < $N) return $resolve($flat[$v], $depth + 1);
        if (is_array($v) && count($v) === 1 && isset($v[0]) && is_int($v[0])) {
            return $resolve($v[0], $depth + 1);
        }
        return $v;
    };

    $offers = [];

    foreach ($flat as $i => $val) {
        // Angebot beginnt bei einem Objekt mit "effectiveLeasingfaktor"
        if (!is_array($val) || !array_key_exists('effectiveLeasingfaktor', $val)) continue;

        // Nettorate = direkt folgendes Element
        $netto = $flat[$i + 1] ?? null;
        if (!is_int($netto) && !is_float($netto)) continue;
        if (is_bool($netto)) continue;

        $ins = $ang = $title = $image = $fwd = null;

        // In den ~20 Folge-Elementen Forward-URL, Titel, Bild suchen
        for ($j = $i + 1; $j < min($i + 22, $N); $j++) {
            $el = $resolve($flat[$j]);
            if (!is_string($el)) continue;

            // Forward-URL (Pflicht – liefert beide UUIDs für die ID)
            if ($fwd === null && str_contains($el, '/forward/') && str_contains($el, 'goleasy')) {
                if (preg_match('/\/forward\/([0-9a-f-]{36})\/leasing\/([0-9a-f-]{36})/', $el, $fm)) {
                    $fwd = $el; $ins = $fm[1]; $ang = $fm[2];
                }
            }
            // Titel direkt ("BMW iX...")
            if ($title === null && str_starts_with($el, 'BMW iX')) {
                $title = $el;
            }
            // Titel im "Null-Leasing_..._BMW iX..." String
            if ($title === null && str_contains($el, '_BMW iX')) {
                if (preg_match('/_(BMW iX.*)$/', $el, $tm)) $title = $tm[1];
            }
            // Bild (Fahrzeugfoto, kein Anbieter-Logo)
            if ($image === null) {
                if (str_contains($el, 'img.classistatic.de') ||
                    (str_contains($el, 'googleapis.com/gl-notion-images/') && !str_contains($el, '/anbieter'))) {
                    $image = $el;
                }
            }
        }

        if (!$fwd) continue; // ohne Forward-URL keine eindeutige ID

        // iX1/iX2/iX3 ausschließen
        $title_check = $title ?? '';
        if (preg_match('/\biX[123]\b/i', $title_check)) continue;

        $rate = round((float)$netto * 1.19, 2);
        if ($rate < 50 || $rate > $price_max) continue;

        $title = $title ? html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'BMW iX';
        $fwd   = html_entity_decode($fwd, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($image) {
            $image = html_entity_decode($image, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $image = str_replace('rule=mo-1024', 'rule=mo-640', $image);
        }

        $id = $ins . '_' . $ang;

        debug("goleasy.de: [$id] $title – $rate € (inkl. MwSt.)");

        $offers[$id] = [
            'source' => 'goleasy.de',
            'id'     => $id,
            'title'  => $title,
            'price'  => number_format($rate, 2, ',', '.') . ' €/mtl.',
            'url'    => $fwd,
            'image'  => $image ?: '',
        ];
    }

    $offers = array_values($offers);

    if (empty($offers)) {
        echo "[INFO] goleasy.de: Keine BMW iX Angebote ≤{$price_max}€ gefunden.\n";
    } else {
        echo "[INFO] goleasy.de: " . count($offers) . " Angebote gefunden.\n";
    }

    return $offers;
}