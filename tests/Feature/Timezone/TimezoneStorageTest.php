<?php

namespace TechiesAfrica\Nomad\Tests\Feature\Timezone;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use TechiesAfrica\Nomad\Casts\NomadDatetimeCast;
use TechiesAfrica\Nomad\Observers\NomadTimezoneObserver;
use TechiesAfrica\Nomad\Services\Timezone\NomadTimezoneResolver;
use TechiesAfrica\Nomad\Tests\Stubs\Models\Post;
use TechiesAfrica\Nomad\Tests\TestCase;

/**
 * What NomadDatetimeCast::set() writes to the database. SQLite keeps text as
 * given, so the stored value read back here is exactly what the cast wrote.
 */
class TimezoneStorageTest extends TestCase
{
    protected NomadTimezoneResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->app->make(NomadTimezoneResolver::class);
        NomadTimezoneObserver::clearCache();
    }

    public function test_iso_text_with_z_is_stored_as_utc_on_a_loaded_record(): void
    {
        $post = $this->loadedPost();

        $post->published_at = '2026-11-19T07:27:21.188Z';
        $post->save();

        $this->assertSame('2026-11-19 07:27:21', $this->storedPublishedAt());
    }

    public function test_a_record_created_with_iso_text_can_be_updated_with_iso_text(): void
    {
        $post = Post::create([
            'user_id' => $this->createTestUser(),
            'title' => 'Test Post',
            'published_at' => '2026-11-19T07:27:10.000Z',
        ]);

        $post->update(['published_at' => '2026-11-19T07:27:21.188Z']);

        $this->assertSame('2026-11-19 07:27:21', $this->storedPublishedAt());
    }

    #[DataProvider('zonedIsoText')]
    public function test_iso_text_with_an_offset_is_converted_to_utc(string $sent, string $stored): void
    {
        $post = $this->loadedPost();

        $post->published_at = $sent;
        $post->save();

        $this->assertSame($stored, $this->storedPublishedAt());
    }

    public static function zonedIsoText(): array
    {
        return [
            'offset with colon' => ['2026-11-19T09:30:00+01:00', '2026-11-19 08:30:00'],
            'offset without colon' => ['2026-11-19T09:30:00+0100', '2026-11-19 08:30:00'],
            'offset in hours only' => ['2026-11-19T09:30:00+01', '2026-11-19 08:30:00'],
            'negative offset' => ['2026-11-19T09:30:00-05:00', '2026-11-19 14:30:00'],
            'zero offset' => ['2026-11-19T09:30:00+00:00', '2026-11-19 09:30:00'],
            'space before offset' => ['2026-11-19 09:30:00 +0100', '2026-11-19 08:30:00'],
            'space separator with z' => ['2026-11-19 09:30:00Z', '2026-11-19 09:30:00'],
            'no seconds' => ['2026-11-19T09:30Z', '2026-11-19 09:30:00'],
            'microseconds' => ['2026-11-19T09:30:00.123456+01:00', '2026-11-19 08:30:00'],
            'crosses midnight' => ['2026-11-19T00:30:00+01:00', '2026-11-18 23:30:00'],
            'surrounding spaces' => [' 2026-11-19T09:30:00Z ', '2026-11-19 09:30:00'],
        ];
    }

    public function test_the_users_timezone_does_not_change_how_text_is_read(): void
    {
        $this->resolver->setTimezone('Asia/Tokyo');
        $post = $this->loadedPost();

        $post->published_at = '2026-11-19T07:27:21Z';
        $post->save();

        $this->assertSame('2026-11-19 07:27:21', $this->storedPublishedAt());
        $this->assertSame(16, $post->fresh()->published_at->hour);
    }

    #[DataProvider('textStoredUnchanged')]
    public function test_text_without_a_timezone_is_stored_exactly_as_before(string $sent): void
    {
        $post = $this->loadedPost();

        $post->published_at = $sent;
        $post->save();

        $this->assertSame($sent, $this->storedPublishedAt());
    }

    public static function textStoredUnchanged(): array
    {
        return [
            'storage format' => ['2026-11-19 07:27:21'],
            't separator, no timezone' => ['2026-11-19T07:27:21'],
            'fraction, no timezone' => ['2026-11-19T07:27:21.188'],
            'date only' => ['2026-11-19'],
            'not a date' => ['not a date'],
            'timezone name, not iso' => ['2026-11-19 07:27:21 UTC'],
            'impossible date' => ['2026-02-30T07:27:21Z'],
            'hour 24' => ['2026-11-19T24:00:00Z'],
            'month 13' => ['2026-13-19T07:27:21Z'],
            'empty' => [''],
        ];
    }

    public function test_carbon_values_are_still_converted_to_utc(): void
    {
        $post = $this->loadedPost();

        $post->published_at = Carbon::parse('2026-11-19 11:00:00', 'Africa/Lagos');
        $post->save();

        $this->assertSame('2026-11-19 10:00:00', $this->storedPublishedAt());
    }

    public function test_the_same_moment_sent_as_iso_text_is_not_a_change(): void
    {
        $post = $this->loadedPost('2026-11-19 07:27:21');

        $post->published_at = '2026-11-19T08:27:21+01:00';

        $this->assertFalse($post->isDirty('published_at'));
    }

    public function test_null_is_stored_as_null(): void
    {
        $post = $this->loadedPost();

        $this->assertNull((new NomadDatetimeCast())->set($post, 'published_at', null, []));
    }

    /**
     * A post read back from the database, so Nomad's cast is in place.
     */
    protected function loadedPost(string $publishedAt = '2024-06-15 10:00:00'): Post
    {
        DB::table('posts')->insert([
            'user_id' => $this->createTestUser(),
            'title' => 'Test Post',
            'published_at' => $publishedAt,
            'created_at' => '2024-06-15 10:00:00',
            'updated_at' => '2024-06-15 10:00:00',
        ]);

        $post = Post::first();
        $this->assertSame(NomadDatetimeCast::class, $post->getCasts()['published_at']);

        return $post;
    }

    protected function storedPublishedAt(): ?string
    {
        return DB::table('posts')->value('published_at');
    }

    protected function createTestUser(): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'test' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
