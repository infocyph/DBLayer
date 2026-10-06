<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

/**
 * Stable cache visibility and dependency identities for one connection.
 */
trait ConnectionCacheIdentity
{
    private ?string $cacheDependencyFingerprintMemo = null;

    private ?string $cacheScopeFingerprintMemo = null;

    /**
     * Build the non-sensitive identity used only for shared data dependencies.
     *
     * Result visibility remains isolated by cacheScopeFingerprint(). Dependency
     * identity deliberately excludes username/cache_scope so a write performed
     * through another role or visibility scope invalidates the same physical
     * table. cache_dependency_scope can distinguish deployments sharing a cache.
     */
    public function cacheDependencyFingerprint(): string
    {
        if ($this->cacheDependencyFingerprintMemo !== null) {
            return $this->cacheDependencyFingerprintMemo;
        }

        $schema = $this->config->get('schema');
        $dependencyScope = $this->config->get('cache_dependency_scope');

        return $this->cacheDependencyFingerprintMemo = substr(hash('sha256', implode("\0", [
            'v1',
            $this->name,
            $this->getDriverName(),
            $this->getDatabaseName(),
            $this->tablePrefix,
            is_string($schema) ? $schema : '',
            is_string($dependencyScope) ? $dependencyScope : '',
        ])), 0, 32);
    }

    /**
     * Build the versioned, non-sensitive identity used by result-cache keys and tags.
     */
    public function cacheScopeFingerprint(): string
    {
        if ($this->cacheScopeFingerprintMemo !== null) {
            return $this->cacheScopeFingerprintMemo;
        }

        $schema = $this->config->get('schema');
        $username = $this->config->get('username');
        $explicitScope = $this->config->get('cache_scope');

        return $this->cacheScopeFingerprintMemo = substr(hash('sha256', implode("\0", [
            'v2',
            $this->name,
            $this->getDriverName(),
            $this->getDatabaseName(),
            $this->tablePrefix,
            is_string($schema) ? $schema : '',
            is_string($username) ? $username : '',
            is_string($explicitScope) ? $explicitScope : '',
        ])), 0, 32);
    }

    /**
     * Build a stable non-sensitive CacheLayer tag for a structured table dependency.
     */
    public function cacheTableTag(string $table, ?string $suffix = null): string
    {
        $table = strtolower(trim($table));
        $schema = $this->config->get('schema');

        if (!str_contains($table, '.') && is_string($schema) && $schema !== '') {
            $table = strtolower($schema) . '.' . $table;
        }

        $dependency = $suffix === null || $suffix === ''
            ? $table
            : $table . "\0" . $suffix;

        return 'db.' . $this->cacheDependencyFingerprint() . '.table.' . hash('xxh3', $dependency);
    }

    private function resetCacheIdentityFingerprints(): void
    {
        $this->cacheDependencyFingerprintMemo = null;
        $this->cacheScopeFingerprintMemo = null;
    }
}
