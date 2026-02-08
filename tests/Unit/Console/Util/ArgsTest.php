<?php

namespace EasyAudit\Tests\Console\Util;

use EasyAudit\Console\Util\Args;
use PHPUnit\Framework\TestCase;

class ArgsTest extends TestCase
{
    // --- parse() tests ---

    public function testParseLongOptionWithValue(): void
    {
        [$opts, $rest] = Args::parse(['--format=sarif']);
        $this->assertEquals('sarif', $opts['format']);
    }

    public function testParseLongFlag(): void
    {
        [$opts, $rest] = Args::parse(['--help']);
        $this->assertTrue($opts['help']);
    }

    public function testParseShortFlagH(): void
    {
        [$opts, $rest] = Args::parse(['-h']);
        $this->assertTrue($opts['help']);
    }

    public function testParseShortFlagV(): void
    {
        [$opts, $rest] = Args::parse(['-v']);
        $this->assertTrue($opts['verbose']);
    }

    public function testParseCombinedShortFlags(): void
    {
        [$opts, $rest] = Args::parse(['-hv']);
        $this->assertTrue($opts['help']);
        $this->assertTrue($opts['verbose']);
    }

    public function testParsePositionalArgument(): void
    {
        [$opts, $rest] = Args::parse(['/path/to/scan']);
        $this->assertEquals('/path/to/scan', $rest);
    }

    public function testParseRepeatedOptionCreatesArray(): void
    {
        [$opts, $rest] = Args::parse(['--exclude=vendor', '--exclude=generated']);
        $this->assertIsArray($opts['exclude']);
        $this->assertEquals(['vendor', 'generated'], $opts['exclude']);
    }

    public function testParseMixedArguments(): void
    {
        [$opts, $rest] = Args::parse(['--format=sarif', '-v', '/path/to/scan']);
        $this->assertEquals('sarif', $opts['format']);
        $this->assertTrue($opts['verbose']);
        $this->assertEquals('/path/to/scan', $rest);
    }

    public function testParseEmptyArray(): void
    {
        [$opts, $rest] = Args::parse([]);
        $this->assertEmpty($opts);
        $this->assertEquals('', $rest);
    }

    public function testParseUnknownShortFlag(): void
    {
        [$opts, $rest] = Args::parse(['-x']);
        $this->assertTrue($opts['x']);
    }

    // --- optStr() tests ---

    public function testOptStrReturnsValueWhenKeyExists(): void
    {
        $opts = ['format' => 'sarif'];
        $this->assertEquals('sarif', Args::optStr($opts, 'format'));
    }

    public function testOptStrReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertEquals('json', Args::optStr([], 'format', 'json'));
    }

    public function testOptStrReturnsNullByDefault(): void
    {
        $this->assertNull(Args::optStr([], 'format'));
    }

    public function testOptStrReturnsDefaultWhenKeyIsArray(): void
    {
        $opts = ['exclude' => ['vendor', 'generated']];
        $this->assertEquals('default', Args::optStr($opts, 'exclude', 'default'));
    }

    // --- optArr() tests ---

    public function testOptArrReturnsEmptyArrayWhenKeyMissing(): void
    {
        $this->assertEquals([], Args::optArr([], 'exclude'));
    }

    public function testOptArrWrappsSingleValueInArray(): void
    {
        $opts = ['exclude' => 'vendor'];
        $this->assertEquals(['vendor'], Args::optArr($opts, 'exclude'));
    }

    public function testOptArrPassesThroughArray(): void
    {
        $opts = ['exclude' => ['vendor', 'generated']];
        $this->assertEquals(['vendor', 'generated'], Args::optArr($opts, 'exclude'));
    }

    // --- optBool() tests ---

    public function testOptBoolReturnsFalseByDefault(): void
    {
        $this->assertFalse(Args::optBool([], 'verbose'));
    }

    public function testOptBoolReturnsCustomDefault(): void
    {
        $this->assertTrue(Args::optBool([], 'verbose', true));
    }

    public function testOptBoolReturnsTrueForTrueLiteral(): void
    {
        $opts = ['verbose' => true];
        $this->assertTrue(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsTrueForStringTrue(): void
    {
        $opts = ['verbose' => 'true'];
        $this->assertTrue(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsTrueForOne(): void
    {
        $opts = ['verbose' => '1'];
        $this->assertTrue(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsTrueForYes(): void
    {
        $opts = ['verbose' => 'yes'];
        $this->assertTrue(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsTrueForOn(): void
    {
        $opts = ['verbose' => 'on'];
        $this->assertTrue(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsFalseForFalsyString(): void
    {
        $opts = ['verbose' => 'false'];
        $this->assertFalse(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsFalseForZero(): void
    {
        $opts = ['verbose' => '0'];
        $this->assertFalse(Args::optBool($opts, 'verbose'));
    }

    public function testOptBoolReturnsFalseForNo(): void
    {
        $opts = ['verbose' => 'no'];
        $this->assertFalse(Args::optBool($opts, 'verbose'));
    }
}
