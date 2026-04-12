<?php

require __DIR__ . '/app/bootstrap.php';

if (!is_post_request()) {
    redirect_to('index.php');
}

$snapshot = load_daily_store();
log_activity_event('daily_reset', [
    'details' => 'Reset the full master data store.',
    'reference' => ($snapshot['latest_date'] ?? '') !== '' ? $snapshot['latest_date'] : current_business_date(),
    'count' => (int) ($snapshot['row_count'] ?? 0),
]);

reset_daily_store();
set_flash('success', 'The full master CSV/JSON data store has been reset.');
redirect_to('index.php');
