<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\SportsBotSports;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SportsBotCardRenderer
{
    private int $width;
    private int $height;
    private string $fontRegular;
    private string $fontBold;

    public function __construct()
    {
        $this->width = (int) config('plugins.SportsBot.cards.width', 1200);
        $this->height = (int) config('plugins.SportsBot.cards.height', 675);
        $this->fontRegular = $this->findFont(false);
        $this->fontBold = $this->findFont(true);
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function fixtureCard(array $fixture): array
    {
        return $this->render('fixture', function ($image, array $c) use ($fixture): void {
            $sport = (string) ($fixture['sport'] ?? $fixture['strSport'] ?? 'Sports');
            $this->header($image, $c, SportsBotSports::icon($sport) . ' Fixture', (string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Competition TBC'));
            $this->versusBlock($image, $c, $fixture, 'VS');
            $this->pill($image, $c, 72, 560, (string) ($fixture['kickoff_label'] ?? $fixture['dateEvent'] ?? 'Kickoff TBC'), [20, 184, 166]);
            $this->muted($image, (string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Venue TBC'), 72, 626, 20);
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function liveMatchCard(array $match): array
    {
        return $this->render('live', function ($image, array $c) use ($match): void {
            $sport = (string) ($match['sport'] ?? $match['strSport'] ?? 'Sports');
            $this->header($image, $c, SportsBotSports::icon($sport) . ' Live Now', (string) ($match['league'] ?? $match['strLeague'] ?? 'Live score'));
            $score = trim((string) ($match['home_score'] ?? $match['intHomeScore'] ?? '')) . ' - ' . trim((string) ($match['away_score'] ?? $match['intAwayScore'] ?? ''));
            $this->versusBlock($image, $c, $match, $score !== ' - ' ? $score : 'LIVE');
            $this->pill($image, $c, 72, 560, (string) ($match['progress'] ?? $match['strProgress'] ?? $match['strStatus'] ?? 'In progress'), [239, 68, 68]);
            $this->muted($image, 'Tap for stats, timeline, lineups and TV channels', 72, 626, 20);
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function tvGuideCard(array $summary): array
    {
        return $this->render('tv-guide', function ($image, array $c) use ($summary): void {
            $this->header($image, $c, '📺 TV Guide', 'Top live sport on UK-first channels');

            $rows = $this->flattenRows((array) ($summary['grouped'] ?? $summary['events'] ?? []), 8);
            $y = 178;
            foreach ($rows as $row) {
                $sport = (string) ($row['sport'] ?? $row['strSport'] ?? 'Sports');
                $time = (string) ($row['time_label'] ?? $row['dateEvent'] ?? '');
                $title = (string) ($row['event'] ?? $row['strEvent'] ?? $row['name'] ?? 'Event TBC');
                $channel = (string) ($row['channel'] ?? $row['strChannel'] ?? 'Channel TBC');
                $this->row($image, $c, $y, SportsBotSports::icon($sport), $time, $title, $channel);
                $y += 56;
            }

            if ($rows === []) {
                $this->centerText($image, 'No TV events found', 42, 338, $c['muted'], true);
            }
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function leagueTableCard(array $league, array $table, int $page = 1): array
    {
        return $this->render('league-table', function ($image, array $c) use ($league, $table, $page): void {
            $this->header($image, $c, '🏆 League Table', (string) ($league['strLeague'] ?? $league['name'] ?? 'League'));
            $offset = max(0, ($page - 1) * 10);
            $rows = array_slice($table, $offset, 10);
            $y = 158;
            $this->small($image, '#', 74, $y, $c['muted'], true);
            $this->small($image, 'Team', 130, $y, $c['muted'], true);
            $this->small($image, 'P', 790, $y, $c['muted'], true);
            $this->small($image, 'GD', 880, $y, $c['muted'], true);
            $this->small($image, 'PTS', 990, $y, $c['muted'], true);
            $y += 38;

            foreach ($rows as $i => $row) {
                $this->tableRow($image, $c, $y, $offset + $i + 1, $row);
                $y += 42;
            }
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function teamProfileCard(array $team): array
    {
        return $this->render('team-profile', function ($image, array $c) use ($team): void {
            $sport = (string) ($team['strSport'] ?? 'Sports');
            $this->header($image, $c, SportsBotSports::icon($sport) . ' Team Page', (string) ($team['strLeague'] ?? $team['strCountry'] ?? 'Team profile'));
            $this->drawRemoteLogo($image, (string) ($team['strBadge'] ?? $team['strLogo'] ?? ''), 72, 210, 160);
            $this->text($image, (string) ($team['strTeam'] ?? 'Team TBC'), 270, 260, 44, $c['text'], true);
            $this->muted($image, (string) ($team['strStadium'] ?? 'Venue TBC'), 270, 322, 24);
            $this->muted($image, trim((string) ($team['strDescriptionEN'] ?? '')), 72, 448, 20, 128);
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function topScorersCard(array $league, array $rows): array
    {
        return $this->render('top-scorers', function ($image, array $c) use ($league, $rows): void {
            $this->header($image, $c, '🎯 Top Scorers', (string) ($league['strLeague'] ?? $league['name'] ?? 'League'));
            $y = 174;
            foreach (array_slice($rows, 0, 10) as $i => $row) {
                $name = (string) ($row['strPlayer'] ?? $row['strName'] ?? $row['player'] ?? 'Player');
                $team = (string) ($row['strTeam'] ?? $row['team'] ?? '');
                $goals = (string) ($row['intGoals'] ?? $row['goals'] ?? $row['total'] ?? '-');
                $this->row($image, $c, $y, (string) ($i + 1), $goals . ' goals', $name, $team);
                $y += 48;
            }
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    public function breakingNewsCard(array $news): array
    {
        return $this->render('breaking-news', function ($image, array $c) use ($news): void {
            $this->header($image, $c, '📰 News & Transfers', (string) ($news['source'] ?? 'SportsBot'));
            $this->centerText($image, (string) ($news['title'] ?? 'Breaking sports update'), 48, 285, $c['text'], true);
            $this->centerText($image, (string) ($news['summary'] ?? 'More details will appear here as feeds are connected.'), 24, 365, $c['muted'], false);
        });
    }

    /**
     * @return array{path:string,type:string,width:int,height:int}
     */
    private function render(string $type, callable $draw): array
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is not available for SportsBot card rendering.');
        }

        $image = imagecreatetruecolor($this->width, $this->height);
        if (!$image) {
            throw new RuntimeException('Could not create SportsBot card canvas.');
        }

        $c = $this->palette($image);
        imagefilledrectangle($image, 0, 0, $this->width, $this->height, $c['bg']);
        imagefilledrectangle($image, 0, 0, $this->width, 16, $c['accent']);
        imagefilledellipse($image, 1040, 110, 360, 360, $c['glow']);
        imagefilledellipse($image, 120, 590, 300, 300, $c['glow2']);

        $draw($image, $c);

        $dir = storage_path('app/sportsbot/cards');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create SportsBot card directory.');
        }

        $path = $dir . '/' . $type . '-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path, 8);
        imagedestroy($image);

        return ['path' => $path, 'type' => $type, 'width' => $this->width, 'height' => $this->height];
    }

    private function header($image, array $c, string $title, string $subtitle): void
    {
        $this->text($image, $title, 72, 76, 42, $c['text'], true);
        $this->muted($image, $subtitle, 74, 122, 22);
    }

    private function versusBlock($image, array $c, array $event, string $middle): void
    {
        $home = (string) ($event['home_team'] ?? $event['strHomeTeam'] ?? $event['strHome'] ?? 'Home');
        $away = (string) ($event['away_team'] ?? $event['strAwayTeam'] ?? $event['strAway'] ?? 'Away');
        $this->drawRemoteLogo($image, (string) ($event['home_badge'] ?? $event['strHomeTeamBadge'] ?? ''), 104, 224, 136);
        $this->drawRemoteLogo($image, (string) ($event['away_badge'] ?? $event['strAwayTeamBadge'] ?? ''), 960, 224, 136);
        $this->text($image, $this->fit($home, 24), 72, 422, 34, $c['text'], true);
        $this->rightText($image, $this->fit($away, 24), 1128, 422, 34, $c['text'], true);
        $this->centerText($image, $middle, 66, 330, $c['accentText'], true);
    }

    private function row($image, array $c, int $y, string $left, string $meta, string $title, string $right): void
    {
        imagefilledrectangle($image, 70, $y - 30, 1130, $y + 18, $c['panel']);
        $this->text($image, $left, 92, $y, 22, $c['accentText'], true);
        $this->small($image, $meta, 170, $y, $c['muted'], true);
        $this->text($image, $this->fit($title, 54), 310, $y, 22, $c['text'], true);
        $this->rightText($image, $this->fit($right, 26), 1100, $y, 18, $c['muted'], false);
    }

    private function tableRow($image, array $c, int $y, int $position, array $row): void
    {
        $team = (string) ($row['strTeam'] ?? $row['name'] ?? $row['team'] ?? 'Team');
        $played = (string) ($row['intPlayed'] ?? $row['played'] ?? $row['P'] ?? '-');
        $gd = (string) ($row['intGoalDifference'] ?? $row['goalDifference'] ?? $row['GD'] ?? '-');
        $points = (string) ($row['intPoints'] ?? $row['points'] ?? $row['PTS'] ?? '-');
        $this->small($image, (string) $position, 74, $y, $c['accentText'], true);
        $this->text($image, $this->fit($team, 42), 130, $y, 22, $c['text'], true);
        $this->small($image, $played, 790, $y, $c['text'], true);
        $this->small($image, $gd, 880, $y, $c['text'], true);
        $this->small($image, $points, 990, $y, $c['text'], true);
    }

    private function pill($image, array $c, int $x, int $y, string $text, array $rgb): void
    {
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, $x, $y - 30, $x + 420, $y + 12, $color);
        $this->text($image, $this->fit($text, 32), $x + 18, $y, 20, $c['bg'], true);
    }

    private function drawRemoteLogo($image, string $url, int $x, int $y, int $size): void
    {
        if ($url === '') {
            return;
        }

        try {
            $response = Http::timeout(5)->get($url);
            if (!$response->successful()) {
                return;
            }

            $logo = @imagecreatefromstring($response->body());
            if (!$logo) {
                return;
            }

            imagecopyresampled($image, $logo, $x, $y, 0, 0, $size, $size, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        } catch (Throwable $error) {
            Log::debug('sportsbot.card.logo_failed', ['url' => $url, 'error' => $error->getMessage()]);
        }
    }

    private function muted($image, string $text, int $x, int $y, int $size, int $limit = 88): void
    {
        $this->text($image, $this->fit($text, $limit), $x, $y, $size, $this->palette($image)['muted'], false);
    }

    private function small($image, string $text, int $x, int $y, int $color, bool $bold = false): void
    {
        $this->text($image, $text, $x, $y, 18, $color, $bold);
    }

    private function text($image, string $text, int $x, int $y, int $size, int $color, bool $bold = false): void
    {
        $font = $bold ? $this->fontBold : $this->fontRegular;
        if ($font !== '') {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
            return;
        }

        imagestring($image, 5, $x, $y - $size, $text, $color);
    }

    private function centerText($image, string $text, int $size, int $y, int $color, bool $bold = false): void
    {
        $x = (int) (($this->width - $this->textWidth($text, $size, $bold)) / 2);
        $this->text($image, $this->fit($text, 54), max(60, $x), $y, $size, $color, $bold);
    }

    private function rightText($image, string $text, int $right, int $y, int $size, int $color, bool $bold = false): void
    {
        $this->text($image, $text, $right - $this->textWidth($text, $size, $bold), $y, $size, $color, $bold);
    }

    private function textWidth(string $text, int $size, bool $bold = false): int
    {
        $font = $bold ? $this->fontBold : $this->fontRegular;
        if ($font !== '') {
            $box = imagettfbbox($size, 0, $font, $text);
            return abs(($box[2] ?? 0) - ($box[0] ?? 0));
        }

        return strlen($text) * imagefontwidth(5);
    }

    /**
     * @return array<string, int>
     */
    private function palette($image): array
    {
        return [
            'bg' => imagecolorallocate($image, 12, 18, 28),
            'panel' => imagecolorallocate($image, 25, 34, 49),
            'text' => imagecolorallocate($image, 241, 245, 249),
            'muted' => imagecolorallocate($image, 148, 163, 184),
            'accent' => imagecolorallocate($image, 20, 184, 166),
            'accentText' => imagecolorallocate($image, 94, 234, 212),
            'glow' => imagecolorallocate($image, 20, 83, 97),
            'glow2' => imagecolorallocate($image, 76, 29, 149),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flattenRows(array $rows, int $limit): array
    {
        $flat = [];
        foreach ($rows as $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $row) {
                    if (is_array($row)) {
                        $flat[] = $row;
                    }
                }
                continue;
            }

            if (is_array($value)) {
                $flat[] = $value;
            }
        }

        return array_slice($flat, 0, $limit);
    }

    private function fit(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…';
    }

    private function findFont(bool $bold): string
    {
        $candidates = $bold
            ? ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf']
            : ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
