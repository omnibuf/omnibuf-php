<?php

declare(strict_types=1);

namespace OmniBuf\Tests;

use OmniBuf\AttributeCodec;
use OmniBuf\Attributes\OmniBufField;
use OmniBuf\Attributes\OmniBufMessage;
use OmniBuf\Exception\EncodeException;
use PHPUnit\Framework\TestCase;

#[OmniBufMessage('FloatSubnormalMsg')]
final class FloatSubnormalMsg
{
    #[OmniBufField(1, wire: 'float32', omitEmpty: true)]
    public float $f32 = 0.0;
}

#[OmniBufMessage('CyclicAttrNode')]
final class CyclicAttrNode
{
    #[OmniBufField(1, wire: 'uint32')]
    public int $id = 0;

    #[OmniBufField(2, wire: 'message', messageClass: self::class)]
    public ?self $next = null;
}

#[OmniBufMessage('InvalidNumMsg')]
final class InvalidNumMsg
{
    #[OmniBufField(0, wire: 'uint32')]
    public int $bad = 0;
}

#[OmniBufMessage('DupNumMsg')]
final class DupNumMsg
{
    #[OmniBufField(1, wire: 'uint32')]
    public int $a = 0;

    #[OmniBufField(1, wire: 'string')]
    public string $b = '';
}

final class AttributeCodecTest extends TestCase
{
    public function testOmitsFloat32SubnormalUnderflowingToPositiveZeroWhilePreservingNegativeZero(): void
    {
        $msg = new FloatSubnormalMsg();
        $msg->f32 = 1e-50; // underflows to +0.0 in IEEE 754 float32
        self::assertSame('', AttributeCodec::marshal($msg));

        $msg->f32 = -0.0;
        self::assertNotSame('', AttributeCodec::marshal($msg));
    }

    public function testRejectsCyclicObjectGraphAtMaxNestingDepth(): void
    {
        $a = new CyclicAttrNode();
        $a->id = 1;
        $a->next = $a;

        $this->expectException(EncodeException::class);
        AttributeCodec::marshal($a);
    }

    public function testRejectsOutOfRangeAndDuplicateFieldNumbers(): void
    {
        try {
            AttributeCodec::marshal(new InvalidNumMsg());
            self::fail('Expected EncodeException for field number 0');
        } catch (EncodeException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(EncodeException::class);
        AttributeCodec::marshal(new DupNumMsg());
    }
}
