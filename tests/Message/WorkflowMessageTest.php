<?php
namespace WorkflowOrchestrator\Tests\Message;

use PHPUnit\Framework\TestCase;
use WorkflowOrchestrator\Message\WorkflowMessage;

class WorkflowMessageTest extends TestCase
{
    public function test_creates_message_with_payload(): void
    {
        $payload = ['data' => 'value'];
        $message = new WorkflowMessage($payload);

        $this->assertSame($payload, $message->getPayload());
        $this->assertNotEmpty($message->getId());
    }

    public function test_creates_message_with_custom_id(): void
    {
        $customId = 'custom-workflow-id';
        $message = new WorkflowMessage('payload', [], [], $customId);

        $this->assertSame($customId, $message->getId());
    }

    public function test_manages_workflow_steps(): void
    {
        $steps = ['step1', 'step2', 'step3'];
        $message = new WorkflowMessage('payload', $steps);

        $this->assertSame($steps, $message->getSteps());
        $this->assertTrue($message->hasMoreSteps());
        $this->assertSame('step1', $message->getNextStep());
    }

    public function test_removes_first_step(): void
    {
        $steps = ['step1', 'step2', 'step3'];
        $message = new WorkflowMessage('payload', $steps);

        $newMessage = $message->withoutFirstStep();

        $this->assertNotSame($message, $newMessage);
        $this->assertSame(['step2', 'step3'], $newMessage->getSteps());
        $this->assertSame('step2', $newMessage->getNextStep());
    }

    public function test_handles_empty_steps(): void
    {
        $message = new WorkflowMessage('payload', []);

        $this->assertFalse($message->hasMoreSteps());
        $this->assertNull($message->getNextStep());
    }

    public function test_manages_headers(): void
    {
        $headers = ['key1' => 'value1', 'key2' => 'value2'];
        $message = new WorkflowMessage('payload', [], $headers);

        $this->assertSame('value1', $message->getHeader('key1'));
        $this->assertSame('default', $message->getHeader('nonexistent', 'default'));
        $this->assertSame($headers, $message->getAllHeaders());
    }

    public function test_adds_single_header(): void
    {
        $message = new WorkflowMessage('payload');
        $newMessage = $message->withHeader('key', 'value');

        $this->assertNotSame($message, $newMessage);
        $this->assertSame('value', $newMessage->getHeader('key'));
        $this->assertNull($message->getHeader('key'));
    }

    public function test_merges_headers(): void
    {
        $message = new WorkflowMessage('payload', [], ['existing' => 'value']);
        $newHeaders = ['new1' => 'value1', 'new2' => 'value2'];
        $newMessage = $message->withHeaders($newHeaders);

        $expected = ['existing' => 'value', 'new1' => 'value1', 'new2' => 'value2'];
        $this->assertSame($expected, $newMessage->getAllHeaders());
    }

    public function test_updates_payload(): void
    {
        $message = new WorkflowMessage('original');
        $newMessage = $message->withPayload('updated');

        $this->assertNotSame($message, $newMessage);
        $this->assertSame('original', $message->getPayload());
        $this->assertSame('updated', $newMessage->getPayload());
    }

    public function test_updates_steps(): void
    {
        $message = new WorkflowMessage('payload', ['old1', 'old2']);
        $newSteps = ['new1', 'new2', 'new3'];
        $newMessage = $message->withSteps($newSteps);

        $this->assertNotSame($message, $newMessage);
        $this->assertSame(['old1', 'old2'], $message->getSteps());
        $this->assertSame($newSteps, $newMessage->getSteps());
    }

    public function test_generates_unique_cryptographic_ids(): void
    {
        $message1 = new WorkflowMessage('payload1');
        $message2 = new WorkflowMessage('payload2');

        $this->assertNotSame($message1->getId(), $message2->getId());
        $this->assertMatchesRegularExpression('/^wf_[0-9a-f]{32}$/', $message1->getId());
        $this->assertMatchesRegularExpression('/^wf_[0-9a-f]{32}$/', $message2->getId());
    }

    public function test_preserves_id_across_transformations(): void
    {
        $message = new WorkflowMessage('payload', ['step1'], [], 'test-id');

        $newMessage = $message
            ->withPayload('new-payload')
            ->withHeader('key', 'value')
            ->withoutFirstStep();

        $this->assertSame('test-id', $newMessage->getId());
    }
}