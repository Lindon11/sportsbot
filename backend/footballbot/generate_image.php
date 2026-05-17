<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function fb_font_candidates(array $config, string $weight): array
{
    $configured = $weight === 'bold' ? $config['images']['font_bold'] : $config['images']['font_regular'];
    $fontDir = $config['paths']['fonts'];
    $local = glob($fontDir . '/*.{ttf,otf,ttc}', GLOB_BRACE) ?: [];

    $candidates = array_merge(
        [$configured],
        $local,
        [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
        ]
    );

    return array_values(array_filter(
        $candidates,
        static fn ($path) => is_string($path) && $path !== '' && is_file($path)
    ));
}

function fb_font(array $config, string $weight = 'regular'): ?string
{
    $candidates = fb_font_candidates($config, $weight);
    return $candidates[0] ?? null;
}

function fb_color(GdImage $image, int $red, int $green, int $blue, int $alpha = 0): int
{
    return imagecolorallocatealpha($image, $red, $green, $blue, max(0, min(127, $alpha)));
}

function fb_rounded_rectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    $width = imagesx($image);
    $height = imagesy($image);
    $rgba = imagecolorsforindex($image, $color);
    $layer = imagecreatetruecolor($width, $height);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);
    imagefilledrectangle($layer, 0, 0, $width, $height, imagecolorallocatealpha($layer, 0, 0, 0, 127));
    $layerColor = imagecolorallocatealpha($layer, (int)$rgba['red'], (int)$rgba['green'], (int)$rgba['blue'], (int)$rgba['alpha']);
    imagefilledrectangle($layer, $x1 + $radius, $y1, $x2 - $radius, $y2, $layerColor);
    imagefilledrectangle($layer, $x1, $y1 + $radius, $x2, $y2 - $radius, $layerColor);
    imagefilledellipse($layer, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $layerColor);
    imagefilledellipse($layer, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $layerColor);
    imagefilledellipse($layer, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $layerColor);
    imagefilledellipse($layer, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $layerColor);
    imagealphablending($image, true);
    imagecopy($image, $layer, 0, 0, 0, 0, $width, $height);
    imagedestroy($layer);
}

function fb_rounded_rectangle_border(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color, int $thickness = 2): void
{
    $width = imagesx($image);
    $height = imagesy($image);
    $rgba = imagecolorsforindex($image, $color);
    $layer = imagecreatetruecolor($width, $height);
    imagealphablending($layer, false);
    imagesavealpha($layer, true);
    imagefilledrectangle($layer, 0, 0, $width, $height, imagecolorallocatealpha($layer, 0, 0, 0, 127));
    $c = imagecolorallocatealpha($layer, (int)$rgba['red'], (int)$rgba['green'], (int)$rgba['blue'], (int)$rgba['alpha']);
    // Outer filled
    imagefilledrectangle($layer, $x1 + $radius, $y1, $x2 - $radius, $y2, $c);
    imagefilledrectangle($layer, $x1, $y1 + $radius, $x2, $y2 - $radius, $c);
    imagefilledellipse($layer, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $c);
    imagefilledellipse($layer, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $c);
    imagefilledellipse($layer, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $c);
    imagefilledellipse($layer, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $c);
    // Inner cutout
    $inner = imagecolorallocatealpha($layer, 0, 0, 0, 127);
    $ix1 = $x1 + $thickness; $iy1 = $y1 + $thickness; $ix2 = $x2 - $thickness; $iy2 = $y2 - $thickness;
    $ir = max(1, $radius - $thickness);
    imagefilledrectangle($layer, $ix1 + $ir, $iy1, $ix2 - $ir, $iy2, $inner);
    imagefilledrectangle($layer, $ix1, $iy1 + $ir, $ix2, $iy2 - $ir, $inner);
    imagefilledellipse($layer, $ix1 + $ir, $iy1 + $ir, $ir * 2, $ir * 2, $inner);
    imagefilledellipse($layer, $ix2 - $ir, $iy1 + $ir, $ir * 2, $ir * 2, $inner);
    imagefilledellipse($layer, $ix1 + $ir, $iy2 - $ir, $ir * 2, $ir * 2, $inner);
    imagefilledellipse($layer, $ix2 - $ir, $iy2 - $ir, $ir * 2, $ir * 2, $inner);
    imagealphablending($image, true);
    imagecopy($image, $layer, 0, 0, 0, 0, $width, $height);
    imagedestroy($layer);
}

function fb_draw_glow(GdImage $image, int $cx, int $cy, int $radius, array $rgb, int $intensity = 60): void
{
    // Subtle broadcast glow only. The old version filled hundreds of
    // translucent circles, which built up into the huge visible blobs.
    $max = max(6, min($radius, 220));
    imagesetthickness($image, 2);
    for ($r = $max; $r > 0; $r -= 14) {
        $fade = $r / $max;
        $alpha = 124 - (int)min(18, max(2, ($intensity / 4) * $fade));
        imageellipse($image, $cx, $cy, $r * 2, $r * 2, fb_color($image, $rgb[0], $rgb[1], $rgb[2], $alpha));
    }
    imagesetthickness($image, 1);
}

function fb_text_box(?string $font, int $size, string $text): array
{
    if ($font && function_exists('imagettfbbox')) {
        $box = imagettfbbox($size, 0, $font, $text);
        if (is_array($box)) {
            return ['width' => abs($box[2] - $box[0]), 'height' => abs($box[7] - $box[1])];
        }
    }
    return ['width' => imagefontwidth(5) * strlen($text), 'height' => imagefontheight(5)];
}

function fb_draw_text(
    GdImage $image, string $text, int $x, int $y, int $size, int $color,
    ?string $font, string $anchor = 'left', ?int $maxWidth = null, bool $shadow = true
): int {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($maxWidth !== null && $maxWidth > 0) {
        while ($size > 8 && fb_text_box($font, $size, $text)['width'] > $maxWidth) { $size--; }
    }
    $box = fb_text_box($font, $size, $text);
    $drawX = match ($anchor) {
        'center' => $x - (int)round($box['width'] / 2),
        'right' => $x - $box['width'],
        default => $x,
    };
    if ($font && function_exists('imagettftext')) {
        if ($shadow) {
            imagettftext($image, $size, 0, $drawX + 2, $y + 2, fb_color($image, 0, 0, 0, 100), $font, $text);
        }
        imagettftext($image, $size, 0, $drawX, $y, $color, $font, $text);
    } else {
        if ($shadow) { imagestring($image, 5, $drawX + 2, $y + 2, $text, fb_color($image, 0, 0, 0, 100)); }
        imagestring($image, 5, $drawX, $y, $text, $color);
    }
    return $size;
}

function fb_draw_multiline(GdImage $image, array $lines, int $x, int $y, int $size, int $lineHeight, int $color, ?string $font, string $anchor, int $maxWidth): void
{
    foreach ($lines as $line) {
        if (trim((string)$line) === '') continue;
        fb_draw_text($image, (string)$line, $x, $y, $size, $color, $font, $anchor, $maxWidth);
        $y += $lineHeight;
    }
}

function fb_load_image_asset(array $config, string $url): ?GdImage
{
    if ($url === '') return null;
    $path = fb_download_asset($config, $url);
    if ($path === null || !is_file($path)) return null;
    $data = file_get_contents($path);
    if ($data === false) return null;
    $image = @imagecreatefromstring($data);
    return $image instanceof GdImage ? $image : null;
}

function fb_draw_badge(GdImage $image, array $config, string $url, string $teamName, int $centerX, int $centerY, int $size): void
{
    // Outer glow
    fb_draw_glow($image, $centerX, $centerY, $size + 18, [20, 255, 180], 18);
    // Dark circle background rgba(255,255,255,0.06)
    imagefilledellipse($image, $centerX, $centerY, $size + 4, $size + 4, fb_color($image, 255, 255, 255, 119));
    // Green border ring rgba(20,255,180,0.35) — 2px
    imagefilledellipse($image, $centerX, $centerY, $size + 4, $size + 4, fb_color($image, 20, 255, 180, 45));
    // Inner dark circle
    imagefilledellipse($image, $centerX, $centerY, $size, $size, fb_color($image, 4, 16, 38, 50));

    $badge = fb_load_image_asset($config, $url);
    if ($badge instanceof GdImage) {
        $sourceWidth = imagesx($badge);
        $sourceHeight = imagesy($badge);
        $innerSize = $size - 8;
        $scale = min($innerSize / max(1, $sourceWidth), $innerSize / max(1, $sourceHeight));
        $targetWidth = (int)round($sourceWidth * $scale);
        $targetHeight = (int)round($sourceHeight * $scale);
        imagecopyresampled($image, $badge, $centerX - (int)round($targetWidth / 2), $centerY - (int)round($targetHeight / 2), 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($badge);
        return;
    }
    $font = fb_font($config, 'bold');
    $initials = fb_team_initials($teamName);
    fb_draw_text($image, $initials, $centerX, $centerY + 20, 44, fb_color($image, 255, 255, 255), $font, 'center', $size);
}

function fb_team_initials(string $teamName): string
{
    $words = preg_split('/\s+/', trim($teamName)) ?: [];
    $initials = '';
    foreach ($words as $word) {
        if ($word === '') continue;
        $initials .= strtoupper(substr($word, 0, 1));
        if (strlen($initials) >= 3) break;
    }
    return $initials !== '' ? $initials : 'FC';
}

function fb_draw_league_logo(GdImage $image, array $config, string $url, int $x, int $y, int $size): void
{
    $logo = fb_load_image_asset($config, $url);
    if (!$logo instanceof GdImage) return;
    $sourceWidth = imagesx($logo);
    $sourceHeight = imagesy($logo);
    $scale = min($size / max(1, $sourceWidth), $size / max(1, $sourceHeight));
    $targetWidth = (int)round($sourceWidth * $scale);
    $targetHeight = (int)round($sourceHeight * $scale);
    imagecopyresampled($image, $logo, $x, $y, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    imagedestroy($logo);
}

function fb_draw_player_thumb(GdImage $image, array $config, string $url, int $x, int $y, int $size): bool
{
    $player = fb_load_image_asset($config, $url);
    if (!$player instanceof GdImage) return false;
    fb_rounded_rectangle($image, $x - 4, $y - 4, $x + $size + 4, $y + $size + 4, 14, fb_color($image, 18, 242, 163, 80));
    fb_rounded_rectangle($image, $x, $y, $x + $size, $y + $size, 12, fb_color($image, 6, 18, 42, 50));
    $sourceWidth = imagesx($player);
    $sourceHeight = imagesy($player);
    $cropSize = min($sourceWidth, $sourceHeight);
    $sourceX = (int)round(($sourceWidth - $cropSize) / 2);
    $sourceY = (int)round(($sourceHeight - $cropSize) / 2);
    imagecopyresampled($image, $player, $x, $y, $sourceX, $sourceY, $size, $size, $cropSize, $cropSize);
    imagedestroy($player);
    return true;
}

function fb_alert_theme(string $type): array
{
    return match ($type) {
        'GOAL' => ['GOAL', [18, 242, 163], [0, 200, 120], [8, 121, 79]],
        'KICK_OFF' => ['KICK-OFF', [64, 173, 255], [30, 120, 220], [20, 95, 181]],
        'MATCH_START' => ['STARTED', [64, 173, 255], [30, 120, 220], [20, 95, 181]],
        'SCORE_UPDATE' => ['SCORE UPDATE', [18, 242, 163], [0, 200, 120], [8, 121, 79]],
        'PERIOD_CHANGE' => ['UPDATE', [100, 200, 255], [40, 140, 220], [30, 120, 180]],
        'HALF_TIME' => ['HALF-TIME', [255, 190, 75], [200, 140, 30], [191, 110, 25]],
        'FULL_TIME' => ['FULL-TIME', [255, 255, 255], [180, 190, 210], [95, 118, 150]],
        'RED_CARD' => ['RED CARD', [255, 74, 92], [200, 40, 55], [168, 22, 40]],
        'YELLOW_CARD' => ['YELLOW CARD', [255, 200, 50], [200, 155, 20], [191, 145, 10]],
        'SUBSTITUTION' => ['SUBSTITUTION', [100, 200, 255], [40, 140, 220], [30, 120, 180]],
        'MATCH_PREVIEW' => ['UPCOMING', [160, 140, 255], [110, 80, 220], [90, 60, 180]],
        'KICKOFF_REMINDER' => ['KICK-OFF SOON', [255, 200, 60], [220, 160, 20], [180, 130, 10]],
        default => [$type, [255, 255, 255], [180, 190, 210], [80, 110, 160]],
    };
}

function fb_minute_label(array $alert): string
{
    $minute = $alert['meta']['minute'] ?? $alert['match']['progress'] ?? null;
    if ($minute === null || $minute === '') return '';
    return ((int)$minute) . "'";
}

function fb_detail_lines(array $alert): array
{
    $meta = $alert['meta'];
    return match ($alert['type']) {
        'GOAL' => array_values(array_filter([
            ($meta['scorer'] ?? 'Scorer unavailable') . ' scores for ' . ($meta['team'] ?? 'their team'),
            !empty($meta['assist']) ? 'Assist: ' . $meta['assist'] : '',
        ])),
        'KICK_OFF' => ['Kick-off ' . (($meta['event_time'] ?? '') ?: 'now')],
        'MATCH_START' => [($alert['match']['sport'] ?? 'Match') . ' started', ($meta['event_time'] ?? '') ?: 'Live now'],
        'SCORE_UPDATE' => array_values(array_filter([
            'Score update',
            !empty($meta['previous_score']) ? 'Previously ' . $meta['previous_score'] : '',
            $alert['match']['league_name'] ?? '',
        ])),
        'PERIOD_CHANGE' => array_values(array_filter([
            'Status: ' . (($meta['status'] ?? '') ?: ($alert['match']['status'] ?? 'Live')),
            $alert['match']['league_name'] ?? '',
        ])),
        'HALF_TIME' => ['Half-time at the interval'],
        'FULL_TIME' => fb_full_time_lines($alert),
        'RED_CARD' => [($meta['player'] ?? 'Player unavailable') . ' sent off', $meta['team'] ?? ''],
        'YELLOW_CARD' => [($meta['player'] ?? 'Player unavailable') . ' booked', $meta['team'] ?? ''],
        'SUBSTITUTION' => array_values(array_filter([
            ($meta['player_on'] ?? 'Player on') . ' comes on',
            !empty($meta['player_off']) ? 'Replacing ' . $meta['player_off'] : '',
            $meta['team'] ?? '',
        ])),
        'MATCH_PREVIEW' => array_values(array_filter([
            ($meta['event_time'] ?? '') ?: 'Kick-off time TBC',
            !empty($meta['tv_channels']) ? 'TV: ' . implode(', ', array_slice((array) $meta['tv_channels'], 0, 3)) : '',
            $meta['league_name'] ?? '',
        ])),
        'KICKOFF_REMINDER' => array_values(array_filter([
            sprintf('Kick-off in %d minutes', (int) ($meta['minutes_until'] ?? 10)),
            ($meta['event_time'] ?? '') ?: '',
            !empty($meta['tv_channels']) ? 'TV: ' . implode(', ', array_slice((array) $meta['tv_channels'], 0, 3)) : '',
            $meta['league_name'] ?? '',
        ])),
        default => [],
    };
}

function fb_full_time_lines(array $alert): array
{
    $scorers = $alert['meta']['scorers'] ?? ['home' => [], 'away' => []];
    $lines = ['Final score'];
    if (!empty($scorers['home'])) $lines[] = $alert['match']['home_team'] . ': ' . implode(', ', array_slice($scorers['home'], 0, 4));
    if (!empty($scorers['away'])) $lines[] = $alert['match']['away_team'] . ': ' . implode(', ', array_slice($scorers['away'], 0, 4));
    return $lines;
}

function fb_load_background(array $config): ?GdImage
{
    // Try assets/bg.png first
    $bgPath = $config['paths']['assets'] . '/bg.png';
    if (is_file($bgPath)) {
        $data = file_get_contents($bgPath);
        if ($data !== false) {
            $img = @imagecreatefromstring($data);
            if ($img instanceof GdImage) return $img;
        }
    }
    return null;
}

function fb_render_engine(array $config): string
{
    $engine = strtolower((string) ($config['images']['render_engine'] ?? 'auto'));

    return in_array($engine, ['auto', 'puppeteer', 'gd'], true) ? $engine : 'auto';
}

function fb_render_allows_puppeteer(array $config): bool
{
    if (fb_render_engine($config) === 'gd') {
        return false;
    }

    $disabledPath = ($config['paths']['cache'] ?? sys_get_temp_dir()) . '/puppeteer-disabled-until';
    if (fb_render_engine($config) === 'auto' && is_file($disabledPath)) {
        $disabledUntil = (int) trim((string) @file_get_contents($disabledPath));
        if ($disabledUntil > time()) {
            return false;
        }
    }

    return true;
}

function fb_node_binary(): string
{
    $nodeBin = trim((string) exec('command -v node 2>/dev/null'));

    return $nodeBin !== '' && is_file($nodeBin) ? $nodeBin : '';
}

function fb_render_payload(array $config): array
{
    $userDataDir = (string) ($config['images']['render_user_data_dir'] ?? ($config['paths']['cache'] . '/chrome'));

    if ($userDataDir !== '' && !is_dir($userDataDir)) {
        @mkdir($userDataDir, 0775, true);
    }

    return [
        'engine' => fb_render_engine($config),
        'chromePath' => (string) ($config['images']['render_chrome_path'] ?? ''),
        'userDataDir' => $userDataDir,
        'extraArgs' => array_values(array_filter(array_map('strval', $config['images']['render_extra_args'] ?? []))),
    ];
}

function fb_render_note_puppeteer_failure(array $config): void
{
    if (fb_render_engine($config) !== 'auto') {
        return;
    }

    $cacheDir = (string) ($config['paths']['cache'] ?? '');
    if ($cacheDir === '') {
        return;
    }

    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }

    @file_put_contents($cacheDir . '/puppeteer-disabled-until', (string) (time() + 600), LOCK_EX);
}

function fb_render_note_puppeteer_success(array $config): void
{
    $path = ($config['paths']['cache'] ?? '') . '/puppeteer-disabled-until';
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function fb_generate_alert_image_puppeteer(array $config, array $alert): ?string
{
    if (!fb_render_allows_puppeteer($config)) {
        return null;
    }

    $nodeBin = fb_node_binary();
    if ($nodeBin === '') {
        return null;
    }

    $rendererPath = $config['app']['root'] . '/render_html.js';
    if (!is_file($rendererPath)) {
        return null;
    }

    $typeSlug = strtolower(str_replace('_', '-', $alert['type']));
    $eventId = preg_replace('/[^A-Za-z0-9_-]/', '', $alert['match']['event_id']);
    $outputPath = sprintf('%s/%s_%s_%s.png', $config['paths']['generated'], $typeSlug, $eventId, gmdate('YmdHis'));

    $payload = json_encode([
        'alert'     => $alert,
        'outputPath' => $outputPath,
        'assetsDir' => $config['paths']['assets'],
        'cacheDir'  => $config['paths']['image_cache'],
        'fontsDir'  => $config['paths']['fonts'],
        'render'    => fb_render_payload($config),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = escapeshellcmd($nodeBin) . ' ' . escapeshellarg($rendererPath);
    $process = proc_open($cmd, $descriptors, $pipes, $config['app']['root']);

    if (!is_resource($process)) {
        fb_log('warning', 'Puppeteer renderer: could not start node process');
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fb_log('warning', 'Puppeteer renderer failed', [
            'exitCode' => $exitCode,
            'stderr'   => trim($stderr),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        fb_log('warning', 'Puppeteer renderer produced no output', [
            'stdout' => trim($stdout),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fb_render_note_puppeteer_success($config);
    return $outputPath;
}

function fb_generate_alert_image(array $config, array $alert): string
{
    // Try Puppeteer renderer first (pixel-perfect CSS output)
    $puppeteerResult = fb_generate_alert_image_puppeteer($config, $alert);
    if ($puppeteerResult !== null) {
        return $puppeteerResult;
    }

    // Fallback to GD-based renderer
    fb_require_extensions(['gd']);

    $width = 1280;
    $height = 720;
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $bold = fb_font($config, 'bold');
    $regular = fb_font($config, 'regular');

    // === BACKGROUND: Load bg.png or fallback gradient ===
    $bg = fb_load_background($config);
    if ($bg instanceof GdImage) {
        // Resize to 1280x720
        $bgW = imagesx($bg);
        $bgH = imagesy($bg);
        imagecopyresampled($image, $bg, 0, 0, 0, 0, $width, $height, $bgW, $bgH);
        imagedestroy($bg);
    } else {
        // Fallback: dark gradient
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            imageline($image, 0, $y, $width, $y, fb_color($image, (int)(4 + 2 * (1 - $ratio)), (int)(12 + 6 * (1 - $ratio)), (int)(35 + 18 * (1 - $ratio))));
        }
    }

    // === DARK OVERLAY: linear-gradient(rgba(1,10,25,0.35), rgba(1,10,25,0.75)) — lighter for glassmorphic ===
    $overlay = imagecreatetruecolor($width, $height);
    imagealphablending($overlay, false);
    imagesavealpha($overlay, true);
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / max(1, $height - 1);
        $alpha = (int)(0.35 * 127 + (0.75 - 0.35) * $ratio * 127);
        imageline($overlay, 0, $y, $width, $y, imagecolorallocatealpha($overlay, 1, 10, 25, 127 - $alpha));
    }
    imagealphablending($image, true);
    imagecopy($image, $overlay, 0, 0, 0, 0, $width, $height);
    imagedestroy($overlay);

    // === COLORS ===
    [$headline, $accentRgb, $accentMidRgb, $accentDarkRgb] = fb_alert_theme($alert['type']);
    $white = fb_color($image, 255, 255, 255);
    $muted = fb_color($image, 160, 180, 210);
    $dimmed = fb_color($image, 100, 125, 165);
    $accent = fb_color($image, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $accentDark = fb_color($image, $accentDarkRgb[0], $accentDarkRgb[1], $accentDarkRgb[2]);
    $accentMid = fb_color($image, $accentMidRgb[0], $accentMidRgb[1], $accentMidRgb[2]);

    $match = $alert['match'];

    // === PANEL: inset 45px, rounded 28px ===
    // Panel shadow
    fb_rounded_rectangle($image, 42, 42, 1238, 678, 30, fb_color($image, 0, 0, 0, 100));
    // Panel background rgba(4, 16, 38, 0.78)
    fb_rounded_rectangle($image, 45, 45, 1235, 675, 28, fb_color($image, 4, 16, 38, 30));
    // Panel border rgba(20, 255, 180, 0.35)
    fb_rounded_rectangle_border($image, 45, 45, 1235, 675, 28, fb_color($image, 20, 255, 180, 45), 2);
    // Inner glow (subtle)
    fb_draw_glow($image, 640, 360, 160, $accentRgb, 4);

    // === TOP BAR: margin 26px, height 86px ===
    $barX1 = 45 + 26; // 71
    $barY1 = 45 + 26; // 71
    $barX2 = 1235 - 26; // 1209
    $barY2 = $barY1 + 86; // 157
    // Top bar background rgba(5, 20, 45, 0.85)
    fb_rounded_rectangle($image, $barX1, $barY1, $barX2, $barY2, 18, fb_color($image, 5, 20, 45, 19));
    // Top bar border rgba(255,255,255,0.08)
    fb_rounded_rectangle_border($image, $barX1, $barY1, $barX2, $barY2, 18, fb_color($image, 255, 255, 255, 10), 1);

    // === GOAL BADGE: left side, 340px wide ===
    $badgeX2 = $barX1 + 340; // 411
    // Badge gradient: accentDark to accentMid (simulate with two halves)
    fb_rounded_rectangle($image, $barX1, $barY1, $badgeX2, $barY2, 18, $accentDark);
    fb_rounded_rectangle($image, $barX1, $barY1, $badgeX2, $barY2 - 20, 18, $accentMid);
    // Badge glow
    fb_draw_glow($image, (int)(($barX1 + $badgeX2) / 2), (int)(($barY1 + $barY2) / 2), 120, $accentRgb, 20);
    // Badge text
    $headlineSize = strlen($headline) > 7 ? 30 : 34;
    fb_draw_text($image, $headline, (int)(($barX1 + $badgeX2) / 2), (int)(($barY1 + $barY2) / 2) + 14, $headlineSize, $white, $bold, 'center', 300, false);

    // === MINUTE PILL: right side ===
    $minute = fb_minute_label($alert);
    $pillX1 = $barX2 - 10;
    $pillX2 = $barX2 - 10;
    $pillY1 = $barY1 + 16;
    $pillY2 = $barY2 - 16;

    if ($minute !== '') {
        $minuteBox = fb_text_box($bold, 34, $minute);
        $pillW = max(80, $minuteBox['width'] + 56);
        $pillX1 = $barX2 - $pillW - 10;
    }

    // === LEAGUE NAME: centered in the remaining space ===
    $leagueX1 = $badgeX2 + 52;
    $leagueX2 = $minute !== '' ? $pillX1 - 34 : $barX2 - 34;
    $leagueCenter = (int)(($leagueX1 + max($leagueX1 + 220, $leagueX2)) / 2);
    $leagueMaxWidth = max(220, $leagueX2 - $leagueX1);
    fb_draw_text($image, $match['league_name'], $leagueCenter, (int)(($barY1 + $barY2) / 2) + 12, 34, $white, $bold, 'center', $leagueMaxWidth, false);

    if ($minute !== '') {
        fb_rounded_rectangle($image, $pillX1, $pillY1, $pillX2, $pillY2, 14, fb_color($image, 255, 255, 255, 16));
        fb_draw_text($image, $minute, (int)(($pillX1 + $pillX2) / 2), (int)(($pillY1 + $pillY2) / 2) + 12, 34, $white, $bold, 'center', 100, false);
    }

    // === SCORE AREA: grid layout ===
    // Badges at y=290 (center), size=180px diameter
    $badgeSize = 180;
    $homeBadgeX = 240;
    $awayBadgeX = 1040;
    $badgeY = 310;

    // === TEAM BADGES ===
    fb_draw_badge($image, $config, (string)$match['home_badge'], $match['home_team'], $homeBadgeX, $badgeY, $badgeSize);
    fb_draw_badge($image, $config, (string)$match['away_badge'], $match['away_team'], $awayBadgeX, $badgeY, $badgeSize);

    // === TEAM NAMES: below badges, 38px ===
    fb_draw_text($image, $match['home_team'], $homeBadgeX, $badgeY + 120, 38, $white, $bold, 'center', 310);
    fb_draw_text($image, $match['away_team'], $awayBadgeX, $badgeY + 120, 38, $white, $bold, 'center', 310);

    // === SCORE: center, 132px font ===
    // Score glow
    fb_draw_glow($image, 640, $badgeY - 10, 90, $accentRgb, 12);
    // Score text with shadow glow
    $scoreText = sprintf('%d - %d', $match['home_score'], $match['away_score']);
    // Draw score with text-shadow glow effect
    if ($bold && function_exists('imagettftext')) {
        // Outer glow layers
        for ($gi = 3; $gi >= 1; $gi--) {
            imagettftext($image, 132, 0, 640 - (int)round(fb_text_box($bold, 132, $scoreText)['width'] / 2), $badgeY + 40, fb_color($image, $accentRgb[0], $accentRgb[1], $accentRgb[2], 30 + $gi * 10), $bold, $scoreText);
        }
    }
    fb_draw_text($image, $scoreText, 640, $badgeY + 40, 132, $white, $bold, 'center', 500);

    // Dash in accent color — draw the " - " in green
    // We'll draw the full score in white, then overlay just the dash in accent color
    $dashBox = fb_text_box($bold, 132, ' - ');
    $scoreFullBox = fb_text_box($bold, 132, $scoreText);
    $dashStartX = 640 - (int)round($scoreFullBox['width'] / 2) + (int)round(fb_text_box($bold, 132, sprintf('%d ', $match['home_score']))['width']);
    // Draw dash in accent color
    if ($bold && function_exists('imagettftext')) {
        $homeNumBox = fb_text_box($bold, 132, (string)$match['home_score']);
        $fullScoreBox = fb_text_box($bold, 132, $scoreText);
        $scoreStartX = 640 - (int)round($fullScoreBox['width'] / 2);
        // Find position of " - " within the score string
        $beforeDash = sprintf('%d ', $match['home_score']);
        $beforeDashBox = fb_text_box($bold, 132, $beforeDash);
        $dashX = $scoreStartX + $beforeDashBox['width'];
        imagettftext($image, 132, 0, $dashX, $badgeY + 40, $accent, $bold, ' - ');
    }

    // === LIVE INDICATOR ===
    $liveY = $badgeY + 80;
    // Pulsing dot
    imagefilledellipse($image, 590, $liveY, 12, 12, $accent);
    fb_draw_glow($image, 590, $liveY, 16, $accentRgb, 12);
    $sportLabel = strtoupper((string) ($match['sport'] ?? 'SPORT'));
    fb_draw_text($image, 'LIVE ' . $sportLabel . ' ALERT', 640, $liveY + 8, 24, $accent, $bold, 'center', 440, false);

    // === INFO CARD: bottom ===
    $lines = fb_detail_lines($alert);
    $cardX1 = 150;
    $cardX2 = 1130;
    $cardY1 = 555;
    $cardY2 = 645;
    // Card shadow
    fb_rounded_rectangle($image, $cardX1 + 4, $cardY1 + 8, $cardX2 + 4, $cardY2 + 8, 22, fb_color($image, 0, 0, 0, 60));
    // Card background rgba(6, 18, 42, 0.86)
    fb_rounded_rectangle($image, $cardX1, $cardY1, $cardX2, $cardY2, 22, fb_color($image, 6, 18, 42, 18));
    // Card border rgba(255,255,255,0.12)
    fb_rounded_rectangle_border($image, $cardX1, $cardY1, $cardX2, $cardY2, 22, fb_color($image, 255, 255, 255, 15), 1);

    $detailX = 640;
    $detailMaxWidth = 900;

    // Player thumbnail for certain alert types
    if (!empty($alert['meta']['player_image']) && in_array($alert['type'], ['GOAL', 'RED_CARD', 'YELLOW_CARD'], true)) {
        if (fb_draw_player_thumb($image, $config, (string)$alert['meta']['player_image'], $cardX1 + 30, $cardY1 + 15, 60)) {
            $detailX = 680;
            $detailMaxWidth = 600;
        }
    }

    // Info icon (⚽) — draw a small accent circle as icon placeholder
    $iconX = $cardX1 + 42;
    $iconY = (int)(($cardY1 + $cardY2) / 2);
    imagefilledellipse($image, $iconX, $iconY, 16, 16, $accent);
    fb_draw_glow($image, $iconX, $iconY, 16, $accentRgb, 10);

    $lineCount = count($lines);
    $detailSize = $lineCount >= 3 ? 24 : 28;
    $detailLineHeight = $lineCount >= 3 ? 28 : 36;
    $detailStartY = $lineCount >= 3 ? $cardY1 + 30 : $cardY1 + 35;
    fb_draw_multiline($image, $lines, $detailX, $detailStartY, $detailSize, $detailLineHeight, $white, $regular, 'center', $detailMaxWidth);

    // === OUTPUT ===
    $typeSlug = strtolower(str_replace('_', '-', $alert['type']));
    $eventId = preg_replace('/[^A-Za-z0-9_-]/', '', $match['event_id']);
    $file = sprintf('%s/%s_%s_%s.png', $config['paths']['generated'], $typeSlug, $eventId, gmdate('YmdHis'));
    imagepng($image, $file, (int)$config['images']['quality']);
    imagedestroy($image);
    return $file;
}

function fb_sample_alerts(): array
{
    $baseMatch = [
        'event_id' => 'sample-ars-che',
        'sport' => 'Soccer',
        'league_id' => '4328',
        'league_name' => 'English Premier League',
        'home_team_id' => '133604',
        'away_team_id' => '133610',
        'home_team' => 'Arsenal',
        'away_team' => 'Chelsea',
        'home_badge' => '',
        'away_badge' => '',
        'home_score' => 2,
        'away_score' => 1,
        'status' => '2H',
        'progress' => 67,
        'event_time' => '15:00:00',
        'date_event' => gmdate('Y-m-d'),
        'updated' => fb_now(),
        'raw_hash' => 'sample',
        'league_logo' => '',
    ];

    return [
        fb_build_base_alert('GOAL', 'sample-goal', $baseMatch, [
            'side' => 'home', 'team' => 'Arsenal', 'scorer' => 'Bukayo Saka',
            'assist' => 'Martin Odegaard', 'minute' => 67,
        ]),
        fb_build_base_alert('KICK_OFF', 'sample-kickoff', array_merge($baseMatch, [
            'event_id' => 'sample-kickoff', 'home_score' => 0, 'away_score' => 0, 'progress' => 1,
        ]), ['minute' => 1, 'event_time' => '15:00:00']),
        fb_build_base_alert('MATCH_PREVIEW', 'sample-preview', array_merge($baseMatch, [
            'event_id' => 'sample-preview', 'home_score' => 0, 'away_score' => 0, 'progress' => 0, 'status' => 'NS',
        ]), ['minute' => 0, 'event_time' => '15:00:00', 'league_name' => 'English Premier League', 'tv_channels' => ['Sky Sports Main Event']]),
        fb_build_base_alert('HALF_TIME', 'sample-halftime', array_merge($baseMatch, [
            'event_id' => 'sample-halftime', 'home_score' => 1, 'away_score' => 1, 'progress' => 45, 'status' => 'HT',
        ]), ['minute' => 45, 'status' => 'Half-time']),
        fb_build_base_alert('FULL_TIME', 'sample-fulltime', array_merge($baseMatch, [
            'event_id' => 'sample-fulltime', 'home_score' => 2, 'away_score' => 1, 'progress' => 90, 'status' => 'FT',
        ]), ['minute' => 90, 'status' => 'Full-time', 'scorers' => [
            'home' => ["Bukayo Saka 24'", "Kai Havertz 67'"],
            'away' => ["Cole Palmer 51'"],
        ]]),
        fb_build_base_alert('RED_CARD', 'sample-redcard', array_merge($baseMatch, [
            'event_id' => 'sample-redcard', 'home_score' => 2, 'away_score' => 1, 'progress' => 76, 'status' => '2H',
        ]), ['side' => 'away', 'team' => 'Chelsea', 'player' => 'Enzo Fernandez', 'minute' => 76, 'detail' => 'Red card']),
        fb_build_base_alert('YELLOW_CARD', 'sample-yellowcard', array_merge($baseMatch, [
            'event_id' => 'sample-yellowcard', 'home_score' => 2, 'away_score' => 1, 'progress' => 58, 'status' => '2H',
        ]), ['side' => 'home', 'team' => 'Arsenal', 'player' => 'Declan Rice', 'minute' => 58, 'detail' => 'Yellow card']),
        fb_build_base_alert('SUBSTITUTION', 'sample-substitution', array_merge($baseMatch, [
            'event_id' => 'sample-substitution', 'home_score' => 2, 'away_score' => 1, 'progress' => 72, 'status' => '2H',
        ]), ['side' => 'home', 'team' => 'Arsenal', 'player_on' => 'Leandro Trossard', 'player_off' => 'Bukayo Saka', 'minute' => 72, 'detail' => 'Substitution']),
    ];
}

function fb_draw_tv_logo(GdImage $image, array $config, string $url, string $label, int $x, int $y, int $size): void
{
    $logo = fb_load_image_asset($config, $url);

    if ($logo instanceof GdImage) {
        $srcW = imagesx($logo);
        $srcH = imagesy($logo);
        $scale = min($size / max(1, $srcW), $size / max(1, $srcH));
        $drawW = max(1, (int) round($srcW * $scale));
        $drawH = max(1, (int) round($srcH * $scale));
        imagecopyresampled($image, $logo, $x + (int)(($size - $drawW) / 2), $y + (int)(($size - $drawH) / 2), 0, 0, $drawW, $drawH, $srcW, $srcH);
        imagedestroy($logo);
        return;
    }

    $bg = fb_color($image, 14, 39, 70, 0);
    $border = fb_color($image, 255, 255, 255, 85);
    imagefilledellipse($image, $x + (int)($size / 2), $y + (int)($size / 2), $size, $size, $bg);
    imageellipse($image, $x + (int)($size / 2), $y + (int)($size / 2), $size, $size, $border);
    fb_draw_text($image, fb_team_initials($label), $x + (int)($size / 2), $y + (int)($size / 2) + 10, 22, fb_color($image, 255, 255, 255), fb_font($config, 'bold'), 'center', $size - 12, false);
}

function fb_generate_tv_schedule_image_puppeteer(array $config, array $events, int $hoursAhead): ?string
{
    if (!fb_render_allows_puppeteer($config)) {
        return null;
    }

    $nodeBin = fb_node_binary();
    if ($nodeBin === '') {
        return null;
    }

    $rendererPath = $config['app']['root'] . '/render_html.js';
    if (!is_file($rendererPath)) {
        return null;
    }

    $outputPath = sprintf('%s/tv-schedule_%s.png', $config['paths']['generated'], gmdate('YmdHis'));

    $channels = fb_tv_channels($config);
    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $sports = array_filter($config['tv']['sports'] ?? []);
    $sportLabel = $sports !== [] ? implode(', ', array_slice($sports, 0, 5)) : (!empty($config['tv']['football_only']) ? 'Soccer' : 'All Sports');
    $dateLabel = $now->format('D j M') . ' - next ' . max(1, $hoursAhead) . ' hours';

    $payload = json_encode([
        'type'       => 'TV_SCHEDULE',
        'outputPath' => $outputPath,
        'assetsDir'  => $config['paths']['assets'],
        'cacheDir'   => $config['paths']['image_cache'],
        'fontsDir'   => $config['paths']['fonts'],
        'render'     => fb_render_payload($config),
        'tvSchedule' => [
            'channels'   => $channels,
            'events'     => $events,
            'dateLabel'  => $dateLabel,
            'sportLabel' => $sportLabel,
            'hoursAhead' => $hoursAhead,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = escapeshellcmd($nodeBin) . ' ' . escapeshellarg($rendererPath);
    $process = proc_open($cmd, $descriptors, $pipes, $config['app']['root']);

    if (!is_resource($process)) {
        fb_log('warning', 'Puppeteer TV schedule renderer: could not start node process');
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fb_log('warning', 'Puppeteer TV schedule renderer failed', [
            'exitCode' => $exitCode,
            'stderr'   => trim($stderr),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        fb_log('warning', 'Puppeteer TV schedule renderer produced no output', [
            'stdout' => trim($stdout),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fb_render_note_puppeteer_success($config);
    return $outputPath;
}

function fb_generate_tv_schedule_image(array $config, array $events, int $hoursAhead): string
{
    // Try Puppeteer renderer first (pixel-perfect CSS output)
    $puppeteerResult = fb_generate_tv_schedule_image_puppeteer($config, $events, $hoursAhead);
    if ($puppeteerResult !== null) {
        return $puppeteerResult;
    }

    // Fallback to GD-based renderer
    fb_require_extensions(['gd']);

    $width = 1280;
    $height = 720;
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $bold = fb_font($config, 'bold');
    $regular = fb_font($config, 'regular');
    $white = fb_color($image, 255, 255, 255);
    $muted = fb_color($image, 170, 190, 215);
    $accent = fb_color($image, 32, 210, 123);
    $panel = fb_color($image, 6, 18, 36, 10);
    $line = fb_color($image, 255, 255, 255, 95);

    $bg = fb_load_background($config);
    if ($bg instanceof GdImage) {
        imagecopyresampled($image, $bg, 0, 0, 0, 0, $width, $height, imagesx($bg), imagesy($bg));
        imagedestroy($bg);
    } else {
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            imageline($image, 0, $y, $width, $y, fb_color($image, (int)(5 + 5 * $ratio), (int)(14 + 12 * $ratio), (int)(30 + 25 * $ratio)));
        }
    }

    fb_rounded_rectangle($image, 42, 38, 1238, 682, 26, fb_color($image, 0, 0, 0, 70));
    fb_rounded_rectangle($image, 48, 44, 1232, 676, 24, $panel);
    fb_rounded_rectangle_border($image, 48, 44, 1232, 676, 24, fb_color($image, 32, 210, 123, 30), 2);

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    fb_draw_text($image, 'TV Sports Guide', 86, 105, 42, $white, $bold, 'left', 520, false);
    fb_draw_text($image, $now->format('D j M') . ' - next ' . max(1, $hoursAhead) . ' hours', 86, 142, 20, $muted, $regular, 'left', 520, false);

    $sports = array_filter($config['tv']['sports'] ?? []);
    $sportLabel = $sports !== [] ? implode(', ', array_slice($sports, 0, 5)) : (!empty($config['tv']['football_only']) ? 'Soccer' : 'All sports');
    fb_draw_text($image, $sportLabel, 1190, 128, 24, $accent, $bold, 'right', 440, false);

    $channels = fb_tv_channels($config);
    $byChannel = [];
    foreach ($events as $event) {
        $byChannel[$event['configured_channel_slug'] ?? $event['channel_slug']][] = $event;
    }

    $rowY = 185;
    $rowH = 112;
    $maxChannels = 4;
    $shown = 0;

    foreach ($channels as $channel) {
        if ($shown >= $maxChannels) {
            break;
        }

        $channelEvents = array_slice($byChannel[$channel['slug']] ?? [], 0, 3);
        $logo = (string) ($channelEvents[0]['channel_logo'] ?? '');
        fb_rounded_rectangle($image, 86, $rowY, 1194, $rowY + $rowH, 16, fb_color($image, 8, 24, 48, 18));
        fb_rounded_rectangle_border($image, 86, $rowY, 1194, $rowY + $rowH, 16, $line, 1);
        fb_draw_tv_logo($image, $config, $logo, $channel['label'], 108, $rowY + 24, 64);
        fb_draw_text($image, $channel['label'], 190, $rowY + 46, 24, $white, $bold, 'left', 260, false);

        if ($channelEvents === []) {
            fb_draw_text($image, 'No listed events', 500, $rowY + 64, 24, $muted, $regular, 'left', 560, false);
        } else {
            $textY = $rowY + 30;
            foreach ($channelEvents as $event) {
                $homeTeam = trim((string) ($event['home_team'] ?? ''));
                $awayTeam = trim((string) ($event['away_team'] ?? ''));
                $timeLabel = trim((string) ($event['time_label'] ?? ''));
                $sport = trim((string) ($event['sport'] ?? ''));
                $league = trim((string) ($event['league'] ?? ''));

                // Time label on the left
                if ($timeLabel !== '') {
                    fb_draw_text($image, $timeLabel, 500, $textY, 20, $accent, $bold, 'left', 70, false);
                }

                $eventStartX = 580;

                // Use fixture-style display when team data is available
                if ($homeTeam !== '' && $awayTeam !== '') {
                    $badgeSize = 20;
                    $badgeGap = 6;

                    // Home team badge + name
                    $homeBadge = trim((string) ($event['home_badge'] ?? ''));
                    if ($homeBadge !== '') {
                        fb_draw_tv_logo($image, $config, $homeBadge, $homeTeam, $eventStartX, $textY - (int)($badgeSize / 2) + 4, $badgeSize);
                        fb_draw_text($image, $homeTeam, $eventStartX + $badgeSize + $badgeGap, $textY, 20, $white, $bold, 'left', 200, false);
                    } else {
                        fb_draw_text($image, $homeTeam, $eventStartX, $textY, 20, $white, $bold, 'left', 240, false);
                    }

                    // "vs" separator
                    $homeWidth = max(fb_text_box($bold, 20, $homeTeam)['width'], $homeBadge !== '' ? 200 : 240);
                    $vsX = $eventStartX + $homeWidth + $badgeGap;
                    fb_draw_text($image, 'vs', $vsX, $textY, 16, $muted, $regular, 'left', 40, false);

                    // Away team badge + name
                    $awayStartX = $vsX + 36;
                    $awayBadge = trim((string) ($event['away_badge'] ?? ''));
                    if ($awayBadge !== '') {
                        fb_draw_tv_logo($image, $config, $awayBadge, $awayTeam, $awayStartX, $textY - (int)($badgeSize / 2) + 4, $badgeSize);
                        fb_draw_text($image, $awayTeam, $awayStartX + $badgeSize + $badgeGap, $textY, 20, $white, $bold, 'left', 200, false);
                    } else {
                        fb_draw_text($image, $awayTeam, $awayStartX, $textY, 20, $white, $bold, 'left', 240, false);
                    }
                } else {
                    // Fallback: flat text display
                    $eventName = trim((string) ($event['event'] ?? ''));
                    $lineText = trim(($sport !== '' ? $sport . ' - ' : '') . $eventName);
                    fb_draw_text($image, $lineText, $eventStartX, $textY, 22, $white, $regular, 'left', 620, false);
                }

                // League label on the right
                if ($league !== '') {
                    fb_draw_text($image, $league, 1160, $textY, 16, $muted, $regular, 'right', 200, false);
                }

                $textY += 28;
            }
        }

        $rowY += $rowH + 16;
        $shown++;
    }

    if ($channels === []) {
        fb_draw_text($image, 'No TV channels configured', 640, 380, 34, $muted, $bold, 'center', 700, false);
    }

    $outputPath = sprintf('%s/tv-schedule_%s.png', $config['paths']['generated'], gmdate('YmdHis'));
    imagepng($image, $outputPath, (int) $config['images']['quality']);
    imagedestroy($image);

    return $outputPath;
}

function fb_generate_daily_card_image_puppeteer(array $config, array $leagues): ?string
{
    if (!fb_render_allows_puppeteer($config)) {
        return null;
    }

    $nodeBin = fb_node_binary();
    if ($nodeBin === '') {
        return null;
    }

    $rendererPath = $config['app']['root'] . '/render_html.js';
    if (!is_file($rendererPath)) {
        return null;
    }

    $outputPath = sprintf('%s/daily-card_%s.png', $config['paths']['generated'], gmdate('YmdHis'));

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    $dateLabel = $now->format('D j M Y');

    $payload = json_encode([
        'type'       => 'DAILY_CARD',
        'outputPath' => $outputPath,
        'assetsDir'  => $config['paths']['assets'],
        'cacheDir'   => $config['paths']['image_cache'],
        'fontsDir'   => $config['paths']['fonts'],
        'render'     => fb_render_payload($config),
        'dailyCard'  => [
            'dateLabel' => $dateLabel,
            'leagues'   => $leagues,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = escapeshellcmd($nodeBin) . ' ' . escapeshellarg($rendererPath);
    $process = proc_open($cmd, $descriptors, $pipes, $config['app']['root']);

    if (!is_resource($process)) {
        fb_log('warning', 'Puppeteer daily card renderer: could not start node process');
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fb_log('warning', 'Puppeteer daily card renderer failed', [
            'exitCode' => $exitCode,
            'stderr'   => trim($stderr),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        fb_log('warning', 'Puppeteer daily card renderer produced no output', [
            'stdout' => trim($stdout),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fb_render_note_puppeteer_success($config);
    return $outputPath;
}

function fb_generate_matchday_card_image_puppeteer(array $config, array $card): ?string
{
    if (!fb_render_allows_puppeteer($config)) {
        return null;
    }

    $nodeBin = fb_node_binary();
    if ($nodeBin === '') {
        return null;
    }

    $rendererPath = $config['app']['root'] . '/render_html.js';
    if (!is_file($rendererPath)) {
        return null;
    }

    $typeSlug = strtolower(str_replace('_', '-', (string) ($card['card_type'] ?? 'matchday-card')));
    $page = max(1, (int) ($card['page'] ?? 1));
    $outputPath = sprintf('%s/%s_p%d_%s.png', $config['paths']['generated'], $typeSlug, $page, gmdate('YmdHis'));

    fb_cache_matchday_card_assets($config, $card);

    $payload = json_encode([
        'type' => 'MATCHDAY_CARD',
        'outputPath' => $outputPath,
        'assetsDir' => $config['paths']['assets'],
        'cacheDir' => $config['paths']['image_cache'],
        'fontsDir' => $config['paths']['fonts'],
        'render' => fb_render_payload($config),
        'matchdayCard' => $card,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = escapeshellcmd($nodeBin) . ' ' . escapeshellarg($rendererPath);
    $process = proc_open($cmd, $descriptors, $pipes, $config['app']['root']);

    if (!is_resource($process)) {
        fb_log('warning', 'Puppeteer matchday card renderer: could not start node process');
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        fb_log('warning', 'Puppeteer matchday card renderer failed', [
            'exitCode' => $exitCode,
            'stderr' => trim($stderr),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    if (!is_file($outputPath) || filesize($outputPath) === 0) {
        fb_log('warning', 'Puppeteer matchday card renderer produced no output', [
            'stdout' => trim($stdout),
        ]);
        fb_render_note_puppeteer_failure($config);
        return null;
    }

    fb_render_note_puppeteer_success($config);
    return $outputPath;
}

function fb_generate_matchday_card_image(array $config, array $card): string
{
    $puppeteerResult = fb_generate_matchday_card_image_puppeteer($config, $card);
    if ($puppeteerResult !== null) {
        return $puppeteerResult;
    }

    if (($card['kind'] ?? '') === 'match' && isset($card['match']) && is_array($card['match'])) {
        $section = is_array($card['section'] ?? null) ? $card['section'] : [];
        $match = $card['match'];
        $leagues = [[
            'league_name' => $section['title'] ?? ($match['league_name'] ?? 'Match'),
            'sport' => $section['sport'] ?? ($match['sport'] ?? ''),
            'league_logo' => $section['logo'] ?? ($match['league_logo'] ?? ''),
            'matches' => [$match],
        ]];

        return fb_generate_daily_card_image($config, $leagues);
    }

    if (($card['kind'] ?? '') === 'matches') {
        $leagues = array_map(static function (array $section): array {
            return [
                'league_name' => $section['title'] ?? 'Sport',
                'sport' => $section['sport'] ?? ($section['subtitle'] ?? ''),
                'league_logo' => $section['logo'] ?? '',
                'matches' => $section['matches'] ?? [],
            ];
        }, $card['sections'] ?? []);

        return fb_generate_daily_card_image($config, $leagues);
    }

    return fb_generate_daily_card_image($config, []);
}

function fb_generate_daily_card_image(array $config, array $leagues): string
{
    $puppeteerResult = fb_generate_daily_card_image_puppeteer($config, $leagues);
    if ($puppeteerResult !== null) {
        return $puppeteerResult;
    }

    fb_require_extensions(['gd']);

    $width = 1280;
    $height = 720;
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, true);
    imagesavealpha($image, true);

    $bold = fb_font($config, 'bold');
    $regular = fb_font($config, 'regular');
    $white = fb_color($image, 255, 255, 255);
    $muted = fb_color($image, 170, 190, 215);
    $accent = fb_color($image, 32, 210, 123);
    $line = fb_color($image, 255, 255, 255, 95);

    $bg = fb_load_background($config);
    if ($bg instanceof GdImage) {
        imagecopyresampled($image, $bg, 0, 0, 0, 0, $width, $height, imagesx($bg), imagesy($bg));
        imagedestroy($bg);
    } else {
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / max(1, $height - 1);
            imageline($image, 0, $y, $width, $y, fb_color($image, (int)(5 + 5 * $ratio), (int)(14 + 12 * $ratio), (int)(30 + 25 * $ratio)));
        }
    }

    fb_rounded_rectangle($image, 42, 38, 1238, 682, 26, fb_color($image, 0, 0, 0, 70));
    fb_rounded_rectangle($image, 48, 44, 1232, 676, 24, fb_color($image, 6, 18, 36, 10));
    fb_rounded_rectangle_border($image, 48, 44, 1232, 676, 24, fb_color($image, 32, 210, 123, 30), 2);

    $tz = new DateTimeZone($config['app']['timezone']);
    $now = new DateTimeImmutable('now', $tz);
    fb_draw_text($image, "Today's Matches", 86, 105, 42, $white, $bold, 'left', 520, false);
    fb_draw_text($image, $now->format('D j M Y'), 86, 142, 20, $muted, $regular, 'left', 520, false);

    $totalMatches = array_sum(array_map(static fn(array $l): int => count($l['matches']), $leagues));
    fb_draw_text($image, sprintf('%d matches', $totalMatches), 1190, 128, 24, $accent, $bold, 'right', 440, false);
    $sports = array_values(array_unique(array_filter(array_map(static fn(array $l): string => (string) ($l['sport'] ?? ''), $leagues))));

    $rowY = 185;
    $rowH = 112;
    $maxLeagues = 4;
    $shown = 0;

    foreach ($leagues as $league) {
        if ($shown >= $maxLeagues) {
            break;
        }

        $matches = array_slice($league['matches'], 0, 3);
        fb_rounded_rectangle($image, 86, $rowY, 1194, $rowY + $rowH, 16, fb_color($image, 8, 24, 48, 18));
        fb_rounded_rectangle_border($image, 86, $rowY, 1194, $rowY + $rowH, 16, $line, 1);

        $leagueLogo = (string) ($league['league_logo'] ?? '');
        if ($leagueLogo !== '') {
            fb_draw_tv_logo($image, $config, $leagueLogo, $league['league_name'], 108, $rowY + 24, 64);
        } else {
            imagefilledellipse($image, 140, $rowY + 56, 64, 64, fb_color($image, 32, 210, 123, 40));
            fb_draw_text($image, fb_team_initials($league['league_name']), 140, $rowY + 62, 22, $white, $bold, 'center', 60, false);
        }

        $leagueLabel = count($sports) > 1 && !empty($league['sport'])
            ? $league['sport'] . ' - ' . $league['league_name']
            : $league['league_name'];
        fb_draw_text($image, $leagueLabel, 190, $rowY + 46, 24, $white, $bold, 'left', 300, false);

        if ($matches === []) {
            fb_draw_text($image, 'No matches today', 500, $rowY + 64, 24, $muted, $regular, 'left', 560, false);
        } else {
            $textY = $rowY + 30;
            foreach ($matches as $match) {
                $time = (string) ($match['event_time'] ?? '');
                $timeShort = $time !== '' ? substr($time, 0, 5) : 'TBC';

                if ($timeShort !== '') {
                    fb_draw_text($image, $timeShort, 500, $textY, 20, $accent, $bold, 'left', 70, false);
                }

                $homeTeam = (string) ($match['home_team'] ?? 'Home');
                $awayTeam = (string) ($match['away_team'] ?? 'Away');
                fb_draw_text($image, $homeTeam, 580, $textY, 20, $white, $bold, 'left', 240, false);
                fb_draw_text($image, 'vs', 830, $textY, 16, $muted, $regular, 'left', 40, false);
                fb_draw_text($image, $awayTeam, 870, $textY, 20, $white, $bold, 'left', 240, false);

                $textY += 28;
            }
        }

        $rowY += $rowH + 16;
        $shown++;
    }

    if ($leagues === []) {
        fb_draw_text($image, 'No matches today', 640, 380, 34, $muted, $bold, 'center', 700, false);
    }

    $outputPath = sprintf('%s/daily-card_%s.png', $config['paths']['generated'], gmdate('YmdHis'));
    imagepng($image, $outputPath, (int) $config['images']['quality']);
    imagedestroy($image);

    return $outputPath;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $config = fb_config();
    fb_ensure_directories($config);

    if (in_array('--sample', $argv, true)) {
        foreach (fb_sample_alerts() as $alert) {
            $path = fb_generate_alert_image($config, $alert);
            $samplePath = $config['paths']['generated'] . '/sample_' . basename($path);
            rename($path, $samplePath);
            echo $samplePath . PHP_EOL;
        }
        exit(0);
    }

    fwrite(STDERR, "Usage: php generate_image.php --sample\n");
    exit(1);
}
