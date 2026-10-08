<?php
/**
 * Stops a module Terminate from running on a service that nobody meant to
 * end: an Active service with no due cancellation request and no cancelled
 * order. Suspension stays untouched; a service that was suspended first, has
 * a cancellation request, or belongs to a cancelled order terminates as
 * usual. To terminate an Active service on purpose, suspend it first and
 * terminate within the window set below; that is the two-step most teams
 * already follow.
 *
 * Why: in WHMCS, cancelling an order with "Run module terminate" ticked, a
 * mis-click on the service page, or an automation rule all call the same
 * module function, and for a VPS provider that is the customer's disk. This
 * hook turns the dangerous cases into a logged refusal.
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function tsg_config(): array
{
    return [
        'enabled' => true,
        // Module system names this applies to; empty = every module.
        'modules' => [],
        // Terminate is allowed when the service was suspended within this many
        // minutes (the two-step "suspend, then terminate" done on purpose).
        'suspended_within_minutes' => 60,
        // Notify admins when a terminate is refused.
        'email_admins' => true,
    ];
}

add_hook('PreModuleTerminate', 1, function (array $vars) {
    $c = tsg_config();
    if (!$c['enabled']) {
        return [];
    }
    $p = $vars['params'] ?? [];
    $sid = (int) ($p['serviceid'] ?? 0);
    if (!$sid) {
        return [];
    }
    if ($c['modules'] && !in_array((string) ($p['moduletype'] ?? ''), $c['modules'], true)) {
        return [];
    }
    try {
        $h = Capsule::table('tblhosting')->where('id', $sid)->first(['domainstatus', 'orderid', 'userid', 'domain']);
        if (!$h) {
            return [];
        }
        // Reasons the terminate is legitimate.
        if ($h->domainstatus !== 'Active') {
            return []; // suspended, cancelled, pending, fraud: let it through
        }
        if (Capsule::table('tblorders')->where('id', (int) $h->orderid)->whereIn('status', ['Cancelled', 'Fraud'])->exists()) {
            return [];
        }
        if (Capsule::table('tblcancelrequests')->where('relid', $sid)->exists()) {
            return [];
        }
        if ($c['suspended_within_minutes'] > 0) {
            $since = date('Y-m-d H:i:s', time() - $c['suspended_within_minutes'] * 60);
            $recentlySuspended = Capsule::table('tblactivitylog')
                ->where('date', '>=', $since)
                ->where('description', 'like', 'Module Suspend Successful - Service ID: ' . $sid . '%')
                ->exists();
            if ($recentlySuspended) {
                return [];
            }
        }
        $msg = 'Terminate refused for Active service #' . $sid . ' (' . $h->domain . '): no cancellation request, order not cancelled, not suspended first. Suspend it, or cancel the order, then terminate.';
        logActivity('terminate_safeguard: ' . $msg, (int) $h->userid);
        if ($c['email_admins'] && function_exists('localAPI')) {
            localAPI('SendAdminEmail', ['customsubject' => 'Terminate refused on service #' . $sid, 'custommessage' => nl2br(htmlspecialchars($msg)), 'type' => 'system']);
        }
        return ['abortcmd' => true];
    } catch (\Throwable $e) {
        logActivity('terminate_safeguard: check failed, terminate allowed: ' . $e->getMessage());
        return [];
    }
});
