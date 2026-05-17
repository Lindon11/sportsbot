<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\FootballBotRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class FootballBotWebhookController extends Controller
{
    public function __invoke(Request $request, FootballBotRuntime $bot): JsonResponse
    {
        $payload = $request->json()->all();

        if (!is_array($payload) || $payload === []) {
            return response()->json(['ok' => false, 'error' => 'Invalid webhook payload.'], 400);
        }

        try {
            $bot->processTelegramWebhook(
                $payload,
                $request->header('X-Telegram-Bot-Api-Secret-Token')
            );

            return response()->json(['ok' => true]);
        } catch (RuntimeException $error) {
            $status = str_contains($error->getMessage(), 'disabled') ? 403 : 400;

            if (str_contains($error->getMessage(), 'secret')) {
                $status = 403;
            }

            return response()->json(['ok' => false, 'error' => $error->getMessage()], $status);
        } catch (Throwable) {
            return response()->json(['ok' => false, 'error' => 'Webhook processing failed.'], 500);
        }
    }
}
