<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTwilioWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('services.twilio.webhook_signature_validation', true)) {
            return $next($request);
        }

        $authToken = (string) config('services.twilio.auth_token', '');
        if ($authToken === '') {
            return response()->json(['message' => 'Twilio auth token not configured.'], 500);
        }

        $provided = (string) $request->header('X-Twilio-Signature', '');
        if ($provided === '') {
            return response()->json(['message' => 'Missing Twilio signature.'], 403);
        }

        $url = $request->fullUrl();
        $computed = $this->computeSignature($url, $request, $authToken);

        if (! hash_equals($computed, $provided)) {
            return response()->json(['message' => 'Invalid Twilio signature.'], 403);
        }

        return $next($request);
    }

    private function computeSignature(string $url, Request $request, string $token): string
    {
        $data = $url;

        if ($request->isJson()) {
            $data .= $request->getContent();
        } else {
            $params = $request->post();
            ksort($params);

            foreach ($params as $key => $value) {
                $data .= $key . (is_array($value) ? implode('', $value) : (string) $value);
            }
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }
}
