## Plan: Twilio Monthly Number Subscription

Add Twilio as a second provider beside 5sim using a provider-aware architecture, live inventory from Twilio APIs with short cache (no inventory DB persistence), USD to NGN conversion using existing exchange rate pipeline, and Twilio-specific markup rules. Implement reserve-first-then-debit purchase flow, plus optional auto-renew for monthly number rental and outbound SMS usage tracking so pricing/profit remains accurate and real-time.

**Steps**
1. Discovery hardening and domain model alignment. Finalize Twilio product boundaries in code terms: monthly number rental, outbound SMS support, inbound SMS webhook handling, and renewal lifecycle states. Define provider-aware entities for number subscription records and message usage records while preserving existing 5sim activation tables. This step blocks all others.
2. Phase A: Provider abstraction refactor for multi-provider support. Refactor current single binding so 5sim stays intact while enabling dynamic provider resolution by provider key. Introduce provider resolver/factory and wire service selection in purchase flows. Depends on step 1.
3. Phase B: Twilio inventory and pricing pipeline. Add Twilio service that fetches available numbers on demand, normalizes inventory shape to current frontend/operator selection expectations, and applies cache with short TTL and cache keying by country/capability/search filter. Reuse existing USD to NGN exchange + safety factor, then apply Twilio-specific markup settings layered over global pricing controls. Parallel with step 2 after interfaces are defined.
4. Phase C: Twilio reserve-first purchase and wallet integration. Implement purchase transaction order as reserve number in Twilio first, then debit wallet atomically, then persist subscription record with next renewal date. If debit fails, release number immediately. Add idempotent references similar to current wallet transactions and explicit compensation logic for partial failures. Depends on steps 2 and 3.
5. Phase D: Monthly renewal engine with auto-renew toggle. Add scheduler job to process due renewals, attempt wallet debit only when auto-renew enabled, and release/deactivate numbers when renewal fails or user disables renewal. Add notification events for renewal success, insufficient balance, and upcoming expiry. Depends on step 4.
6. Phase E: Outbound SMS send flow and usage cost accounting. Add endpoint/service for sending SMS from subscribed Twilio number, capture Twilio message SID/status/cost callbacks, and maintain usage ledger to support profit tracking and possible future usage-based surcharge. Parallel with step 5 after Twilio auth/client is ready.
7. Phase F: API and UI integration. Extend user catalog endpoints to support provider filter and Twilio listing from cache, add Twilio monthly purchase endpoints, add user dashboard screens for active number, expiry, auto-renew toggle, conversation log, and outbound message composer. Update admin pages for Twilio markup/settings, provider visibility, and renewal health. Depends on steps 3 to 6.
8. Phase G: Observability, controls, and rollout. Add structured logs and metrics for Twilio fetch latency, cache hit ratio, reserve failures, renewal outcomes, and wallet debit failures. Gate Twilio behind feature flag and release by country. Depends on steps 2 to 7.

**Relevant files**
- /home/dollarhunter/Documents/github/smsgang/backend/app/Services/ActivationService.php — reuse order creation and wallet debit orchestration pattern; split 5sim-specific logic out to provider strategy.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Services/SmsProviders/ProviderInterface.php — baseline interface to evolve into provider-specific capability contracts.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Providers/AppServiceProvider.php — replace single provider binding with resolver/factory wiring.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Services/PricingService.php — reuse conversion and markup engine; add Twilio-specific markup branch/config keys.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Services/ExchangeRateService.php — retain USD to NGN source of truth used by Twilio pricing.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Services/WalletService.php — reuse idempotent debit/refund logic for Twilio reserve/debit compensation.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Jobs/SyncAllPricingJob.php — keep for 5sim; do not overload with Twilio inventory persistence.
- /home/dollarhunter/Documents/github/smsgang/backend/routes/console.php — add Twilio renewal and stale reservation cleanup schedules.
- /home/dollarhunter/Documents/github/smsgang/backend/routes/api.php — add Twilio listing/purchase/send/renew endpoints and callback routes.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Http/Controllers/Api/TwilioWebhookController.php — implement inbound SMS and message status/cost callback handlers.
- /home/dollarhunter/Documents/github/smsgang/backend/config/services.php — add Twilio credentials, rate limits, and inventory cache TTL config.
- /home/dollarhunter/Documents/github/smsgang/backend/app/Models/Order.php — extend for provider-aware monthly subscription order metadata.
- /home/dollarhunter/Documents/github/smsgang/backend/database/migrations — add subscription and message usage tables (new files).
- /home/dollarhunter/Documents/github/smsgang/frontend/src/app/virtual-numbers/page.tsx — provider filter and Twilio monthly card rendering.
- /home/dollarhunter/Documents/github/smsgang/frontend/src/app/services/[slug]/page.tsx — Twilio monthly purchase path beside existing 5sim flow.
- /home/dollarhunter/Documents/github/smsgang/frontend/src/app/activations/page.tsx — split one-time activation history from monthly number subscriptions.
- /home/dollarhunter/Documents/github/smsgang/admin/src/app/(admin)/settings/page.tsx — Twilio markup and renewal defaults controls.
- /home/dollarhunter/Documents/github/smsgang/admin/src/app/(admin)/operators/page.tsx — optional provider filter and Twilio cache health indicators.

**Verification**
1. Unit tests for pricing math: USD to NGN conversion, global markup + Twilio markup composition, and rounding consistency with current behavior.
2. Integration tests for reserve-first flow: reserve success plus debit success, reserve success plus debit failure with release compensation, and idempotent retries.
3. Scheduler tests for renewal: auto-renew on and sufficient balance, auto-renew on and insufficient balance, auto-renew off, expiry transition, and release behavior.
4. API tests for live inventory endpoint caching: cache hit/miss behavior, TTL expiry, and fallback errors when Twilio API fails.
5. Webhook tests for inbound SMS and message status/cost callbacks, including signature validation and duplicate callback idempotency.
6. Manual staging run: list Twilio numbers, buy monthly number, send outbound SMS, receive inbound SMS, toggle auto-renew, force renewal, and verify wallet ledger/profit fields.
7. Regression tests for existing 5sim buy flow to ensure no behavior change.

**Decisions**
- Confirmed scope: outbound texting is included in phase 1.
- Confirmed inventory strategy: live Twilio pull with short cache, not persistent inventory DB storage.
- Confirmed billing: manual renewal and auto-renew toggle both supported.
- Confirmed pricing: reuse existing USD to NGN conversion path with Twilio-specific markup config.
- Confirmed transaction order: reserve number first, then debit wallet.
- Included scope: Twilio number rental, renewal lifecycle, send/receive SMS, provider-aware pricing and wallet flow.
- Excluded scope for now: voice calls, MMS, advanced campaign tooling, full CRM inbox features.

**Further Considerations**
1. Compliance and sender policy: outbound SMS may require country-specific messaging rules and sender registration. Recommendation: enforce allowed countries at launch and add policy checks before send.
2. Usage billing model: monthly rental can be flat, but outbound SMS has variable carrier cost. Recommendation: keep rental and usage as separate ledger lines to avoid hidden margin loss.
3. Inventory race handling: Twilio availability can change quickly. Recommendation: keep reservation timeout short and enforce fast compensation paths for failed debit or webhook delays.
