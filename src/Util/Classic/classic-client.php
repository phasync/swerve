<?php

/*
 * Prepended to every script of a Classic application (auto_prepend_file, in the request server's
 * and php -S's processes): Swerve's services for scripts that run outside Swerve's workers.
 * `Swerve\Swerve::cache()`, `publish()`, `publishRaw()` and `subscribe()` work as in a worker,
 * through the service socket of the worker that forwarded the request (see ServiceServer), which
 * the request names in a header only Swerve sets. A Classic process can't run the real class (it
 * has no channel to the master), so this one takes its name, also when the application installs
 * phasync/swerve itself. Calls block, as everything does in a Classic script.
 *
 * Then the application's own auto_prepend_file, if php.ini names one.
 */

namespace Swerve\Classic {
    /** The service socket of this request: one connection for calls, one per subscription. */
    final class Services
    {
        public const CALL      = 1;
        public const REPLY     = 2;
        public const JSON      = 3;
        public const BYTES     = 4;
        public const HEARTBEAT = 5;
        public const END       = 6;

        /** The socket the current request names; null outside a forwarded request. */
        public static ?string $path = null;

        /** @var array{string, resource}|null the calls' connection, and the socket it is to */
        private static ?array $calls = null;

        /** @return resource a new connection to the request's service socket */
        public static function connect()
        {
            if (null === self::$path) {
                throw new \LogicException("Swerve's services are only available while Swerve serves a request");
            }
            $conn = @\stream_socket_client('unix://' . self::$path, $errno, $error, 5);
            if (false === $conn) {
                throw new \RuntimeException("Can't reach Swerve's service socket " . self::$path . ": $error");
            }
            \stream_set_timeout($conn, 86400 * 365);

            return $conn;
        }

        /** The answer to a call on the calls' connection, kept for the process's next requests. */
        public static function call(array $call): mixed
        {
            if (null === self::$calls || self::$calls[0] !== self::$path) {
                self::$calls = [self::$path, self::connect()];
            }
            self::write(self::$calls[1], self::CALL, \serialize($call));
            [$type, $payload] = self::read(self::$calls[1]);
            if (self::REPLY !== $type) {
                throw new \UnexpectedValueException("Swerve's service socket answered with a frame of type $type");
            }

            return self::answer($payload);
        }

        /** The result in a REPLY, or its exception thrown. */
        public static function answer(string $payload): mixed
        {
            $reply = \unserialize($payload, ['allowed_classes' => false]);
            if (true === $reply[0]) {
                return $reply[1];
            }
            if ('Swerve\\CacheKeyException' === $reply[1]) {
                throw new CacheKeyException($reply[2]);
            }
            throw new \InvalidArgumentException($reply[2]);
        }

        /** @param resource $conn */
        public static function write($conn, int $type, string $payload): void
        {
            $bytes = \pack('CN', $type, \strlen($payload)) . $payload;
            if (\strlen($bytes) !== @\fwrite($conn, $bytes)) {
                throw new \RuntimeException("Swerve's service socket closed");
            }
        }

        /**
         * @param resource $conn
         *
         * @return array{int, string}
         */
        public static function read($conn): array
        {
            $head = self::exactly($conn, 5);
            $length = \unpack('N', $head, 1)[1];

            return [\ord($head[0]), 0 === $length ? '' : self::exactly($conn, $length)];
        }

        /** @param resource $conn */
        private static function exactly($conn, int $length): string
        {
            $data = '';
            while (\strlen($data) < $length) {
                $chunk = \fread($conn, $length - \strlen($data));
                if ('' === $chunk || false === $chunk) {
                    throw new \RuntimeException("Swerve's service socket closed");
                }
                $data .= $chunk;
            }

            return $data;
        }
    }

    /**
     * Swerve::cache() in a Classic script, with the PSR-16 interface when the application has it
     * loaded. Values are serialized here, with the application's classes, and stored as they are.
     */
    trait CacheMethods
    {
        public function get($key, mixed $default = null): mixed
        {
            return $this->getMultiple([$key], $default)[$key];
        }

        public function getMultiple($keys, mixed $default = null): iterable
        {
            $values = [];
            foreach (Services::call(['cache.get', self::keys($keys)]) as $key => $serialized) {
                $values[$key] = null === $serialized ? $default : \unserialize($serialized);
            }

            return $values;
        }

        public function has($key): bool
        {
            return null !== Services::call(['cache.get', self::keys([$key])])[$key];
        }

        public function set($key, mixed $value, $ttl = null): bool
        {
            return $this->setMultiple([$key => $value], $ttl);
        }

        public function setMultiple($values, $ttl = null): bool
        {
            if (!\is_iterable($values)) {
                throw new CacheKeyException('setMultiple() takes an iterable of key => value, not ' . \get_debug_type($values));
            }
            if (null !== $ttl && !\is_int($ttl) && !$ttl instanceof \DateInterval) {
                throw new \TypeError('A cache TTL is seconds (int), a DateInterval or null, not ' . \get_debug_type($ttl));
            }
            if ($ttl instanceof \DateInterval) {
                $ttl = (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp();
            }
            $serialized = [];
            foreach ($values as $key => $value) {
                $serialized[self::keys([(string) $key])[0]] = \serialize($value);
            }

            return Services::call(['cache.set', $serialized, $ttl]);
        }

        public function delete($key): bool
        {
            return $this->deleteMultiple([$key]);
        }

        public function deleteMultiple($keys): bool
        {
            return Services::call(['cache.delete', self::keys($keys)]);
        }

        public function clear(): bool
        {
            return Services::call(['cache.clear']);
        }

        /** PSR-16 keys, checked here as in a worker. */
        private static function keys($keys): array
        {
            if (!\is_iterable($keys)) {
                throw new CacheKeyException('Cache keys come as an iterable of strings, not ' . \get_debug_type($keys));
            }
            $valid = [];
            foreach ($keys as $key) {
                if (!\is_string($key) || '' === $key || false !== \strpbrk($key, '{}()/\\@:')) {
                    throw new CacheKeyException('A cache key is a non-empty string without {}()/\\@:, not ' . \var_export($key, true));
                }
                $valid[] = $key;
            }

            return $valid;
        }
    }

    /**
     * A subscription of a Classic script: its own connection, which the worker streams the topic's
     * messages to, until this object goes.
     *
     * @implements \IteratorAggregate<int, mixed>
     */
    final class Subscription implements \IteratorAggregate
    {
        /** @var resource|null */
        private $conn;

        public function __construct(public readonly string $topic, float $maxLag, ?float $heartbeat)
        {
            $this->conn = Services::connect();
            Services::write($this->conn, Services::CALL, \serialize(['subscribe', $topic, $maxLag, $heartbeat]));
            [, $payload] = Services::read($this->conn);
            Services::answer($payload);
        }

        /** @return \Generator<int, mixed> each message; null for a heartbeat */
        public function getIterator(): \Generator
        {
            while (null !== $this->conn) {
                [$type, $payload] = Services::read($this->conn);
                switch ($type) {
                    case Services::JSON:
                        yield \json_decode($payload, false, 512, \JSON_THROW_ON_ERROR);
                        break;
                    case Services::BYTES:
                        yield $payload;
                        break;
                    case Services::HEARTBEAT:
                        yield null;
                        break;
                    case Services::END:
                        $this->close();
                        if ('' !== $payload) {
                            throw new \RuntimeException($payload); // the subscriber fell behind
                        }

                        return;
                }
            }
        }

        public function __destruct()
        {
            $this->close();
        }

        private function close(): void
        {
            if (null !== $this->conn) {
                @\fclose($this->conn);
                $this->conn = null;
            }
        }
    }
}

namespace Swerve {
    if (!\class_exists(Swerve::class, false)) {
        /** Swerve's services in a Classic script: see classic-client.php. */
        final class Swerve
        {
            private static ?object $cache = null;

            public static function cache(): object
            {
                if (null === self::$cache) {
                    require_once __DIR__ . '/classic-client-cache.php';
                    self::$cache = new \Swerve\Classic\Cache();
                }

                return self::$cache;
            }

            public static function publish(string $topic, mixed $message): void
            {
                if (null === $message) {
                    throw new \InvalidArgumentException('null is no message: a subscription with a heartbeat yields null when none came');
                }
                \Swerve\Classic\Services::call(['publish', $topic, \json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION, 511)]);
            }

            public static function publishRaw(string $topic, string $bytes): void
            {
                \Swerve\Classic\Services::call(['publish', $topic, "\0" . $bytes]);
            }

            public static function subscribe(string $topic, float $maxLag = 30.0, ?float $heartbeat = null): \Swerve\Classic\Subscription
            {
                return new \Swerve\Classic\Subscription($topic, $maxLag, $heartbeat);
            }
        }
    }
}

namespace {
    // The request names the worker's service socket; the application doesn't see the header
    \Swerve\Classic\Services::$path = $_SERVER['HTTP_X_SWERVE_SERVICES'] ?? null;
    unset($_SERVER['HTTP_X_SWERVE_SERVICES']);
    if ('' !== ($prepend = (string) \getenv('SWERVE_APP_PREPEND'))) {
        require $prepend;
    }
    unset($prepend);
}
