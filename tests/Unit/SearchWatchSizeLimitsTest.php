<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PriceBuddyPlus keeps its own code under 1000 lines per file and 200 per function. The file
 * list is the strict PHPStan config, so a new module file is covered as soon as it is analysed.
 */
final class SearchWatchSizeLimitsTest extends TestCase
{
    private const int FILE_LIMIT = 1000;

    private const int FUNCTION_LIMIT = 200;

    /**
     * @return list<string>
     */
    private static function files(): array
    {
        $root = \dirname(__DIR__, 2);
        $config = (string) \file_get_contents($root.'/phpstan-strict.neon');
        \preg_match_all('/^\s+- ((?:app|tests)\/\S+)$/m', $config, $matches);

        $files = [];
        foreach ($matches[1] as $path) {
            $full = $root.'/'.$path;
            if (\is_dir($full)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                        $files[] = $file->getPathname();
                    }
                }
            } else {
                $files[] = $full;
            }
        }

        return $files;
    }

    public function test_the_module_file_list_is_found(): void
    {
        self::assertGreaterThan(20, \count(self::files()));
        foreach (self::files() as $file) {
            self::assertFileExists($file);
        }
    }

    public function test_no_module_file_exceeds_the_line_limit(): void
    {
        $over = [];
        foreach (self::files() as $file) {
            $lines = \count(\file($file) ?: []);
            if ($lines > self::FILE_LIMIT) {
                $over[] = \sprintf('%s: %d lines (limit %d)', $file, $lines, self::FILE_LIMIT);
            }
        }

        self::assertSame([], $over, "Files over the size limit:\n".\implode("\n", $over));
    }

    public function test_no_module_function_exceeds_the_line_limit(): void
    {
        $over = [];
        foreach (self::files() as $file) {
            foreach (self::functionLengths((string) \file_get_contents($file)) as $name => $lines) {
                if ($lines > self::FUNCTION_LIMIT) {
                    $over[] = \sprintf('%s::%s: %d lines (limit %d)', $file, $name, $lines, self::FUNCTION_LIMIT);
                }
            }
        }

        self::assertSame([], $over, "Functions over the size limit:\n".\implode("\n", $over));
    }

    /**
     * Walk tokens rather than counting braces in text, so a brace inside a string or comment
     * is not mistaken for a block.
     *
     * @return array<string, int>
     */
    private static function functionLengths(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $lengths = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! $tokens[$i]->is(\T_FUNCTION)) {
                continue;
            }

            $name = 'closure@'.$tokens[$i]->line;
            for ($j = $i + 1; $j < $count && ! $tokens[$j]->is(['{', ';']); $j++) {
                if ($tokens[$j]->is(\T_STRING) && \str_starts_with($name, 'closure@')) {
                    $name = $tokens[$j]->text;
                }
            }

            if ($j >= $count || $tokens[$j]->is(';')) {
                continue;
            }

            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
                if ($tokens[$k]->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($tokens[$k]->is('}')) {
                    $depth--;
                    if ($depth === 0) {
                        $lengths[$name.'@'.$tokens[$i]->line] = $tokens[$k]->line - $tokens[$j]->line + 1;
                        break;
                    }
                }
            }
        }

        return $lengths;
    }
}
