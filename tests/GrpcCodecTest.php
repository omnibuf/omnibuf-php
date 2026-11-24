<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use Grpc\BaseStub;
use Grpc\CallInvoker;
use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use Omnibuf\Bench\PeerUpdate as PbPeerUpdate;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Generated\Omnibuf\Bench\Location as GenLocation;
use OmniBuf\Generated\Omnibuf\Bench\PeerUpdate as GenPeerUpdate;
use OmniBuf\Grpc\GrpcCodec;
use OmniBuf\OmniBuf;
use PHPUnit\Framework\TestCase;

#[OmniBufMessage('GrpcPing')]
final class GrpcPing
{
    #[OmniBufField(1, wire: 'uint32')]
    public int $id = 0;

    #[OmniBufField(2, wire: 'string')]
    public string $node = '';

    #[OmniBufField(3, wire: 'bool')]
    public bool $ok = false;
}

/**
 * In-memory unary call capturing BaseStub's method, metadata, serialized request, and deserializer.
 */
final class RecordingUnaryCall
{
    public string $method;
    /** @var callable(string): object */
    public $deserialize;
    /** @var array<string, list<string>> */
    public array $metadata = [];
    public string $requestWire = '';
    public ?object $response = null;
    /** @var \Closure(string, string, array<string, list<string>>): string */
    private \Closure $handler;
    private bool $selfDescribing;

    /**
     * @param callable(string): object $deserialize
     * @param \Closure(string, string, array<string, list<string>>): string $handler
     */
    public function __construct(
        string $method,
        callable $deserialize,
        \Closure $handler,
        bool $selfDescribing,
    ) {
        $this->method = $method;
        $this->deserialize = $deserialize;
        $this->handler = $handler;
        $this->selfDescribing = $selfDescribing;
    }

    /**
     * @param array<string, list<string>> $metadata
     * @param array<string, mixed> $options
     */
    public function start(object $argument, array $metadata = [], array $options = []): void
    {
        $this->metadata = $metadata;
        $this->requestWire = GrpcCodec::serialize($argument, deterministic: true, selfDescribing: $this->selfDescribing);
        $responseWire = ($this->handler)($this->method, $this->requestWire, $metadata);
        $this->response = ($this->deserialize)($responseWire);
    }

    /**
     * @return array{0: ?object, 1: object{code: int, details: string}}
     */
    public function wait(): array
    {
        return [$this->response, (object) ['code' => 0, 'details' => '']];
    }
}

/**
 * CallInvoker wired into a real Grpc\BaseStub subclass for integration testing without ext-grpc.
 */
final class LoopbackCallInvoker implements CallInvoker
{
    public ?RecordingUnaryCall $lastCall = null;
    /** @var \Closure(string, string, array<string, list<string>>): string */
    private \Closure $handler;
    private bool $selfDescribing;

    /**
     * @param \Closure(string, string, array<string, list<string>>): string $handler
     */
    public function __construct(\Closure $handler, bool $selfDescribing = false)
    {
        $this->handler = $handler;
        $this->selfDescribing = $selfDescribing;
    }

    /**
     * @param string $hostname
     * @param array<string, mixed> $opts
     */
    public function createChannelFactory($hostname, $opts): object
    {
        return (object) ['hostname' => $hostname, 'opts' => $opts];
    }

    /**
     * @param mixed $channel
     * @param string $method
     * @param callable(string): object $deserialize
     * @param array<string, mixed> $options
     */
    public function UnaryCall($channel, $method, $deserialize, $options): RecordingUnaryCall
    {
        $this->lastCall = new RecordingUnaryCall($method, $deserialize, $this->handler, $this->selfDescribing);
        return $this->lastCall;
    }

    /**
     * @param mixed $channel
     * @param string $method
     * @param callable $deserialize
     * @param array<string, mixed> $options
     */
    public function ClientStreamingCall($channel, $method, $deserialize, $options): never
    {
        throw new \LogicException('Not used in unary test');
    }

    /**
     * @param mixed $channel
     * @param string $method
     * @param callable $deserialize
     * @param array<string, mixed> $options
     */
    public function ServerStreamingCall($channel, $method, $deserialize, $options): never
    {
        throw new \LogicException('Not used in unary test');
    }

    /**
     * @param mixed $channel
     * @param string $method
     * @param callable $deserialize
     * @param array<string, mixed> $options
     */
    public function BidiStreamingCall($channel, $method, $deserialize, $options): never
    {
        throw new \LogicException('Not used in unary test');
    }
}

/**
 * Real Grpc\BaseStub subclass configured with OmniBuf\Grpc\GrpcCodec.
 */
final class OmnibufPingStub extends BaseStub
{
    public function ping(GrpcPing $request, bool $selfDescribing = false): RecordingUnaryCall
    {
        /** @var RecordingUnaryCall $call */
        $call = $this->_simpleRequest(
            '/omnibuf.test.PingService/Ping',
            $request,
            GrpcCodec::deserialize(GrpcPing::class),
            GrpcCodec::callMetadata($selfDescribing),
        );

        return $call;
    }
}

final class GrpcCodecTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\class_exists(\Grpc\ChannelCredentials::class, false)) {
            require_once __DIR__ . '/GrpcChannelCredentialsShim.php';
        }
    }

    public function testConstantsAndCallMetadata(): void
    {
        self::assertSame('omnibuf', GrpcCodec::CODEC_NAME);
        self::assertSame('x-omnibuf', GrpcCodec::SELF_DESCRIBING_CODEC_NAME);
        self::assertSame('application/grpc+omnibuf', GrpcCodec::CONTENT_TYPE_GRPC);

        self::assertSame(
            ['content-type' => ['application/grpc+omnibuf']],
            GrpcCodec::callMetadata(),
        );
        self::assertSame(
            ['content-type' => ['application/grpc+x-omnibuf']],
            GrpcCodec::callMetadata(true),
        );
    }

    public function testRoundTripAttributedClass(): void
    {
        $msg = new GrpcPing();
        $msg->id = 42;
        $msg->node = 'edge-fra-1';
        $msg->ok = true;

        $wire = GrpcCodec::serialize($msg, deterministic: true);
        $deserializer = GrpcCodec::deserialize(GrpcPing::class);

        self::assertInstanceOf(\Closure::class, $deserializer);

        $decoded = $deserializer($wire);
        self::assertInstanceOf(GrpcPing::class, $decoded);
        self::assertSame(42, $decoded->id);
        self::assertSame('edge-fra-1', $decoded->node);
        self::assertTrue($decoded->ok);
    }

    public function testRoundTripGeneratedAndProtobufMessages(): void
    {
        $gen = new GenPeerUpdate(
            asn: 64512,
            routerId: 'rtr-fra-01',
            active: true,
            location: new GenLocation(region: 'eu-central-1', city: 'Frankfurt', rack: 7),
            sequence: 123456,
        );

        $wire = GrpcCodec::serialize($gen, deterministic: true);

        $genDecoded = GrpcCodec::deserialize(GenPeerUpdate::class)($wire);
        self::assertSame(64512, $genDecoded->asn);
        self::assertSame('rtr-fra-01', $genDecoded->routerId);
        self::assertSame('Frankfurt', $genDecoded->location?->city);
        self::assertSame(123456, $genDecoded->sequence);

        $pbDecoded = GrpcCodec::deserialize(PbPeerUpdate::class)($wire);
        self::assertSame(64512, $pbDecoded->getAsn());
        self::assertSame('Frankfurt', $pbDecoded->getLocation()?->getCity());

        $pbWire = GrpcCodec::serialize($pbDecoded, deterministic: true);
        self::assertSame($wire, $pbWire);
    }

    public function testSelfDescribingSerializeIncludesFieldZeroMetadata(): void
    {
        $msg = new GrpcPing();
        $msg->id = 7;
        $msg->node = 'ams-1';
        $msg->ok = true;

        $wire = GrpcCodec::serialize($msg, deterministic: true, selfDescribing: true);
        $blind = OmniBuf::decodeBlind($wire);

        self::assertSame(7, $blind['id']);
        self::assertSame('ams-1', $blind['node']);
        self::assertTrue($blind['ok']);

        $decoded = GrpcCodec::deserialize(GrpcPing::class)($wire);
        self::assertSame(7, $decoded->id);
        self::assertSame('ams-1', $decoded->node);
        self::assertTrue($decoded->ok);
    }

    public function testEmptyPayloadAndInvalidWireError(): void
    {
        $empty = GrpcCodec::deserialize(GrpcPing::class)('');
        self::assertSame(0, $empty->id);
        self::assertSame('', $empty->node);
        self::assertFalse($empty->ok);

        $this->expectException(DecodeException::class);
        GrpcCodec::deserialize(GrpcPing::class)("\x08\x00");
    }

    public function testBaseStubIntegrationWithOmniBufCodec(): void
    {
        $serverHandler = static function (string $method, string $reqWire, array $metadata): string {
            self::assertSame('/omnibuf.test.PingService/Ping', $method);
            self::assertSame(['application/grpc+omnibuf'], $metadata['content-type'] ?? null);

            $req = GrpcCodec::deserialize(GrpcPing::class)($reqWire);
            $reply = new GrpcPing();
            $reply->id = $req->id + 1;
            $reply->node = 'echo:' . $req->node;
            $reply->ok = $req->ok;

            return GrpcCodec::serialize($reply, deterministic: true);
        };

        $invoker = new LoopbackCallInvoker($serverHandler, selfDescribing: false);
        $stub = new OmnibufPingStub('127.0.0.1:50051', [
            'credentials' => null,
            'grpc_call_invoker' => $invoker,
        ]);

        $req = new GrpcPing();
        $req->id = 42;
        $req->node = 'edge-fra-1';
        $req->ok = true;

        $call = $stub->ping($req);
        [$reply, $status] = $call->wait();

        self::assertSame(0, $status->code);
        self::assertInstanceOf(GrpcPing::class, $reply);
        self::assertSame(43, $reply->id);
        self::assertSame('echo:edge-fra-1', $reply->node);
        self::assertTrue($reply->ok);
    }

    public function testBaseStubIntegrationWithSelfDescribingCodec(): void
    {
        $serverHandler = static function (string $method, string $reqWire, array $metadata): string {
            self::assertSame('/omnibuf.test.PingService/Ping', $method);
            self::assertSame(['application/grpc+x-omnibuf'], $metadata['content-type'] ?? null);

            // Verify Field #0 metadata is present on the wire
            $blind = OmniBuf::decodeBlind($reqWire);
            self::assertSame('ams-1', $blind['node']);

            $req = GrpcCodec::deserialize(GrpcPing::class)($reqWire);
            $reply = new GrpcPing();
            $reply->id = $req->id + 10;
            $reply->node = 'sd:' . $req->node;
            $reply->ok = $req->ok;

            return GrpcCodec::serialize($reply, deterministic: true, selfDescribing: true);
        };

        $invoker = new LoopbackCallInvoker($serverHandler, selfDescribing: true);
        $stub = new OmnibufPingStub('127.0.0.1:50051', [
            'credentials' => null,
            'grpc_call_invoker' => $invoker,
        ]);

        $req = new GrpcPing();
        $req->id = 7;
        $req->node = 'ams-1';
        $req->ok = true;

        $call = $stub->ping($req, selfDescribing: true);
        [$reply, $status] = $call->wait();

        self::assertSame(0, $status->code);
        self::assertInstanceOf(GrpcPing::class, $reply);
        self::assertSame(17, $reply->id);
        self::assertSame('sd:ams-1', $reply->node);
        self::assertTrue($reply->ok);
    }
}
