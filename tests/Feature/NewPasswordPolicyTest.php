<?php

namespace Tests\Feature;

use App\Models\{Role, User};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Auth, Hash, Password, Schema};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FeedDatabase;
use Tests\TestCase;

class NewPasswordPolicyTest extends TestCase
{
    use FeedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFeedDatabase();
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['customer', 'agent', 'branch_manager', 'admin', 'owner']);
    }

    private function account(string $role): User
    {
        return User::create(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test', 'password' => 'OldQaOnly',
            'status' => 'active', 'role_id' => Role::create(['name' => $role, 'level' => 1])->id]);
    }

    public static function passwordInputs(): array
    {
        return [
            'empty' => ['', '', false],
            'nine' => ['Password9', 'Password9', false],
            'ten' => ['Password10', 'Password10', true],
            'eleven' => ['Password110', 'Password110', true],
            'mismatch' => ['Password10', 'Password11', false],
            'exact byte cap' => [str_repeat('é', 36), str_repeat('é', 36), true],
            'over byte cap' => [str_repeat('é', 36).'a', str_repeat('é', 36).'a', false],
        ];
    }

    #[DataProvider('passwordInputs')]
    public function test_web_and_api_registration_share_policy(string $password, string $confirmation, bool $valid): void
    {
        Role::create(['name' => 'customer', 'level' => 1]);
        foreach (['/web-auth/register', '/api/auth/register'] as $index => $route) {
            $email = 'signup-'.$index.'@example.test';
            $response = $this->postJson($route, ['name' => 'Synthetic Customer', 'email' => $email,
                'password' => $password, 'password_confirmation' => $confirmation]);
            if ($valid) {
                $response->assertStatus($index === 0 ? 200 : 201);
                $this->assertTrue(Hash::check($password, User::where('email', $email)->firstOrFail()->password));
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('password');
                $this->assertDatabaseMissing('users', ['email' => $email]);
            }
        }
    }

    #[DataProvider('roles')]
    public function test_all_roles_can_change_own_password_and_revoke_old_credentials(string $role): void
    {
        $user = $this->account($role);
        $token = $user->createToken('old session')->plainTextToken;
        $this->withToken($token);
        $valid = ['current_password' => 'OldQaOnly', 'password' => 'Password10', 'password_confirmation' => 'Password10'];
        $this->patchJson('/api/me/password', [...$valid, 'user_id' => $user->id + 1])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->patchJson('/api/me/password', [...$valid, 'current_password' => 'Wrong'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/me/password', [...$valid, 'password' => 'Password9', 'password_confirmation' => 'Password9'])->assertUnprocessable();
        $this->patchJson('/api/me/password', [...$valid, 'password_confirmation' => 'Password11'])->assertUnprocessable();
        $this->patchJson('/api/me/password', $valid)->assertOk()->assertJsonMissingPath('password');
        $this->assertTrue(Hash::check('Password10', $user->fresh()->password));
        $this->assertFalse(Hash::check('OldQaOnly', $user->fresh()->password));
        $this->assertSame($role, $user->fresh()->role->name);
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_all_roles_can_reset_with_eleven_characters(string $role): void
    {
        $user = $this->account($role);
        $user->forceFill(['email_verified_at' => now()])->save();
        $token = Password::createToken($user);
        $request = ['email' => $user->email, 'token' => $token, 'password' => 'Password110', 'password_confirmation' => 'Password110'];
        $this->postJson('/password/reset', [...$request, 'password' => 'Password9', 'password_confirmation' => 'Password9'])->assertUnprocessable();
        $this->postJson('/password/reset', [...$request, 'password_confirmation' => 'Password10'])->assertUnprocessable();
        $this->post('/password/reset', $request)->assertRedirect(route('password.reset.complete'));
        $this->assertTrue(Hash::check('Password110', $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user, $token));
    }
}
