<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Domain\Task;
use App\Domain\TaskStatus;

// Получаем инстанс PDO подключения к MySQL
$pdo = getDbConnection();

$command = $argv[1] ?? null;

if ($command === null) {
    echo "Использование: php task-cli.php <команда> [аргументы]\n";
    echo "Команды: add, update, delete, mark-in-progress, mark-done, list [status]\n";
    exit(0);
}

try {
    match ($command) {
        'add' => handleAdd($pdo, $argv[2] ?? null),
        'update' => handleUpdate($pdo, $argv[2] ?? null, $argv[3] ?? null),
        'delete' => handleDelete($pdo, $argv[2] ?? null),
        'mark-in-progress' => handleStatusChange($pdo, $argv[2] ?? null, TaskStatus::InProgress),
        'mark-done' => handleStatusChange($pdo, $argv[2] ?? null, TaskStatus::Done),
        'list' => handleList($pdo, $argv[2] ?? null),
        default => printError("Неизвестная команда: '{$command}'")
    };
} catch (Throwable $e) {
    printError($e->getMessage());
}

// ============================================================================
// Обработчики команд
// ============================================================================

function handleAdd(PDO $pdo, ?string $description): void
{
    if ($description === null || trim($description) === '') {
        printError('Укажите описание: php task-cli.php add "Текст задачи"');
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    // Prepared Statement для защиты от SQL Injection
    $stmt = $pdo->prepare('
        INSERT INTO tasks (description, status, created_at, updated_at) 
        VALUES (:description, :status, :created_at, :updated_at)
    ');

    $stmt->execute([
        'description' => trim($description),
        'status' => TaskStatus::Todo->value,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $newId = (int) $pdo->lastInsertId();
    echo "Task added successfully (ID: {$newId})\n";
}

function handleUpdate(PDO $pdo, ?string $idRaw, ?string $newDescription): void
{
    $id = parseId($idRaw);

    if ($newDescription === null || trim($newDescription) === '') {
        printError('Укажите описание: php task-cli.php update <id> "Текст"');
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        UPDATE tasks 
        SET description = :description, updated_at = :updated_at 
        WHERE id = :id
    ');

    $stmt->execute([
        'id' => $id,
        'description' => trim($newDescription),
        'updated_at' => $now,
    ]);

    if ($stmt->rowCount() === 0 && !taskExists($pdo, $id)) {
        printError("Задача с ID {$id} не найдена.");
    }

    echo "Task updated successfully (ID: {$id})\n";
}

function handleDelete(PDO $pdo, ?string $idRaw): void
{
    $id = parseId($idRaw);

    $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = :id');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() === 0) {
        printError("Задача с ID {$id} не найдена.");
    }

    echo "Task deleted successfully (ID: {$id})\n";
}

function handleStatusChange(PDO $pdo, ?string $idRaw, TaskStatus $status): void
{
    $id = parseId($idRaw);
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        UPDATE tasks 
        SET status = :status, updated_at = :updated_at 
        WHERE id = :id
    ');

    $stmt->execute([
        'id' => $id,
        'status' => $status->value,
        'updated_at' => $now,
    ]);

    if ($stmt->rowCount() === 0 && !taskExists($pdo, $id)) {
        printError("Задача с ID {$id} не найдена.");
    }

    echo "Task status updated to '{$status->value}' (ID: {$id})\n";
}

function handleList(PDO $pdo, ?string $statusFilterRaw): void
{
    if ($statusFilterRaw !== null) {
        $status = TaskStatus::tryFrom($statusFilterRaw);
        if ($status === null) {
            printError("Неверный статус. Допустимые: todo, in-progress, done");
        }

        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE status = :status ORDER BY id ASC');
        $stmt->execute(['status' => $status->value]);
    } else {
        $stmt = $pdo->query('SELECT * FROM tasks ORDER BY id ASC');
    }

    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        echo "Задач нет.\n";
        return;
    }

    $tasks = array_map(fn(array $row): Task => Task::fromArray($row), $rows);

    printf("%-4s | %-12s | %-32s | %-16s\n", "ID", "Статус", "Описание", "Создано");
    echo str_repeat('-', 72) . "\n";

    foreach ($tasks as $task) {
        printf(
            "%-4d | %-12s | %-32s | %-16s\n",
            $task->id,
            $task->status->value,
            mb_strimwidth($task->description, 0, 30, '...'),
            $task->createdAt->format('Y-m-d H:i')
        );
    }
}

// ============================================================================
// Подключение к БД и хелперы
// ============================================================================

function getDbConnection(): PDO
{
    // Берем переменные, которые мы прописали в docker-compose.yml
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_NAME') ?: 'task_tracker';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: 'rootpassword';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function taskExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM tasks WHERE id = :id');
    $stmt->execute(['id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function parseId(?string $idRaw): int
{
    $id = filter_var($idRaw, FILTER_VALIDATE_INT);
    if ($id === false) {
        printError("ID задачи должен быть целым числом. Получено: '{$idRaw}'");
    }
    return (int) $id;
}

function printError(string $message): never
{
    fwrite(STDERR, "Ошибка: {$message}\n");
    exit(1);
}