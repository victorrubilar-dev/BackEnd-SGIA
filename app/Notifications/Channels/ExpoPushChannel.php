<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envía la notificación como push a los dispositivos Expo del usuario.
 *
 * La notificación debe definir `toExpo($notifiable): array{title: string, body: string, data?: array}`.
 * Un fallo del servicio de Expo se registra pero nunca interrumpe el flujo de negocio.
 */
class ExpoPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toExpo') || ! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $tokens = $notifiable->deviceTokens()->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        $payload = $notification->toExpo($notifiable);
        $messages = array_map(fn (string $token) => $payload + ['to' => $token, 'sound' => 'default'], $tokens);

        foreach (array_chunk($messages, 100) as $chunk) {
            try {
                $request = Http::timeout(10)->acceptJson();

                if ($accessToken = config('services.expo.access_token')) {
                    $request = $request->withToken($accessToken);
                }

                $response = $request->post(config('services.expo.url'), $chunk);

                $this->pruneInvalidTokens($chunk, (array) $response->json('data', []));
            } catch (\Throwable $e) {
                Log::warning('No se pudo enviar el push de Expo.', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Borra los tokens que Expo reporta como dados de baja en el dispositivo.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tickets
     */
    private function pruneInvalidTokens(array $messages, array $tickets): void
    {
        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? null) === 'error' && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                DeviceToken::where('token', $messages[$index]['to'] ?? '')->delete();
            }
        }
    }
}
