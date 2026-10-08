<?php
/**
 * Telegram alerts for the things a small hosting company wants to hear about
 * the moment they happen: new orders, paid invoices, new tickets and client
 * replies, failed module actions, cancellation requests.
 *
 * Setup: create a bot with @BotFather, add it to a chat or group, get the chat
 * id (send a message, then open https://api.telegram.org/bot<TOKEN>/getUpdates)
 * and fill in $config below. Pick the events you want. Drop the file into
 * <whmcs>/includes/hooks/.
 *
 * Messages are plain HTML (bold labels), sent with a 5-second timeout. A
 * failed delivery is written to the WHMCS activity log and never interrupts
 * the action that triggered it.
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function tgn_config(): array
{
    return [
        'bot_token' => '',          // 123456789:AA... from @BotFather (required)
        'chat_id' => '',            // chat or group id, e.g. -1001234567890 (required)
        'admin_url' => '',          // e.g. https://billing.example.com/admin ; adds links to messages when set
        'events' => [
            'order' => true,        // new order placed
            'invoice_paid' => true, // invoice paid
            'ticket_open' => true,  // new support ticket
            'ticket_reply' => true, // client replied to a ticket
            'module_failed' => true,// create/suspend/unsuspend/terminate/change package failed
            'cancellation' => true, // client submitted a cancellation request
        ],
        'silent' => false,          // true = Telegram delivers without a notification sound
    ];
}

function tgn_send(string $html): void
{
    $c = tgn_config();
    if ($c['bot_token'] === '' || $c['chat_id'] === '') {
        return;
    }
    try {
        $ch = curl_init('https://api.telegram.org/bot' . $c['bot_token'] . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $c['chat_id'],
                'text' => mb_substr($html, 0, 4000),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'disable_notification' => $c['silent'] ? 'true' : 'false',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code !== 200 && function_exists('logActivity')) {
            logActivity('Telegram notification failed (HTTP ' . $code . '): ' . ($err ?: mb_substr((string) $body, 0, 200)));
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Telegram notification failed: ' . $e->getMessage());
        }
    }
}

/** HTML-escape a value for a Telegram message. */
function tgn_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** "Name (company)" for a client id, or "#id" when unknown. */
function tgn_client(int $userid): string
{
    try {
        $cl = Capsule::table('tblclients')->where('id', $userid)->first(['firstname', 'lastname', 'companyname', 'email']);
        if (!$cl) {
            return '#' . $userid;
        }
        $name = trim($cl->firstname . ' ' . $cl->lastname) . ($cl->companyname ? ' (' . $cl->companyname . ')' : '');
        return $name . ' · ' . $cl->email;
    } catch (\Throwable $e) {
        return '#' . $userid;
    }
}

function tgn_link(string $path, string $label): string
{
    $c = tgn_config();
    return $c['admin_url'] !== '' ? '<a href="' . tgn_e(rtrim($c['admin_url'], '/') . '/' . ltrim($path, '/')) . '">' . tgn_e($label) . '</a>' : tgn_e($label);
}

// New order: hook fires after checkout, before payment.
add_hook('AfterShoppingCartCheckout', 1, function (array $vars): void {
    if (empty(tgn_config()['events']['order'])) {
        return;
    }
    try {
        $order = Capsule::table('tblorders')->where('id', (int) $vars['OrderID'])->first();
        if (!$order) {
            return;
        }
        $items = Capsule::table('tblhosting AS h')->join('tblproducts AS p', 'p.id', '=', 'h.packageid')
            ->where('h.orderid', $order->id)->pluck('p.name')->all();
        $domains = Capsule::table('tbldomains')->where('orderid', $order->id)->pluck('domain')->all();
        $currency = Capsule::table('tblcurrencies')->where('id', (int) Capsule::table('tblclients')->where('id', $order->userid)->value('currency'))->value('code') ?: '';
        tgn_send('🛒 <b>New order</b> ' . tgn_link('orders.php?action=view&id=' . $order->id, '#' . $order->ordernum) . "\n"
            . 'Client: ' . tgn_e(tgn_client((int) $order->userid)) . "\n"
            . 'Items: ' . tgn_e(implode(', ', array_merge($items, $domains)) ?: '-') . "\n"
            . 'Total: ' . tgn_e(number_format((float) $order->amount, 2) . ' ' . $currency) . ' · ' . tgn_e($order->paymentmethod));
    } catch (\Throwable $e) {
        logActivity('Telegram order notification skipped: ' . $e->getMessage());
    }
});

add_hook('InvoicePaid', 1, function (array $vars): void {
    if (empty(tgn_config()['events']['invoice_paid'])) {
        return;
    }
    try {
        $inv = Capsule::table('tblinvoices')->where('id', (int) $vars['invoiceid'])->first(['id', 'userid', 'total', 'paymentmethod']);
        if (!$inv) {
            return;
        }
        $currency = Capsule::table('tblcurrencies')->where('id', (int) Capsule::table('tblclients')->where('id', $inv->userid)->value('currency'))->value('code') ?: '';
        tgn_send('💳 <b>Invoice paid</b> ' . tgn_link('invoices.php?action=edit&id=' . $inv->id, '#' . $inv->id) . "\n"
            . 'Client: ' . tgn_e(tgn_client((int) $inv->userid)) . "\n"
            . 'Amount: ' . tgn_e(number_format((float) $inv->total, 2) . ' ' . $currency) . ' · ' . tgn_e($inv->paymentmethod));
    } catch (\Throwable $e) {
        logActivity('Telegram invoice notification skipped: ' . $e->getMessage());
    }
});

add_hook('TicketOpen', 1, function (array $vars): void {
    if (empty(tgn_config()['events']['ticket_open'])) {
        return;
    }
    $who = !empty($vars['userid']) ? tgn_client((int) $vars['userid']) : 'guest (no client account)';
    tgn_send('🎫 <b>New ticket</b> ' . tgn_link('supporttickets.php?action=view&id=' . (int) $vars['ticketid'], '#' . ($vars['ticketmask'] ?? $vars['ticketid'])) . "\n"
        . 'From: ' . tgn_e($who) . "\n"
        . 'Dept: ' . tgn_e($vars['deptname'] ?? '') . ' · Priority: ' . tgn_e($vars['priority'] ?? '') . "\n"
        . 'Subject: ' . tgn_e($vars['subject'] ?? '') . "\n"
        . tgn_e(mb_substr(trim(strip_tags((string) ($vars['message'] ?? ''))), 0, 300)));
});

add_hook('TicketUserReply', 1, function (array $vars): void {
    if (empty(tgn_config()['events']['ticket_reply'])) {
        return;
    }
    $who = !empty($vars['userid']) ? tgn_client((int) $vars['userid']) : 'guest (no client account)';
    tgn_send('💬 <b>Ticket reply</b> ' . tgn_link('supporttickets.php?action=view&id=' . (int) $vars['ticketid'], '#' . ($vars['ticketmask'] ?? $vars['ticketid'])) . "\n"
        . 'From: ' . tgn_e($who) . "\n"
        . 'Subject: ' . tgn_e($vars['subject'] ?? '') . "\n"
        . tgn_e(mb_substr(trim(strip_tags((string) ($vars['message'] ?? ''))), 0, 300)));
});

foreach (['AfterModuleCreateFailed' => 'Create', 'AfterModuleSuspendFailed' => 'Suspend', 'AfterModuleUnsuspendFailed' => 'Unsuspend', 'AfterModuleTerminateFailed' => 'Terminate', 'AfterModuleChangePackageFailed' => 'Change package'] as $hook => $label) {
    add_hook($hook, 1, function (array $vars) use ($label): void {
        if (empty(tgn_config()['events']['module_failed'])) {
            return;
        }
        $p = $vars['params'] ?? [];
        $sid = (int) ($p['serviceid'] ?? 0);
        tgn_send('🚨 <b>Module ' . tgn_e(strtolower($label)) . ' failed</b> ' . ($sid ? tgn_link('clientsservices.php?id=' . $sid, 'service #' . $sid) : '') . "\n"
            . 'Client: ' . tgn_e(!empty($p['userid']) ? tgn_client((int) $p['userid']) : '-') . "\n"
            . 'Product: ' . tgn_e(($p['producttype'] ?? '') . ' ' . ($p['domain'] ?? '')) . "\n"
            . 'Error: ' . tgn_e(mb_substr((string) ($vars['failureResponseMessage'] ?? ''), 0, 400)));
    });
}

add_hook('CancellationRequest', 1, function (array $vars): void {
    if (empty(tgn_config()['events']['cancellation'])) {
        return;
    }
    $sid = (int) ($vars['relid'] ?? 0);
    tgn_send('⚠️ <b>Cancellation request</b> ' . ($sid ? tgn_link('clientsservices.php?id=' . $sid, 'service #' . $sid) : '') . "\n"
        . 'Client: ' . tgn_e(!empty($vars['userid']) ? tgn_client((int) $vars['userid']) : '-') . "\n"
        . 'Type: ' . tgn_e($vars['type'] ?? '') . "\n"
        . 'Reason: ' . tgn_e(mb_substr((string) ($vars['reason'] ?? ''), 0, 300)));
});
