# PHP JSONL - Documentation Complète

## 📦 Présentation

**PHP JSONL** est une bibliothèque PHP moderne et performante pour la gestion de fichiers au format [JSONL (JSON Lines)](https://jsonlines.org/). Elle offre une solution complète pour le logging structuré, le caching, et la gestion de données ligne par ligne avec des stratégies de partitionnement flexibles.

### Caractéristiques principales

- ✅ **Écriture haute performance** avec bufferisation automatique
- ✅ **Verrouillage automatique** pour les accès concurrents
- ✅ **Recherche avancée** avec filtrage personnalisé
- ✅ **Nettoyage intelligent** (expiration, ancienneté, patterns)
- ✅ **Deux stratégies de partitionnement** : temporelle (logs) et par clé (cache)
- ✅ **Architecture stateless** : tout l'état est déporté dans un contexte unifié
- ✅ **Support PHP 8.2+** avec types stricts et readonly properties
- ✅ **Tests complets** : 79 tests, 432 assertions

## 🚀 Installation

```bash
composer require andydefer/php-jsonl
```

## 🏗️ Architecture

### Structure générale

```
JsonlService (stateless)
    ├── JsonlPathStrategyInterface (stratégie de chemin)
    │   ├── TemporalPathStrategy (pour logs)
    │   └── KeyBasedPathStrategy (pour cache)
    ├── FileSystemInterface (opérations fichiers)
    └── JsonlContext (état unifié: locks, buffer, traitement)
```

### Composants principaux

| Composant | Rôle |
|-----------|------|
| `JsonlService` | Service principal, orchestrateur de toutes les opérations |
| `JsonlPathStrategyInterface` | Détermine l'emplacement des fichiers |
| `TemporalPathStrategy` | Organisation par date/heure (logs) |
| `KeyBasedPathStrategy` | Organisation par hash MD5 (cache) |
| `JsonlContext` | Gestion unifiée de l'état (verrous, buffer, traitement) |

## 📚 Concepts fondamentaux

### Format JSONL (JSON Lines)

Le format JSONL stocke chaque enregistrement JSON sur une ligne distincte :

```jsonl
{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"user_login"}
{"time":"2026-01-15T14:36:00+00:00","level":"info","type":"user_login"}
{"time":"2026-01-15T14:37:00+00:00","level":"error","type":"payment_failed"}
```

**Avantages :**
- Streaming possible (lecture ligne par ligne)
- Append uniquement (pas de réécriture complète)
- Facile à parser avec des outils classiques (`grep`, `awk`, `jq`)

### Types d'enregistrements

#### LogJsonlRecord - Pour les logs structurés

```php
use AndyDefer\PhpJsonl\Records\LogJsonlRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$log = new LogJsonlRecord(
    time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject(['user_id' => 123, 'username' => 'john_doe']),
);
```

#### CacheJsonlRecord - Pour le caching

```php
use AndyDefer\PhpJsonl\Records\CacheJsonlRecord;

$cache = new CacheJsonlRecord(
    key: 'user_123',
    value: json_encode(['name' => 'John', 'email' => 'john@example.com']),
    expires_at: new DateTimeVO('+1 hour'), // null = jamais expiré
);
```

## 🎯 Stratégies de partitionnement

### 1. TemporalPathStrategy - Pour les logs

Organise les fichiers par date et heure :

```
/var/logs/
├── 2026-01-15/
│   ├── 00.jsonl
│   ├── 01.jsonl
│   └── ...
│   └── 23.jsonl
├── 2026-01-16/
│   └── ...
```

**Avantages :**
- Recherche efficace par plage temporelle
- Nettoyage facile (suppression par jour)
- Pas de fichier unique trop volumineux

**Utilisation :**
```php
use AndyDefer\PhpJsonl\Strategies\TemporalPathStrategy;

$strategy = new TemporalPathStrategy('/var/logs');
$service = new JsonlService($strategy, $fileSystem, $context);
```

### 2. KeyBasedPathStrategy - Pour le cache

Organise les fichiers par hash MD5 de la clé :

```
/var/cache/
├── a/
│   ├── b/
│   │   └── user_123.jsonl
│   └── c/
│       └── session_abc.jsonl
└── d/
    └── e/
        └── product_456.jsonl
```

**Avantages :**
- Distribution uniforme des fichiers
- Accès direct par clé (pas de scanning)
- Évite les répertoires trop volumineux

**Utilisation :**
```php
use AndyDefer\PhpJsonl\Strategies\KeyBasedPathStrategy;

$strategy = new KeyBasedPathStrategy('/var/cache', hashLevels: 2);
$service = new JsonlService($strategy, $fileSystem, $context);
```

## 🔧 Installation et configuration

### Configuration de base

```php
use AndyDefer\PhpJsonl\JsonlService;
use AndyDefer\PhpJsonl\Contexts\JsonlContext;
use AndyDefer\PhpJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpServices\Enums\PermissionMode;

// 1. Créer la stratégie de chemin
$strategy = new TemporalPathStrategy('/var/app/logs');

// 2. Créer le service de fichiers
$fileSystem = new FileSystemService();

// 3. Créer le contexte unifié (état)
$context = new JsonlContext();

// 4. Instancier le service
$service = new JsonlService(
    pathStrategy: $strategy,
    fileSystem: $fileSystem,
    context: $context,
    defaultBufferSize: 100,              // Buffer de 100 entrées
    directoryPermission: PermissionMode::DIRECTORY  // Permissions 755
);
```

### Avec stratégie pour cache

```php
use AndyDefer\PhpJsonl\Strategies\KeyBasedPathStrategy;

$cacheStrategy = new KeyBasedPathStrategy(
    basePath: '/var/app/cache',
    hashLevels: 2  // 2 niveaux de hash (16² = 256 dossiers possibles)
);

$cacheService = new JsonlService($cacheStrategy, $fileSystem, $context);
```

## 📝 API détaillée

### Opérations d'écriture

#### write() - Écriture simple

```php
public function write(AbstractRecord $entity, bool $lock = true): void
```

**Exemple :**
```php
$log = new LogJsonlRecord(/* ... */);
$service->write($log);
$service->write($log, lock: false);  // Sans verrouillage
```

#### writeBatch() - Écriture par lots

```php
public function writeBatch(array $entities, bool $lock = true): void
```

**Exemple :**
```php
$logs = [/* 100 entrées */];
$service->writeBatch($logs);
```

#### writeBuffered() - Écriture bufferisée

```php
public function writeBuffered(AbstractRecord $entity): void
```

**Exemple :**
```php
$service->enableBuffer(50);  // Buffer de 50 entrées

for ($i = 0; $i < 1000; $i++) {
    $service->writeBuffered($log);
    // Écriture automatique après 50 entrées
}

$service->flushBuffer();  // Écriture manuelle du reste
```

### Opérations de lecture

#### readAll() - Lire tout le fichier

```php
public function readAll(string $filePath): array
```

**Exemple :**
```php
$lines = $service->readAll('/var/logs/2026-01-15/14.jsonl');
foreach ($lines as $line) {
    echo $line['level'] . ': ' . $line['type'] . "\n";
}
```

#### readLineByLine() - Lecture ligne par ligne (streaming)

```php
public function readLineByLine(string $filePath, callable $callback): void
```

**Exemple :**
```php
$service->readLineByLine('/var/logs/large_file.jsonl', function ($line) {
    if ($line['level'] === 'error') {
        echo "ERREUR: " . $line['type'] . "\n";
    }
});
```

#### getFirstLine() / getLastLine() - Première/dernière ligne

```php
public function getFirstLine(string $filePath): ?array
public function getLastLine(string $filePath): ?array
```

**Exemple :**
```php
$first = $service->getFirstLine('/var/logs/app.jsonl');
$last = $service->getLastLine('/var/logs/app.jsonl');

echo "Premier log: " . $first['type'] . "\n";
echo "Dernier log: " . $last['type'] . "\n";
```

### Recherche

#### search() - Recherche dans un fichier

```php
public function search(string $filePath, callable $filter): array
```

**Exemple :**
```php
$errors = $service->search('/var/logs/14.jsonl', function ($line) {
    return $line['level'] === 'error';
});

foreach ($errors as $error) {
    echo $error['time'] . ': ' . $error['type'] . "\n";
}
```

#### searchMultiple() - Recherche dans plusieurs fichiers

```php
public function searchMultiple(array $filePaths, callable $filter): array
```

**Exemple :**
```php
$files = [
    '/var/logs/2026-01-15/14.jsonl',
    '/var/logs/2026-01-15/15.jsonl',
    '/var/logs/2026-01-15/16.jsonl',
];

$payments = $service->searchMultiple($files, function ($line) {
    return $line['type'] === 'payment_success';
});

echo "Nombre de paiements: " . count($payments);
```

### Gestion du buffer

#### enableBuffer() - Activer le buffer

```php
public function enableBuffer(int $size = 100): void
```

**Exemple :**
```php
$service->enableBuffer(200);  // Écrit toutes les 200 entrées
```

#### disableBuffer() - Désactiver le buffer

```php
public function disableBuffer(): void
```

**Exemple :**
```php
$service->disableBuffer();  // Écriture immédiate
```

#### flushBuffer() - Vider le buffer

```php
public function flushBuffer(?string $filePath = null): void
```

**Exemple :**
```php
$service->writeBuffered($log1);
$service->writeBuffered($log2);
$service->flushBuffer();  // Écrit les 2 entrées
```

#### onFlush() - Callback sur flush

```php
public function onFlush(callable $callback): void
```

**Exemple :**
```php
$service->onFlush(function (string $filePath, int $count) {
    echo "Flush: {$count} lignes écrites dans {$filePath}\n";
});
```

### Nettoyage des données

#### cleanOlderThan() - Supprimer les fichiers trop vieux

```php
public function cleanOlderThan(int $days, string $basePath): int
```

**Exemple :**
```php
$deleted = $service->cleanOlderThan(30, '/var/logs');
echo "Supprimé {$deleted} fichiers de logs de plus de 30 jours";
```

#### cleanExpired() - Supprimer les entrées expirées (cache)

```php
public function cleanExpired(string $basePath, callable $isExpired): int
```

**Exemple :**
```php
$deleted = $service->cleanExpired('/var/cache', function ($line) {
    if (!isset($line['expires_at'])) {
        return false;
    }
    $expiresAt = new DateTimeVO($line['expires_at']);
    $now = new DateTimeVO();
    return $expiresAt->isBefore($now);
});

echo "Supprimé {$deleted} entrées de cache expirées";
```

#### cleanByPattern() - Supprimer par pattern glob

```php
public function cleanByPattern(string $pattern): int
```

**Exemple :**
```php
$pattern = '/var/logs/2026-01-15/*.jsonl';
$deleted = $service->cleanByPattern($pattern);
echo "Supprimé {$deleted} fichiers";
```

#### dryRun() - Simuler une suppression

```php
public function dryRun(string $basePath, callable $filter): array
```

**Exemple :**
```php
$filesToDelete = $service->dryRun('/var/logs', function ($file) {
    return filemtime($file) < strtotime('-90 days');
});

echo "Fichiers qui seraient supprimés:\n";
foreach ($filesToDelete as $file) {
    echo "  - {$file}\n";
}

if (count($filesToDelete) > 0) {
    $confirm = readline("Procéder à la suppression? (y/n): ");
    if ($confirm === 'y') {
        $deleted = $service->cleanOlderThan(90, '/var/logs');
        echo "{$deleted} fichiers supprimés\n";
    }
}
```

#### clear() - Vider complètement un répertoire

```php
public function clear(string $basePath): int
```

**Exemple :**
```php
$deleted = $service->clear('/var/cache');
echo "Cache vidé: {$deleted} fichiers supprimés";
```

### Verrouillage (locks)

#### acquire() - Acquérir un verrou

```php
public function acquire(string $filePath, int $timeout = 5): bool
```

**Exemple :**
```php
if ($service->acquire('/var/logs/app.jsonl', timeout: 3)) {
    try {
        // Opérations exclusives
        $service->append($filePath, $data);
    } finally {
        $service->release('/var/logs/app.jsonl');
    }
}
```

#### release() - Libérer un verrou

```php
public function release(string $filePath): void
```

#### executeWithLock() - Exécuter avec verrou automatique

```php
public function executeWithLock(string $filePath, callable $callback): mixed
```

**Exemple :**
```php
$result = $service->executeWithLock('/shared/data.jsonl', function () use ($service) {
    $existing = $service->readAll('/shared/data.jsonl');
    $newData = ['timestamp' => time(), 'value' => rand(1, 100)];
    $existing[] = $newData;
    
    $content = '';
    foreach ($existing as $item) {
        $content .= json_encode($item) . "\n";
    }
    file_put_contents('/shared/data.jsonl', $content);
    
    return count($existing);
});

echo "Total après opération atomique: {$result}";
```

#### isLocked() - Vérifier si un verrou est actif

```php
public function isLocked(string $filePath): bool
```

**Exemple :**
```php
if ($service->isLocked('/var/logs/app.jsonl')) {
    echo "Fichier verrouillé, réessayez plus tard";
} else {
    $service->acquire('/var/logs/app.jsonl');
    // ...
}
```

### Utilitaires cache

#### isExpired() - Vérifier si un cache est expiré

```php
public function isExpired(CacheJsonlRecord $record): bool
```

**Exemple :**
```php
$record = CacheJsonlRecord::fromArray($line);
if ($service->isExpired($record)) {
    echo "Cache expiré, rafraîchissement nécessaire";
} else {
    echo "Cache valide: " . $record->value;
}
```

#### decodeCacheValue() - Décoder une valeur de cache

```php
public function decodeCacheValue(string $encodedValue, string $typeString): StrictDataObject
```

**Exemple :**
```php
$cachedData = $service->readAll('/cache/a/b/user_123.jsonl');
if (!empty($cachedData)) {
    $decoded = $service->decodeCacheValue(
        $cachedData[0]['value'],
        $cachedData[0]['value_type']
    );
    
    echo "Nom: " . $decoded->name;
    echo "Email: " . $decoded->email;
}
```

### Utilitaires généraux

#### getFilePath() - Obtenir le chemin d'un enregistrement

```php
public function getFilePath(AbstractRecord $entity): string
```

**Exemple :**
```php
$record = new LogJsonlRecord(/* ... */);
$path = $service->getFilePath($record);
echo "Le log sera stocké dans: {$path}";
```

#### getFilesToScan() - Obtenir les fichiers à scanner

```php
public function getFilesToScan(AbstractRecord $query): array
```

**Exemple :**
```php
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T10:00:00+00:00'),
    to: new DateTimeVO('2026-01-15T14:00:00+00:00'),
);

$files = $service->getFilesToScan($query);
echo "Fichiers à scanner: " . count($files);
```

#### getContext() - Accéder au contexte unifié

```php
public function getContext(): JsonlContext
```

**Exemple :**
```php
$context = $service->getContext();
if ($context->hasError()) {
    echo "Erreur: " . $context->getLastError();
}
echo "Total lignes traitées: " . $context->getTotalLinesProcessed();
```

#### resetProcessingState() - Réinitialiser l'état de traitement

```php
public function resetProcessingState(): self
```

**Exemple :**
```php
$service->resetProcessingState();  // Réinitialise les stats
```

#### setPathStrategy() - Changer la stratégie de chemin

```php
public function setPathStrategy(JsonlPathStrategyInterface $pathStrategy): void
```

**Exemple :**
```php
// Passer de logs à cache
$service->setPathStrategy($keyBasedStrategy);
```

## 💡 Cas d'utilisation avancés

### 1. Logging haute performance avec buffer

```php
$service->enableBuffer(1000);
$service->onFlush(function ($path, $count) {
    echo "[PERF] Écriture de {$count} logs dans {$path}\n";
});

for ($i = 0; $i < 100000; $i++) {
    $service->writeBuffered(new LogJsonlRecord(
        time: new DateTimeVO(),
        level: 'info',
        type: 'api_request',
        payload: new StrictDataObject(['request_id' => $i]),
    ));
}

$service->flushBuffer();
```

### 2. Mise en cache avec TTL

```php
class UserCache
{
    public function __construct(private JsonlService $cache) {}
    
    public function get(int $userId): ?array
    {
        $key = "user_{$userId}";
        $path = $this->cache->getFilePath(new CacheJsonlRecord(key: $key, value: '', expires_at: null));
        
        if (!$this->cache->fileExists($path)) {
            return null;
        }
        
        $lines = $this->cache->readAll($path);
        $record = CacheJsonlRecord::fromArray($lines[0]);
        
        if ($this->cache->isExpired($record)) {
            $this->cache->delete($path);
            return null;
        }
        
        return $this->cache->decodeCacheValue($record->value, $record->value_type)->toArray();
    }
    
    public function set(int $userId, array $data, int $ttlSeconds = 3600): void
    {
        $record = new CacheJsonlRecord(
            key: "user_{$userId}",
            value: json_encode($data),
            expires_at: new DateTimeVO("+{$ttlSeconds} seconds"),
        );
        
        $this->cache->write($record);
    }
}
```

### 3. Analyse de logs avec streaming

```php
function analyzeLogs(JsonlService $service, string $logDir): array
{
    $stats = ['total' => 0, 'errors' => 0, 'warnings' => 0];
    
    // Parcourir tous les fichiers du dernier jour
    $today = (new DateTime())->format('Y-m-d');
    $hourFiles = [];
    
    for ($hour = 0; $hour < 24; $hour++) {
        $path = "{$logDir}/{$today}/{$hour}.jsonl";
        if ($service->fileExists($path)) {
            $hourFiles[] = $path;
        }
    }
    
    // Analyser ligne par ligne (économie mémoire)
    foreach ($hourFiles as $file) {
        $service->readLineByLine($file, function ($line) use (&$stats) {
            $stats['total']++;
            
            if ($line['level'] === 'error') {
                $stats['errors']++;
            } elseif ($line['level'] === 'warning') {
                $stats['warnings']++;
            }
        });
    }
    
    return $stats;
}
```

### 4. Migration automatique des vieux logs

```php
class LogRotator
{
    public function __construct(
        private JsonlService $logs,
        private JsonlService $archive,
        private int $retentionDays = 30
    ) {}
    
    public function rotate(): void
    {
        // Simuler pour voir ce qui serait supprimé
        $toDelete = $this->logs->dryRun('/var/logs', function ($file) {
            return filemtime($file) < strtotime("-{$this->retentionDays} days");
        });
        
        if (empty($toDelete)) {
            echo "Aucun log à archiver\n";
            return;
        }
        
        echo "Fichiers à archiver ({$this->retentionDays} jours):\n";
        foreach ($toDelete as $file) {
            echo "  - {$file}\n";
        }
        
        $confirm = readline("Archiver ces fichiers? (y/n): ");
        if ($confirm !== 'y') {
            echo "Opération annulée\n";
            return;
        }
        
        // Archiver avant suppression
        foreach ($toDelete as $file) {
            $content = $this->logs->readAll($file);
            $archiveFile = str_replace('/logs/', '/archive/', $file);
            
            $this->archive->writeBatch($content);
            echo "Archivé: {$file} → {$archiveFile}\n";
        }
        
        // Supprimer les originaux
        $deleted = $this->logs->cleanOlderThan($this->retentionDays, '/var/logs');
        echo "Supprimé {$deleted} fichiers\n";
    }
}
```

### 5. Opérations atomiques avec verrouillage

```php
class CounterService
{
    public function __construct(private JsonlService $storage) {}
    
    public function increment(string $counterName): int
    {
        $filePath = "/counters/{$counterName}.jsonl";
        
        return $this->storage->executeWithLock($filePath, function () use ($filePath, $counterName) {
            $value = 0;
            
            if ($this->storage->fileExists($filePath)) {
                $lastLine = $this->storage->getLastLine($filePath);
                $value = $lastLine['value'] ?? 0;
            }
            
            $newValue = $value + 1;
            
            $record = new LogJsonlRecord(
                time: new DateTimeVO(),
                level: 'info',
                type: 'counter_increment',
                payload: new StrictDataObject([
                    'counter' => $counterName,
                    'value' => $newValue,
                    'increment' => 1,
                ]),
            );
            
            $this->storage->write($record, lock: false); // Déjà locké
            
            return $newValue;
        });
    }
}
```

## 🔍 Dépannage

### Erreurs fréquentes

| Erreur | Cause | Solution |
|--------|-------|----------|
| `JsonlException: Unsupported record type` | Type d'enregistrement non supporté par la stratégie | Utiliser `LogJsonlRecord` avec `TemporalPathStrategy` ou `CacheJsonlRecord` avec `KeyBasedPathStrategy` |
| `JsonlLockException: Timeout acquiring lock` | Fichier verrouillé trop longtemps | Augmenter le timeout ou vérifier les deadlocks |
| `JsonlException: File does not exist` | Fichier inexistant en lecture | Vérifier avec `fileExists()` avant lecture |
| `InvalidArgumentException: expects...` | Mauvais type d'enregistrement | Vérifier la compatibilité stratégie/record |

### Suivi d'opération avec le contexte

```php
$service->write($record);
$context = $service->getContext();

if ($context->hasError()) {
    echo "Erreur: " . $context->getLastError() . "\n";
    echo "Opération: " . $context->getCurrentOperation()->value . "\n";
    echo "Fichiers traités: " . implode(', ', $context->getProcessedFiles()->toArray()) . "\n";
    echo "Lignes traitées: " . $context->getTotalLinesProcessed() . "\n";
}

// Réinitialiser pour la prochaine opération
$service->resetProcessingState();
```

## 📊 Performance

### Complexités

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `write()` | O(1) | + verrouillage fichier |
| `writeBatch()` | O(n) | n = nombre d'entités |
| `writeBuffered()` | O(1) | flush à taille atteinte |
| `readAll()` | O(l) | l = nombre de lignes |
| `search()` | O(l) | parcourt toutes les lignes |
| `getFirstLine()` / `getLastLine()` | O(1) | lecture partielle |
| `cleanOlderThan()` | O(f) | f = fichiers trouvés |
| `cleanExpired()` | O(f × l) | f = fichiers, l = lignes |
| `getFilesToScan()` | O(jours × 24) | pour stratégie temporelle |

### Optimisations recommandées

1. **Buffer pour écritures massives**
   ```php
   $service->enableBuffer(1000);  // 1000 logs avant écriture disque
   ```

2. **Streaming pour gros fichiers**
   ```php
   $service->readLineByLine($path, $callback);  // Pas de chargement mémoire
   ```

3. **Dry run avant suppression**
   ```php
   $toDelete = $service->dryRun($path, $filter);  // Vérifier avant de supprimer
   ```

4. **Verrouillage uniquement quand nécessaire**
   ```php
   $service->write($record, lock: false);  // Pas de lock si écriture unique
   ```

## 🔗 Dépendances

- `PHP ^8.2` - Langage requis
- `andydefer/php-services` - Services de base (FileSystemInterface)
- `andydefer/php-vo` - Value objects (DateTimeVO)
- `andydefer/domain-structures` - Structures de domaine (AbstractRecord, StrictDataObject)

## 📜 License

MIT License

## 👨‍💻 Auteur

**Andy Kani** - [andykanidimbu@gmail.com](mailto:andykanidimbu@gmail.com)

## 🙏 Contributions

Les contributions sont les bienvenues !

---

## 📖 Résumé rapide

```php
// Initialisation
$strategy = new TemporalPathStrategy('/var/logs');
$context = new JsonlContext();
$service = new JsonlService($strategy, new FileSystemService(), $context);

// Écrire un log
$service->write(new LogJsonlRecord(
    time: new DateTimeVO(),
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject(['user_id' => 123]),
));

// Lire les logs
$logs = $service->readAll('/var/logs/2026-01-15/14.jsonl');

// Rechercher des erreurs
$errors = $service->search('/var/logs/14.jsonl', fn($line) => $line['level'] === 'error');

// Nettoyer les vieux logs
$service->cleanOlderThan(30, '/var/logs');

// Accéder au contexte
$context = $service->getContext();
echo "Total lignes: " . $context->getTotalLinesProcessed();

// Réinitialiser l'état
$service->resetProcessingState();
```
---