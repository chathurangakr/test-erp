<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * Telegram could not take the message now — unreachable (no internet, Telegram down) or asking to
 * slow down (429 flood control) — as opposed to refusing it. Waiting work should simply be tried
 * again later.
 */
class TelegramUnreachable extends RuntimeException {}
