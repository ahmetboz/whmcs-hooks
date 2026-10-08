<?php
/**
 * Ticket statuses carry their colour inline (Setup > Support > Ticket
 * Statuses), and "Answered" is #000000 by default. Themes that give labels
 * dark text then show black on black in the client area's "Recent Support
 * Tickets" panel and ticket list. This hook makes any label with an inline
 * background colour use white text.
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

add_hook('ClientAreaHeadOutput', 1, function (array $vars): string {
    return '<style>.label[style*="background-color"],.status[style*="background-color"],.badge[style*="background-color"],.status-custom{color:#fff!important;border-color:transparent!important}</style>';
});
