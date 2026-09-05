<?php

declare(strict_types=1);

namespace cosmicpe\protobuf;

use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use function array_push;
use function array_unique;
use function explode;
use function file;
use function is_dir;
use function proc_close;
use function proc_open;
use function strlen;
use function strpos;
use function substr;
use const FILE_IGNORE_NEW_LINES;
use const STDERR;
use const STDIN;
use const STDOUT;

final class Protop{

	/** @param list<string> $arguments */
	public function run(array $arguments) : int{
		$process = proc_open(["protoc", ...$arguments], [STDIN, STDOUT, STDERR], $pipes);
		$process !== false || throw new RuntimeException("Failed to start protoc; install it and add it to PATH");
		$status = proc_close($process);
		if($status !== 0){
			return $status;
		}

		$arguments = $this->expandArguments($arguments);
		$outputs = [];
		foreach($arguments as $index => $argument){
			$option = explode("=", $argument, 2);
			if($argument === "--php_out"){
				$output = $arguments[$index + 1];
			}elseif($option[0] === "--php_out" && isset($option[1])){
				$output = $option[1];
			}else{
				continue;
			}
			// protoc accepts generator options before the output path's colon.
			$colon = strpos($output, ":");
			$is_drive = $colon === 1 && isset($output[2]) && ($output[2] === "/" || $output[2] === "\\");
			$outputs[] = $colon !== false && !$is_drive ? substr($output, $colon + 1) : $output;
		}

		$processor = new GeneratedFiles();
		foreach(array_unique($outputs) as $output){
			if(is_dir($output)){
				$processor->process($output, new TypeAnnotations(), new PropertyVisibility());
			}else{
				$archive = new PharData($output);
				foreach(new RecursiveIteratorIterator($archive) as $file){
					if($file->isFile() && $file->getExtension() === "php"){
						$name = substr($file->getPathname(), strlen("phar://" . $archive->getPath()) + 1);
						$archive[$name] = $processor->transform($file->getContent(), new TypeAnnotations(), new PropertyVisibility());
					}
				}
			}
		}
		return 0;
	}

	/**
	 * @param list<string> $arguments
	 * @return list<string>
	 */
	private function expandArguments(array $arguments) : array{
		$result = [];
		foreach($arguments as $argument){
			if(isset($argument[0]) && $argument[0] === "@"){
				$path = substr($argument, 1);
				$lines = file($path, FILE_IGNORE_NEW_LINES);
				$lines !== false || throw new RuntimeException("Failed to read argument file {$path}");
				array_push($result, ...$lines);
			}else{
				$result[] = $argument;
			}
		}
		return $result;
	}
}
