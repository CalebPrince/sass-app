<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Project;
use App\Models\Task;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UsageLog;

/**
 * Tenant-scoped project manager, plus the task list nested under each project.
 * Every read and write is scoped to the caller's own tenant via
 * Session::tenantId() — same barrier as every other client controller.
 */
final class ProjectController extends Controller
{
    private const STATUSES = ['active', 'archived'];
    private const ENGAGEMENT_TYPES = ['tax_return', 'bookkeeping', 'audit', 'advisory', 'payroll', 'other'];

    public function list(Request $request): void
    {
        Response::ok(['projects' => Project::forTenant(Session::tenantId())]);
    }

    public function create(Request $request): void
    {
        $tenantId = Session::tenantId();
        $fields = $this->require($request, ['name']);

        $subscription = Subscription::forTenant($tenantId);
        $tierConfig = $this->tier($subscription['tier']);
        $limit = (int) ($tierConfig['max_projects'] ?? PHP_INT_MAX);

        if (Project::countForTenant($tenantId) >= $limit) {
            Response::error('Engagement limit reached for your plan. Upgrade to add more.', 402);
        }

        $engagementType = (string) $request->input('engagement_type', 'other');
        if (!in_array($engagementType, self::ENGAGEMENT_TYPES, true)) {
            $engagementType = 'other';
        }

        $project = Project::create(
            $tenantId,
            $fields['name'],
            trim((string) $request->input('description', '')),
            $engagementType,
            trim((string) $request->input('deadline', ''))
        );
        UsageLog::record($tenantId, Session::userId(), 'project.created', 'project', 1, ['name' => $fields['name']]);
        Response::created(['project' => $project]);
    }

    public function update(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        $existing = Project::find($tenantId, $id);
        if ($existing === null) {
            Response::notFound('Project not found');
        }

        $fields = $this->require($request, ['name']);
        $status = (string) $request->input('status', $existing['status']);
        if (!in_array($status, self::STATUSES, true)) {
            $status = $existing['status'];
        }
        $engagementType = (string) $request->input('engagement_type', $existing['engagement_type']);
        if (!in_array($engagementType, self::ENGAGEMENT_TYPES, true)) {
            $engagementType = $existing['engagement_type'];
        }

        $project = Project::update(
            $tenantId,
            $id,
            $fields['name'],
            trim((string) $request->input('description', '')),
            $status,
            $engagementType,
            trim((string) $request->input('deadline', ''))
        );
        Response::ok(['project' => $project], 'Updated');
    }

    public function delete(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        if (Project::find($tenantId, $id) === null) {
            Response::notFound('Project not found');
        }

        Project::delete($tenantId, $id);
        Response::ok([], 'Deleted');
    }

    public function tasks(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $projectId = (int) $args['id'];

        if (Project::find($tenantId, $projectId) === null) {
            Response::notFound('Project not found');
        }

        Response::ok(['tasks' => Task::forProject($tenantId, $projectId)]);
    }

    public function createTask(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $projectId = (int) $args['id'];

        if (Project::find($tenantId, $projectId) === null) {
            Response::notFound('Project not found');
        }

        $fields = $this->require($request, ['title']);
        $assigneeUserId = $this->resolveAssignee($request, $tenantId);
        $dueDate = $this->resolveDueDate($request);

        $task = Task::create(
            $tenantId,
            $projectId,
            $fields['title'],
            trim((string) $request->input('description', '')),
            $assigneeUserId,
            $dueDate
        );

        UsageLog::record($tenantId, Session::userId(), 'task.created', 'task', 1, ['title' => $fields['title'], 'project_id' => $projectId]);
        Response::created(['task' => $task]);
    }

    public function team(Request $request): void
    {
        Response::ok(['users' => User::forTenant(Session::tenantId())]);
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
