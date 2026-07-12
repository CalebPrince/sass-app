<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Task;
use App\Models\User;

/**
 * Task mutation by task id. A task's own id (tenant-scoped) is enough to
 * locate and authorize it — no need to route through its parent project.
 */
final class TaskController extends Controller
{
    private const STATUSES = ['todo', 'in_progress', 'done'];

    public function update(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        $existing = Task::find($tenantId, $id);
        if ($existing === null) {
            Response::notFound('Task not found');
        }

        $fields = $this->require($request, ['title']);
        $status = (string) $request->input('status', $existing['status']);
        if (!in_array($status, self::STATUSES, true)) {
            $status = $existing['status'];
        }

        $task = Task::update(
            $tenantId,
            $id,
            $fields['title'],
            trim((string) $request->input('description', '')),
            $status,
            $this->resolveAssignee($request, $tenantId),
            $this->resolveDueDate($request)
        );

        Response::ok(['task' => $task], 'Updated');
    }

    public function delete(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        if (Task::find($tenantId, $id) === null) {
            Response::notFound('Task not found');
        }

        Task::delete($tenantId, $id);
        Response::ok([], 'Deleted');
    }

    /** Only ever assign to a user who belongs to the caller's own tenant. */
    private function resolveAssignee(Request $request, int $tenantId): ?int
    {
        $raw = $request->input('assignee_user_id');
        if ($raw === null || $raw === '') {
            return null;
        }
        $assigneeId = (int) $raw;
        foreach (User::forTenant($tenantId) as $user) {
            if ($user['id'] === $assigneeId) {
                return $assigneeId;
            }
        }
        return null;
    }

    private function resolveDueDate(Request $request): ?string
    {
        $raw = trim((string) $request->input('due_date', ''));
        return $raw === '' ? null : $raw;
    }
}
