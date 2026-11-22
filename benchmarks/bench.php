#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Conformance/AnnotatedModels.php';

use Omnibuf\Bench\Location as PbLocation;
use Omnibuf\Bench\Neighbor as PbNeighbor;
use Omnibuf\Bench\PeerUpdate as PbPeerUpdate;
use OmniBuf\Conformance\AnnotatedLocation;
use OmniBuf\Conformance\AnnotatedNeighbor;
use OmniBuf\Conformance\AnnotatedPeerUpdate;
use OmniBuf\Generated\Omnibuf\Bench\Location as GenLocation;
use OmniBuf\Generated\Omnibuf\Bench\Neighbor as GenNeighbor;
use OmniBuf\Generated\Omnibuf\Bench\OpaquePeerUpdate;
use OmniBuf\Generated\Omnibuf\Bench\PeerUpdate as GenPeerUpdate;
use OmniBuf\OmniBuf;

const NEIGHBOR_SPECS = [
    [3356, 'ae0.fra1.level3.net', 850, true],
    [1299, 'ffm-b5-link.telia.net', 920, true],
    [174, 'be2040.ccr41.fra03.atlas.cogentco.com', 1120, true],
    [2914, 'ae-12.r24.frnkge08.de.bb.gin.ntt.net', 980, false],
];

const PREFIXES = [
    167772160,
    172032000,
    3232235520,
    2886729728,
    134744072,
    134743040,
];

const TAGS = ['tier1', 'ebgp', 'eu-central', 'ixp-de-cix'];

function buildSampleGenerated(int $neighborRepeats = 1): GenPeerUpdate
{
    $neighbors = [];
    for ($r = 0; $r < $neighborRepeats; $r++) {
        foreach (NEIGHBOR_SPECS as [$pasn, $rtr, $lat, $est]) {
            $neighbors[] = new GenNeighbor(peerAsn: $pasn, router: $rtr, latencyUs: $lat, established: $est);
        }
    }
    return new GenPeerUpdate(
        asn: 64512,
        routerId: 'edge-rtr-fra01.core.backbone.net',
        active: true,
        uptimeSec: 1849203,
        rssiDbm: -62,
        cpuLoad: 0.42875,
        tags: TAGS,
        prefixes: PREFIXES,
        location: new GenLocation(
            region: 'eu-central-1',
            datacenter: 'FRA-Equinix-FR5',
            city: 'Frankfurt',
            rack: 42,
            latitude: 50.1109,
            longitude: 8.6821,
        ),
        neighbors: $neighbors,
        sequence: 9876543210,
    );
}

function buildSampleAnnotated(int $neighborRepeats = 1): AnnotatedPeerUpdate
{
    $m = new AnnotatedPeerUpdate();
    $m->asn = 64512;
    $m->routerId = 'edge-rtr-fra01.core.backbone.net';
    $m->active = true;
    $m->uptimeSec = 1849203;
    $m->rssiDbm = -62;
    $m->cpuLoad = 0.42875;
    $m->tags = TAGS;
    $m->prefixes = PREFIXES;
    $loc = new AnnotatedLocation();
    $loc->region = 'eu-central-1';
    $loc->datacenter = 'FRA-Equinix-FR5';
    $loc->city = 'Frankfurt';
    $loc->rack = 42;
    $loc->latitude = 50.1109;
    $loc->longitude = 8.6821;
    $m->location = $loc;
    for ($r = 0; $r < $neighborRepeats; $r++) {
        foreach (NEIGHBOR_SPECS as [$pasn, $rtr, $lat, $est]) {
            $nb = new AnnotatedNeighbor();
            $nb->peerAsn = $pasn;
            $nb->router = $rtr;
            $nb->latencyUs = $lat;
            $nb->established = $est;
            $m->neighbors[] = $nb;
        }
    }
    $m->sequence = 9876543210;
    return $m;
}

function buildSampleProtobuf(int $neighborRepeats = 1): PbPeerUpdate
{
    $neighbors = [];
    for ($r = 0; $r < $neighborRepeats; $r++) {
        foreach (NEIGHBOR_SPECS as [$pasn, $rtr, $lat, $est]) {
            $neighbors[] = new PbNeighbor([
                'peer_asn' => $pasn,
                'router' => $rtr,
                'latency_us' => $lat,
                'established' => $est,
            ]);
        }
    }
    return new PbPeerUpdate([
        'asn' => 64512,
        'router_id' => 'edge-rtr-fra01.core.backbone.net',
        'active' => true,
        'uptime_sec' => 1849203,
        'rssi_dbm' => -62,
        'cpu_load' => 0.42875,
        'tags' => TAGS,
        'prefixes' => PREFIXES,
        'location' => new PbLocation([
            'region' => 'eu-central-1',
            'datacenter' => 'FRA-Equinix-FR5',
            'city' => 'Frankfurt',
            'rack' => 42,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ]),
        'neighbors' => $neighbors,
        'sequence' => 9876543210,
    ]);
}

/**
 * @return array<string, mixed>
 */
function buildSampleArray(int $neighborRepeats = 1): array
{
    $neighbors = [];
    for ($r = 0; $r < $neighborRepeats; $r++) {
        foreach (NEIGHBOR_SPECS as [$pasn, $rtr, $lat, $est]) {
            $neighbors[] = [
                'peer_asn' => $pasn,
                'router' => $rtr,
                'latency_us' => $lat,
                'established' => $est,
            ];
        }
    }
    return [
        'asn' => 64512,
        'router_id' => 'edge-rtr-fra01.core.backbone.net',
        'active' => true,
        'uptime_sec' => 1849203,
        'rssi_dbm' => -62,
        'cpu_load' => 0.42875,
        'tags' => TAGS,
        'prefixes' => PREFIXES,
        'location' => [
            'region' => 'eu-central-1',
            'datacenter' => 'FRA-Equinix-FR5',
            'city' => 'Frankfurt',
            'rack' => 42,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ],
        'neighbors' => $neighbors,
        'sequence' => 9876543210,
    ];
}

function consumeGenOrAttr(GenPeerUpdate|AnnotatedPeerUpdate $m): int
{
    $acc = $m->asn + \strlen($m->routerId) + ($m->active ? 1 : 0) + $m->uptimeSec + $m->rssiDbm + (int) ($m->cpuLoad * 1000.0) + $m->sequence;
    foreach ($m->tags as $t) {
        $acc += \strlen($t);
    }
    foreach ($m->prefixes as $p) {
        $acc += $p;
    }
    if ($m->location !== null) {
        $loc = $m->location;
        $acc += \strlen($loc->region) + \strlen($loc->datacenter) + \strlen($loc->city) + $loc->rack + (int) ($loc->latitude * 1000.0) + (int) ($loc->longitude * 1000.0);
    }
    foreach ($m->neighbors as $nb) {
        $acc += $nb->peerAsn + \strlen($nb->router) + $nb->latencyUs + ($nb->established ? 1 : 0);
    }
    return $acc;
}

function consumeProtobuf(PbPeerUpdate $m): int
{
    $acc = (int) $m->getAsn() + \strlen((string) $m->getRouterId()) + ($m->getActive() ? 1 : 0) + (int) $m->getUptimeSec() + (int) $m->getRssiDbm() + (int) ($m->getCpuLoad() * 1000.0) + (int) $m->getSequence();
    foreach ($m->getTags() as $t) {
        $acc += \strlen((string) $t);
    }
    foreach ($m->getPrefixes() as $p) {
        $acc += ((int) $p) & 0xFFFFFFFF;
    }
    $loc = $m->getLocation();
    if ($loc !== null) {
        $acc += \strlen((string) $loc->getRegion()) + \strlen((string) $loc->getDatacenter()) + \strlen((string) $loc->getCity()) + (int) $loc->getRack() + (int) ($loc->getLatitude() * 1000.0) + (int) ($loc->getLongitude() * 1000.0);
    }
    foreach ($m->getNeighbors() as $nb) {
        /** @var PbNeighbor $nb */
        $acc += (int) $nb->getPeerAsn() + \strlen((string) $nb->getRouter()) + (int) $nb->getLatencyUs() + ($nb->getEstablished() ? 1 : 0);
    }
    return $acc;
}

/**
 * @param array<string, mixed> $m
 */
function consumeArray(array $m): int
{
    $acc = (int) $m['asn'] + \strlen((string) $m['router_id']) + ($m['active'] ? 1 : 0) + (int) $m['uptime_sec'] + (int) $m['rssi_dbm'] + (int) ($m['cpu_load'] * 1000.0) + (int) $m['sequence'];
    foreach ($m['tags'] as $t) {
        $acc += \strlen((string) $t);
    }
    foreach ($m['prefixes'] as $p) {
        $acc += (int) $p;
    }
    if (isset($m['location'])) {
        $loc = $m['location'];
        $acc += \strlen((string) $loc['region']) + \strlen((string) $loc['datacenter']) + \strlen((string) $loc['city']) + (int) $loc['rack'] + (int) ($loc['latitude'] * 1000.0) + (int) ($loc['longitude'] * 1000.0);
    }
    foreach ($m['neighbors'] as $nb) {
        $acc += (int) $nb['peer_asn'] + \strlen((string) $nb['router']) + (int) $nb['latency_us'] + ($nb['established'] ? 1 : 0);
    }
    return $acc;
}

/**
 * @return array{name: string, nsPerOp: int, opsPerSec: int, wireBytes?: int, highlight?: bool, baseline?: bool}
 */
function runBench(
    string $name,
    callable $fn,
    ?int $wireBytes = null,
    bool $highlight = false,
    bool $baseline = false,
    float $warmupMs = 100.0,
    float $sampleMs = 350.0,
): array {
    $warmupNs = (int) ($warmupMs * 1_000_000);
    $sampleNs = (int) ($sampleMs * 1_000_000);

    $wStart = hrtime(true);
    while (hrtime(true) - $wStart < $warmupNs) {
        for ($i = 0; $i < 16; $i++) {
            $fn();
        }
    }

    gc_collect_cycles();
    $iterations = 0;
    $batch = 32;
    $start = hrtime(true);
    $elapsed = 0;
    while ($elapsed < $sampleNs) {
        for ($i = 0; $i < $batch; $i++) {
            $fn();
        }
        $iterations += $batch;
        $elapsed = hrtime(true) - $start;
    }

    $nsPerOp = $elapsed / $iterations;
    $opsPerSec = ($iterations * 1_000_000_000.0) / $elapsed;
    $entry = [
        'name' => $name,
        'nsPerOp' => (int) round($nsPerOp),
        'opsPerSec' => (int) round($opsPerSec),
    ];
    if ($wireBytes !== null) {
        $entry['wireBytes'] = $wireBytes;
    }
    if ($highlight) {
        $entry['highlight'] = true;
    }
    if ($baseline) {
        $entry['baseline'] = true;
    }
    return $entry;
}

/**
 * @param list<array{name: string, nsPerOp: int, opsPerSec: int, wireBytes?: int, highlight?: bool, baseline?: bool}> $results
 * @return list<string>
 */
function formatTable(string $title, array $results): array
{
    $lines = ["\n### {$title}\n"];
    $showBytes = false;
    $baseNs = $results[0]['nsPerOp'];
    foreach ($results as $r) {
        if (isset($r['wireBytes'])) {
            $showBytes = true;
        }
        if ($r['baseline'] ?? false) {
            $baseNs = $r['nsPerOp'];
        }
    }
    if ($showBytes) {
        $lines[] = '| Codec / Operation | Latency (ns/op) | Throughput (ops/sec) | Wire Size (B) | vs Baseline |';
        $lines[] = '|---|---:|---:|---:|---:|';
    } else {
        $lines[] = '| Codec / Operation | Latency (ns/op) | Throughput (ops/sec) | vs Baseline |';
        $lines[] = '|---|---:|---:|---:|';
    }
    foreach ($results as $r) {
        $ratio = $r['nsPerOp'] / max(1, $baseNs);
        if (($r['baseline'] ?? false) || abs($ratio - 1.0) < 0.03) {
            $rel = '1.00x (baseline)';
        } elseif ($ratio > 1.0) {
            $rel = \sprintf('%.2fx slower', $ratio);
        } else {
            $rel = \sprintf('%.2fx faster', 1.0 / $ratio);
        }
        $nsStr = number_format($r['nsPerOp']);
        $opsStr = number_format($r['opsPerSec']);
        if ($showBytes) {
            $wb = isset($r['wireBytes']) ? (string) $r['wireBytes'] : '-';
            $lines[] = "| {$r['name']} | {$nsStr} ns | {$opsStr} | {$wb} | {$rel} |";
        } else {
            $lines[] = "| {$r['name']} | {$nsStr} ns | {$opsStr} | {$rel} |";
        }
    }
    return $lines;
}

function detectCpu(): string
{
    if (PHP_OS_FAMILY === 'Darwin') {
        $out = @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null');
        if (\is_string($out) && trim($out) !== '') {
            return trim($out);
        }
    }
    return php_uname('m');
}

/** @var list<string> $cliArgs */
$cliArgs = \is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$smoke = \in_array('--smoke', $cliArgs, true);
$protobufCOnly = \in_array('--protobuf-c-only', $cliArgs, true);
$warmupMs = $smoke ? 2.0 : 120.0;
$sampleMs = $smoke ? 5.0 : 350.0;
$hasExtProtobuf = \extension_loaded('protobuf');
$pbLabel = $hasExtProtobuf ? 'google/protobuf (C ext)' : 'google/protobuf (pure PHP)';

/** @var array<string, array{name: string, nsPerOp: int, opsPerSec: int, wireBytes?: int, highlight?: bool, baseline?: bool}> $extPbRows */
$extPbRows = [];
$protobufExtPath = getenv('PROTOBUF_EXT');
if (!$protobufCOnly && !$hasExtProtobuf && \is_string($protobufExtPath) && $protobufExtPath !== '' && is_file($protobufExtPath)) {
    $cmd = \sprintf(
        '%s -d %s %s --protobuf-c-only%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $protobufExtPath),
        escapeshellarg(__FILE__),
        $smoke ? ' --smoke' : '',
    );
    $subOut = shell_exec($cmd);
    if (\is_string($subOut) && trim($subOut) !== '') {
        /** @var mixed $decoded */
        $decoded = json_decode($subOut, true, 512, JSON_THROW_ON_ERROR);
        if (\is_array($decoded)) {
            /** @var array<string, array{name: string, nsPerOp: int, opsPerSec: int, wireBytes?: int, highlight?: bool, baseline?: bool}> $decoded */
            $extPbRows = $decoded;
        }
    }
}

$sampleGen = buildSampleGenerated();
$sampleAttr = buildSampleAnnotated();
$samplePb = buildSampleProtobuf();
$sampleArr = buildSampleArray();

$omnibufBytes = $sampleGen->marshalOmnibuf();
$omnibufAttrBytes = OmniBuf::marshal($sampleAttr);
$pbBytes = $samplePb->serializeToString();
$jsonBytes = json_encode($sampleArr, JSON_THROW_ON_ERROR);

\assert(\strlen($omnibufBytes) === 365, 'expected 365B OmniBuf wire size');
\assert(\strlen($omnibufAttrBytes) === 365, 'expected 365B OmniBuf attr wire size');
\assert(\strlen($pbBytes) === 341, 'expected 341B Protobuf wire size');

$cpu = detectCpu();
$runtime = 'PHP ' . PHP_VERSION;
$platform = strtolower(PHP_OS_FAMILY === 'Darwin' ? 'darwin' : PHP_OS);
$arch = php_uname('m');

$output = [
    '============================================================================',
    "OmniBuf-PHP Benchmark Suite ({$runtime} / {$platform}-{$arch} / {$cpu})",
    'Workload: 11-field PeerUpdate (365B OmniBuf, 341B Protobuf) + >16 KiB stress cases',
    '============================================================================',
];

// 1. Full Message Encode Speed
$pbEncodeRow = runBench($pbLabel, static fn(): string => $samplePb->serializeToString(), \strlen($pbBytes), false, false, $warmupMs, $sampleMs);
$encodeResults = [
    runBench('OmniBuf (Generated)', static fn(): string => $sampleGen->marshalOmnibuf(), \strlen($omnibufBytes), true, true, $warmupMs, $sampleMs),
    runBench('OmniBuf (Attributes)', static fn(): string => OmniBuf::marshal($sampleAttr), \strlen($omnibufAttrBytes), true, false, $warmupMs, $sampleMs),
];
if (isset($extPbRows['encode'])) {
    $encodeResults[] = $extPbRows['encode'];
}
$encodeResults[] = $pbEncodeRow;
$encodeResults[] = runBench('json_encode (stdlib)', static fn(): string => json_encode($sampleArr, JSON_THROW_ON_ERROR), \strlen($jsonBytes), false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('1. Full Message Encode Speed', $encodeResults));

// 2. Full Message Decode Speed
$decodeGen = static fn(): int => consumeGenOrAttr(GenPeerUpdate::unmarshalOmnibuf($omnibufBytes));
$decodeAttr = static fn(): int => consumeGenOrAttr(OmniBuf::unmarshal($omnibufAttrBytes, AnnotatedPeerUpdate::class));
$decodePb = static function () use ($pbBytes): int {
    $m = new PbPeerUpdate();
    $m->mergeFromString($pbBytes);
    return consumeProtobuf($m);
};
$decodeJson = static function () use ($jsonBytes): int {
    /** @var array<string, mixed> $arr */
    $arr = json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
    return consumeArray($arr);
};

$checksum = $decodeGen();
\assert($decodeAttr() === $checksum);
\assert($decodePb() === $checksum);
\assert($decodeJson() === $checksum);

$pbDecodeRow = runBench($pbLabel, $decodePb, null, false, false, $warmupMs, $sampleMs);
$decodeResults = [
    runBench('OmniBuf (Generated)', $decodeGen, null, true, true, $warmupMs, $sampleMs),
    runBench('OmniBuf (Attributes)', $decodeAttr, null, true, false, $warmupMs, $sampleMs),
];
if (isset($extPbRows['decode'])) {
    $decodeResults[] = $extPbRows['decode'];
}
$decodeResults[] = $pbDecodeRow;
$decodeResults[] = runBench('json_decode (stdlib)', $decodeJson, null, false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('2. Full Message Decode Speed', $decodeResults));

// 3. Read a Single Nested Field
$lookupOmnibuf = static function () use ($omnibufBytes): string {
    $view = new OpaquePeerUpdate($omnibufBytes);
    $loc = $view->opaqueLocation();
    return $loc !== null ? $loc->getCity() : '';
};
$lookupPb = static function () use ($pbBytes): string {
    $m = new PbPeerUpdate();
    $m->mergeFromString($pbBytes);
    return (string) ($m->getLocation()?->getCity() ?? '');
};
$lookupJson = static function () use ($jsonBytes): string {
    /** @var array{location: array{city: string}} $arr */
    $arr = json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
    return $arr['location']['city'];
};

\assert($lookupOmnibuf() === 'Frankfurt');
\assert($lookupPb() === 'Frankfurt');
\assert($lookupJson() === 'Frankfurt');

$pbLookupRow = runBench($pbLabel, $lookupPb, null, false, false, $warmupMs, $sampleMs);
$lookupResults = [
    runBench('OmniBuf (Generated)', $lookupOmnibuf, null, true, true, $warmupMs, $sampleMs),
];
if (isset($extPbRows['lookup_city'])) {
    $lookupResults[] = $extPbRows['lookup_city'];
}
$lookupResults[] = $pbLookupRow;
$lookupResults[] = runBench('json_decode (stdlib)', $lookupJson, null, false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('3. Read a Single Nested Field', $lookupResults));

// 4. Selective Single-Field Mutation
$mutateOmnibuf = static function () use ($omnibufBytes): string {
    $view = new OpaquePeerUpdate($omnibufBytes);
    $loc = $view->opaqueLocation();
    if ($loc !== null && $loc->getCity() === 'Frankfurt') {
        $view->setSequence($view->getSequence() + 1);
    }
    return $view->marshal();
};
$mutatePb = static function () use ($pbBytes): string {
    $m = new PbPeerUpdate();
    $m->mergeFromString($pbBytes);
    if ($m->getLocation()?->getCity() === 'Frankfurt') {
        $m->setSequence((int) $m->getSequence() + 1);
    }
    return $m->serializeToString();
};
$mutateJson = static function () use ($jsonBytes): string {
    /** @var array{location: array{city: string}, sequence: int} $arr */
    $arr = json_decode($jsonBytes, true, 512, JSON_THROW_ON_ERROR);
    if ($arr['location']['city'] === 'Frankfurt') {
        $arr['sequence']++;
    }
    return json_encode($arr, JSON_THROW_ON_ERROR);
};

\assert(GenPeerUpdate::unmarshalOmnibuf($mutateOmnibuf())->sequence === 9876543211);

$pbMutateRow = runBench($pbLabel, $mutatePb, null, false, false, $warmupMs, $sampleMs);
$mutateResults = [
    runBench('OmniBuf (Generated)', $mutateOmnibuf, null, true, true, $warmupMs, $sampleMs),
];
if (isset($extPbRows['mutate_sequence'])) {
    $mutateResults[] = $extPbRows['mutate_sequence'];
}
$mutateResults[] = $pbMutateRow;
$mutateResults[] = runBench('json (stdlib)', $mutateJson, null, false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('4. Selective Single-Field Mutation', $mutateResults));

// 5. Large Embedded Message Encode (>16 KiB container shift)
$largeDc = str_repeat('d', 20_480);
$largeRtr = str_repeat('n', 18_432);

$largeGen = buildSampleGenerated();
\assert($largeGen->location !== null);
$largeGen->location->datacenter = $largeDc;
$largeGen->neighbors[0]->router = $largeRtr;

$largeAttr = buildSampleAnnotated();
\assert($largeAttr->location !== null);
$largeAttr->location->datacenter = $largeDc;
$largeAttr->neighbors[0]->router = $largeRtr;

$largePb = buildSampleProtobuf();
$largePb->getLocation()?->setDatacenter($largeDc);
$largePb->getNeighbors()[0]->setRouter($largeRtr);

$largeArr = buildSampleArray();
$largeArr['location']['datacenter'] = $largeDc;
$largeArr['neighbors'][0]['router'] = $largeRtr;

$largeOmnibufBytes = $largeGen->marshalOmnibuf();
$largeAttrBytes = OmniBuf::marshal($largeAttr);
$largePbBytes = $largePb->serializeToString();
$largeJsonBytes = json_encode($largeArr, JSON_THROW_ON_ERROR);

$pbEncodeLargeRow = runBench($pbLabel, static fn(): string => $largePb->serializeToString(), \strlen($largePbBytes), false, false, $warmupMs, $sampleMs);
$encodeLargeResults = [
    runBench('OmniBuf (Generated)', static fn(): string => $largeGen->marshalOmnibuf(), \strlen($largeOmnibufBytes), true, true, $warmupMs, $sampleMs),
    runBench('OmniBuf (Attributes)', static fn(): string => OmniBuf::marshal($largeAttr), \strlen($largeAttrBytes), true, false, $warmupMs, $sampleMs),
];
if (isset($extPbRows['encode_nested_shift'])) {
    $encodeLargeResults[] = $extPbRows['encode_nested_shift'];
}
$encodeLargeResults[] = $pbEncodeLargeRow;
$encodeLargeResults[] = runBench('json_encode (stdlib)', static fn(): string => json_encode($largeArr, JSON_THROW_ON_ERROR), \strlen($largeJsonBytes), false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('5. Large Embedded Message Encode', $encodeLargeResults));

// 6. Embedded Message Growth (32-neighbor packet, growing location.datacenter to 20 KiB)
$routingGen = buildSampleGenerated(8);
$routingPb = buildSampleProtobuf(8);
$routingArr = buildSampleArray(8);

$routingOmnibufBytes = $routingGen->marshalOmnibuf();
$routingPbBytes = $routingPb->serializeToString();
$routingJsonBytes = json_encode($routingArr, JSON_THROW_ON_ERROR);

$growOmnibuf = static function () use ($routingOmnibufBytes, $largeDc): string {
    $view = new OpaquePeerUpdate($routingOmnibufBytes);
    $loc = $view->getLocation();
    if ($loc !== null && $loc->city === 'Frankfurt') {
        $loc->datacenter = $largeDc;
        $view->setLocation($loc);
    }
    return $view->marshal();
};
$growPb = static function () use ($routingPbBytes, $largeDc): string {
    $m = new PbPeerUpdate();
    $m->mergeFromString($routingPbBytes);
    $loc = $m->getLocation();
    if ($loc !== null && $loc->getCity() === 'Frankfurt') {
        $loc->setDatacenter($largeDc);
    }
    return $m->serializeToString();
};
$growJson = static function () use ($routingJsonBytes, $largeDc): string {
    /** @var array{location: array{city: string, datacenter: string}} $arr */
    $arr = json_decode($routingJsonBytes, true, 512, JSON_THROW_ON_ERROR);
    if ($arr['location']['city'] === 'Frankfurt') {
        $arr['location']['datacenter'] = $largeDc;
    }
    return json_encode($arr, JSON_THROW_ON_ERROR);
};

$grownVerify = GenPeerUpdate::unmarshalOmnibuf($growOmnibuf());
\assert($grownVerify->location !== null && $grownVerify->location->datacenter === $largeDc);
\assert(\count($grownVerify->neighbors) === 32);

$pbGrowRow = runBench($pbLabel, $growPb, null, false, false, $warmupMs, $sampleMs);
$growResults = [
    runBench('OmniBuf (Generated)', $growOmnibuf, null, true, true, $warmupMs, $sampleMs),
];
if (isset($extPbRows['mutate_embedded_grow'])) {
    $growResults[] = $extPbRows['mutate_embedded_grow'];
}
$growResults[] = $pbGrowRow;
$growResults[] = runBench('json (stdlib)', $growJson, null, false, false, $warmupMs, $sampleMs);
$output = array_merge($output, formatTable('6. Embedded Message Growth', $growResults));

if ($protobufCOnly) {
    echo json_encode([
        'encode' => $pbEncodeRow,
        'decode' => $pbDecodeRow,
        'lookup_city' => $pbLookupRow,
        'mutate_sequence' => $pbMutateRow,
        'encode_nested_shift' => $pbEncodeLargeRow,
        'mutate_embedded_grow' => $pbGrowRow,
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$fullText = implode("\n", $output) . "\n";
echo $fullText;

if ($smoke) {
    echo "\nSmoke run complete (testdata/benchmarks.json left untouched).\n";
    exit(0);
}

$testdataDir = __DIR__ . '/../testdata';
if (!is_dir($testdataDir)) {
    mkdir($testdataDir, 0755, true);
}

file_put_contents($testdataDir . '/benchmarks.txt', $fullText);

$payload = [
    'language' => 'php',
    'runtime' => $runtime,
    'platform' => $platform,
    'arch' => $arch,
    'cpu' => $cpu,
    'workload' => 'PeerUpdate',
    'categories' => [
        ['id' => 'encode', 'title' => 'Full Message Encode Speed', 'unit' => 'ns', 'results' => $encodeResults],
        ['id' => 'decode', 'title' => 'Full Message Decode Speed', 'unit' => 'ns', 'results' => $decodeResults],
        ['id' => 'lookup_city', 'title' => 'Read a Single Nested Field', 'unit' => 'ns', 'results' => $lookupResults],
        ['id' => 'mutate_sequence', 'title' => 'Selective Single-Field Mutation', 'unit' => 'ns', 'results' => $mutateResults],
        ['id' => 'encode_nested_shift', 'title' => 'Large Embedded Message Encode', 'unit' => 'ns', 'results' => $encodeLargeResults],
        ['id' => 'mutate_embedded_grow', 'title' => 'Embedded Message Growth', 'unit' => 'ns', 'results' => $growResults],
    ],
];

file_put_contents(
    $testdataDir . '/benchmarks.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);

echo "\nSaved benchmark results to testdata/benchmarks.txt and testdata/benchmarks.json\n";
