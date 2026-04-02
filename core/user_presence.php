<?php
/**
 * Online presence helpers.
 */

function presence_schema_ready(): bool
{
    return shift_schema_has_column('users', 'last_seen_at');
}

function touch_current_user_presence(): void
{
    if (!Auth::check() || !presence_schema_ready()) {
        return;
    }

    $nowTs = time();
    $lastTouched = (int)($_SESSION['presence_last_touched_at'] ?? 0);
    if ($lastTouched > 0 && ($nowTs - $lastTouched) < 60) {
        return;
    }

    $_SESSION['presence_last_touched_at'] = $nowTs;

    try {
        Database::exec(
            "UPDATE users SET last_seen_at = NOW() WHERE id = ?",
            [Auth::id()]
        );
    } catch (Throwable $e) {
        // Presence is non-critical; fail silently to avoid breaking requests.
    }
}

function user_is_online(array $user, ?DateTimeImmutable $now = null, int $thresholdSeconds = 300): bool
{
    if (!presence_schema_ready()) {
        return false;
    }

    $lastSeenAt = shift_datetime($user['last_seen_at'] ?? null);
    if (!$lastSeenAt) {
        return false;
    }

    $now = $now ?: shift_now();
    return ($now->getTimestamp() - $lastSeenAt->getTimestamp()) <= $thresholdSeconds;
}
