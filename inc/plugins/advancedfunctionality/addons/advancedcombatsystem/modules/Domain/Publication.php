<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** R2 publication vocabulary. Generic operations (draft/clock/admin) keep their own statuses. */
final class AF_ACS_Publication
{
    public const STATES = ['registered','validating','posting','post_confirmed','committing','committed','recovery_required','rejected','moderation_pending'];
    private const NEXT = [
        'registered' => ['validating','rejected','recovery_required'],
        'validating' => ['posting','rejected','recovery_required'],
        'posting' => ['post_confirmed','moderation_pending','recovery_required'],
        'post_confirmed' => ['committing','recovery_required'],
        'committing' => ['committed','recovery_required'],
        'moderation_pending' => ['post_confirmed','recovery_required'],
        // Only a separately authorized, audited proof can resolve uncertainty.
        'recovery_required' => ['post_confirmed','moderation_pending','rejected'],
        'rejected' => [], 'committed' => [],
    ];

    public static function requireTransition(string $from, string $to): void
    {
        if (!in_array($to, self::NEXT[$from] ?? [], true)) {
            throw new RuntimeException('ACS_PUBLICATION_TRANSITION_DENIED');
        }
    }

    /** Safe projection: a persisted post is not evidence of applied damage. */
    public static function card(string $status): array
    {
        if (!in_array($status, self::STATES, true)) { throw new RuntimeException('ACS_PUBLICATION_STATE_INVALID'); }
        return [
            'status' => $status,
            'mechanics_confirmed' => $status === 'committed',
            'label' => match ($status) {
                'committed' => 'Результат подтверждён',
                'recovery_required' => 'Требуется восстановление',
                'moderation_pending' => 'Сообщение ожидает модерации',
                'rejected' => 'Публикация отклонена',
                default => 'Публикация обрабатывается',
            },
        ];
    }
}
