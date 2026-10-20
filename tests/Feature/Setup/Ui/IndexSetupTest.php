<?php

namespace Tests\Feature\Setup\Ui;

use App\Models\Setting;
use App\Models\User;
use Tests\TestCase;

class IndexSetupTest extends TestCase
{
    public function test_page_renders()
    {
        $this->get(route('setup'))
            ->assertOk();
    }

    public function test_page_redirects_if_no_records_found()
    {
        $this->assertDatabaseEmpty('users');

        $this->get(route('home'))
            ->assertStatus(302)
            ->assertRedirectToRoute('setup');
    }

    public function test_page_redirects_if_records_found()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('setup'))
            ->assertStatus(302)
            ->assertRedirectToRoute('home');
    }

    public function test_migration_page_renders()
    {

        $this->assertDatabaseEmpty('users');

        $this->post(route('setup.migrate'))
            ->assertOk();

    }

    public function test_migration_creates_passport_clients()
    {
        $this->assertDatabaseEmpty('oauth_personal_access_clients');

        $this->post(route('setup.migrate'))
            ->assertOk();

        $this->assertDatabaseCount('oauth_personal_access_clients', 1);
    }

    public function test_test_email_is_sent_to_the_from_address()
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'snipeit@example.com']);

        $this->get(route('setup.mailtest'))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('snipeit@example.com', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_test_email_is_sent_before_settings_exist()
    {
        config(['mail.default' => 'array']);
        Setting::query()->delete();
        Setting::$_cache = null;

        $this->get(route('setup.mailtest'))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_test_email_failure_returns_the_error()
    {
        config(['mail.default' => 'not-a-mailer']);

        $this->get(route('setup.mailtest'))
            ->assertStatus(500)
            ->assertJson(['status' => 'error', 'messages' => 'Mailer [not-a-mailer] is not defined.']);
    }

    public function test_create_first_user_page_renders()
    {

        $this->assertDatabaseEmpty('users');

        $this->get(route('setup.user'))
            ->assertOk();

    }

    public function test_create_first_user_validation()
    {

        $this->assertDatabaseEmpty('users');

        $response = $this->post(route('setup.user.save'))
            ->assertStatus(302);

        $this->followRedirects($response)->assertSee('error');
        $this->assertDatabaseCount('users', 0);

    }

    public function test_create_first_user_saved()
    {

        $this->assertDatabaseEmpty('users');

        $this->post(route('setup.user.save'),
            [
                'site_name' => 'Snipe-IT',
                'first_name' => 'First',
                'last_name' => 'Admin',
                'username' => 'AwesomeAdmin',
                'password' => '0834529!!*423',
                'password_confirmation' => '0834529!!*423',
                'email_domain' => 'example.org',
            ])
            ->assertRedirectToRoute('setup.done')
            ->assertStatus(302)
            ->assertSessionHas('success');

        $this->assertDatabaseCount('users', 1);

    }
}
