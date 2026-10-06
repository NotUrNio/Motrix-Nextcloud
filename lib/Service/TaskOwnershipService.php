<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use OCP\IDBConnection;

class TaskOwnershipService {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function recordTask(string $taskId, string $userId, string $targetFolder = ''): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('motrix_tasks')
            ->values([
                'task_id' => $qb->createNamedParameter($taskId),
                'user_id' => $qb->createNamedParameter($userId),
                'target_folder' => $qb->createNamedParameter($targetFolder),
                'created_at' => $qb->createNamedParameter(time()),
                'synced' => $qb->createNamedParameter(false, \PDO::PARAM_BOOL),
            ]);
        $qb->executeStatement();
    }

    public function getTask(string $taskId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('motrix_tasks')
            ->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        return $row !== false ? $row : null;
    }

    public function isTaskOwnedBy(string $taskId, string $userId): bool {
        $task = $this->getTask($taskId);
        return $task !== null && $task['user_id'] === $userId;
    }

    /**
     * @return string[] List of task_id strings owned by the user
     */
    public function getUserTaskIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('task_id')
            ->from('motrix_tasks')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $taskIds = [];
        while ($row = $result->fetchAssociative()) {
            $taskIds[] = (string)$row['task_id'];
        }
        return $taskIds;
    }

    public function markTaskSynced(string $taskId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('motrix_tasks')
            ->set('synced', $qb->createNamedParameter(true, \PDO::PARAM_BOOL))
            ->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId)));
        $qb->executeStatement();
    }

    public function deleteTask(string $taskId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete('motrix_tasks')
            ->where($qb->expr()->eq('task_id', $qb->createNamedParameter($taskId)));
        $qb->executeStatement();
    }
}
