<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Domain\Task;
use App\Domain\TaskStatus;

// Путь к файлу базы данных
const DB_FILE = __DIR__ . '/tasks.json';

// Получаем переданные CLI-аргументы
// $argv[0] — это имя самого скрипта ('task-cli.php')
// $argv[1] — имя команды ('add', 'update', 'delete', 'list' и т.д.)
$command = $argv[1] ?? null;

if ($command === null) {
    echo "Использование: php task-cli.php <команда> [аргументы]\n";
    echo "Доступные команды:\n";
    echo "  add <описание>\n";
    echo "  update <id> <новое описание>\n";
    echo "  delete <id>\n";
    echo "  mark-in-progress <id>\n";
    echo "  mark-done <id>\n";
    echo "  list [todo|in-progress|done]\n";
    exit(0);
}

// Конструкция match (появилась в PHP 8.0) — строгий аналог switch,
// который сравнивает через === и сразу вызывает нужную функцию
match ($command) {
    'add' => handleAdd($argv[2] ?? null),
    'update' => handleUpdate($argv[2] ?? null, $argv[3] ?? null),
    'delete' => handleDelete($argv[2] ?? null),
    'mark-in-progress' => handleStatusChange($argv[2] ?? null, TaskStatus::InProgress),
    'mark-done' => handleStatusChange($argv[2] ?? null, TaskStatus::Done),
    'list' => handleList($argv[2] ?? null),
    default => printError("Неизвестная команда: '$command'")
};

// ============================================================================
// Функции-обработчики команд
// ============================================================================

function handleAdd(?string $description): void
{
    if ($description === null || trim($description) === '') {
        printError('Укажите описание задачи: php task-cli.php add "Текст задачи"');
    }

    $tasks = loadTasks();

    // Вычисляем следующий ID (автоинкремент)
    $nextId = 1;
    if (!empty($tasks)) {
        $ids = array_map(fn(Task $task): int => $task->id, $tasks);
        $nextId = max($ids) + 1;
    }

    $newTask = new Task(
        id: $nextId,
        description: trim($description),
        status: TaskStatus::Todo
    );

    $tasks[] = $newTask;
    saveTasks($tasks);

    echo "Task added successfully (ID: {$newTask->id})\n";
}

function handleUpdate(?string $idRaw, ?string $newDescription): void
{
    $id = parseId($idRaw);

    if ($newDescription === null || trim($newDescription) === '') {
        printError('Укажите новое описание: php task-cli.php update <id> "Новый текст"');
    }

    $tasks = loadTasks();
    $found = false;

    foreach ($tasks as $task) {
        if ($task->id === $id) {
            $task->description = trim($newDescription);
            $task->updatedAt = new DateTimeImmutable();
            $found = true;
            break;
        }
    }

    if (!$found) {
        printError("Задача с ID {$id} не найдена.");
    }

    saveTasks($tasks);
    echo "Task updated successfully (ID: {$id})\n";
}

function handleDelete(?string $idRaw): void
{
    $id = parseId($idRaw);
    $tasks = loadTasks();

    // array_filter оставляет только элементы, подходящие под условие
    // array_values переиндексирует ключи массива с 0
    $filteredTasks = array_values(
        array_filter($tasks, fn(Task $task): bool => $task->id !== $id)
    );

    if (count($tasks) === count($filteredTasks)) {
        printError("Задача с ID {$id} не найдена.");
    }

    saveTasks($filteredTasks);
    echo "Task deleted successfully (ID: {$id})\n";
}

function handleStatusChange(?string $idRaw, TaskStatus $newStatus): void
{
    $id = parseId($idRaw);
    $tasks = loadTasks();
    $found = false;

    foreach ($tasks as $task) {
        if ($task->id === $id) {
            $task->status = $newStatus;
            $task->updatedAt = new DateTimeImmutable();
            $found = true;
            break;
        }
    }

    if (!$found) {
        printError("Задача с ID {$id} не найдена.");
    }

    saveTasks($tasks);
    echo "Task status updated to '{$newStatus->value}' (ID: {$id})\n";
}

function handleList(?string $statusFilterRaw): void
{
    $tasks = loadTasks();

    // Если передан фильтр по статусу (например, php task-cli.php list done)
    if ($statusFilterRaw !== null) {
        // tryFrom возвращает null, если переданная строка не совпадает ни с одним статусом
        $filterStatus = TaskStatus::tryFrom($statusFilterRaw);
        if ($filterStatus === null) {
            printError("Неверный статус. Допустимые: todo, in-progress, done");
        }

        $tasks = array_values(
            array_filter($tasks, fn(Task $task): bool => $task->status === $filterStatus)
        );
    }

    if (empty($tasks)) {
        echo "Задач нет.\n";
        return;
    }

    // Красивый табличный вывод в консоль
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
// Вспомогательные функции работы с файлом и валидацией
// ============================================================================

/**
 * Читает tasks.json и возвращает список объектов Task
 * @return list<Task>
 */
function loadTasks(): array
{
    if (!file_exists(DB_FILE)) {
        return [];
    }

    $content = file_get_contents(DB_FILE);
    if ($content === false || trim($content) === '') {
        return [];
    }

    $raw = json_decode($content, true);
    if (!is_array($raw)) {
        return [];
    }

    // Превращаем каждый сырой ассоциативный массив в объект Task
    return array_map(fn(array $item): Task => Task::fromArray($item), $raw);
}

/**
 * Сериализует объекты Task и сохраняет их в tasks.json
 * @param list<Task> $tasks
 */
function saveTasks(array $tasks): void
{
    $payload = array_map(fn(Task $task): array => $task->toArray(), $tasks);

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        printError('Ошибка при сериализации данных в JSON.');
    }

    // LOCK_EX блокирует файл на запись (защита от race condition)
    file_put_contents(DB_FILE, $json, LOCK_EX);
}

/**
 * Валидирует и приводит строковый ID к числу
 */
function parseId(?string $idRaw): int
{
    $id = filter_var($idRaw, FILTER_VALIDATE_INT);
    if ($id === false) {
        printError("ID задачи должен быть целым числом. Получено: '{$idRaw}'");
    }

    return (int) $id;
}

/**
 * Выводит сообщение об ошибке в поток ошибок STDERR и завершает скрипт с кодом 1
 */
function printError(string $message): never
{
    fwrite(STDERR, "Ошибка: {$message}\n");
    exit(1);
}