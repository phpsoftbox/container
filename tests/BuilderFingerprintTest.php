<?php

declare(strict_types=1);

namespace PhpSoftBox\Container\Tests;

use PhpSoftBox\Container\Compilation\BuilderFingerprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function PhpSoftBox\Container\autowire;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(BuilderFingerprint::class)]
#[CoversMethod(BuilderFingerprint::class, 'create')]
final class BuilderFingerprintTest extends TestCase
{
    private const string NAMESPACE = 'PhpSoftBox\\Container\\Tests\\Generated';

    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * Проверим, что fingerprint не меняется, если определения и конструкторы классов не менялись.
     *
     * @see BuilderFingerprint::create()
     */
    #[Test]
    public function createIsStableForUnchangedClasses(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->declareClass('Dependency' . $suffix, '');
        $target = $this->declareClass(
            'Target' . $suffix,
            'public function __construct(public ?Dependency' . $suffix . ' $dependency = null) {}',
        );

        $definitions = [$target => autowire()];

        $this->assertSame($this->fingerprint($definitions), $this->fingerprint($definitions));
    }

    /**
     * Проверим, что fingerprint учитывает состояние классов из сигнатуры конструктора, вшитой AOT:
     * появление класса зависимости (как после composer update) меняет fingerprint при тех же определениях.
     *
     * @see BuilderFingerprint::create()
     */
    #[Test]
    public function createChangesWhenConstructorDependencyAppears(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $target = $this->declareClass(
            'Target' . $suffix,
            'public function __construct(public ?Dependency' . $suffix . ' $dependency = null) {}',
        );

        $definitions = [$target => autowire()];
        $before      = $this->fingerprint($definitions);

        // Класс зависимости появился — AOT теперь сгенерировал бы $this->get() вместо null.
        $this->declareClass('Dependency' . $suffix, '');

        $this->assertNotSame($before, $this->fingerprint($definitions));
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private function fingerprint(array $definitions): string
    {
        return new BuilderFingerprint()->create(
            autowiring: true,
            attributes: false,
            definitions: $definitions,
            decorators: [],
            lazyEntries: [],
            definitionFiles: [],
        );
    }

    /**
     * @return class-string
     */
    private function declareClass(string $shortName, string $body): string
    {
        $file = sys_get_temp_dir() . '/psb_container_fp_' . $shortName . '.php';
        file_put_contents(
            $file,
            "<?php\n\nnamespace " . self::NAMESPACE . ";\n\nfinal class " . $shortName . "\n{\n    " . $body . "\n}\n",
        );
        $this->files[] = $file;

        require_once $file;

        /** @var class-string */
        return self::NAMESPACE . '\\' . $shortName;
    }
}
