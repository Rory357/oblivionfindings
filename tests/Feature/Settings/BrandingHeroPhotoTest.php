<?php

use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\Branding\BrandHeroPhoto;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * Settings › Branding › Website and sign-in photo (client demo website, 23 Oct 2026): one photo
 * for the public home hero and both sign-in pages, re-encoded so no camera metadata is published.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('public');
});

function heroPhotoUser(string $role = 'admin'): User
{
    $user = User::factory()->withoutTwoFactor()->create(['email' => $role.'@example.test', 'role' => $role, 'approved_at' => now()]);
    $user->roles()->attach(Role::where('name', $role)->firstOrFail());

    return $user;
}

/** A real JPEG with an EXIF block: an orientation tag and a marker standing in for GPS data. */
function heroPhotoJpeg(int $width, int $height, int $orientation = 1): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 15, 118, 110));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($image);

    // TIFF (little endian) with IFD0 holding one SHORT: Orientation (0x0112).
    $tiff = 'II'.pack('v', 42).pack('V', 8)
        .pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0)
        .'GPS-SECRET-LOCATION-MARKER';
    $exif = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    $path = tempnam(sys_get_temp_dir(), 'hero').'.jpg';
    file_put_contents($path, substr($jpeg, 0, 2).$segment.substr($jpeg, 2));

    return $path;
}

it('re-encodes the photo without its metadata and shares it with the website and both sign-in pages', function () {
    $admin = heroPhotoUser();
    $source = heroPhotoJpeg(3000, 2000);
    expect(file_get_contents($source))->toContain('GPS-SECRET-LOCATION-MARKER');

    $this->actingAs($admin)->post('/settings/branding', [
        'branding' => ['name' => 'Kōwhai Living', 'hero_alt' => 'Two people cooking'],
        'hero_photo' => new UploadedFile($source, 'hero.jpg', 'image/jpeg', null, true),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $path = AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->value('value');
    expect($path)->toBeString()->toStartWith('branding/hero-')->toEndWith('.jpg');
    Storage::disk('public')->assertExists($path);
    $stored = Storage::disk('public')->get($path);
    expect($stored)->not->toContain('GPS-SECRET-LOCATION-MARKER')->not->toContain("Exif\0\0");
    $size = getimagesizefromstring($stored);
    expect($size[2])->toBe(IMAGETYPE_JPEG)
        ->and($size[0])->toBe(BrandHeroPhoto::MAX_EDGE)
        ->and($size[1])->toBe(1600);

    $url = Storage::disk('public')->url($path);
    $this->actingAs($admin)->get('/settings/branding')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('branding.heroUrl', $url)->where('branding.hero_alt', 'Two people cooking'));

    auth()->logout();
    foreach (['/', '/login', '/portal/login'] as $uri) {
        $this->get($uri)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('branding.heroUrl', $url)->where('branding.heroAlt', 'Two people cooking')->where('demoSite', false));
    }
});

it('turns a photo upright from its EXIF orientation', function () {
    $source = heroPhotoJpeg(1600, 1000, 6);

    $this->actingAs(heroPhotoUser())->post('/settings/branding', [
        'hero_photo' => new UploadedFile($source, 'sideways.jpg', 'image/jpeg', null, true),
    ])->assertSessionHasNoErrors();

    $path = AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->value('value');
    [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect([$width, $height])->toBe([1000, 1600]);
});

it('accepts only JPG, PNG or WebP photos that are large enough and under 8 MB', function (Closure $file, string $message) {
    $this->actingAs(heroPhotoUser())->post('/settings/branding', ['hero_photo' => $file()])
        ->assertSessionHasErrors(['hero_photo' => $message]);

    expect(AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->exists())->toBeFalse();
    expect(Storage::disk('public')->allFiles('branding'))->toBe([]);
})->with([
    'a PDF' => [fn () => UploadedFile::fake()->create('plan.pdf', 100, 'application/pdf'), 'Choose a JPG, PNG or WebP photo.'],
    'an SVG' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'Choose a JPG, PNG or WebP photo.'],
    'too small' => [fn () => UploadedFile::fake()->image('small.jpg', 800, 600), 'Choose a photo at least 1200 × 800 pixels, so it stays sharp on large screens.'],
    'over 8 MB' => [fn () => UploadedFile::fake()->image('huge.jpg', 1600, 1000)->size(9000), 'Choose a photo up to 8 MB.'],
]);

it('replaces the previous photo file and removes the photo with its description', function () {
    $admin = heroPhotoUser();
    $this->actingAs($admin)->post('/settings/branding', [
        'branding' => ['hero_alt' => 'First'],
        'hero_photo' => UploadedFile::fake()->image('first.jpg', 1600, 1000),
    ])->assertSessionHasNoErrors();
    $first = AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->value('value');

    $this->actingAs($admin)->post('/settings/branding', [
        'branding' => ['hero_alt' => 'Second'],
        'hero_photo' => UploadedFile::fake()->image('second.png', 1600, 1000),
    ])->assertSessionHasNoErrors();
    $second = AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->value('value');
    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->actingAs($admin)->post('/settings/branding', [
        'branding' => ['hero_alt' => 'Second'],
        'remove_hero_photo' => true,
    ])->assertSessionHasNoErrors();
    Storage::disk('public')->assertMissing($second);
    expect(AppSetting::query()->whereIn('key', [BrandHeroPhoto::PATH_SETTING, BrandHeroPhoto::ALT_SETTING])->exists())->toBeFalse();

    auth()->logout();
    $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('branding.heroUrl', null));
});

it('lets only people who manage branding change the photo', function () {
    $this->actingAs(heroPhotoUser('support_worker'))->post('/settings/branding', [
        'hero_photo' => UploadedFile::fake()->image('hero.jpg', 1600, 1000),
    ])->assertForbidden();

    expect(AppSetting::query()->where('key', BrandHeroPhoto::PATH_SETTING)->exists())->toBeFalse();
});
