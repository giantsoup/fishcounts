<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Region;
use App\Models\Species;
use App\Models\TripType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RouteAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_denied_at_every_authenticated_route_method(): void
    {
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('auth', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $this->call($method, $this->examplePath($route))->assertRedirect(route('login'));
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('notification_deliveries');
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_normal_user_cannot_reach_admin_actions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Http::preventStrayRequests();
        Notification::fake();
        Queue::fake();
        $checked = 0;

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('admin', $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $response = $this->call($method, $this->examplePath($route));

                if ($route->parameterNames() === []) {
                    $response->assertForbidden();
                } else {
                    $this->assertContains($response->status(), [403, 404], $method.' '.$route->uri());
                }
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('parser_diagnostic_review_actions');
        $this->assertDatabaseEmpty('notification_deliveries');
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_private_file_routes_require_correct_unexpired_signature_and_purpose(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('audit.txt', 'Synthetic private content');

        $this->get('/storage/audit.txt')->assertForbidden();
        $this->put('/storage/unsigned.txt', ['content' => 'blocked'])->assertForbidden();
        Storage::disk('local')->assertMissing('unsigned.txt');

        $download = URL::temporarySignedRoute('storage.local', now()->addMinute(), ['path' => 'audit.txt'], absolute: false);
        $this->get($download)->assertOk()->assertStreamedContent('Synthetic private content');
        $this->head($download)->assertOk();

        $this->get(str_replace('audit.txt', 'other.txt', $download))->assertForbidden();
        $this->put($download)->assertForbidden();

        $upload = URL::temporarySignedRoute('storage.local.upload', now()->addMinute(), ['path' => 'uploaded.txt', 'upload' => true], absolute: false);
        $this->call('PUT', $upload, content: 'Synthetic upload')->assertNoContent();
        $this->assertSame('Synthetic upload', Storage::disk('local')->get('uploaded.txt'));
        $this->get($upload)->assertForbidden();

        $this->travel(2)->minutes();
        $this->get($download)->assertForbidden();
        $this->call('PUT', $upload, content: 'expired')->assertForbidden();
        $this->assertSame('Synthetic upload', Storage::disk('local')->get('uploaded.txt'));
    }

    public function test_alert_rule_writes_reject_other_users_and_preserve_owner_access(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $species = Species::query()->create(['name' => 'Audit species', 'slug' => 'audit-species']);
        $region = Region::query()->create(['name' => 'Audit region', 'slug' => 'audit-region']);
        $tripType = TripType::query()->create(['name' => 'Audit trip', 'slug' => 'audit-trip']);
        $rule = AlertRule::query()->create(['user_id' => $owner->id, 'species_id' => $species->id, 'name' => 'Private rule']);
        $data = [
            'name' => 'Permitted update',
            'species_id' => $species->id,
            'minimum_score' => 70,
            'recent_window_days' => 3,
            'comparison_window_days' => 7,
            'region_ids' => [$region->id],
            'trip_type_ids' => [$tripType->id],
            'user_id' => $other->id,
        ];

        $this->actingAs($other)->put(route('alert-rules.update', $rule), $data)->assertForbidden();
        $this->actingAs($other)->delete(route('alert-rules.destroy', $rule))->assertForbidden();
        $this->assertSame('Private rule', $rule->fresh()->name);
        $this->assertSame($owner->id, $rule->fresh()->user_id);
        $this->assertSame(0, $rule->regions()->count());

        $this->actingAs($owner)->put(route('alert-rules.update', $rule), $data)->assertRedirect(route('alert-rules.edit', $rule));
        $this->assertSame('Permitted update', $rule->fresh()->name);
        $this->assertSame($owner->id, $rule->fresh()->user_id);
        $this->assertTrue($rule->regions()->whereKey($region)->exists());
        $this->actingAs($owner)->delete(route('alert-rules.destroy', $rule))->assertRedirect(route('alert-rules.index'));
        $this->assertModelMissing($rule);
    }

    public function test_profile_write_requires_csrf_token_through_real_route(): void
    {
        $user = User::factory()->create();
        $this->withSession([Auth::guard('web')->getName() => $user->id, '_token' => 'synthetic-audit-csrf']);
        $data = ['name' => 'Protected profile update', 'email' => $user->email];
        $this->app['env'] = 'audit';

        try {
            $this->patch(route('profile.update'), $data)->assertStatus(419);
            $this->assertSame($user->name, $user->fresh()->name);

            $this->patch(route('profile.update'), [...$data, '_token' => 'synthetic-audit-csrf'])
                ->assertRedirect(route('profile.edit'));
            $this->assertSame('Protected profile update', $user->fresh()->name);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    private function examplePath(Route $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
    }
}
