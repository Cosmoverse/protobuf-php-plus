<?php

declare(strict_types=1);

namespace cosmicpe\protobuf\tests;

use cosmicpe\protobuf\GeneratedFiles;
use cosmicpe\protobuf\PropertyVisibility;
use cosmicpe\protobuf\TypeAnnotations;
use PHPUnit\Framework\TestCase;

final class AstTransformationTest extends TestCase{

	public function testUnrelatedPhpIsNotModified() : void{
		$source = <<<'PHP'
<?php

final class Application{
	protected string $value = "untouched";
}
PHP;
		$result = (new GeneratedFiles())->transform($source, new TypeAnnotations(), new PropertyVisibility());
		self::assertSame($source, $result);
	}
}
