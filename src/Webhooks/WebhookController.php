<?php

namespace Raccount\Sso\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Raccount\Sso\Events\WebhookReceived;
use Raccount\Sso\Models\RaccountWebhookEvent;
use Throwable;

final class WebhookController
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): Response
    {
        $rawBody = $request->getContent();
        $eventId = (string) $request->header('X-RAccount-Event-Id', '');

        $secrets = array_values(array_filter((array) config('raccount-sso.webhooks.secrets', [])));
        $verifier = new WebhookVerifier($secrets, (int) config('raccount-sso.webhooks.tolerance', 300));

        if ($eventId === '' || ! $verifier->verify(
            $rawBody,
            $request->header('X-RAccount-Signature'),
            $request->header('X-RAccount-Timestamp'),
        )) {
            // Permanent failure — acknowledge so the server stops retrying,
            // and record enough context to debug from the application log.
            Log::warning('raccount-sso: webhook delivery rejected (signature/timestamp/event id).', [
                'event_id' => $eventId,
            ]);

            return new Response('', 200);
        }

        $claim = RaccountWebhookEvent::query()->find($eventId);

        if ($claim !== null && $claim->processed_at !== null) {
            return new Response('', 200);
        }

        if ($claim === null) {
            try {
                $claim = RaccountWebhookEvent::query()->create([
                    'event_id' => $eventId,
                    'event_type' => (string) $request->header('X-RAccount-Event-Type', ''),
                    'payload' => json_decode($rawBody, true),
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $claim = RaccountWebhookEvent::query()->findOrFail($eventId);
            }
        }

        $type = (string) $request->header('X-RAccount-Event-Type', '');
        $payload = WebhookPayload::fromArray((array) json_decode($rawBody, true), $eventId, $type);

        try {
            $this->events->dispatch(new WebhookReceived($payload));

            $eventClass = WebhookPayload::eventClassFor($type);

            if ($eventClass !== null) {
                $this->events->dispatch(new $eventClass($payload));
            }

            $claim->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $exception) {
            // Let the server retry this delivery via its retry ladder.
            report($exception);

            return new Response('', 500);
        }

        return new Response('', 200);
    }
}
