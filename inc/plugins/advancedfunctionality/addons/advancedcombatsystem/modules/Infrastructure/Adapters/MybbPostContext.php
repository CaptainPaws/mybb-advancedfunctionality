<?php
if (!defined('IN_MYBB')) { die('No direct access'); }
require_once __DIR__.'/../SubmissionStore.php';

enum AF_ACS_MybbPostStatus: string
{
    case POST_CONFIRMED = 'POST_CONFIRMED';
    case MODERATION_PENDING = 'MODERATION_PENDING';
    case REJECTED = 'REJECTED';
    case RECOVERY_REQUIRED = 'RECOVERY_REQUIRED';
}

final readonly class AF_ACS_MybbPostResult
{
    public function __construct(
        public AF_ACS_MybbPostStatus $status,
        public string $submissionId,
        public ?string $pid,
        public string $stateVersion,
        public string $code,
        public array $validationErrors = [],
    ) {}
}

/** Server-only envelope. HTTP cannot supply a row, prose, actor, pid or controls. */
final readonly class AF_ACS_MybbPostContext
{
    private function __construct(public array $submission, public array $options) {}

    public static function fromStore(AF_ACS_SubmissionStore $store, string $id, string $expectedVersion, array $options = []): self
    {
        $row = $store->submission($id) ?? throw new RuntimeException('ACS_SUBMISSION_NOT_FOUND');
        if ($row['state_version'] !== AF_ACS_Repository::integer($expectedVersion)) { throw new RuntimeException('ACS_VERSION_CONFLICT'); }
        if (array_diff(array_keys($options), ['subject','replyto','icon','signature','subscriptionmethod','disablesmilies','posthash','quick_reply'])) {
            throw new InvalidArgumentException('ACS_MYBB_OPTIONS_UNSUPPORTED');
        }
        foreach (['subject','posthash'] as $field) {
            if (isset($options[$field]) && !is_string($options[$field])) { throw new InvalidArgumentException('ACS_MYBB_OPTIONS_UNSUPPORTED'); }
        }
        foreach (['replyto','icon'] as $field) { $options[$field] = AF_ACS_Repository::integer($options[$field] ?? '0','4294967295',true); }
        foreach (['signature','disablesmilies','quick_reply'] as $field) {
            if (!in_array($options[$field] ?? 0,[0,1,false,true],true)) { throw new InvalidArgumentException('ACS_MYBB_OPTIONS_UNSUPPORTED'); }
            $options[$field] = (int)($options[$field] ?? 0);
        }
        if (!in_array($options['subscriptionmethod'] ?? '',['','none','email','pm'],true)) { throw new InvalidArgumentException('ACS_MYBB_OPTIONS_UNSUPPORTED'); }
        $options['subscriptionmethod'] ??= '';
        $options['posthash'] ??= '';
        if ($options['posthash'] !== '' && !preg_match('/\A[a-f0-9]{32}\z/D',$options['posthash'])) { throw new InvalidArgumentException('ACS_MYBB_OPTIONS_UNSUPPORTED'); }
        return new self($row,$options);
    }
}
