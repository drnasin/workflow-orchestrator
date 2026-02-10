<?php

namespace WorkflowOrchestrator\Queue;

use Exception;
use InvalidArgumentException;
use PDO;
use WorkflowOrchestrator\Contracts\QueueInterface;
use WorkflowOrchestrator\Message\WorkflowMessage;

class SqliteQueue implements QueueInterface
{
    public function __construct(private readonly PDO $pdo, private readonly string $tableName = 'workflow_queue')
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->tableName)) {
            throw new InvalidArgumentException(
                "Invalid table name '{$this->tableName}'. Table name must contain only letters, digits, and underscores, and must start with a letter or underscore."
            );
        }

        $this->ensureTableExists();
    }

    private function ensureTableExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue_name VARCHAR(255) NOT NULL,
                message_data TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )
        ";

        $this->pdo->exec($sql);

        $indexSql = "CREATE INDEX IF NOT EXISTS idx_queue_created ON {$this->tableName} (queue_name, created_at)";
        $this->pdo->exec($indexSql);
    }

    public function push(string $queue, WorkflowMessage $message): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->tableName} (queue_name, message_data, created_at)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $queue,
            json_encode($message->toArray(), JSON_THROW_ON_ERROR),
            date('Y-m-d H:i:s')
        ]);
    }

    /**
     * @throws Exception
     */
    public function pop(string $queue): ?WorkflowMessage
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, message_data
                FROM {$this->tableName}
                WHERE queue_name = ?
                ORDER BY created_at ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute([$queue]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->pdo->rollBack();
                return null;
            }

            $deleteStmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE id = ?");
            $deleteStmt->execute([$row['id']]);

            $this->pdo->commit();

            return WorkflowMessage::fromArray(json_decode($row['message_data'], true, 512, JSON_THROW_ON_ERROR));
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function size(string $queue): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->tableName} WHERE queue_name = ?");
        $stmt->execute([$queue]);
        return (int)$stmt->fetchColumn();
    }

    public function clear(string $queue): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tableName} WHERE queue_name = ?");
        $stmt->execute([$queue]);
    }
}
