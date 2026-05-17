<?php

namespace App\Plugins\SportsBot\Services;

use App\Plugins\SportsBot\Support\SportsBotSports;
use Illuminate\Support\Facades\Cache;
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
    public function fixtureCard(array $fixture, string $variant = 'v1'): array
    {
        $variant = $this->normalizeCardVariant($variant);

        return $this->render('fixture-' . $variant, function ($image, array $c) use ($fixture, $variant): void {
            $sport = (string) ($fixture['sport'] ?? $fixture['strSport'] ?? 'Sports');
            $normalizedSport = SportsBotSports::normalize($sport);
            if ($normalizedSport === 'fights') {
                if ($variant === 'v2') {
                    $this->fightFixtureCardV2($image, $fixture);
                } else {
                    $this->header($image, $c, SportsBotSports::icon($sport) . ' Fight Night', (string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Fights'));
                    $this->centerText($image, (string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? 'Fight event'), 42, 325, $c['text'], true);
                    $this->pill($image, $c, 72, 560, (string) ($fixture['kickoff_label'] ?? $fixture['dateEvent'] ?? 'Time TBC'), [20, 184, 166]);
                    $this->muted($image, (string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Venue TBC'), 72, 626, 20);
                }
                return;
            }

            if (in_array($normalizedSport, ['football', 'rugby'], true)) {
                if ($variant === 'v2') {
                    $this->footballFixtureCardV2($image, $fixture);
                } else {
                    if ($normalizedSport === 'football') {
                        $this->footballFixtureCardV1($image, $fixture);
                    } else {
                        $this->header($image, $c, SportsBotSports::icon($sport) . ' Fixture', (string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Competition TBC'));
                        $this->versusBlock($image, $c, $fixture, 'VS');
                        $this->pill($image, $c, 72, 560, (string) ($fixture['kickoff_label'] ?? $fixture['dateEvent'] ?? 'Kickoff TBC'), [20, 184, 166]);
                        $this->muted($image, (string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Venue TBC'), 72, 626, 20);
                    }
                }
                return;
            }

            if (in_array($normalizedSport, ['formula_1', 'motorsport'], true)) {
                $this->motorsportFixtureCard($image, $fixture);
                return;
            }

            $this->header($image, $c, SportsBotSports::icon($sport) . ' Fixture', (string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Competition TBC'));
            $this->versusBlock($image, $c, $fixture, 'VS');
            $this->pill($image, $c, 72, 560, (string) ($fixture['kickoff_label'] ?? $fixture['dateEvent'] ?? 'Kickoff TBC'), [20, 184, 166]);
            $this->muted($image, (string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Venue TBC'), 72, 626, 20);
        });
    }

    private function normalizeCardVariant(string $variant): string
    {
        return strtolower(trim($variant)) === 'v2' ? 'v2' : 'v1';
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

    /**
     * @param array<string, mixed> $fixture
     */
    private function footballFixtureCardV1($image, array $fixture): void
    {
        $white = imagecolorallocate($image, 248, 250, 252);
        $panel = imagecolorallocate($image, 255, 255, 255);
        $border = imagecolorallocate($image, 213, 218, 229);
        $navy = imagecolorallocate($image, 10, 28, 64);
        $purple = imagecolorallocate($image, 47, 21, 94);
        $softPurple = imagecolorallocate($image, 238, 233, 248);

        imagefilledrectangle($image, 0, 0, $this->width, $this->height, $white);
        imagefilledrectangle($image, 0, 0, $this->width, 122, $panel);
        imagefilledrectangle($image, 0, 0, 148, 122, $purple);
        imagefilledrectangle($image, 0, 122, $this->width, 126, $border);
        imagefilledrectangle($image, 0, 488, $this->width, 492, $border);
        imagefilledrectangle($image, 0, 650, $this->width, 675, $purple);
        imagefilledrectangle($image, 0, 126, $this->width, 488, $panel);
        imagefilledellipse($image, 65, 205, 295, 295, $softPurple);
        imagefilledellipse($image, 1145, 430, 330, 330, $softPurple);

        $league = trim((string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Competition TBC'));
        $home = trim((string) ($fixture['home_team'] ?? $fixture['strHomeTeam'] ?? 'Home'));
        $away = trim((string) ($fixture['away_team'] ?? $fixture['strAwayTeam'] ?? 'Away'));
        $date = $this->compactDateLabel(trim((string) ($fixture['date_label'] ?? $fixture['dateEvent'] ?? 'Date TBC')));
        $kickoff = trim((string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? 'Kickoff TBC'));
        $tv = trim((string) ($fixture['tv_channel'] ?? ''));
        $tv = $tv !== '' ? $tv : 'TBC';

        $this->textFitted($image, strtoupper($league), 220, 75, 820, 38, 24, $navy, true);
        $this->drawCalendarIcon($image, 72, 528, 104, $purple, $softPurple);
        $this->drawTvIcon($image, 690, 530, 116, $purple, $softPurple);
        imagefilledrectangle($image, 620, 520, 624, 640, $border);
        $this->text($image, 'DATE / KICKOFF', 215, 552, 22, $purple, true);
        $this->textFitted($image, strtoupper($date), 215, 588, 370, 27, 20, $navy, true);
        $this->textFitted($image, $kickoff, 215, 640, 370, 55, 34, $purple, true);
        $this->text($image, 'UK TV', 840, 552, 25, $navy, true);
        $tvY = 600;
        foreach ($this->fitTextLines(strtoupper($tv), 320, 35, true, 2) as $line) {
            $this->textFitted($image, $line, 840, $tvY, 320, 35, 24, $navy, true);
            $tvY += 44;
        }

        $homeLogo = (string) ($fixture['home_badge'] ?? $fixture['strHomeTeamBadge'] ?? '');
        $awayLogo = (string) ($fixture['away_badge'] ?? $fixture['strAwayTeamBadge'] ?? '');
        if (!$this->drawTeamLogoContain($image, $homeLogo, 290, 275, 250, 250, $purple)) {
            $this->teamPlaceholder($image, $home, 165, 150, 250, $purple, $panel);
        }
        if (!$this->drawTeamLogoContain($image, $awayLogo, 910, 275, 250, 250, $purple)) {
            $this->teamPlaceholder($image, $away, 785, 150, 250, $navy, $panel);
        }

        imagefilledrectangle($image, 540, 255, 543, 350, $border);
        imagefilledrectangle($image, 657, 255, 660, 350, $border);
        $this->centerFittedText($image, 'VS', 600, 330, 110, 66, 52, $navy, true);
        $this->centerFittedText($image, strtoupper($home), 290, 445, 410, 43, 28, $navy, true);
        $this->centerFittedText($image, strtoupper($away), 910, 445, 410, 43, 28, $navy, true);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function footballFixtureCardV2($image, array $fixture): void
    {
        $white = imagecolorallocate($image, 252, 253, 255);
        $panel = imagecolorallocate($image, 255, 255, 255);
        $border = imagecolorallocate($image, 220, 224, 235);
        $navy = imagecolorallocate($image, 8, 18, 40);
        $muted = imagecolorallocate($image, 91, 98, 118);
        $purple = imagecolorallocate($image, 55, 20, 94);
        $purple2 = imagecolorallocate($image, 88, 20, 125);
        $softPurple = imagecolorallocate($image, 242, 238, 250);
        $shadow = imagecolorallocate($image, 234, 235, 242);

        imagefilledrectangle($image, 0, 0, $this->width, $this->height, imagecolorallocate($image, 4, 6, 12));
        $this->roundedRect($image, 24, 18, 1176, 650, 38, $shadow);
        $this->roundedRect($image, 18, 12, 1170, 642, 38, $white);
        imagefilledrectangle($image, 18, 626, 1170, 642, $purple2);

        $this->filledPolygon($image, [[18, 12], [214, 12], [148, 642], [18, 642]], $purple);
        $this->filledPolygon($image, [[64, 12], [214, 12], [148, 642], [18, 642]], $purple2);
        $this->filledPolygon($image, [[975, 12], [1170, 12], [1170, 355], [860, 642], [805, 642], [1110, 12]], $softPurple);
        $this->filledPolygon($image, [[1080, 12], [1170, 12], [1170, 210], [925, 642], [880, 642]], imagecolorallocate($image, 247, 244, 253));

        $league = trim((string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Competition TBC'));
        $home = trim((string) ($fixture['home_team'] ?? $fixture['strHomeTeam'] ?? 'Home'));
        $away = trim((string) ($fixture['away_team'] ?? $fixture['strAwayTeam'] ?? 'Away'));
        $date = $this->compactDateLabel(trim((string) ($fixture['date_label'] ?? $fixture['dateEvent'] ?? 'Date TBC')));
        $kickoff = trim((string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? 'Kickoff TBC'));
        $venue = trim((string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Venue TBC'));
        $tv = trim((string) ($fixture['tv_channel'] ?? '')) ?: 'Not listed';

        $this->drawLeagueLogoMark($image, $fixture, 108, 96, 116, $panel, $purple);
        $this->textFitted($image, $this->displayTitle($this->shortLeagueName($league)), 258, 88, 620, 36, 22, $purple, true);

        $homeLogo = (string) ($fixture['home_badge'] ?? $fixture['strHomeTeamBadge'] ?? '');
        $awayLogo = (string) ($fixture['away_badge'] ?? $fixture['strAwayTeamBadge'] ?? '');
        if (!$this->drawTeamLogoContain($image, $homeLogo, 330, 278, 245, 245, $purple)) {
            $this->teamPlaceholder($image, $home, 205, 153, 250, $purple, $panel);
        }
        if (!$this->drawTeamLogoContain($image, $awayLogo, 890, 278, 245, 245, $purple)) {
            $this->teamPlaceholder($image, $away, 765, 153, 250, $navy, $panel);
        }

        imagefilledrectangle($image, 542, 265, 670, 268, $border);
        imagefilledrectangle($image, 542, 350, 670, 353, $border);
        $this->centerFittedText($image, 'VS', 606, 335, 150, 70, 46, $purple, true);

        $homeLines = $this->teamDisplayLines($home);
        $awayLines = $this->teamDisplayLines($away);
        $this->centerFittedText($image, $this->displayTitle($homeLines[0]), 330, 455, 330, 42, 28, $navy, true);
        if (($homeLines[1] ?? '') !== '') {
            $this->centerFittedText($image, $this->displayTitle($homeLines[1]), 330, 504, 330, 30, 20, $muted, true);
        }
        $this->centerFittedText($image, $this->displayTitle($awayLines[0]), 890, 455, 360, 42, 28, $navy, true);
        if (($awayLines[1] ?? '') !== '') {
            $this->centerFittedText($image, $this->displayTitle($awayLines[1]), 890, 504, 360, 30, 20, $muted, true);
        }

        $this->roundedRect($image, 82, 512, 1118, 620, 16, imagecolorallocate($image, 251, 252, 255));
        imagerectangle($image, 82, 512, 1118, 620, $border);
        imagefilledrectangle($image, 410, 530, 413, 602, $border);
        imagefilledrectangle($image, 758, 530, 761, 602, $border);

        $this->drawCalendarIcon($image, 106, 538, 42, $purple, $white);
        $this->text($image, 'DATE & KICKOFF', 164, 548, 18, $purple, true);
        $this->textFitted($image, $date, 164, 580, 232, 23, 16, $navy, true);
        $this->textFitted($image, $kickoff, 164, 608, 232, 21, 16, $muted, true);

        $this->drawTvIcon($image, 446, 538, 46, $purple, $white);
        $this->text($image, 'BROADCAST', 508, 548, 18, $purple, true);
        $tvLines = $this->fitTextLines($tv, 235, 22, true, 2);
        $tvY = 580;
        foreach ($tvLines as $index => $line) {
            $this->textFitted($image, $line, 508, $tvY + ($index * 28), 235, 23, 16, $index === 0 ? $navy : $muted, true);
        }

        $this->drawVenueIcon($image, 792, 538, 46, $purple);
        $this->text($image, 'VENUE', 854, 548, 18, $purple, true);
        $venueLines = $this->fitTextLines($venue !== '' ? $this->displayTitle($venue) : 'Venue TBC', 245, 18, true, 2);
        foreach ($venueLines as $index => $line) {
            $this->textFitted($image, $line, 854, 582 + ($index * 25), 245, 18, 14, $index === 0 ? $navy : $muted, true);
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fightFixtureCardV2($image, array $fixture): void
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 4, 6, 12);

        $title = trim((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? 'Fight event'));

        $poster = $this->fightArtworkUrl($fixture);
        if ($poster !== '') {
            $this->drawRemoteImageCover($image, $poster, 0, 0, $this->width, $this->height);
        } else {
            imagefilledrectangle($image, 0, 0, $this->width, $this->height, $black);
        }

        if ($poster === '') {
            foreach ($this->fitTextLines($title, 900, 54, true, 2) as $index => $line) {
                $this->centerFittedText($image, $line, 600, 265 + ($index * 62), 900, 52, 28, $white, true);
            }
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function motorsportFixtureCard($image, array $fixture): void
    {
        $black = imagecolorallocate($image, 5, 8, 14);
        $panel = imagecolorallocate($image, 248, 250, 252);
        $panelSoft = imagecolorallocate($image, 226, 232, 240);
        $border = imagecolorallocate($image, 203, 213, 225);
        $text = imagecolorallocate($image, 15, 23, 42);
        $muted = imagecolorallocate($image, 71, 85, 105);
        $red = imagecolorallocate($image, 220, 38, 38);
        $redDark = imagecolorallocate($image, 127, 29, 29);
        $yellow = imagecolorallocate($image, 250, 204, 21);
        $white = imagecolorallocate($image, 255, 255, 255);

        imagefilledrectangle($image, 0, 0, $this->width, $this->height, $black);
        $this->drawCheckeredBand($image, 0, 0, 1200, 76, 38, imagecolorallocate($image, 229, 231, 235), $black);
        imagefilledrectangle($image, 0, 76, 1200, 88, $red);
        $this->filledPolygon($image, [[0, 88], [255, 88], [140, 675], [0, 675]], $redDark);
        $this->filledPolygon($image, [[1035, 88], [1200, 88], [1200, 675], [872, 675]], imagecolorallocate($image, 30, 41, 59));
        $this->roundedRect($image, 92, 132, 1108, 620, 28, $panel);
        imagerectangle($image, 92, 132, 1108, 620, $border);

        $league = trim((string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'Motorsport'));
        $title = trim((string) ($fixture['event_name'] ?? $fixture['strEvent'] ?? 'Race event'));
        $date = $this->compactDateLabel(trim((string) ($fixture['date_label'] ?? $fixture['dateEvent'] ?? 'Date TBC')));
        $kickoff = trim((string) ($fixture['kickoff_label'] ?? $fixture['time'] ?? 'Time TBC'));
        $venue = trim((string) ($fixture['venue'] ?? $fixture['strVenue'] ?? 'Circuit TBC'));
        $tv = trim((string) ($fixture['tv_channel'] ?? '')) ?: 'Not listed';

        $this->drawLeagueLogoMark($image, $fixture, 172, 196, 92, $white, $red);
        $this->textFitted($image, strtoupper($this->shortLeagueName($league)), 238, 190, 760, 30, 18, $redDark, true);
        imagefilledrectangle($image, 238, 214, 1000, 218, $yellow);

        foreach ($this->fitTextLines($this->displayTitle($title), 880, 48, true, 2) as $index => $line) {
            $this->centerFittedText($image, $line, 600, 310 + ($index * 58), 880, 48, 28, $text, true);
        }

        $this->roundedRect($image, 134, 464, 1066, 586, 18, $panelSoft);
        imagefilledrectangle($image, 412, 486, 415, 566, $border);
        imagefilledrectangle($image, 740, 486, 743, 566, $border);

        $this->drawCalendarIcon($image, 156, 494, 48, $redDark, $panel);
        $this->text($image, 'SESSION', 220, 505, 18, $redDark, true);
        $this->textFitted($image, $date, 220, 536, 178, 17, 13, $text, true);
        $this->textFitted($image, $kickoff, 220, 562, 178, 17, 13, $muted, true);

        $this->drawTvIcon($image, 444, 494, 50, $redDark, $panel);
        $this->text($image, 'UK TV', 510, 505, 18, $redDark, true);
        foreach ($this->fitTextLines($tv, 198, 18, true, 2) as $index => $line) {
            $this->textFitted($image, $line, 510, 538 + ($index * 24), 198, 17, 13, $index === 0 ? $text : $muted, true);
        }

        $this->drawVenueIcon($image, 772, 496, 48, $redDark);
        $this->text($image, 'CIRCUIT', 836, 505, 18, $redDark, true);
        foreach ($this->fitTextLines($this->displayTitle($venue !== '' ? $venue : 'Circuit TBC'), 205, 17, true, 2) as $index => $line) {
            $this->textFitted($image, $line, 836, 538 + ($index * 24), 205, 16, 12, $index === 0 ? $text : $muted, true);
        }
    }

    private function drawCheckeredBand($image, int $x, int $y, int $width, int $height, int $cell, int $light, int $dark): void
    {
        for ($row = 0; $row * $cell < $height; $row++) {
            for ($col = 0; $col * $cell < $width; $col++) {
                $color = (($row + $col) % 2 === 0) ? $light : $dark;
                imagefilledrectangle(
                    $image,
                    $x + ($col * $cell),
                    $y + ($row * $cell),
                    min($x + $width, $x + (($col + 1) * $cell)),
                    min($y + $height, $y + (($row + 1) * $cell)),
                    $color
                );
            }
        }
    }

    private function pill($image, array $c, int $x, int $y, string $text, array $rgb): void
    {
        $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, $x, $y - 30, $x + 420, $y + 12, $color);
        $this->text($image, $this->fit($text, 32), $x + 18, $y, 20, $c['bg'], true);
    }

    private function roundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    /**
     * @param array<int, array{0:int,1:int}> $points
     */
    private function filledPolygon($image, array $points, int $color): void
    {
        $flat = [];
        foreach ($points as $point) {
            $flat[] = $point[0];
            $flat[] = $point[1];
        }

        imagefilledpolygon($image, $flat, $color);
    }

    private function drawPremierLeagueMark($image, int $x, int $y, int $size, int $color): void
    {
        imagefilledellipse($image, $x + (int) ($size * 0.48), $y + (int) ($size * 0.52), (int) ($size * 0.82), (int) ($size * 0.82), $color);
        imagefilledellipse($image, $x + (int) ($size * 0.64), $y + (int) ($size * 0.34), (int) ($size * 0.36), (int) ($size * 0.36), $color);
        imagefilledpolygon($image, [
            $x + (int) ($size * 0.22), $y + (int) ($size * 0.12),
            $x + (int) ($size * 0.34), $y - (int) ($size * 0.12),
            $x + (int) ($size * 0.44), $y + (int) ($size * 0.16),
            $x + (int) ($size * 0.56), $y - (int) ($size * 0.10),
            $x + (int) ($size * 0.66), $y + (int) ($size * 0.18),
            $x + (int) ($size * 0.78), $y,
            $x + (int) ($size * 0.78), $y + (int) ($size * 0.28),
            $x + (int) ($size * 0.22), $y + (int) ($size * 0.30),
        ], $color);
        imagefilledellipse($image, $x + (int) ($size * 0.78), $y + (int) ($size * 0.24), 8, 8, imagecolorallocate($image, 55, 20, 94));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function drawLeagueLogoMark($image, array $fixture, int $centerX, int $centerY, int $size, int $bg, int $ink): void
    {
        $badge = trim((string) ($fixture['league_badge'] ?? $fixture['strLeagueBadge'] ?? ''));
        $logo = trim((string) ($fixture['league_logo'] ?? $fixture['strLeagueLogo'] ?? ''));

        foreach (array_filter([$badge, $logo]) as $url) {
            $logoImage = $this->remoteLogoImage($url);
            if (!$logoImage) {
                continue;
            }

            imagefilledellipse($image, $centerX, $centerY, $size, $size, $this->logoNeedsDarkBackground($logoImage) ? $ink : $bg);
            if ($this->drawLogoImageContain($image, $logoImage, $centerX, $centerY, (int) ($size * 0.74), (int) ($size * 0.74))) {
                imagedestroy($logoImage);
                return;
            }

            imagedestroy($logoImage);
        }

        imagefilledellipse($image, $centerX, $centerY, $size, $size, $bg);
        $initials = $this->leagueInitials((string) ($fixture['league'] ?? $fixture['strLeague'] ?? 'League'));
        $textSize = $this->fittedTextSize($initials, (int) ($size * 0.7), 30, 18, true);
        $this->text(
            $image,
            $initials,
            $centerX - (int) floor($this->textWidth($initials, $textSize, true) / 2),
            $centerY + (int) floor($textSize / 2),
            $textSize,
            $ink,
            true
        );
    }

    private function leagueInitials(string $league): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^A-Za-z0-9 ]+/', ' ', $this->shortLeagueName($league)) ?? $league) ?: []));
        $initials = '';

        foreach ($words as $word) {
            if (in_array(strtolower($word), ['the', 'and', 'of'], true)) {
                continue;
            }

            $initials .= strtoupper(substr($word, 0, 1));
            if (strlen($initials) >= 3) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'TV';
    }

    private function shortLeagueName(string $league): string
    {
        $league = trim($league);

        return match ($league) {
            'English Premier League' => 'Premier League',
            'Scottish Premiership', 'Scottish Premier League' => 'Scottish Premiership',
            default => $league !== '' ? $league : 'Competition TBC',
        };
    }

    private function displayTitle(string $value): string
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
        if ($value === '') {
            return '';
        }

        return ucwords($value);
    }

    private function compactDateLabel(string $date): string
    {
        $date = trim(preg_replace('/\s+/', ' ', $date) ?? $date);
        $upper = strtoupper($date);
        $replacements = [
            'MONDAY' => 'MON',
            'TUESDAY' => 'TUE',
            'WEDNESDAY' => 'WED',
            'THURSDAY' => 'THU',
            'FRIDAY' => 'FRI',
            'SATURDAY' => 'SAT',
            'SUNDAY' => 'SUN',
        ];

        return strtr($upper, $replacements);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function teamDisplayLines(string $team): array
    {
        $team = strtoupper(trim(preg_replace('/\s+/', ' ', $team) ?? $team));
        foreach ([' UNITED', ' CITY', ' WANDERERS', ' ROVERS', ' ATHLETIC'] as $suffix) {
            if (str_ends_with($team, $suffix)) {
                return [trim(substr($team, 0, -strlen($suffix))), trim($suffix)];
            }
        }

        $words = preg_split('/\s+/', $team) ?: [];
        if (count($words) >= 3) {
            return [implode(' ', array_slice($words, 0, -1)), (string) end($words)];
        }

        return [$team, ''];
    }

    private function drawVenueIcon($image, int $x, int $y, int $size, int $ink): void
    {
        imagesetthickness($image, max(3, (int) round($size * 0.1)));
        imagearc($image, $x + (int) ($size / 2), $y + (int) ($size / 2), $size, (int) ($size * 0.55), 0, 360, $ink);
        imagearc($image, $x + (int) ($size / 2), $y + (int) ($size / 2) + 3, (int) ($size * 0.72), (int) ($size * 0.36), 0, 360, $ink);
        imageline($image, $x + 8, $y + (int) ($size / 2), $x + $size - 8, $y + (int) ($size / 2), $ink);
        imageline($image, $x + 16, $y + 7, $x + 16, $y + 22, $ink);
        imageline($image, $x + (int) ($size / 2), $y + 4, $x + (int) ($size / 2), $y + 20, $ink);
        imageline($image, $x + $size - 16, $y + 7, $x + $size - 16, $y + 22, $ink);
        imagesetthickness($image, 1);
    }

    private function drawRemoteLogo($image, string $url, int $x, int $y, int $size): bool
    {
        $logo = $this->remoteLogoImage($url);
        if (!$logo) {
            return false;
        }

        imagecopyresampled($image, $logo, $x, $y, 0, 0, $size, $size, imagesx($logo), imagesy($logo));
        imagedestroy($logo);

        return true;
    }

    private function drawRemoteLogoContain($image, string $url, int $centerX, int $centerY, int $maxWidth, int $maxHeight): bool
    {
        $logo = $this->remoteLogoImage($url);
        if (!$logo) {
            return false;
        }

        $drawn = $this->drawLogoImageContain($image, $logo, $centerX, $centerY, $maxWidth, $maxHeight);
        imagedestroy($logo);

        return $drawn;
    }

    private function drawTeamLogoContain($image, string $url, int $centerX, int $centerY, int $maxWidth, int $maxHeight, int $contrast): bool
    {
        $logo = $this->remoteLogoImage($url);
        if (!$logo) {
            return false;
        }

        if ($this->logoNeedsDarkBackground($logo)) {
            $backingSize = max($maxWidth, $maxHeight) + 24;
            imagefilledellipse($image, $centerX, $centerY, $backingSize, $backingSize, $contrast);
        }

        $drawn = $this->drawLogoImageContain($image, $logo, $centerX, $centerY, $maxWidth, $maxHeight);
        imagedestroy($logo);

        return $drawn;
    }

    private function remoteLogoImage(string $url)
    {
        if ($url === '') {
            return null;
        }

        try {
            $body = $this->cachedRemoteImageBody($url);
            if ($body === null) {
                return null;
            }

            $logo = @imagecreatefromstring($body);
            if (!$logo) {
                return null;
            }

            return $logo;
        } catch (Throwable $error) {
            Log::debug('sportsbot.card.logo_failed', ['url' => $url, 'error' => $error->getMessage()]);
        }

        return null;
    }

    private function cachedRemoteImageBody(string $url): ?string
    {
        $ttl = max(0, (int) config('plugins.SportsBot.cards.image_cache_ttl', 604800));
        $dir = storage_path('app/sportsbot/image-cache');
        $path = $dir . '/' . sha1($url) . '.img';

        if (is_file($path) && ($ttl === 0 || (time() - (int) filemtime($path)) <= $ttl)) {
            $body = @file_get_contents($path);
            if (is_string($body) && $body !== '') {
                return $body;
            }
        }

        try {
            $response = Http::timeout(8)->get($url);
            if ($response->successful()) {
                $body = $response->body();
                if ($body !== '') {
                    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                        return $body;
                    }

                    @file_put_contents($path, $body, LOCK_EX);

                    return $body;
                }
            }
        } catch (Throwable $error) {
            Log::debug('sportsbot.card.image_fetch_failed', ['url' => $url, 'error' => $error->getMessage()]);
        }

        if (is_file($path)) {
            $body = @file_get_contents($path);
            if (is_string($body) && $body !== '') {
                return $body;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fightArtworkUrl(array $fixture): string
    {
        $candidates = [
            $fixture['event_thumb'] ?? null,
            $fixture['strThumb'] ?? null,
            $fixture['event_poster'] ?? null,
            $fixture['strPoster'] ?? null,
        ];

        $cacheKey = $this->fightArtworkCacheKey($fixture);
        foreach ($candidates as $candidate) {
            $url = trim((string) $candidate);
            if ($url === '') {
                continue;
            }

            if ($cacheKey !== '') {
                Cache::put($cacheKey, $url, max(60, (int) config('plugins.SportsBot.cards.fight_art_url_cache_ttl', 2592000)));
            }

            return $url;
        }

        return $cacheKey !== '' ? trim((string) Cache::get($cacheKey, '')) : '';
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function fightArtworkCacheKey(array $fixture): string
    {
        $eventId = trim((string) ($fixture['event_id'] ?? $fixture['idEvent'] ?? ''));
        if ($eventId !== '') {
            return 'sportsbot:card:fight_art_url:event:' . $eventId;
        }

        $identity = json_encode([
            $fixture['league'] ?? $fixture['strLeague'] ?? '',
            $fixture['event_name'] ?? $fixture['strEvent'] ?? '',
            $fixture['date_label'] ?? $fixture['dateEvent'] ?? '',
            $fixture['kickoff_label'] ?? $fixture['strTime'] ?? '',
        ]);

        return is_string($identity) ? 'sportsbot:card:fight_art_url:fallback:' . sha1($identity) : '';
    }

    private function drawLogoImageContain($image, $logo, int $centerX, int $centerY, int $maxWidth, int $maxHeight): bool
    {
        $sourceWidth = imagesx($logo);
        $sourceHeight = imagesy($logo);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return false;
        }

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $targetX = $centerX - (int) floor($targetWidth / 2);
        $targetY = $centerY - (int) floor($targetHeight / 2);

        imagecopyresampled($image, $logo, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return true;
    }

    private function drawRemoteImageCover($image, string $url, int $x1, int $y1, int $x2, int $y2): bool
    {
        $source = $this->remoteLogoImage($url);
        if (!$source) {
            return false;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = max(1, $x2 - $x1);
        $targetHeight = max(1, $y2 - $y1);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($source);
            return false;
        }

        $scale = max($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $cropWidth = (int) floor($targetWidth / $scale);
        $cropHeight = (int) floor($targetHeight / $scale);
        $cropX = max(0, (int) floor(($sourceWidth - $cropWidth) / 2));
        $cropY = max(0, (int) floor(($sourceHeight - $cropHeight) / 2));

        imagecopyresampled($image, $source, $x1, $y1, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        imagedestroy($source);

        return true;
    }

    private function logoNeedsDarkBackground($logo): bool
    {
        $width = imagesx($logo);
        $height = imagesy($logo);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $stepX = max(1, (int) floor($width / 24));
        $stepY = max(1, (int) floor($height / 24));
        $total = 0.0;
        $count = 0;

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $color = imagecolorsforindex($logo, imagecolorat($logo, $x, $y));
                if (($color['alpha'] ?? 0) > 100) {
                    continue;
                }

                $total += (0.2126 * $color['red']) + (0.7152 * $color['green']) + (0.0722 * $color['blue']);
                $count++;
            }
        }

        return $count > 0 && ($total / $count) > 190;
    }

    private function teamPlaceholder($image, string $team, int $x, int $y, int $size, int $bg, int $fg): void
    {
        imagefilledellipse($image, $x + (int) ($size / 2), $y + (int) ($size / 2), $size, $size, $bg);
        $initials = $this->teamInitials($team);
        $textWidth = $this->textWidth($initials, 42, true);
        $this->text($image, $initials, $x + (int) (($size - $textWidth) / 2), $y + 108, 42, $fg, true);
    }

    private function teamInitials(string $team): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^A-Za-z0-9 ]+/', ' ', $team) ?? $team) ?: []));
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }

        return $initials !== '' ? $initials : 'FC';
    }

    private function centerFittedText($image, string $text, int $centerX, int $y, int $maxWidth, int $maxSize, int $minSize, int $color, bool $bold = false): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $size = $this->fittedTextSize($text, $maxWidth, $maxSize, $minSize, $bold);
        $this->text($image, $text, $centerX - (int) floor($this->textWidth($text, $size, $bold) / 2), $y, $size, $color, $bold);
    }

    private function textFitted($image, string $text, int $x, int $y, int $maxWidth, int $maxSize, int $minSize, int $color, bool $bold = false): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $size = $this->fittedTextSize($text, $maxWidth, $maxSize, $minSize, $bold);

        while ($this->textWidth($text, $size, $bold) > $maxWidth && mb_strlen($text) > 1) {
            $text = rtrim(mb_substr($text, 0, -4)) . '...';
        }

        $this->text($image, $text, $x, $y, $size, $color, $bold);
    }

    private function fittedTextSize(string $text, int $maxWidth, int $maxSize, int $minSize, bool $bold = false): int
    {
        for ($size = $maxSize; $size > $minSize; $size--) {
            if ($this->textWidth($text, $size, $bold) <= $maxWidth) {
                return $size;
            }
        }

        return $minSize;
    }

    /**
     * @return array<int, string>
     */
    private function fitTextLines(string $text, int $maxWidth, int $size, bool $bold, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current . ' ' . $word);
            if ($current !== '' && $this->textWidth($candidate, $size, $bold) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if ($lines === []) {
            $lines = [$text];
        }

        if (count($lines) > $maxLines) {
            $head = array_slice($lines, 0, $maxLines - 1);
            $tail = implode(' ', array_slice($lines, $maxLines - 1));
            $lines = array_merge($head, [$tail]);
        }

        return array_slice($lines, 0, $maxLines);
    }

    private function drawCalendarIcon($image, int $x, int $y, int $size, int $ink, int $fill): void
    {
        imagefilledrectangle($image, $x, $y + 16, $x + $size, $y + $size, $fill);
        imagesetthickness($image, max(3, (int) round($size * 0.067)));
        imagerectangle($image, $x + 6, $y + 22, $x + $size - 6, $y + $size - 6, $ink);
        imageline($image, $x + 6, $y + 48, $x + $size - 6, $y + 48, $ink);
        imageline($image, $x + 28, $y + 2, $x + 28, $y + 34, $ink);
        imageline($image, $x + $size - 28, $y + 2, $x + $size - 28, $y + 34, $ink);
        imagesetthickness($image, 1);
    }

    private function drawTvIcon($image, int $x, int $y, int $size, int $ink, int $fill): void
    {
        imagefilledrectangle($image, $x, $y + 28, $x + $size, $y + $size - 12, $fill);
        imagesetthickness($image, max(3, (int) round($size * 0.067)));
        imagerectangle($image, $x + 8, $y + 34, $x + $size - 8, $y + $size - 20, $ink);
        imageline($image, $x + 35, $y + 6, $x + (int) ($size / 2), $y + 34, $ink);
        imageline($image, $x + $size - 35, $y + 6, $x + (int) ($size / 2), $y + 34, $ink);
        imageline($image, $x + (int) ($size / 2), $y + $size - 20, $x + (int) ($size / 2), $y + $size - 2, $ink);
        imageline($image, $x + 36, $y + $size - 2, $x + $size - 36, $y + $size - 2, $ink);
        imagesetthickness($image, 1);
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

        $this->scaledBuiltinText($image, $text, $x, $y, $size, $color);
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

        return (int) ceil(strlen($text) * imagefontwidth(5) * $this->builtinFontScale($size));
    }

    private function scaledBuiltinText($image, string $text, int $x, int $baselineY, int $size, int $color): void
    {
        if ($text === '') {
            return;
        }

        $font = 5;
        $sourceWidth = max(1, imagefontwidth($font) * strlen($text));
        $sourceHeight = imagefontheight($font);
        $scale = $this->builtinFontScale($size);
        $targetWidth = max(1, (int) ceil($sourceWidth * $scale));
        $targetHeight = max(1, (int) ceil($sourceHeight * $scale));

        $source = imagecreatetruecolor($sourceWidth, $sourceHeight);
        if (!$source) {
            return;
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);
        $transparent = imagecolorallocatealpha($source, 0, 0, 0, 127);
        imagefilledrectangle($source, 0, 0, $sourceWidth, $sourceHeight, $transparent);
        $rgb = imagecolorsforindex($image, $color);
        $sourceColor = imagecolorallocate($source, $rgb['red'], $rgb['green'], $rgb['blue']);
        imagestring($source, $font, 0, 0, $text, $sourceColor);

        imagecopyresampled($image, $source, $x, $baselineY - $targetHeight, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);
    }

    private function builtinFontScale(int $size): float
    {
        return max(1.0, $size / 13.0);
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

        return rtrim(mb_substr($text, 0, max(1, $limit - 3))) . '...';
    }

    private function findFont(bool $bold): string
    {
        $configured = trim((string) config($bold ? 'plugins.SportsBot.cards.font_bold' : 'plugins.SportsBot.cards.font_regular', ''));
        $candidates = $bold
            ? [
                $configured,
                '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSansCondensed-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
                '/System/Library/Fonts/SFNS.ttf',
                '/System/Library/Fonts/HelveticaNeue.ttc',
                '/Library/Fonts/Arial Bold.ttf',
            ]
            : [
                $configured,
                '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSansCondensed.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
                '/System/Library/Fonts/SFNS.ttf',
                '/System/Library/Fonts/HelveticaNeue.ttc',
                '/Library/Fonts/Arial.ttf',
            ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
