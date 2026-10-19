<?php
declare(strict_types=1);

namespace App\Domain;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in-progress';
    case Done = 'done';
}
