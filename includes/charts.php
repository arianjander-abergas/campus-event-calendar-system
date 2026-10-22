<?php
/**
 * Small chart helpers for the admin dashboard, reports and evaluation
 * results. Charts are drawn as inline SVG / HTML on the server, so they
 * also appear on printouts. Hovering (or tabbing to) a bar shows its value
 * (assets/js/charts.js); every chart also has a table with the same numbers.
 *
 * Colors follow a fixed meaning everywhere (never re-assigned by rank):
 *   on-campus = blue, off-campus = orange, online = green-teal.
 */

/** Series colors for venue types (checked for color-blind safety). */
const VENUE_TYPE_COLORS = ['on_campus' => '#2a78d6', 'off_campus' => '#eb6834', 'online' => '#1baf7a'];

/** Rating colors, Excellent (5) to Needs Improvement (1): blue side = good, neutral gray = 3, red side = poor. */
const RATING_COLORS = [5 => '#256abf', 4 => '#86b6ef', 3 => '#d6d4cd', 2 => '#ee9594', 1 => '#c93b3b'];

/** A round axis maximum for counts: 3 -> 4, 7 -> 8, 13 -> 15, 42 -> 50. */
function chart_nice_max(int $max): int
{
    if ($max <= 4) return max(1, $max);
    foreach ([5, 10, 20, 25, 50, 100, 200, 250, 500, 1000] as $step) {
        if ($max <= $step) return $step;
    }
    return (int) (ceil($max / 1000) * 1000);
}

/**
 * Legend for a set of series: [['label' => 'On-campus', 'color' => '#2a78d6'], ...]
 */
function chart_legend(array $series): string
{
    $html = '<div class="chart-legend">';
    foreach ($series as $s) {
        $html .= '<span class="chart-key"><span class="chart-swatch" style="background:' . htmlspecialchars($s['color']) . '"></span>'
            . htmlspecialchars($s['label']) . '</span>';
    }
    return $html . '</div>';
}

/**
 * Stacked column chart (e.g. events per month, split by venue type).
 *
 * @param array $columns [['key' => '2026-09', 'label' => 'Sep', 'title' => 'September 2026', 'current' => bool], ...]
 * @param array $series  [['key' => 'on_campus', 'label' => 'On-campus', 'color' => '#2a78d6', 'values' => ['2026-09' => 3, ...]], ...]
 *                       listed bottom to top
 * @param string $caption accessible name, e.g. "Events per month, SY 2026-2027"
 */
function chart_stacked_columns(array $columns, array $series, string $caption, string $unit = 'event'): string
{
    $width = 720;
    $height = 230;
    $left = 34;
    $right = 10;
    $top = 22;
    $bottom = 30;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;

    $totals = [];
    foreach ($columns as $c) {
        $totals[$c['key']] = 0;
        foreach ($series as $s) {
            $totals[$c['key']] += (int) ($s['values'][$c['key']] ?? 0);
        }
    }
    $max = chart_nice_max($totals ? max($totals) : 0);
    $y = fn(float $v): float => $top + $plotH - ($v / $max) * $plotH;

    $n = max(1, count($columns));
    $band = $plotW / $n;
    $barW = min(24, $band * 0.56);
    $plural = fn(int $v) => $v . ' ' . $unit . ($v === 1 ? '' : 's');

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . htmlspecialchars($caption) . '">';

    // Gridlines + y-axis labels: 0, half, max (whole numbers only).
    $ticks = array_unique([0, $max % 2 === 0 ? intdiv($max, 2) : null, $max]);
    foreach (array_filter($ticks, fn($t) => $t !== null) as $t) {
        $ty = round($y($t), 1);
        $svg .= '<line x1="' . $left . '" x2="' . ($width - $right) . '" y1="' . $ty . '" y2="' . $ty . '" class="' . ($t === 0 ? 'chart-baseline' : 'chart-grid') . '"/>';
        $svg .= '<text x="' . ($left - 8) . '" y="' . ($ty + 4) . '" class="chart-tick" text-anchor="end">' . $t . '</text>';
    }

    foreach (array_values($columns) as $i => $c) {
        $x = round($left + $band * $i + ($band - $barW) / 2, 1);
        $cx = round($left + $band * $i + $band / 2, 1);
        $base = $top + $plotH;

        // Segments bottom-up, 2px surface gap between them; rounded top on the last one.
        $segments = array_values(array_filter($series, fn($s) => (int) ($s['values'][$c['key']] ?? 0) > 0));
        foreach ($segments as $j => $s) {
            $v = (int) $s['values'][$c['key']];
            $h = ($v / $max) * $plotH;
            $yTop = $base - $h;
            $isTop = $j === count($segments) - 1;
            $drawH = max(1, $h - ($isTop ? 0 : 2)); // gap above lower segments
            $tip = $c['title'] . ' · ' . $s['label'] . ': ' . $plural($v);
            $attrs = ' fill="' . htmlspecialchars($s['color']) . '" class="chart-mark" tabindex="0" data-tip="' . htmlspecialchars($tip) . '"';
            if ($isTop && $drawH > 4) {
                $r = 4;
                $x1 = $x;
                $x2 = round($x + $barW, 1);
                $yb = round($yTop + $drawH, 1);
                $yt = round($yTop, 1);
                $svg .= '<path d="M' . $x1 . ' ' . $yb . ' V' . ($yt + $r) . ' Q' . $x1 . ' ' . $yt . ' ' . ($x1 + $r) . ' ' . $yt
                    . ' H' . ($x2 - $r) . ' Q' . $x2 . ' ' . $yt . ' ' . $x2 . ' ' . ($yt + $r) . ' V' . $yb . ' Z"' . $attrs . '><title>' . htmlspecialchars($tip) . '</title></path>';
            } else {
                $svg .= '<rect x="' . $x . '" y="' . round($yTop, 1) . '" width="' . round($barW, 1) . '" height="' . round($drawH, 1) . '"' . $attrs . '><title>' . htmlspecialchars($tip) . '</title></rect>';
            }
            $base = $yTop;
        }

        // Total on the cap (also what a printout shows).
        if ($totals[$c['key']] > 0) {
            $svg .= '<text x="' . $cx . '" y="' . round($y($totals[$c['key']]) - 6, 1) . '" class="chart-value" text-anchor="middle">' . $totals[$c['key']] . '</text>';
        }
        $svg .= '<text x="' . $cx . '" y="' . ($height - 10) . '" class="chart-label' . (!empty($c['current']) ? ' is-current' : '') . '" text-anchor="middle">'
            . htmlspecialchars($c['label']) . '</text>';
    }

    return $svg . '</svg>';
}

/**
 * Diverging bar for 1-5 ratings: Needs Improvement/Fair to the left of a center line,
 * Excellent/Very Satisfactory to the right, Satisfactory straddling it.
 * @param array $distribution [5 => n, 4 => n, 3 => n, 2 => n, 1 => n]
 */
function chart_rating_bar(array $distribution): string
{
    $total = array_sum($distribution);
    if ($total === 0) {
        return '<div class="rating-bar rating-bar-empty"><span class="rating-center"></span></div>';
    }
    $share = fn(int $r) => ($distribution[$r] ?? 0) / $total;
    $negative = $share(1) + $share(2) + $share(3) / 2;
    $html = '<div class="rating-bar"><span class="rating-center"></span>'
        . '<div class="rating-stack" style="left:' . round(50 - $negative * 50, 2) . '%;width:' . round(50, 2) . '%">';
    foreach ([1, 2, 3, 4, 5] as $r) {
        $n = (int) ($distribution[$r] ?? 0);
        if ($n === 0) continue;
        $tip = RATING_SCALE[$r] . ' (' . $r . '): ' . $n . ' · ' . round($n / $total * 100) . '%';
        $html .= '<span class="rating-seg" tabindex="0" style="flex-grow:' . $n . ';background:' . RATING_COLORS[$r] . '" data-tip="' . htmlspecialchars($tip) . '" title="' . htmlspecialchars($tip) . '"></span>';
    }
    return $html . '</div></div>';
}
