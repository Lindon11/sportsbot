<?php

namespace App\Core\Services;

/**
 * Semantic version constraint resolver.
 * Supports: *, ^1.0, ~1.0, >=1.0.0, >1.0, <=1.0, <1.0, =1.0, exact 1.0.0
 * Does not support pre-release tags (1.0.0-beta) or build metadata (1.0.0+001).
 */
class SemverResolver
{
    /**
     * Check whether $installedVersion satisfies the given $constraint.
     *
     * @param string $installedVersion  e.g. "1.2.3"
     * @param string $constraint        e.g. "^1.0" or ">=1.2.0"
     */
    public function satisfies(string $installedVersion, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        // Caret: ^1.2.3 = >=1.2.3 <2.0.0
        if (str_starts_with($constraint, '^')) {
            $required = $this->parse(substr($constraint, 1));
            $installed = $this->parse($installedVersion);

            if ($required[0] === 0) {
                // ^0.y.z = >=0.y.z <0.(y+1).0
                return $installed[0] === 0
                    && $installed[1] === $required[1]
                    && $this->compare($installedVersion, substr($constraint, 1)) >= 0;
            }

            return $installed[0] === $required[0]
                && $this->compare($installedVersion, substr($constraint, 1)) >= 0;
        }

        // Tilde: ~1.2.3 = >=1.2.3 <1.3.0
        if (str_starts_with($constraint, '~')) {
            $required = $this->parse(substr($constraint, 1));
            $installed = $this->parse($installedVersion);
            $upperBound = $required[0] . '.' . ($required[1] + 1) . '.0';

            return $this->compare($installedVersion, substr($constraint, 1)) >= 0
                && $this->compare($installedVersion, $upperBound) < 0;
        }

        // Range operators
        if (str_starts_with($constraint, '>=')) {
            return $this->compare($installedVersion, substr($constraint, 2)) >= 0;
        }
        if (str_starts_with($constraint, '>')) {
            return $this->compare($installedVersion, substr($constraint, 1)) > 0;
        }
        if (str_starts_with($constraint, '<=')) {
            return $this->compare($installedVersion, substr($constraint, 2)) <= 0;
        }
        if (str_starts_with($constraint, '<')) {
            return $this->compare($installedVersion, substr($constraint, 1)) < 0;
        }
        if (str_starts_with($constraint, '=')) {
            $constraint = substr($constraint, 1);
        }

        // Exact match (or bare version string)
        return $this->compare($installedVersion, $constraint) === 0;
    }

    /**
     * Parse a version string into [major, minor, patch] integers.
     */
    protected function parse(string $version): array
    {
        $version = trim($version);
        $parts = explode('.', $version, 3);

        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            (int) ($parts[2] ?? 0),
        ];
    }

    /**
     * Compare two version strings.
     * Returns negative if $a < $b, 0 if equal, positive if $a > $b.
     */
    protected function compare(string $a, string $b): int
    {
        $aParts = $this->parse($a);
        $bParts = $this->parse($b);

        for ($i = 0; $i < 3; $i++) {
            if ($aParts[$i] !== $bParts[$i]) {
                return $aParts[$i] <=> $bParts[$i];
            }
        }

        return 0;
    }
}
