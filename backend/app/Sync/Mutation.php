<?php

namespace App\Sync;

/**
 * One entry of a push batch (SYNCDESKTOP §4.4).
 *
 * A value object rather than a bare array so the applier cannot silently read
 * a key that the wire format never defines - the batch arrives from an
 * offline client that may be several app versions behind.
 */
final class Mutation
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly int $seq,
        public readonly string $idempotencyKey,
        public readonly string $op,
        public readonly string $entity,
        public readonly ?string $clientId,
        public readonly ?int $serverId,
        public readonly ?int $baseSyncVersion,
        public readonly ?string $action,
        public readonly ?string $scope,
        public readonly ?string $occurredAt,
        public readonly array $changedFields,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            seq: (int) $raw['seq'],
            idempotencyKey: (string) $raw['idempotency_key'],
            op: (string) $raw['op'],
            entity: (string) $raw['entity'],
            clientId: isset($raw['client_id']) ? (string) $raw['client_id'] : null,
            serverId: isset($raw['server_id']) ? (int) $raw['server_id'] : null,
            baseSyncVersion: isset($raw['base_sync_version']) ? (int) $raw['base_sync_version'] : null,
            action: isset($raw['action']) ? (string) $raw['action'] : null,
            scope: isset($raw['scope']) ? (string) $raw['scope'] : null,
            occurredAt: isset($raw['occurred_at']) ? (string) $raw['occurred_at'] : null,
            changedFields: array_values(array_map('strval', $raw['changed_fields'] ?? [])),
            payload: $raw['payload'] ?? [],
        );
    }
}
