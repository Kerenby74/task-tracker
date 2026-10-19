<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class Task
{
    public function __construct(
        public readonly int $id,
        public string $description,
        public TaskStatus $status = TaskStatus::Todo,
        public readonly DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            description: (string) $data['description'],
            status: TaskStatus::from($data['status']),
            // Поддерживаем как snake_case из MySQL, так и camelCase из JSON:
            createdAt: new DateTimeImmutable($data['created_at'] ?? $data['createdAt']),
            updatedAt: new DateTimeImmutable($data['updated_at'] ?? $data['updatedAt']),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'updatedAt' => $this->updatedAt->format(DateTimeImmutable::ATOM),
        ];
    }
}