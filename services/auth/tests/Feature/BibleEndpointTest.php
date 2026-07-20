<?php

use App\Models\BibleBook;
use App\Models\BibleSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * Bible HTTP endpoints (FR-BI-1/3/4). HelloAO is faked.
 */

// ── available (admin) ────────────────────────────────────────────────

test('admin lists available translations filtered by language', function () {
    Http::fake(['*/available_translations.json' => Http::response(['translations' => [
        ['id' => 'BSB', 'name' => 'Berean Standard Bible', 'englishName' => 'Berean Standard Bible', 'language' => 'eng'],
        ['id' => 'LSG', 'name' => 'Louis Segond', 'englishName' => 'Louis Segond', 'language' => 'fra'],
        ['id' => 'LUT', 'name' => 'Luther', 'language' => 'deu'],
    ]])]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->getJson('/api/bible/translations/available')
        ->assertOk()
        ->assertJsonCount(2, 'translations') // eng + fra, not deu
        ->assertJsonPath('translations.1.id', 'LSG');
});

test('non-admin cannot list available translations', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/bible/translations/available')->assertStatus(403);
});

// ── save settings (admin) ────────────────────────────────────────────

test('admin saves settings and populates the book cache', function () {
    Http::fake(['*/LSG/books.json' => Http::response(['books' => [
        ['id' => 'JHN', 'name' => 'Jean', 'numberOfChapters' => 21, 'order' => 43],
        ['id' => 'GEN', 'name' => 'Genèse', 'numberOfChapters' => 50, 'order' => 1],
    ]])]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson('/api/bible/settings', [
        'enabled_translations'   => [['id' => 'LSG', 'name' => 'Louis Segond', 'language' => 'fra']],
        'default_translation_id' => 'LSG',
    ])
        ->assertOk()
        ->assertJsonPath('default_translation_id', 'LSG');

    expect(BibleBook::where('translation_id', 'LSG')->count())->toBe(2);
    expect(BibleSetting::current()->default_translation_id)->toBe('LSG');
});

test('saving rejects a default that is not enabled', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->putJson('/api/bible/settings', [
        'enabled_translations'   => [['id' => 'LSG', 'name' => 'Louis Segond', 'language' => 'fra']],
        'default_translation_id' => 'BSB',
    ])->assertStatus(422);
});

// ── resolve (authed) ─────────────────────────────────────────────────

test('resolve works from a freeform reference', function () {
    BibleSetting::factory()->create(['enabled_translations' => [['id' => 'LSG', 'name' => 'Louis Segond']], 'default_translation_id' => 'LSG']);
    BibleBook::factory()->create(['translation_id' => 'LSG', 'book_code' => 'JHN', 'name' => 'Jean']);
    Http::fake(['*/LSG/JHN/3.json' => Http::response(['chapter' => ['number' => 3, 'content' => [
        ['type' => 'verse', 'number' => 16, 'content' => ['Car Dieu a tant aimé le monde']],
    ]]])]);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/bible/resolve?q='.urlencode('Jean 3:16'))
        ->assertOk()
        ->assertJsonPath('reference_label', 'Jean 3:16')
        ->assertJsonPath('translation_label', 'Louis Segond')
        ->assertJsonPath('verses.0.text', 'Car Dieu a tant aimé le monde');
});

test('resolve works from discrete params', function () {
    BibleSetting::factory()->create(['enabled_translations' => [['id' => 'LSG', 'name' => 'Louis Segond']], 'default_translation_id' => 'LSG']);
    BibleBook::factory()->create(['translation_id' => 'LSG', 'book_code' => 'JHN', 'name' => 'Jean']);
    Http::fake(['*/LSG/JHN/3.json' => Http::response(['chapter' => ['number' => 3, 'content' => [
        ['type' => 'verse', 'number' => 16, 'content' => ['v16']],
        ['type' => 'verse', 'number' => 17, 'content' => ['v17']],
    ]]])]);

    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/bible/resolve?book_code=JHN&start_chapter=3&start_verse=16&end_chapter=3&end_verse=17')
        ->assertOk()
        ->assertJsonPath('reference_label', 'Jean 3:16-17')
        ->assertJsonCount(2, 'verses');
});

test('resolve 422s on an unparseable reference', function () {
    BibleSetting::factory()->create(['default_translation_id' => 'LSG']);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/bible/resolve?q=gibberish')->assertStatus(422);
});

test('resolve requires authentication', function () {
    $this->getJson('/api/bible/resolve?q=Jean+3:16')->assertStatus(401);
});
