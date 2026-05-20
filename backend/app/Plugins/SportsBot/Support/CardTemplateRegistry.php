<?php

namespace App\Plugins\SportsBot\Support;

class CardTemplateRegistry
{
    public const TEMPLATE_TYPES = [
        'fixture-card',
        'tv-guide-card',
        'result-card',
        'league-table-card',
        'fight-poster-card',
        'breaking-news-card',
        'weekly-roundup-card',
        'highlight-card',
        'countdown-card',
        'halftime-card',
        'fulltime-card',
        'player-spotlight-card',
        'injury-alert-card',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        string $sportKey,
        ?string $routeKey = null,
        string $templateType = 'fixture-card',
        array $overrides = []
    ): array {
        $sportKey = SportsBotSports::normalize($sportKey);
        $routeKey = TelegramRouteKeys::normalize($routeKey);
        $templateType = in_array($templateType, self::TEMPLATE_TYPES, true) ? $templateType : 'fixture-card';

        $defaults = $this->defaults();
        $sport = $defaults['sports'][$sportKey] ?? [];
        $route = $defaults['routes'][$routeKey] ?? [];
        $topic = $defaults['topics'][$routeKey] ?? [];

        $template = (string) ($overrides['template'] ?? $topic['template'] ?? $route['template'] ?? $sport['template'] ?? $defaults['template'] ?? 'stadium-v3');
        $theme = (string) ($overrides['theme'] ?? $topic['theme'] ?? $route['theme'] ?? $sport['theme'] ?? $defaults['theme'] ?? 'limitless-dark');
        $version = (string) ($overrides['card_version'] ?? $sport['card_version'] ?? $defaults['card_version'] ?? 'v3');
        $branding = array_merge(
            (array) ($defaults['branding'] ?? []),
            (array) ($sport['branding'] ?? []),
            (array) ($route['branding'] ?? []),
            (array) ($topic['branding'] ?? []),
            (array) ($overrides['branding'] ?? []),
        );

        return [
            'template_type' => $templateType,
            'template' => $template,
            'theme' => $theme,
            'card_version' => in_array($version, ['v1', 'v2', 'v3'], true) ? $version : 'v3',
            'template_path' => $this->templatePath($template),
            'theme_path' => $this->themePath($theme),
            'branding' => $branding,
            'output' => $this->outputProfile((string) ($overrides['target'] ?? 'telegram'), (string) ($overrides['format'] ?? 'png')),
            'video_ready' => [
                'pipeline' => 'html-css-js',
                'planned_formats' => ['gif', 'mp4'],
                'implemented' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $defaults = $this->defaults();

        return [
            'template_types' => self::TEMPLATE_TYPES,
            'templates' => $defaults['templates'] ?? [],
            'themes' => $defaults['themes'] ?? [],
            'sports' => $defaults['sports'] ?? [],
            'routes' => $defaults['routes'] ?? [],
            'formats' => ['png', 'webp', 'jpeg', 'gif', 'mp4'],
            'targets' => ['telegram', 'discord'],
            'layouts' => ['square_1_1', 'landscape_16_9', 'vertical_9_16'],
        ];
    }

    private function templatePath(string $template): string
    {
        $path = resource_path('cards/templates/' . basename($template) . '.html');

        return is_file($path) ? $path : '';
    }

    private function themePath(string $theme): string
    {
        $path = resource_path('cards/themes/' . basename($theme) . '.css');

        return is_file($path) ? $path : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function outputProfile(string $target, string $format): array
    {
        $target = in_array($target, ['telegram', 'discord'], true) ? $target : 'telegram';
        $format = in_array($format, ['png', 'webp', 'jpeg', 'gif', 'mp4'], true) ? $format : 'png';

        return [
            'target' => $target,
            'format' => $format,
            'layout' => 'landscape_16_9',
            'width' => (int) config('plugins.SportsBot.cards.width', 1200),
            'height' => (int) config('plugins.SportsBot.cards.height', 675),
            'telegram_optimized' => $target === 'telegram',
            'discord_optimized' => $target === 'discord',
            'low_bandwidth' => (bool) config('plugins.SportsBot.cards.low_bandwidth_mode', false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (array) config('plugins.SportsBot.cards.template_registry', []);
    }
}
