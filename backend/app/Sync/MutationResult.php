<?php

namespace App\Sync;

/**
 * One entry of a push response (SYNCDESKTOP §4.4).
 *
 * FOUR statuses, and the difference between them is what the client does next:
 *   applied    - done; adopt `server_id`/`sync_version`, drop the outbox row.
 *   duplicate  - already done in an earlier attempt; same treatment, no error.
 *   conflict   - the server row moved under fields this mutation touches; the
 *                outbox row goes to the Conflict Inbox with `server_row`.
 *   rejected   - terminal refusal (policy, validation, state machine). Retrying
 *                the same bytes cannot succeed.
 *
 * A TRANSIENT failure is deliberately NOT in this list. Protocol §2.4/P4a:
 * when the lock-wait retry is exhausted the mutation gets no entry at all, and
 * §4.3/P10b makes "no entry" mean "not processed, still queued". Giving a
 * temporary condition a terminal status is how offline work gets lost.
 */
final class MutationResult
{
    private function __construct(
        public readonly int $seq,
        public readonly string $status,
        public readonly ?int $serverId = null,
        public readonly ?int $syncVersion = null,
        public readonly ?string $code = null,
        public readonly ?array $conflictingFields = null,
        public readonly ?array $serverRow = null,
        public readonly ?string $message = null,
        public readonly ?int $affected = null,
    ) {}

    public static function applied(int $seq, ?int $serverId, ?int $syncVersion, ?int $affected = null): self
    {
        return new self($seq, 'applied', serverId: $serverId, syncVersion: $syncVersion, affected: $affected);
    }

    public static function duplicate(int $seq, ?int $serverId = null, ?int $syncVersion = null): self
    {
        return new self($seq, 'duplicate', serverId: $serverId, syncVersion: $syncVersion);
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<string, mixed>|null  $serverRow
     */
    public static function conflict(int $seq, string $code, array $fields, ?array $serverRow, ?int $syncVersion): self
    {
        return new self(
            $seq,
            'conflict',
            syncVersion: $syncVersion,
            code: $code,
            conflictingFields: $fields,
            serverRow: $serverRow,
        );
    }

    /**
     * @param  array<string, mixed>|null  $serverRow
     */
    public static function rejected(int $seq, string $code, ?string $message = null, ?array $serverRow = null): self
    {
        return new self($seq, 'rejected', code: $code, serverRow: $serverRow, message: $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['seq' => $this->seq, 'status' => $this->status];

        foreach ([
            'server_id' => $this->serverId,
            'sync_version' => $this->syncVersion,
            'code' => $this->code,
            'conflicting_fields' => $this->conflictingFields,
            'server_row' => $this->serverRow,
            'message' => $this->message,
            'affected' => $this->affected,
        ] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self(
            seq: (int) ($stored['seq'] ?? 0),
            status: 'duplicate',
            serverId: isset($stored['server_id']) ? (int) $stored['server_id'] : null,
            syncVersion: isset($stored['sync_version']) ? (int) $stored['sync_version'] : null,
        );
    }
}
