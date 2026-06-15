# CachePathStrategy - Référence Technique

## Description

Stratégie de chemin pour le stockage de cache au format JSONL. Organise les fichiers selon une structure basée sur le hachage MD5 des clés.

## Hiérarchie / Implémentations

```
JsonlPathStrategyInterface
    └── CachePathStrategy
```

## Rôle principal

Déterminer l'emplacement des fichiers de cache en fonction de la clé fournie. La stratégie génère une arborescence à plusieurs niveaux à partir du hash MD5 pour éviter d'avoir trop de fichiers dans un même répertoire.

## Structure des fichiers

```
{basePath}/
├── {hash[0]}/
│   └── {hash[1]}/
│       └── {sanitized_key}.jsonl
├── {hash[0]}/
│   └── {hash[2]}/
│       └── {sanitized_key}.jsonl
└── ...
```

**Exemple :** Avec `hashLevels = 2` et la clé `user_123` (MD5 = `e10adc3949ba59abbe56e057f20f883e`)
- Niveau 1 : `e`
- Niveau 2 : `1`
- Chemin final : `/cache/e/1/user_123.jsonl`

## DETAILS

[Voir la classe CachePathStrategy](https://github.com/andydefer/php-jsonl/blob/main/src/Strategies/CachePathStrategy.php)

## API / Méthodes publiques

### `__construct(string $basePath, int $hashLevels = 2): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$basePath` | `string` | Répertoire racine de stockage |
| `$hashLevels` | `int` | Nombre de niveaux de hash (1-4, défaut: 2) |

### `getFilePath(AbstractRecord $entity): string`

Retourne le chemin du fichier pour une entité `CacheRecord`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | L'entité à stocker (doit être `CacheRecord`) |

**Retourne :** `string` - Chemin absolu du fichier

**Exceptions :** `InvalidArgumentException` - Si l'entité n'est pas un `CacheRecord`

**Exemple :**
```php
$strategy = new CachePathStrategy('/var/cache', 2);
$record = new CacheRecord(key: 'user_123', value: '...');

$path = $strategy->getFilePath($record);
// Résultat: /var/cache/e/1/user_123.jsonl
```

### `getFilesToScan(AbstractRecord $query): array`

Retourne les fichiers à scanner pour une requête donnée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `AbstractRecord` | La requête (non utilisée pour le cache) |

**Retourne :** `array` - Toujours un tableau vide (accès direct par clé uniquement)

### `getBaseDirectory(): string`

Retourne le répertoire de base.

**Retourne :** `string` - Le chemin de base configuré

### `getFilePathForKey(string $key): string`

Calcule le chemin du fichier à partir d'une clé brute.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$key` | `string` | La clé de cache (déjà normalisée) |

**Retourne :** `string` - Chemin absolu du fichier

**Exemple :**
```php
$strategy = new CachePathStrategy('/var/cache', 2);
$path = $strategy->getFilePathForKey('user_123');
// Résultat: /var/cache/e/1/user_123.jsonl
```

## Cas d'utilisation

### Cas 1 : Configuration avec 2 niveaux de hash (défaut)

```php
$strategy = new CachePathStrategy('/var/cache', 2);
$path = $strategy->getFilePathForKey('session_abc123');
// Résultat: /var/cache/a/b/session_abc123.jsonl
```

### Cas 2 : Configuration avec 4 niveaux de hash

```php
$strategy = new CachePathStrategy('/var/cache', 4);
$path = $strategy->getFilePathForKey('user_456');
// Résultat: /var/cache/0/6/4/1/user_456.jsonl
// (4 niveaux de hash)
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Entité non conforme | `InvalidArgumentException` | `CachePathStrategy expects CacheRecord, got {className}` |

## Intégration

Cette stratégie est utilisée par `JsonlCacheService` via `JsonlService`. Elle est enregistrée dans le conteneur Laravel par `JsonlCacheServiceProvider`.

```php
// Dans le Service Provider
$this->app->singleton(CachePathStrategy::class, function (Application $app) {
    $config = $app->make(JsonlCacheConfig::class);
    return new CachePathStrategy(
        $config->getBasePath(),
        $config->getHashLevels()
    );
});
```

## Performance

- Calcul du chemin : O(1) avec MD5
- Sanitization des clés : O(n) où n est la longueur de la clé
- MD5 est rapide mais calculé à chaque opération
- Pour les clés très longues (>64 caractères), elles sont déjà hashed par le service

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.2+ | ✅ Requis |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ❌ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\JsonlCache\Records\CacheRecord;
use AndyDefer\JsonlCache\Strategies\CachePathStrategy;

$strategy = new CachePathStrategy('/data/cache', 3);

// Avec un record
$record = new CacheRecord(
    key: 'user_profile_123',
    value: '{"name":"John"}',
    expires_at: null,
);

$path = $strategy->getFilePath($record);
echo $path; // /data/cache/e/1/0/user_profile_123.jsonl

// Directement avec une clé
$path = $strategy->getFilePathForKey('session_xyz');
echo $path; // /data/cache/4/f/3/session_xyz.jsonl

// Accès au répertoire de base
$baseDir = $strategy->getBaseDirectory();
echo $baseDir; // /data/cache
```

## Voir aussi

- `JsonlPathStrategyInterface` - Interface implémentée
- `CacheRecord` - Record utilisé par cette stratégie
- `JsonlCacheService` - Service qui utilise cette stratégie
- `JsonlCacheConfig` - Configuration des niveaux de hash
---