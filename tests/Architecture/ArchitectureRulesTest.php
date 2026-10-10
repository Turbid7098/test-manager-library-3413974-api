<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArchitectureRulesTest extends TestCase
{
    public function test_domain_and_application_import_only_their_allowed_inner_layers(): void
    {
        $root = dirname(__DIR__, 2);

        foreach ([
            'src/Domain' => ['Domain'],
            'src/Application' => ['Application', 'Domain'],
        ] as $directory => $allowedNamespaces) {
            foreach ($this->phpFiles($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory)) as $file) {
                $contents = file_get_contents($file->getPathname());
                self::assertNotFalse($contents);
                $violations = $this->disallowedImports($contents, $allowedNamespaces);

                self::assertSame(
                    [],
                    $violations,
                    $file->getPathname().' contains forbidden imports: '.implode(', ', $violations)
                );
            }
        }
    }

    public function test_architecture_contract_rejects_a_forbidden_dependency_fixture(): void
    {
        $violations = $this->disallowedImports(
            "<?php\nuse App\\Adapters\\Outbound\\Persistence\\DatabaseBookRepository;\n",
            ['Domain']
        );

        self::assertSame(['App\\Adapters\\Outbound\\Persistence\\DatabaseBookRepository'], $violations);
    }

    public function test_adapters_are_instantiated_only_at_the_composition_point(): void
    {
        $root = dirname(__DIR__, 2);
        $compositionPoint = realpath($root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Providers'.DIRECTORY_SEPARATOR.'AppServiceProvider.php');
        self::assertNotFalse($compositionPoint);

        foreach (['app', 'routes', 'src'] as $directory) {
            $sourceRoot = $root.DIRECTORY_SEPARATOR.$directory;
            if (! is_dir($sourceRoot)) {
                continue;
            }

            foreach ($this->phpFiles($sourceRoot) as $file) {
                if (realpath($file->getPathname()) === $compositionPoint) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                self::assertNotFalse($contents);
                $cleanContents = $this->withoutComments($contents);
                $adapterImports = $this->adapterImports($cleanContents);

                foreach ($adapterImports as $adapterClass => $alias) {
                    $classReference = preg_quote($adapterClass, '/');
                    $aliasReference = preg_quote($alias, '/');
                    $pattern = '/\bnew\s+(?:\\\\?'.$classReference.'\b|'.$aliasReference.'\b)/';
                    self::assertDoesNotMatchRegularExpression(
                        $pattern,
                        $cleanContents,
                        $file->getPathname().' instantiates '.$adapterClass.' outside AppServiceProvider.'
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $allowedNamespaces
     * @return list<string>
     */
    private function disallowedImports(string $contents, array $allowedNamespaces): array
    {
        $contents = $this->withoutComments($contents);
        preg_match_all('/^\s*use\s+(?!function\b|const\b)([^;]+);/m', $contents, $matches);
        $violations = [];

        foreach ($matches[1] as $import) {
            $import = trim(preg_replace('/\s+as\s+\w+\s*$/i', '', trim($import)) ?? trim($import), ' \\t\\n\\r\\0\\x0B\\\\');
            if ($import === '' || ! str_contains($import, '\\')) {
                continue;
            }

            $namespace = strstr($import, '\\', true);
            if ($namespace === false || ! in_array($namespace, $allowedNamespaces, true)) {
                $violations[] = $import;
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    private function adapterImports(string $contents): array
    {
        preg_match_all(
            '/^\s*use\s+(App\\\\Adapters\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;/m',
            $contents,
            $matches,
            PREG_SET_ORDER
        );
        $imports = [];

        foreach ($matches as $match) {
            $class = $match[1];
            $alias = $match[2] ?? substr($class, strrpos($class, '\\') + 1);
            $imports[$class] = $alias;
        }

        return $imports;
    }

    private function withoutComments(string $contents): string
    {
        $tokens = token_get_all($contents);
        $source = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }
}
