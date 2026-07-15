<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

enum CacheDriver: string
{
    case APCU = 'apcu';

    case FILE = 'file';

    case LOCAL = 'local';

    case MEMCACHE = 'memcache';

    case MEMORY = 'memory';

    case MONGODB = 'mongodb';

    case NODE = 'node';

    case NULL_STORE = 'null_store';

    case PDO = 'pdo';

    case PHP_FILES = 'php_files';

    case REDIS = 'redis';

    case REDIS_CLUSTER = 'redis_cluster';

    case SCYLLADB = 'scylladb';

    case SHARED_MEMORY = 'shared_memory';

    case SQLITE = 'sqlite';

    case TIERED = 'tiered';

    case VALKEY = 'valkey';

    case WEAK_MAP = 'weak_map';
}
