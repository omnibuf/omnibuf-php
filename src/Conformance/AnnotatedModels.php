<?php

declare(strict_types=1);

namespace OmniBuf\Conformance;

use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;

#[OmniBufMessage('ConformanceRequest')]
final class ConformanceFrameRequest
{
    #[OmniBufField(1, 'string', name: 'test_name')]
    public string $testName = '';

    #[OmniBufField(2, 'string', name: 'message_type')]
    public string $messageType = '';

    #[OmniBufField(3, 'uint32', name: 'tier')]
    public int $tier = 0;

    #[OmniBufField(4, 'uint32', name: 'requested_output_format')]
    public int $requestedOutputFormat = 0;

    #[OmniBufField(5, 'bytes', name: 'omnibuf_payload')]
    public string $omnibufPayload = '';

    #[OmniBufField(6, 'string', name: 'json_payload')]
    public string $jsonPayload = '';

    #[OmniBufField(7, 'bool', name: 'self_describing')]
    public bool $selfDescribing = false;

    #[OmniBufField(8, 'bool', name: 'test_opaque_splice')]
    public bool $testOpaqueSplice = false;

    #[OmniBufField(9, 'bool', name: 'test_opaque_splice_shrink')]
    public bool $testOpaqueSpliceShrink = false;
}

#[OmniBufMessage('ConformanceResponse')]
final class ConformanceFrameResponse
{
    #[OmniBufField(1, 'string', name: 'parse_error', omitEmpty: true)]
    public string $parseError = '';

    #[OmniBufField(2, 'string', name: 'serialize_error', omitEmpty: true)]
    public string $serializeError = '';

    #[OmniBufField(3, 'string', name: 'runtime_error', omitEmpty: true)]
    public string $runtimeError = '';

    #[OmniBufField(4, 'string', name: 'skipped', omitEmpty: true)]
    public string $skipped = '';

    #[OmniBufField(5, 'bytes', name: 'omnibuf_payload', omitEmpty: true)]
    public string $omnibufPayload = '';

    #[OmniBufField(6, 'string', name: 'json_payload', omitEmpty: true)]
    public string $jsonPayload = '';
}

#[OmniBufMessage('Location')]
final class AnnotatedLocation
{
    #[OmniBufField(1, 'string', name: 'region')]
    public string $region = '';

    #[OmniBufField(2, 'string', name: 'datacenter')]
    public string $datacenter = '';

    #[OmniBufField(3, 'string', name: 'city')]
    public string $city = '';

    #[OmniBufField(4, 'uint32', name: 'rack')]
    public int $rack = 0;

    #[OmniBufField(5, 'float32', name: 'latitude')]
    public float $latitude = 0.0;

    #[OmniBufField(6, 'float32', name: 'longitude')]
    public float $longitude = 0.0;
}

#[OmniBufMessage('Neighbor')]
final class AnnotatedNeighbor
{
    #[OmniBufField(1, 'uint32', name: 'peer_asn')]
    public int $peerAsn = 0;

    #[OmniBufField(2, 'string', name: 'router')]
    public string $router = '';

    #[OmniBufField(3, 'uint32', name: 'latency_us')]
    public int $latencyUs = 0;

    #[OmniBufField(4, 'bool', name: 'established')]
    public bool $established = false;
}

#[OmniBufMessage('PeerUpdate')]
final class AnnotatedPeerUpdate
{
    #[OmniBufField(1, 'uint32', name: 'asn')]
    public int $asn = 0;

    #[OmniBufField(2, 'string', name: 'router_id')]
    public string $routerId = '';

    #[OmniBufField(3, 'bool', name: 'active')]
    public bool $active = false;

    #[OmniBufField(4, 'uint64', name: 'uptime_sec')]
    public int $uptimeSec = 0;

    #[OmniBufField(5, 'sint32', name: 'rssi_dbm')]
    public int $rssiDbm = 0;

    #[OmniBufField(6, 'float64', name: 'cpu_load')]
    public float $cpuLoad = 0.0;

    /** @var list<string> */
    #[OmniBufField(7, 'array', name: 'tags', elementWire: 'string')]
    public array $tags = [];

    /** @var list<int> */
    #[OmniBufField(8, 'array', name: 'prefixes', elementWire: 'uint32')]
    public array $prefixes = [];

    #[OmniBufField(9, 'message', name: 'location', messageClass: AnnotatedLocation::class)]
    public ?AnnotatedLocation $location = null;

    /** @var list<AnnotatedNeighbor> */
    #[OmniBufField(10, 'array', name: 'neighbors', elementWire: 'message', messageClass: AnnotatedNeighbor::class)]
    public array $neighbors = [];

    #[OmniBufField(11, 'uint64', name: 'sequence')]
    public int $sequence = 0;
}

#[OmniBufMessage('AllTypesSubMsg')]
final class AnnotatedAllTypesSubMsg
{
    #[OmniBufField(1, 'uint32', name: 'id')]
    public int $id = 0;

    #[OmniBufField(2, 'string', name: 'label')]
    public string $label = '';

    #[OmniBufField(3, 'bool', name: 'flag')]
    public bool $flag = false;

    #[OmniBufField(4, 'sint64', name: 'score')]
    public int $score = 0;
}

#[OmniBufMessage('TestAllTypes')]
final class AnnotatedTestAllTypes
{
    #[OmniBufField(1, 'uint32', name: 'f1_uint32')]
    public int $f1Uint32 = 0;

    #[OmniBufField(2, 'uint64', name: 'f2_uint64')]
    public int $f2Uint64 = 0;

    #[OmniBufField(3, 'sint32', name: 'f3_sint32')]
    public int $f3Sint32 = 0;

    #[OmniBufField(4, 'sint64', name: 'f4_sint64')]
    public int $f4Sint64 = 0;

    #[OmniBufField(5, 'bool', name: 'f5_bool')]
    public bool $f5Bool = false;

    #[OmniBufField(6, 'string', name: 'f6_string')]
    public string $f6String = '';

    #[OmniBufField(7, 'bytes', name: 'f7_bytes')]
    public string $f7Bytes = '';

    #[OmniBufField(8, 'message', name: 'f8_sub_msg', messageClass: AnnotatedAllTypesSubMsg::class)]
    public ?AnnotatedAllTypesSubMsg $f8SubMsg = null;

    /** @var list<string> */
    #[OmniBufField(9, 'array', name: 'f9_string_arr', elementWire: 'string')]
    public array $f9StringArr = [];

    /** @var list<int> */
    #[OmniBufField(10, 'array', name: 'f10_uint32_arr', elementWire: 'uint32')]
    public array $f10Uint32Arr = [];

    /** @var list<AnnotatedAllTypesSubMsg> */
    #[OmniBufField(11, 'array', name: 'f11_sub_msg_arr', elementWire: 'message', messageClass: AnnotatedAllTypesSubMsg::class)]
    public array $f11SubMsgArr = [];

    /** @var array<string, int> */
    #[OmniBufField(12, 'map', name: 'f12_map_str_uint', keyWire: 'string', valueWire: 'uint64')]
    public array $f12MapStrUint = [];

    #[OmniBufField(13, 'float32', name: 'f13_float32')]
    public float $f13Float32 = 0.0;

    #[OmniBufField(14, 'float64', name: 'f14_float64')]
    public float $f14Float64 = 0.0;

    #[OmniBufField(15, 'fixed32', name: 'f15_fixed32')]
    public int $f15Fixed32 = 0;

    #[OmniBufField(16, 'fixed64', name: 'f16_fixed64')]
    public int $f16Fixed64 = 0;

    #[OmniBufField(20, 'string', name: 'f20_overflow_str')]
    public string $f20OverflowStr = '';
}

#[OmniBufMessage('TreeNode')]
final class TreeNode
{
    #[OmniBufField(1, 'uint32', name: 'id')]
    public int $id = 0;

    #[OmniBufField(2, 'string', name: 'name')]
    public string $name = '';

    #[OmniBufField(3, 'sint64', name: 'weight')]
    public int $weight = 0;

    #[OmniBufField(4, 'message', name: 'primary', messageClass: self::class)]
    public ?self $primary = null;

    /** @var list<self> */
    #[OmniBufField(5, 'array', name: 'children', elementWire: 'message', messageClass: self::class)]
    public array $children = [];

    /** @var list<self> */
    #[OmniBufField(6, 'array', name: 'branches', elementWire: 'message', messageClass: self::class)]
    public array $branches = [];

    /** @var array<string, int> */
    #[OmniBufField(7, 'map', name: 'labels', keyWire: 'string', valueWire: 'uint64')]
    public array $labels = [];
}

#[OmniBufMessage('NestedNIC')]
final class NestedNIC
{
    #[OmniBufField(1, 'uint32', name: 'if_index')]
    public int $ifIndex = 0;

    #[OmniBufField(2, 'string', name: 'mac')]
    public string $mac = '';

    /** @var list<int> */
    #[OmniBufField(3, 'array', name: 'ips', elementWire: 'uint32')]
    public array $ips = [];
}

#[OmniBufMessage('NestedServer')]
final class NestedServer
{
    #[OmniBufField(1, 'uint32', name: 'server_id')]
    public int $serverId = 0;

    #[OmniBufField(2, 'string', name: 'hostname')]
    public string $hostname = '';

    /** @var list<NestedNIC> */
    #[OmniBufField(3, 'array', name: 'nics', elementWire: 'message', messageClass: NestedNIC::class)]
    public array $nics = [];
}

#[OmniBufMessage('NestedRack')]
final class NestedRack
{
    #[OmniBufField(1, 'uint32', name: 'rack_id')]
    public int $rackId = 0;

    #[OmniBufField(2, 'string', name: 'zone')]
    public string $zone = '';

    /** @var list<NestedServer> */
    #[OmniBufField(3, 'array', name: 'servers', elementWire: 'message', messageClass: NestedServer::class)]
    public array $servers = [];
}

#[OmniBufMessage('NestedCluster')]
final class NestedCluster
{
    #[OmniBufField(1, 'string', name: 'cluster_name')]
    public string $clusterName = '';

    /** @var list<NestedRack> */
    #[OmniBufField(2, 'array', name: 'racks', elementWire: 'message', messageClass: NestedRack::class)]
    public array $racks = [];

    #[OmniBufField(3, 'uint64', name: 'epoch')]
    public int $epoch = 0;
}
