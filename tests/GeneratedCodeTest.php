<?php

declare(strict_types=1);

namespace cosmicpe\protobuf\tests;

use Error;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\RepeatedField;
use PHPUnit\Framework\TestCase;
use Protop\Test\Child;
use Protop\Test\Sample;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use function array_map;

final class GeneratedCodeTest extends TestCase{

	public function testPropertiesHaveNativeTypesAndAsymmetricVisibility() : void{
		$name = new ReflectionProperty(Sample::class, "name");
		self::assertInstanceOf(ReflectionNamedType::class, $name->getType());
		self::assertSame("string", $name->getType()->getName());
		self::assertTrue($name->isPublic());
		self::assertTrue($name->isProtectedSet());

		$count = new ReflectionProperty(Sample::class, "count");
		self::assertInstanceOf(ReflectionUnionType::class, $count->getType());
		self::assertEqualsCanonicalizing(["int", "string", "null"], array_map(
			static fn(ReflectionNamedType $type) : string => $type->getName(),
			$count->getType()->getTypes()
		));
	}

	public function testCollectionPropertiesHaveContainerTypes() : void{
		self::assertSame(RepeatedField::class, (new ReflectionProperty(Sample::class, "tags"))->getType()?->getName());
		self::assertSame(MapField::class, (new ReflectionProperty(Sample::class, "children"))->getType()?->getName());
	}

	public function testMethodsHaveParameterAndReturnTypes() : void{
		$getter = new ReflectionMethod(Sample::class, "getName");
		self::assertSame("string", $getter->getReturnType()?->getName());
		$setter = new ReflectionMethod(Sample::class, "setName");
		self::assertSame("string", $setter->getParameters()[0]->getType()?->getName());
		self::assertSame("self", $setter->getReturnType()?->getName());
	}

	public function testGeneratedMessageSerializesAndPreservesOneofState() : void{
		$message = new Sample([
			"name" => "example",
			"count" => "9223372036854775807",
			"tags" => ["one", "two"],
			"children" => ["first" => new Child(["value" => "child"])],
			"enabled" => true
		]);
		self::assertSame("example", $message->name);
		self::assertSame("enabled", $message->getSelection());

		$encoded = $message->serializeToString();
		$decoded = new Sample();
		$decoded->mergeFromString($encoded);
		self::assertSame($encoded, $decoded->serializeToString());
	}

	public function testPropertiesCannotBeWrittenFromPublicScope() : void{
		$message = new Sample(["name" => "original"]);
		$this->expectException(Error::class);
		$message->name = "changed";
	}
}
