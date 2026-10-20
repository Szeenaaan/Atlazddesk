<?php

namespace App\Services;

use App\Models\{Customer, User};
use App\Support\{AmcAudit, ServiceResult};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A client and its initial Customer Admin are created atomically. */
final class ClientOnboardingService
{
    public function onboard(Request $request, array $data): ServiceResult
    {
        abort_unless($request->user()?->role === 'admin', 403);

        [$customer, $contact] = DB::transaction(function () use ($request, $data): array {
            $customer = Customer::create([
                'company_name' => $data['company_name'],
                'customer_code' => $data['customer_code'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'status' => 'active',
            ]);

            $contact = User::create([
                'customer_id' => $customer->id,
                'name' => $data['customer_admin_name'],
                'email' => $data['customer_admin_email'],
                'password' => $data['customer_admin_password'], // Hashed by the User model cast.
                'role' => 'customer_admin',
                'status' => 'active',
            ]);

            AmcAudit::record($request, 'client.onboarded', $customer, ['customer_admin_id' => $contact->id]);

            return [$customer, $contact];
        });

        return ServiceResult::ok([
            'customer' => $customer->only(['id', 'company_name', 'customer_code', 'status']),
            'customer_admin' => $contact->only(['id', 'name', 'email', 'role', 'customer_id']),
        ], 'Client and initial Customer Admin created.', 201);
    }
}
