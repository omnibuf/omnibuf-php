<?php

declare(strict_types=1);

namespace Grpc;

/**
 * Minimal shim allowing Grpc\BaseStub to instantiate in environments without PECL ext-grpc.
 */
if (!\class_exists(ChannelCredentials::class, false)) {
    final class ChannelCredentials
    {
        public static function isDefaultRootsPemSet(): bool
        {
            return true;
        }

        public static function setDefaultRootsPem(string $pem): void {}
    }
}
