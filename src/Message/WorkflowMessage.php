<?php
namespace WorkflowOrchestrator\Message;

class WorkflowMessage
{
    public function __construct(
        private readonly mixed $payload,
        private readonly array $steps = [],
        private readonly array $headers = [],
        private string $id = ''
    ) {
        $this->id = $id ?: uniqid('wf_', true);
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
            'payload' => $this->payload,
            'steps' => $this->steps,
            'headers' => $this->headers,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['payload'],
            $data['steps'] ?? [],
            $data['headers'] ?? [],
            $data['id'] ?? ''
        );
    }
}