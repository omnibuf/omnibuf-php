<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

require_once __DIR__ . '/../src/Conformance/AnnotatedModels.php';

use OmniBuf\Conformance\AnnotatedLocation;
use OmniBuf\Conformance\AnnotatedNeighbor;
use OmniBuf\Conformance\AnnotatedPeerUpdate;
use OmniBuf\Exception\DecodeException;
use OmniBuf\Generated\Omnibuf\Bench\Location as GenLocation;
use OmniBuf\Generated\Omnibuf\Bench\Neighbor as GenNeighbor;
use OmniBuf\Generated\Omnibuf\Bench\OpaquePeerUpdate;
use OmniBuf\Generated\Omnibuf\Bench\PeerUpdate as GenPeerUpdate;
use OmniBuf\OmniBuf;
use OmniBuf\SchemaMeta;
use Omnibuf\Bench\Location as PbLocation;
use Omnibuf\Bench\Neighbor as PbNeighbor;
use Omnibuf\Bench\PeerUpdate as PbPeerUpdate;
use PHPUnit\Framework\TestCase;

final class CodecTiersTest extends TestCase
{
    public function testFourTierWireEquivalenceAndSelfDescribing(): void
    {
        // Tier 1: Generated
        $gen = new GenPeerUpdate(
            asn: 64512,
            routerId: 'edge-rtr-fra01.core.backbone.net',
            active: true,
            uptimeSec: 1849203,
            rssiDbm: -62,
            cpuLoad: 0.42875,
            tags: ['tier1', 'ebgp', 'eu-central', 'ixp-de-cix'],
            prefixes: [167772160, 172032000, 3232235520, 2886729728, 134744072, 134743040],
            location: new GenLocation(
                region: 'eu-central-1',
                datacenter: 'FRA-Equinix-FR5',
                city: 'Frankfurt',
                rack: 42,
                latitude: 50.1109,
                longitude: 8.6821,
            ),
            neighbors: [
                new GenNeighbor(peerAsn: 3356, router: 'ae0.fra1.level3.net', latencyUs: 850, established: true),
                new GenNeighbor(peerAsn: 1299, router: 'ffm-b5-link.telia.net', latencyUs: 920, established: true),
                new GenNeighbor(peerAsn: 174, router: 'be2040.ccr41.fra03.atlas.cogentco.com', latencyUs: 1120, established: true),
                new GenNeighbor(peerAsn: 2914, router: 'ae-12.r24.frnkge08.de.bb.gin.ntt.net', latencyUs: 980, established: false),
            ],
            sequence: 9876543210,
        );
        $wireGen = OmniBuf::marshal($gen, true, false);
        self::assertSame(365, \strlen($wireGen));

        // Tier 2: google/protobuf
        $pb = OmniBuf::unmarshal($wireGen, PbPeerUpdate::class);
        self::assertSame(64512, $pb->getAsn());
        self::assertSame('Frankfurt', $pb->getLocation()?->getCity());
        self::assertSame(9876543210, $pb->getSequence());
        $wirePb = OmniBuf::marshal($pb, true, false);
        self::assertSame(365, \strlen($wirePb));

        // Tier 3: PHP 8 Attributes
        $attr = OmniBuf::unmarshal($wirePb, AnnotatedPeerUpdate::class);
        self::assertSame(64512, $attr->asn);
        self::assertSame('Frankfurt', $attr->location?->city);
        self::assertSame(-62, $attr->rssiDbm);
        self::assertCount(4, $attr->neighbors);
        $wireAttr = OmniBuf::marshal($attr, true, true);

        // Tier 4: Blind decode with Field #0
        $blind = OmniBuf::decodeBlind($wireAttr);
        self::assertSame(64512, $blind['asn']);
        self::assertSame('Frankfurt', $blind['location']['city']);
        self::assertSame(9876543210, $blind['sequence']);

        // Lazy Opaque<T> zero-decode & splice
        $opaque = new OpaquePeerUpdate($wireGen);
        self::assertSame('Frankfurt', $opaque->opaqueLocation()?->getCity());
        $opaque->setSequence($opaque->getSequence() + 1);
        $spliced = $opaque->marshal();
        $decodedAfterSplice = GenPeerUpdate::unmarshalOmnibuf($spliced);
        self::assertSame(9876543211, $decodedAfterSplice->sequence);
        self::assertSame('Frankfurt', $decodedAfterSplice->location?->city);
    }

    public function testReplaceVsMergeDecode(): void
    {
        $part1 = OmniBuf::marshal(new GenPeerUpdate(asn: 100, tags: ['a'], location: new GenLocation(region: 'eu')));
        $part2 = OmniBuf::marshal(new GenPeerUpdate(tags: ['b'], location: new GenLocation(city: 'FRA')));

        // Merge mode preserves omitted fields and appends arrays / merges submessages
        $merged = GenPeerUpdate::unmarshalOmnibuf($part1);
        $merged->unmarshalInto($part2, true);
        self::assertSame(100, $merged->asn);
        self::assertSame(['a', 'b'], $merged->tags);
        self::assertNotNull($merged->location);
        self::assertSame('eu', $merged->location->region);
        self::assertSame('FRA', $merged->location->city);

        // Replace mode resets omitted fields
        $replaced = GenPeerUpdate::unmarshalOmnibuf($part1);
        $replaced->unmarshalInto($part2, false);
        self::assertSame(0, $replaced->asn);
        self::assertSame(['b'], $replaced->tags);
        self::assertNotNull($replaced->location);
        self::assertSame('', $replaced->location->region);
        self::assertSame('FRA', $replaced->location->city);
    }

    public function testSchemaMetaMergeAndDepthLimit(): void
    {
        $m1 = new SchemaMeta('Node', [1 => 'id', 2 => 'old']);
        $m2 = new SchemaMeta('', [2 => 'name', 3 => 'active']);
        $merged = SchemaMeta::merge($m1, $m2);
        self::assertNotNull($merged);
        self::assertSame('Node', $merged->typeName);
        self::assertSame([1 => 'id', 2 => 'name', 3 => 'active'], $merged->fields);

        // Build a 33-level nested submessage (exceeds MAX_NESTING_DEPTH = 32)
        $payload = '';
        for ($i = 0; $i < 33; $i++) {
            $payload = "\x19" . \OmniBuf\Wire::encodeVarint(\strlen($payload)) . $payload;
        }
        $this->expectException(DecodeException::class);
        OmniBuf::decodeBlind($payload);
    }
}
