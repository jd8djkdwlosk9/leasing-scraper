<?php
// ============================================================
//  SCRAPER: toprate.de  (direkt von der WEBSEITE, SSR-HTML)
//
//  Warum nicht die signal.locarl-API? Dort sind manche Fahrzeuge
//  (v.a. Vorführwagen) NICHT als "iX" getaggt und fehlen deshalb.
//  Die Webseite zeigt sie aber. Daher lesen wir die Listenseite
//  direkt aus – so sehen wir genau das, was der Nutzer auch sieht.
//
//  Konfiguration in der URL: leasing_default=27_5_0
//    → 27 Monate, 5.000 km/Jahr, 0 € Anzahlung (echte Rate!)
//
//  EINE kombinierte Liste (alle Fahrzeugarten in einem Filter,
//  genau die URL, die der Nutzer im Browser öffnet). Die Seite
//  paginiert: Seite 1 zeigt ~12 Autos, der Rest kommt über
//  &page=2, &page=3 … Wir laufen die Seiten durch, bis keine
//  Karten mehr kommen.
//
//  WICHTIG: Das Link-Präfix wechselt je Seite!
//    Seite 1: /junge-gebrauchte/bmw/i/v/{VN}-slug
//    Seite 2: /bmw/page/2/v/{VN}-slug
//  Deshalb verankern wir präfix-unabhängig nur an  /v/{VN}-slug .
//  Kategorie (Neuwagen/Vorführwagen/…) und Farbe stehen zuverlässig
//  im Slug ("…-Elektro-{Farbe}-als-{Kategorie}-in-{Ort}").
// ============================================================

function scrape_toprate(): array
{
    $cap_nw = price_cap('PRICE_MAX_TOPRATE_NW', 800);
    $cap_jg = price_cap('PRICE_MAX_TOPRATE_JG', 750);
    $cap    = max($cap_nw, $cap_jg);   // URL-Grenze = höheres Limit

    // Kombinierter Filter (alle Fahrzeugarten), Modell auf iX beschränkt.
    $filter = 'bmw i,ix,i7,fahrzeugart_dienstwagen,modell_bmw--ix,'
            . 'fahrzeugart_neuwagen,fahrzeugart_vorfuehrwagen,fahrzeugart_gebrauchtwagen,'
            . 'monthly_rate_max_' . (int)$cap . ',serie_bmw-i';
    $base = 'https://toprate.de/junge-gebrauchte/bmw/i/';
    $q    = 'filter=' . str_replace('%20', '+', rawurlencode($filter))
          . '&order=price_asc&leasing_default=27_5_0&listing=leasing&price=leasing';

    $offers     = [];
    $seen       = [];    // Doppelte über alle Seiten vermeiden (per Fahrzeugnummer)
    $expected   = null;  // von toprate gemeldete Gesamtzahl ("N Angebote gefunden")
    $first_size = null;  // Kartenzahl der ersten Seite (volle Seite)

    for ($page = 1; $page <= 15; $page++) {
        if ($page > 1) usleep(500000 + random_int(0, 400000));  // höfliche Pause + Jitter gegen Drossel

        $html = toprate_get($base . '?' . $q . '&page=' . $page, $page === 1);
        if ($html === false) {
            echo "[WARNUNG] toprate.de: Seite $page nicht ladbar (Drossel?) – Abbruch.\n";
            break;
        }

        // Gesamtzahl merken (nur einmal, von Seite 1) → wir wissen, wann wir fertig sind
        if ($expected === null && preg_match('/(\d+)\s*Angebote gefunden/u', $html, $em)) {
            $expected = (int)$em[1];
        }

        $cards = toprate_parse_listing($html);
        if (empty($cards)) break;                        // Sicherheitsnetz
        if ($first_size === null) $first_size = count($cards);

        foreach ($cards as $c) {
            $rate = $c['rate'];
            if ($rate < 100 || $rate > $cap) continue;

            // STABILE ID = reine Fahrzeugnummer (bleibt gleich, egal ob das Auto als
            // Neuwagen, Vorführwagen oder Junger Gebrauchtwagen gelistet ist).
            $vn = $c['vn'];
            if (isset($seen[$vn])) continue;   // dasselbe Auto nur einmal
            $seen[$vn] = true;

            $cat   = $c['category'] ?: 'Junger Gebrauchtwagen';
            $title = $c['title'] . ($c['color'] ? ' – ' . $c['color'] : '') . ' · ' . $cat;

            $offers[] = [
                'source'     => 'toprate.de',   // einheitlich → ID/Key bleiben stabil
                'id'         => $vn,            // reine Fahrzeugnummer
                'title'      => $title,
                'price'      => number_format($rate, 2, ',', '.') . ' €/mtl. (27M/5.000km)',
                'url'        => 'https://toprate.de/junge-gebrauchte/bmw/i/v/' . $vn
                              . '?leasing_default=27_5_0&listing=leasing&price=leasing',
                'image'      => $c['image'],
                'color'      => $c['color'],
                'list_price' => $c['list'],
                'category'   => $cat,
            ];
            debug("toprate.de: $vn $title – {$rate}€");
        }

        // Ende erkennen: kürzere Seite = letzte Seite. (Leeres Listenende fängt
        // oben der empty($cards)-Break ab, ohne unnötige Retries auf Folgeseiten.)
        if (count($cards) < $first_size) break;
    }

    // Vollständigkeits-Warnung nur bei DEUTLICHER Lücke – toprates Zähler ist
    // manchmal um 1 daneben (meldet 25, liefert real 24), das ist kein Problem.
    if ($expected !== null && count($seen) > 0 && count($seen) < $expected * 0.85) {
        echo "[WARNUNG] toprate.de: nur " . count($seen) . " von $expected Angeboten erhalten (unvollständig – Drossel?).\n";
    }

    if (empty($offers)) {
        echo "[INFO] toprate.de: Keine passenden Angebote gefunden.\n";
    } else {
        echo "[INFO] toprate.de: " . count($offers) . " Angebote gefunden.\n";
    }

    return $offers;
}

// ── Eine Listenseite laden – robust gegen Drosselung ─────────
// toprate antwortet bei Drossel oft mit HTTP 200, aber LEERER Liste.
//   $retry_empty=true  (Seite 1): Dort gibt es IMMER Angebote → eine leere
//     Antwort ist eine Drossel und wird mit Backoff wiederholt.
//   $retry_empty=false (Folgeseiten): "leer" ist das normale Listenende →
//     wird direkt zurückgegeben (der Aufrufer bricht dann sauber ab).
// HTTP-Fehler (nicht erreichbar) werden auf ALLEN Seiten wiederholt.
function toprate_get(string $url, bool $retry_empty): string|false
{
    for ($try = 1; $try <= 3; $try++) {
        $html = http_get($url, ['Referer: https://toprate.de/']);
        if ($html !== false) {
            if (!$retry_empty || preg_match('#/v/[A-Z0-9]{6,}-#', $html)) {
                return $html;
            }
        }
        if ($try < 3) sleep(2 + $try);   // Backoff: 3s, dann 4s
    }
    return false;
}

// ── Listenseite parsen: ein Eintrag je Auto ──────────────────
function toprate_parse_listing(string $html): array
{
    // Detail-Links präfix-unabhängig einsammeln: /v/{VN}-{slug}
    // (Präfix wechselt je Seite, daher NICHT auf /bmw/i/v/ festnageln.)
    if (!preg_match_all('#/v/([A-Z0-9]{6,})-([^"?/]+)#', $html, $lm, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    // Aufeinanderfolgende gleiche Fahrzeugnummer zusammenfassen (erste Position behalten)
    $cards = [];
    $last  = null;
    foreach ($lm[0] as $i => $full) {
        $vn   = $lm[1][$i][0];
        $slug = $lm[2][$i][0];
        if ($vn === $last) continue;
        $cards[] = ['pos' => $full[1], 'vn' => $vn, 'slug' => $slug];
        $last = $vn;
    }

    $out = [];
    $n   = count($cards);
    for ($i = 0; $i < $n; $i++) {
        $start = $cards[$i]['pos'];
        $end   = ($i + 1 < $n) ? $cards[$i + 1]['pos'] : strlen($html);
        $seg   = substr($html, $start, $end - $start);
        $slug  = $cards[$i]['slug'];

        // Titel (z.B. "BMW iX xDrive45")
        $title = '';
        if (preg_match('#locarl-card__title[^>]*>(.*?)</#s', $seg, $tm)) {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags($tm[1])));
        }
        if (stripos($title, 'ix') === false) continue;       // nur iX
        if (preg_match('/\bix[123]\b/i', $title)) continue;  // iX1/iX2/iX3 raus

        // Farbe aus dem Slug ("…-Elektro-{Farbe}-als-…"), Fallback: Bild-alt
        $color = '';
        if (preg_match('/-Elektro-([A-Za-z\x{00C0}-\x{00FF}]+)-als-/u', $slug, $sc)) {
            $color = $sc[1];
        } elseif (preg_match('/alt="BMW iX[^"]*?\bin\s+([A-Za-z\x{00C0}-\x{00FF}]+)/u', $seg, $am)) {
            $color = $am[1];
        }
        if ($color === 'Weiss') $color = 'Weiß';

        // Kategorie aus dem Slug ("…-als-{Kategorie}-in-{Ort}"), Fallback: Badge
        $category = '';
        if (preg_match('/-als-(.+?)-in-/u', $slug, $sm)) {
            $category = str_replace('-', ' ', $sm[1]);
        } else {
            $bp = strpos($seg, 'locarl-card__badges');
            if ($bp !== false && preg_match('/(Vorführwagen|Junger Gebrauchtwagen|Neuwagen|Dienstwagen|Tageszulassung)/u',
                    substr($seg, $bp, 500), $cm)) {
                $category = $cm[1];
            }
        }
        if ($category === 'Vorfuehrwagen') $category = 'Vorführwagen';

        // Monatsrate: freistehender 3-4-stelliger €-Wert (kein Ziffer/Punkt davor → nicht der Kaufpreis),
        // nicht in der Fußnote. Erster Treffer 100–2000.
        $rate = 0;
        if (preg_match_all('/(?<![\d.,])(\d{3,4})\s*€/u', $seg, $rm, PREG_OFFSET_CAPTURE)) {
            foreach ($rm[1] as $j => $cap) {
                $rpos = $rm[0][$j][1];
                $pre  = substr($seg, max(0, $rpos - 120), min(120, $rpos));
                if (str_contains($pre, 'footnote')) continue;
                $v = (int)$cap[0];
                if ($v >= 100 && $v <= 2000) { $rate = $v; break; }
            }
        }
        if (!$rate) continue;

        // Listenpreis/Kaufpreis = erster 5-6-stelliger €-Wert mit Tausenderpunkt
        $list = 0;
        if (preg_match('/(\d{2,3}\.\d{3})\s*€/u', $seg, $pm)) {
            $list = (int)str_replace('.', '', $pm[1]);
        }

        // Bild (mobile.de-CDN)
        $image = '';
        if (preg_match('#<img[^>]+src="(https://cdn\.mportal\.de[^"]+)"#', $seg, $im)) {
            $image = $im[1];
        }

        $out[] = [
            'vn'       => $cards[$i]['vn'],
            'title'    => $title,
            'color'    => $color,
            'rate'     => $rate,
            'list'     => $list,
            'image'    => $image,
            'category' => $category,
        ];
    }

    return $out;
}
