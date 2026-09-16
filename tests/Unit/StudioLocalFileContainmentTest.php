<?php

namespace Tests\Unit;

use App\Http\Controllers\StudioController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the path-traversal fix in StudioController.
 *
 * `source_url` / `reference_url` / `image` only carry `string|max:2048` validation,
 * so before the fix a payload like "/../../../../etc/passwd" reached public_path()
 * unchecked. faceDescription()/poseDescription() then handed the bytes to a vision
 * model and echoed the description back inside the prompt — an arbitrary local file
 * read. Every local resolution now goes through resolveLocalImage()/safeLocalFile(),
 * which rejects traversal segments and enforces realpath() containment.
 *
 * These tests pin the security property AND the legitimate flow, so a future refactor
 * cannot silently reopen the hole or break real image resolution.
 */
class StudioLocalFileContainmentTest extends TestCase
{
    private StudioController $controller;
    private ReflectionMethod $resolve;
    /** @var string[] */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $class = new ReflectionClass(StudioController::class);
        $this->controller = $class->newInstanceWithoutConstructor();

        $this->resolve = new ReflectionMethod(StudioController::class, 'resolveLocalImage');
        $this->resolve->setAccessible(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->created = [];

        parent::tearDown();
    }

    private function resolve(string $url, bool $stripImageRoute = false): ?string
    {
        $result = $this->resolve->invoke($this->controller, $url, $stripImageRoute);

        return $result === null ? null : (string) $result;
    }

    private function makeFileUnderStorage(string $relative): string
    {
        $dir = storage_path('app/public/'.dirname($relative));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $abs = storage_path('app/public/'.$relative);
        file_put_contents($abs, 'stub-image-bytes');
        $this->created[] = $abs;

        return $abs;
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function traversalPayloads(): array
    {
        return [
            'dotdot to /etc/passwd' => ['../../../../etc/passwd', false],
            'leading slash dotdot' => ['/../../../../etc/passwd', false],
            'absolute /etc/passwd' => ['/etc/passwd', false],
            'url wrapped traversal' => ['https://example.com/../../../../etc/passwd', false],
            'storage prefix traversal' => ['/storage/../../../../etc/passwd', false],
            'deep traversal' => ['/storage/a/b/../../../../../../../etc/shadow', false],
            'traversal via studio image route' => ['/studio/image/../../../../etc/passwd', true],
            'traversal via studio image route no strip' => ['studio/image/../../../etc/passwd', false],
            'encoded null byte' => ['/storage/x.png'."\0".'.jpg', false],
            'backslash traversal' => ['..\..\..\etc\passwd', false],
            'empty string' => ['', false],
            'slash only' => ['/', false],
        ];
    }

    #[DataProvider('traversalPayloads')]
    public function test_traversal_payloads_never_resolve_to_a_file(string $payload, bool $strip): void
    {
        $resolved = $this->resolve($payload, $strip);

        $this->assertNull(
            $resolved,
            'Traversal payload must not resolve to any local file, got: '.var_export($resolved, true)
        );
    }

    public function test_traversal_payload_cannot_reach_a_real_file_outside_the_roots(): void
    {
        // A file that definitely exists outside both allowed roots.
        $outside = '/etc/hostname';
        $this->assertTrue(is_file($outside), 'test precondition: /etc/hostname should exist');

        $payload = str_repeat('../', 12).'etc/hostname';

        $this->assertNull($this->resolve($payload));
        $this->assertNull($this->resolve('/'.$payload));
        $this->assertNull($this->resolve('https://x.test/'.$payload));
        $this->assertNull($this->resolve('/studio/image/'.$payload, true));
    }

    public function test_legitimate_storage_file_still_resolves(): void
    {
        $abs = $this->makeFileUnderStorage('studio/generations/legit.png');

        // The three shapes the app really produces.
        $this->assertSame(realpath($abs), $this->resolve('/storage/studio/generations/legit.png'));
        $this->assertSame(realpath($abs), $this->resolve('https://shop.test/storage/studio/generations/legit.png'));
        $this->assertSame(realpath($abs), $this->resolve('storage/studio/generations/legit.png'));
    }

    public function test_legitimate_studio_image_route_form_still_resolves(): void
    {
        $abs = $this->makeFileUnderStorage('studio/assets/face-ref.jpg');

        $this->assertSame(
            realpath($abs),
            $this->resolve('/studio/image/studio/assets/face-ref.jpg', true),
            'the /api/image/{path} route form must keep working for faceDescription()/poseDescription()'
        );
    }

    public function test_api_image_route_form_and_emitted_url_still_resolve(): void
    {
        // FabrikAI đã đổi tiền tố route ảnh: /studio/image/{path} -> /api/image/{path},
        // và studio_image_url() (app/Support/helpers.php) nay phát ra '/api/image/...'.
        // resolveLocalImage() với $stripImageRoute=true (faceDescription/poseDescription)
        // phải nhận ĐÚNG dạng URL mà chính app phát ra.
        $abs = $this->makeFileUnderStorage('studio/assets/api-face-ref.jpg');

        $this->assertSame(
            realpath($abs),
            $this->resolve('/api/image/studio/assets/api-face-ref.jpg', true),
            'the /api/image/{path} route form must resolve for faceDescription()/poseDescription()'
        );

        $emitted = studio_image_url('/storage/studio/assets/api-face-ref.jpg');
        $this->assertSame('/api/image/studio/assets/api-face-ref.jpg', $emitted);
        $this->assertSame(
            realpath($abs),
            $this->resolve((string) $emitted, true),
            'URL do studio_image_url() phát ra phải resolve lại được cục bộ: '.$emitted
        );
    }

    public function test_missing_file_resolves_to_null_without_error(): void
    {
        $this->assertNull($this->resolve('/storage/studio/generations/does-not-exist.png'));
    }

    public function test_resolver_result_is_always_inside_an_allowed_root(): void
    {
        $abs = $this->makeFileUnderStorage('studio/generations/inside.png');
        $resolved = $this->resolve('/storage/studio/generations/inside.png');

        $this->assertNotNull($resolved);

        $roots = [realpath(public_path()), realpath(storage_path('app/public'))];
        $inside = false;
        foreach ($roots as $root) {
            if ($root !== false && str_starts_with((string) $resolved, $root.DIRECTORY_SEPARATOR)) {
                $inside = true;
            }
        }

        $this->assertTrue($inside, 'resolved path must live inside public/ or storage/app/public');
    }
}
