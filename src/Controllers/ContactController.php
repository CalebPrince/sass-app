<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Contact;
use App\Models\Subscription;
use App\Models\UsageLog;

/**
 * Tenant-scoped contact/lead manager. Every read and write is scoped to the
 * caller's own tenant via Session::tenantId() — same barrier as every other
 * client controller.
 */
final class ContactController extends Controller
{
    private const STATUSES = ['lead', 'active', 'customer', 'inactive'];
    private const ENTITY_TYPES = [
        'individual', 'sole_prop', 'partnership', 'llc', 's_corp', 'c_corp', 'nonprofit', 'trust_estate', 'other',
    ];

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
            Response::error('Client limit reached for your plan. Upgrade to add more.', 402);
        }

        $entityType = (string) $request->input('entity_type', 'individual');
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            $entityType = 'individual';
        }

        $contact = Contact::create(
            $tenantId,
            $fields['name'],
            trim((string) $request->input('email', '')),
            trim((string) $request->input('phone', '')),
            trim((string) $request->input('company', '')),
            trim((string) $request->input('notes', '')),
            $entityType,
            trim((string) $request->input('tax_id', '')),
            trim((string) $request->input('fiscal_year_end', ''))
        );

        UsageLog::record($tenantId, Session::userId(), 'contact.created', 'contact', 1, ['name' => $fields['name']]);
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
        $entityType = (string) $request->input('entity_type', $existing['entity_type']);
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            $entityType = $existing['entity_type'];
        }

        $contact = Contact::update(
            $tenantId,
            $id,
            $fields['name'],
            trim((string) $request->input('email', '')),
            trim((string) $request->input('phone', '')),
            trim((string) $request->input('company', '')),
            $status,
            trim((string) $request->input('notes', '')),
            $entityType,
            trim((string) $request->input('tax_id', '')),
            trim((string) $request->input('fiscal_year_end', ''))
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
