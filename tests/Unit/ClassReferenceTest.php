<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * app/ 配下のコードが参照しているクラスが、実際に解決できることを確認する。
 *
 * `php -l` は構文しか見ないので、クラスを別ファイルへ切り出したときの `use` 漏れを見逃す。
 * その場合 PHP は同一 namespace のクラスとして解決しようとし、
 * 「Class "App\Services\TimelineJobMeta" not found」のような実行時エラーになる。
 * 実際にその経路を踏んで画面が 500 になったことがあるため、テストで押さえておく。
 */
class ClassReferenceTest extends TestCase
{
    #[Test]
    public function every_referenced_class_can_be_resolved(): void
    {
        $unresolved = [];

        foreach ($this->phpFilesUnderApp() as $file) {
            $source = file_get_contents($file->getPathname());
            $namespace = $this->namespaceOf($source);
            $imports = $this->importsOf($source);
            $code = $this->stripCommentsAndStrings($source);

            foreach ($this->referencedClassNames($code) as $name) {
                if ($this->resolves($name, $namespace, $imports)) {
                    continue;
                }

                $relative = str_replace(base_path() . '/', '', $file->getPathname());
                $unresolved[] = "{$relative}: {$name}";
            }
        }

        $this->assertSame([], array_values(array_unique($unresolved)), '解決できないクラス参照');
    }

    /** @return list<SplFileInfo> */
    private function phpFilesUnderApp(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function namespaceOf(string $source): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $source, $m) ? trim($m[1]) : '';
    }

    /** @return array<string, string> 小文字の別名 => 完全修飾名 */
    private function importsOf(string $source): array
    {
        $imports = [];
        preg_match_all('/^use\s+(?!function |const )([^;]+);/m', $source, $matches);

        foreach ($matches[1] as $use) {
            $use = trim($use);

            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $use, $m)) {
                $imports[strtolower($m[2])] = trim($m[1]);

                continue;
            }

            $segments = explode('\\', $use);
            $imports[strtolower(end($segments))] = $use;
        }

        return $imports;
    }

    /** コメントと文字列リテラルを落とす（docblock 内のクラス名は参照ではない）。 */
    private function stripCommentsAndStrings(string $source): string
    {
        $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], $ignored, true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return list<string> */
    private function referencedClassNames(string $code): array
    {
        $names = [];
        $patterns = [
            '/(?<![\\\\$>\w])([A-Z][A-Za-z0-9_]*)\s*::/',      // Foo::bar()
            '/\bnew\s+([A-Z][A-Za-z0-9_]*)\s*\(/',             // new Foo()
            '/\binstanceof\s+\\\\?([A-Z][A-Za-z0-9_]*)/',      // $x instanceof Foo
            '/\bcatch\s*\(\s*\\\\?([A-Z][A-Za-z0-9_]*)/',      // catch (Foo $e)
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $code, $matches);
            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        unset($names['self'], $names['static'], $names['parent']);

        return array_keys($names);
    }

    /** @param array<string, string> $imports */
    private function resolves(string $name, string $namespace, array $imports): bool
    {
        $candidates = isset($imports[strtolower($name)])
            ? [$imports[strtolower($name)]]
            : array_filter([$namespace === '' ? null : $namespace . '\\' . $name, $name]);

        foreach ($candidates as $fqn) {
            if (class_exists($fqn) || interface_exists($fqn) || trait_exists($fqn) || enum_exists($fqn)) {
                return true;
            }
        }

        return false;
    }
}
