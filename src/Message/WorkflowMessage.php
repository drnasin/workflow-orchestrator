<?php
namespace WorkflowOrchestrator\Message;

use WorkflowOrchestrator\Contracts\SerializablePayload;

class WorkflowMessage
{
    /** Marker keys used to tag a SerializablePayload across JSON round-trips. */
    private const SERIALIZED_TYPE_KEY = '__wfo_payload_type__';
    private const SERIALIZED_DATA_KEY = '__wfo_payload_data__';

    public function __construct(
        private readonly mixed $payload,
        private readonly array $steps = [],
        private readonly array $headers = [],
        private string $id = ''
    ) {
        $this->id = $id !== '' ? $id : 'wf_' . bin2hex(random_bytes(16));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getNextStep(): ?string
    {
        return $this->steps[0] ?? null;
    }

    public function hasMoreSteps(): bool
    {
        return !empty($this->steps);
    }

    public function withPayload(mixed $payload): self
    {
        return new self($payload, $this->steps, $this->headers, $this->id);
    }

    public function withoutFirstStep(): self
    {
        return new self($this->payload, array_slice($this->steps, 1), $this->headers, $this->id);
    }

    public function withSteps(array $steps): self
    {
        return new self($this->payload, $steps, $this->headers, $this->id);
    }

    public function getHeader(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key] ?? $default;
    }

    public function withHeader(string $key, mixed $value): self
    {
        $headers = $this->headers;
        $headers[$key] = $value;
        return new self($this->payload, $this->steps, $headers, $this->id);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->payload, $this->steps, array_merge($this->headers, $headers), $this->id);
    }

    public function getAllHeaders(): array
    {
        return $this->headers;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'payload' => $this->encodePayload($this->payload),
            'steps' => $this->steps,
            'headers' => $this->headers,
        ];
    }

    public static function fromArray(array $data): self
    {
        if (!array_key_exists('payload', $data)) {
            throw new \InvalidArgumentException('Missing required key "payload" in message data');
        }

        return new self(
            self::decodePayload($data['payload']),
            $data['steps'] ?? [],
            $data['headers'] ?? [],
            $data['id'] ?? ''
        );
    }

    /**
     * Wraps a SerializablePayload in a tagged envelope so its concrete type can be
     * restored on the other side of a JSON round-trip. Other payload shapes
     * (scalars, plain arrays, null) pass through untouched.
     */
    private function encodePayload(mixed $payload): mixed
    {
        if ($payload instanceof SerializablePayload) {
            return [
                self::SERIALIZED_TYPE_KEY => $payload::class,
                self::SERIALIZED_DATA_KEY => $payload->toArray(),
            ];
        }

        return $payload;
    }

    /**
     * Rehydrates a payload only if it carries the envelope produced by
     * {@see encodePayload()} AND the named class still implements
     * SerializablePayload. The interface check is the whitelist: a malicious queue
     * cannot trigger construction of arbitrary classes — only those the
     * application explicitly opted in by implementing this interface.
     */
    private static function decodePayload(mixed $payload): mixed
    {
        if (!is_array($payload)
            || !isset($payload[self::SERIALIZED_TYPE_KEY], $payload[self::SERIALIZED_DATA_KEY])
            || !is_string($payload[self::SERIALIZED_TYPE_KEY])
            || !is_array($payload[self::SERIALIZED_DATA_KEY])
        ) {
            return $payload;
        }

        $type = $payload[self::SERIALIZED_TYPE_KEY];

        if (!class_exists($type) || !is_a($type, SerializablePayload::class, true)) {
            return $payload;
        }

        return $type::fromArray($payload[self::SERIALIZED_DATA_KEY]);
    }
}