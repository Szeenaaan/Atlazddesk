<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMfaCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

final class ProfileEmailTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'super_admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'password' => 'Profile#2026']);
    }

    public function test_super_admin_can_change_own_email_and_normalizes_it(): void
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        $user = $this->account();
        $this->actingAs($user)->patchJson('/api/v2/auth/profile', [
            'name' => $user->name, 'email' => ' NEW@example.com ', 'current_password' => 'Profile#2026',
            'role' => 'admin', 'customer_id' => 999,
        ])->assertOk()->assertJsonPath('user.email', 'new@example.com');
        $this->assertSame('super_admin', $user->fresh()->role);
        $this->assertNull($user->fresh()->customer_id);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_change_requires_current_password_but_unchanged_email_does_not(): void
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        $user = $this->account();
        $original = $user->email;
        foreach ([null, 'WrongPassword#1'] as $password) {
            $this->actingAs($user)->patchJson('/api/v2/auth/profile', [
                'name' => $user->name, 'email' => 'new@example.com', 'current_password' => $password,
            ])->assertUnprocessable();
            $this->assertSame($original, $user->fresh()->email);
        }
        $this->patchJson('/api/v2/auth/profile', ['name' => $user->name, 'email' => $original])->assertOk();
    }

    public function test_email_must_be_unique_across_all_roles_including_inactive_accounts(): void
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        $user = $this->account();
        foreach (['super_admin', 'admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            $other = User::factory()->create(['role' => $role, 'status' => 'inactive']);
            $this->actingAs($user)->patchJson('/api/v2/auth/profile', [
                'name' => $user->name, 'email' => strtoupper($other->email), 'current_password' => 'Profile#2026',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }

    public function test_other_roles_cannot_change_email_through_profile(): void
    {
        $this->withoutMiddleware(EnsureMfaCompleted::class);
        foreach (['admin', 'customer_admin', 'customer_user', 'technician'] as $role) {
            Auth::forgetGuards();
            $user = $this->account($role);
            $this->actingAs($user)->patchJson('/api/v2/auth/profile', [
                'name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'Profile#2026',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }

    public function test_profile_email_change_does_not_bypass_required_mfa(): void
    {
        $user = $this->account();
        $user->forceFill(['mfa_security_version' => 1])->save();
        $this->actingAs($user)->patchJson('/api/v2/auth/profile', [
            'name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'Profile#2026',
        ])->assertForbidden();
    }
}
