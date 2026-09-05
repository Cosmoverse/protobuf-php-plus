<?php

declare(strict_types=1);

namespace cosmicpe\protobuf;

use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use function file_get_contents;
use function file_put_contents;
use function strlen;

final class GeneratedFiles{

	private Parser $parser;
	private Standard $printer;

	public function __construct(){
		$version = PhpVersion::fromString("8.4");
		$this->parser = (new ParserFactory())->createForVersion($version);
		$this->printer = new Standard(["phpVersion" => $version]);
	}

	public function transform(string $source, NodeVisitor ...$visitors) : string{
		$original = $this->parser->parse($source);
		$nodes = (new NodeTraverser(new CloningVisitor()))->traverse($original);
		foreach($visitors as $visitor){
			$nodes = (new NodeTraverser(new NameResolver(null, ["replaceNodes" => false]), $visitor))->traverse($nodes);
		}
		return $this->printer->printFormatPreserving($nodes, $original, $this->parser->getTokens());
	}

	public function process(string $directory, NodeVisitor ...$visitors) : void{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
		foreach($files as $file){
			if(!$file->isFile() || $file->getExtension() !== "php"){
				continue;
			}
			$path = $file->getPathname();
			$source = file_get_contents($path);
			$source !== false || throw new RuntimeException("Failed to read {$path}");
			$result = $this->transform($source, ...$visitors);
			if($result !== $source){
				file_put_contents($path, $result) === strlen($result) || throw new RuntimeException("Failed to write {$path}");
			}
		}
	}
}
