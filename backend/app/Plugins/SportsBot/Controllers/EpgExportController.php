<?php

namespace App\Plugins\SportsBot\Controllers;

use App\Plugins\SportsBot\Services\SportsBotEpgExporter;
use App\Plugins\SportsBot\Services\SportsBotSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class EpgExportController extends Controller
{
    public function xmltv(string $token, SportsBotEpgExporter $exporter, SportsBotSettingsService $settings): Response
    {
        $this->guardToken($token, $settings);

        return response($exporter->exportXmltv(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    public function json(string $token, SportsBotEpgExporter $exporter, SportsBotSettingsService $settings): JsonResponse
    {
        $this->guardToken($token, $settings);

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
