<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Contact;
use App\Models\Subscription;

/**
 * Tenant-scoped contact/lead manager. Every read and write is scoped to the
 * caller's own tenant via Session::tenantId() — same barrier as every other
 * client controller.
 */
final class ContactController extends Controller
{
    private const STATUSES = ['lead', 'active', 'customer', 'inactive'];

    public function list(Request $request): void
    {
        Response::ok(['contacts' => Contact::forTenant(Session::tenantId())]);
    }

    public function create(Request $request): void
    {
        $tenantId = Session::tenantId();
        $fields = $this->require($request, ['name']);

        $subscription = Subscription::forTenant($tenantId);
        $tierConfig = $this->tier($subscription['tier']);
        $limit = (int) ($tierConfig['max_contacts'] ?? PHP_INT_MAX);

        if (Contact::countForTenant($tenantId) >= $limit) {
            Response::error('Contact limit reached for your plan. Upgrade to add more.', 402);
        }

        $contact = Contact::create(
            $tenantId,
            $fields['name'],
            trim((string) $request->input('email', '')),
            trim((string) $request->input('phone', '')),
            trim((string) $request->input('company', '')),
            trim((string) $request->input('notes', ''))
        );

        Response::created(['contact' => $contact]);
    }

    public function update(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        $existing = Contact::find($tenantId, $id);
        if ($existing === null) {
            Response::notFound('Contact not found');
        }

        $fields = $this->require($request, ['name']);
        $status = (string) $request->input('status', $existing['status']);
        if (!in_array($status, self::STATUSES, true)) {
            $status = $existing['status'];
        }

        $contact = Contact::update(
            $tenantId,
            $id,
            $fields['name'],
            trim((string) $request->input('email', '')),
            trim((string) $request->input('phone', '')),
            trim((string) $request->input('company', '')),
            $status,
            trim((string) $request->input('notes', ''))
        );

        Response::ok(['contact' => $contact], 'Updated');
    }

    public function delete(Request $request, array $args): void
    {
        $tenantId = Session::tenantId();
        $id = (int) $args['id'];

        if (Contact::find($tenantId, $id) === null) {
            Response::notFound('Contact not found');
        }

        Contact::delete($tenantId, $id);
        Response::ok([], 'Deleted');
    }
}
