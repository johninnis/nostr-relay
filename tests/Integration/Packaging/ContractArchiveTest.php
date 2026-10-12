<?php

declare(strict_types=1);

namespace Innis\Nostr\Relay\Tests\Integration\Packaging;

use PHPUnit\Framework\TestCase;

final class ContractArchiveTest extends TestCase
{
    private const string CONTRACT_DIRECTORY = 'tests/Contract';

    // Deliberate: consumers autoload the store contract from this directory, so the dist archive must carry it — see ADR-0037
    public function testNothingKeepsTheStoreContractOutOfTheDistArchive(): void
    {
        $this->assertSame([], array_values(array_filter(
            [...self::exportIgnoredPatterns(), ...self::archiveExcludedPatterns()],
            static fn (string $pattern): bool => self::covers($pattern, self::CONTRACT_DIRECTORY),
        )));
    }

    /**
     * @return list<string>
     */
    private static function exportIgnoredPatterns(): array
    {
        $attributes = self::packageRoot().'/.gitattributes';
        $lines = is_file($attributes) ? (file($attributes, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return array_values(array_map(
            static fn (string $line): string => (string) strtok(trim($line), " \t"),
            array_filter($lines, static fn (string $line): bool => str_contains($line, 'export-ignore') && !str_contains($line, '-export-ignore')),
        ));
    }

    /**
     * @return list<string>
     */
    private static function archiveExcludedPatterns(): array
    {
        $manifest = json_decode((string) file_get_contents(self::packageRoot().'/composer.json'), true);
        $excluded = is_array($manifest) && is_array($manifest['archive'] ?? null) ? ($manifest['archive']['exclude'] ?? []) : [];

        return is_array($excluded) ? array_values(array_filter($excluded, is_string(...))) : [];
    }

    private static function covers(string $pattern, string $path): bool
    {
        $trimmed = trim($pattern, '/');

        return '' !== $trimmed && !str_starts_with($trimmed, '!') && (fnmatch($trimmed, $path) || str_starts_with($path.'/', $trimmed.'/'));
    }

    private static function packageRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
