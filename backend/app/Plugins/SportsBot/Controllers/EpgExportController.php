<?php

namespace App\Plugins\SportsBot\Controllers;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

class EpgExportController extends Controller
{
    public function xmltv(string $token, SportsBotEpgExporter $exporter, SportsBotSettingsService $settings): Response
    {
        $this->guardToken($token, $settings);

        $path = storage_path('app/sportsbot/epg/guide.xml');
        if (is_file($path)) {
            return response()->file($path, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=900',
            ]);
        }

        return response($exporter->exportXmltv(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    public function json(string $token, SportsBotEpgExporter $exporter, SportsBotSettingsService $settings): Response
    {
        $this->guardToken($token, $settings);

        $path = storage_path('app/sportsbot/epg/guide.json');
        if (is_file($path)) {
            return response()->file($path, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Cache-Control' => 'public, max-age=900',
            ]);
        }

        return response()->json($exporter->exportJson(), 200, [
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function guardToken(string $token, SportsBotSettingsService $settings): void
    {
        $configured = trim((string) $settings->get('epg_export_token', config('plugins.SportsBot.epg.export_token', '')));
        if ($configured === '' || ! hash_equals($configured, $token)) {
            abort(404);
        }
    }
}
