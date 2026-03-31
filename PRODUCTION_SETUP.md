# SMS Gang - Production Implementation Guide

## 🚀 Final Architecture - Wallet-First System

### **New Payment Flow (Simplified)**

```
┌─────────────────────────────────────┐
│   User Needs Virtual Number or SMM  │
└──────────────┬──────────────────────┘
               │
               ↓
        ┌──────────────┐
        │ Wallet Empty?│
        └──────┬───────┘
            YES│ NO
               │  │
               ↓  ↓
        ┌─────────────────────┐     ┌──────────────────────┐
        │  FUND WALLET (Step1)│     │ PURCHASE (Step 2)    │
        │                     │     │                      │
        │ 1. Create Trans     │     │ ✅ Check Wallet      │
        │ 2. Send to Checkout │     │ ✅ Deduct Funds      │
        │ 3. Webhook Updates  │     │ ✅ Create Order      │
        │ 4. Add to Wallet    │     │ ✅ Process Order     │
        └─────────────────────┘     └──────────────────────┘
              │                              │
              └──────────────┬───────────────┘
                             ↓
                    ┌─────────────────┐
                    │  Order Complete │
                    │  Wallet Deducted│
                    └─────────────────┘
```

---

## 1. Wallet Funding Flow (WITH Webhook)

**Problem Fixed:**
- Wallet funding did NOT create transaction first
- Was only creating transaction after payment verification
- Not following industry standard monitoring/tracking

**Solution Implemented:**
When user calls `/api/wallet/fund`:
1. ✅ **Creates transaction FIRST** with status `pending` (monitoring mode)
2. ✅ **Sends to Lendoverify checkout** 
3. ✅ **Webhook updates transaction status** to `paid` or `failed`
4. ✅ **Then adds funds to wallet** only if payment successful

**Files Modified:**
- `app/Http/Controllers/Api/WalletController.php` - Now creates transaction before checkout
- `app/Http/Controllers/Api/LendoverifyWebhookController.php` - Now handles wallet transactions

---

### 2. Virtual Number Purchase Flow (WALLET ONLY - No Webhook)

**Status: ✅ Wallet Direct Deduction**
- User clicks "Buy Virtual Number"
- ✅ System checks wallet balance
- ✅ Deducts price directly from wallet
- ✅ Creates order with status `pending`
- ✅ Activates service immediately
- ✅ No checkout, no webhook needed

**Identical to SMM Boosting Pattern**

---

### 3. Discord Logging Setup (Production)

**Purpose:** Log everything to Discord for real-time monitoring

#### Step 1: Create Discord Server & Webhook

1. Go to Discord server settings → **Channels** → Create new channel (e.g., `#smsgang-logs`)
2. Go to **Webhooks** → Create New Webhook
3. Copy the **Webhook URL** - looks like: `https://discordapp.com/api/webhooks/XXXX/YYYY`

#### Step 2: Configure Environment Variables

Add to your **production .env**:
```env
# LOGGING
LOG_CHANNEL=stack
LOG_STACK=single,discord
LOG_DISCORD_LEVEL=error      # error, warning, info, debug
DISCORD_WEBHOOK_URL=https://discordapp.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN
```

#### Step 3: Test Discord Logging

```bash
# SSH to production server
cd /path/to/smsgang/backend

# Test error logging
php artisan tinker
>>> \Illuminate\Support\Facades\Log::error('SMS Gang production test from Tinker', ['timestamp' => now()]);
>>> exit
```

You should see the error appear in your Discord channel within seconds!

#### Step 4: What Gets Logged to Discord?

**Automatically Logged:**
- ✅ All errors (exceptions, database errors, validation errors)
- ✅ Wallet funding webhook events (success/failure) - ONLY webhook events
- ✅ Wallet transactions (pending → paid/failed)
- ✅ Virtual number purchases (immediate deduction)
- ✅ SMM order processing
- ✅ Authentication failures
- ✅ API rate limiting violations

**Log Levels & Discord Colors:**
- 🔴 **ERROR** (Orange embed) - Errors and failures
- 🔴 **CRITICAL** (Red embed) - Critical system errors
- ⚠️ **WARNING** (Yellow embed) - Warnings and issues
- 🔵 **INFO** (Blue embed) - Important info messages
- ⚫ **DEBUG** (Gray embed) - Debug information

#### Log Levels Configuration:

```env
# Only show errors and critical issues in Discord
LOG_DISCORD_LEVEL=error

# Show warnings + errors + critical
LOG_DISCORD_LEVEL=warning

# Show everything including info
LOG_DISCORD_LEVEL=info
```

#### Step 5: Monitoring Best Practices

**Discord Channel Setup:**
```
Create roles/channels:
├── #smsgang-logs          (errors, critical)
├── #smsgang-transactions  (wallet, payments)
├── #smsgang-orders        (new orders in)
├── #smsgang-alerts        (system alerts)
```

**Using Multiple Webhooks (Optional):**
```env
# Primary webhook for critical issues
DISCORD_WEBHOOK_URL=https://...

# Or create separate channels for different log types
DISCORD_WEBHOOK_ERRORS=https://...
DISCORD_WEBHOOK_TRANSACTIONS=https://...
```

---

### 4. Deployment Checklist

Before deploying to production:

```bash
# 1. Clear any cached configuration
php artisan config:clear
php artisan cache:clear

# 2. Update environment
# Add DISCORD_WEBHOOK_URL to .env

# 3. Verify Discord logging works
php artisan tinker
>>> Log::error('Production deployment test');
>>> exit

# 4. Check recent logs in Discord channel

# 5. Monitor for 1 hour after deployment
```

---

### 5. Logging Additional Events (Custom)

To log specific business events to Discord:

```php
// In your controller/service
use Illuminate\Support\Facades\Log;

// Log transaction
Log::error('Wallet funding initiated', [
    'user_id' => $user->id,
    'amount' => 50000,
    'reference' => $reference,
]);

// Log purchase
Log::info('Virtual number order created', [
    'user_id' => $user->id,
    'service' => 'telegram',
    'country' => 'Nigeria',
    'order_id' => $order->id,
]);

// Log webhook event
Log::warning('Webhook payment failed', [
    'reference' => $reference,
    'status' => 'failed',
    'reason' => 'Insufficient funds',
]);
```

---

### 6. Troubleshooting

**Discord not receiving logs:**
1. Check `DISCORD_WEBHOOK_URL` is correct in .env
2. Verify webhook still exists in Discord server
3. Check `LOG_DISCORD_LEVEL` is set to appropriate level
4. Verify network connectivity to discord.com
5. Check Laravel error logs: `tail -f storage/logs/laravel.log`

**Too many messages in Discord:**
- Increase `LOG_DISCORD_LEVEL` threshold (from `debug` → `info` → `warning` → `error`)
- Or create separate webhooks for different application areas

**Sensitive data in logs:**
- Handler automatically redacts passwords/secrets
- Review log context before sending

---

### 7. Files Modified

**Controllers:**
- ✅ `app/Http/Controllers/Api/WalletController.php` - Transaction created FIRST with pending status
- ✅ `app/Http/Controllers/Api/ActivationController.php` - Removed order payment flow (deprecated)
- ✅ `app/Http/Controllers/Api/LendoverifyWebhookController.php` - **WALLET FUNDING ONLY** (orders removed)

**Config:**
- ✅ `config/logging.php` - Discord channel configuration

**Handlers & Formatters:**
- ✅ `app/Logging/Handlers/DiscordHandler.php` - Custom Discord webhook handler
- ✅ `app/Logging/Formatters/DiscordFormatter.php` - Discord embed formatting

---

### 8. Transaction Status Reference

When wallet funding or order payment is processed:

```
pending    → Awaiting payment/processing
processing → Payment gateway is processing
paid       → Payment successful, funds added
failed     → Payment failed, order cancelled
error      → System error during processing
```

---

### 9. Monitoring Recommendations

**Set Discord Notifications:**
1. Go to Discord channel → Channel Settings → Notifications
2. Set to **All Messages** during first week (learn patterns)
3. Later switch to **Mentions Only** or specific keywords

**Create Notification Rules:**
- Mention @admin-team when there are payment failures
- Mention @support when there are API errors
- Create bots to analyze trends

---

### 10. Next Steps

1. ✅ Deploy changes to production server
2. ✅ Add Discord webhook URL to production .env
3. ✅ Test wallet funding flow (small amount)
4. ✅ Verify Discord receives webhook events
5. ✅ Create Discord monitoring channel structure
6. ✅ Train team on alerts
7. ✅ Set up automated alerts/escalations

---

## Questions?

Check logs in Discord!

---

## 🔒 **Webhook Reference Format (CRITICAL)**

⚠️ **The webhook endpoint ONLY accepts WALLET_ references**

The webhook endpoint (`POST /api/webhooks/lendoverify`) NOW ONLY accepts:
- ✅ `WALLET_*` payment references (wallet funding)
- ❌ Order payment references (NO LONGER supported)

**Why?**
- Orders use instant wallet deduction (no checkout needed, no webhook)
- Wallets use Lendoverify checkout (webhook updates transaction)
- This separation keeps the system simple and fast

**If someone tries to pay for order via checkout:**
- Webhook will reject it with reference format error
- User must fund wallet first, then order from wallet

---

## 📊 **Transaction Reference Patterns**

| Type | Reference Format | Example | Handler |
|------|-----------------|---------|---------|
| **Wallet Funding** | `WALLET_{user_id}_{timestamp}` | `WALLET_123_abc123def` | ✅ Webhook processes |
| **Virtual Order** | `order_{order_id}` | `order_789` | ❌ No webhook (wallet instant) |
| **SMM Order** | `smm_order_{order_id}` | `smm_order_456` | ❌ No webhook (wallet instant) |

---

## ✅ **Testing Checklist Before Production**

- [ ] Discord webhook URL added to .env
- [ ] Wallet funding tested with small amount
- [ ] Virtual number purchase from wallet tested
- [ ] Discord receives payment notifications
- [ ] Discord receives error logs
- [ ] No payment verification endpoints called (deprecated)
- [ ] User can fund → purchase → order flow works
- [ ] Wallet balance updates correctly after each transaction
- [ ] Verify `verifyPayment` endpoint returns 410 deprecated

---

**Ready for production!** 🚀

