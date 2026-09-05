<?php

declare(strict_types=1);

namespace cosmicpe\protobuf;

use Google\Protobuf\Internal\GPBUtil;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\OneofField;
use Google\Protobuf\RepeatedField;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use function spl_object_id;

final class TypeAnnotations extends NodeVisitorAbstract{

	private NodeFinder $finder;

	public function __construct(){
		$this->finder = new NodeFinder();
	}

	public function leaveNode(Node $node) : null{
		if(!($node instanceof Class_)){
			return null;
		}
		if($this->isMessage($node)){
			$this->annotateMessage($node);
		}elseif($node->getProperty("valueToName") !== null && $node->getMethod("name") !== null && $node->getMethod("value") !== null){
			$this->annotateEnum($node);
		}elseif($node->getProperty("is_initialized") !== null && ($method = $node->getMethod("initOnce")) !== null){
			$node->getProperty("is_initialized")->type = new Identifier("bool");
			$method->returnType = new Identifier("void");
		}
		return null;
	}

	private function isMessage(Class_ $class) : bool{
		$extends = $class->extends?->getAttribute("resolvedName");
		return $extends instanceof Name && $extends->toString() === Message::class;
	}

	private function annotateEnum(Class_ $class) : void{
		$class->getProperty("valueToName")->type = new Identifier("array");
		$name = $class->getMethod("name");
		$name->params[0]->type = new Identifier("int");
		$name->returnType = new Identifier("string");
		$value = $class->getMethod("value");
		$value->params[0]->type = new Identifier("string");
		$value->returnType = new Identifier("int");
	}

	private function annotateMessage(Class_ $class) : void{
		$class->setAttribute("protobuf_message", true);
		/** @var array<string, Identifier|Name|NullableType|UnionType> $property_types */
		$property_types = [];
		/** @var array<int, Identifier|Name|NullableType|UnionType> $oneof_types */
		$oneof_types = [];
		/** @var array<int, true> $setters */
		$setters = [];

		foreach($class->getMethods() as $method){
			$validator = $this->findValidator($method);
			if($validator === null){
				continue;
			}
			$type = $this->getValidatedType($validator);
			$method->params[0]->type = $this->getParameterType($type);
			$method->returnType = new Name("self");
			$setters[spl_object_id($method)] = true;

			$assignment = $this->finder->findFirst($method->stmts, static function(Node $node) : bool{
				return $node instanceof Assign && $node->var instanceof PropertyFetch
					&& $node->var->var instanceof Variable && $node->var->var->name === "this";
			});
			if($assignment instanceof Assign){
				$property_types[$assignment->var->name->toString()] = $type;
				continue;
			}
			$call = $this->findThisCall($method, "writeOneof") ?? throw new LogicException("Unsupported protobuf setter: {$class->name}::{$method->name}");
			$index = $call->args[0]->value;
			$index instanceof Int_ || throw new LogicException("Unsupported protobuf oneof index: {$class->name}::{$method->name}");
			$oneof_types[$index->value] = $type;
		}

		foreach($class->getMethods() as $method){
			if(isset($setters[spl_object_id($method)])){
				continue;
			}
			if($method->name->toLowerString() === "__construct"){
				$method->params[0]->type = new NullableType(new Identifier("array"));
				continue;
			}
			$return = $this->finder->findFirstInstanceOf($method->stmts, Return_::class);
			if($return === null || $return->expr === null){
				$method->returnType = new Identifier("void");
				continue;
			}
			if($return->expr instanceof Isset_ || $this->findThisCall($method, "hasOneof") !== null){
				$method->returnType = new Identifier("bool");
				continue;
			}
			if(($call = $this->findThisCall($method, "readOneof")) !== null){
				$index = $call->args[0]->value;
				$index instanceof Int_ || throw new LogicException("Unsupported protobuf oneof index: {$class->name}::{$method->name}");
				$method->returnType = clone ($oneof_types[$index->value] ?? throw new LogicException("Missing protobuf oneof setter: {$class->name}::{$method->name}"));
				continue;
			}
			if(($call = $this->findThisCall($method, "whichOneof")) !== null){
				$group = $call->args[0]->value;
				$group instanceof String_ || throw new LogicException("Unsupported protobuf oneof group: {$class->name}::{$method->name}");
				$property_types[$group->value] = new FullyQualified(OneofField::class);
				$method->returnType = new Identifier("string");
				continue;
			}
			$property = $this->finder->findFirstInstanceOf($return->expr, PropertyFetch::class);
			$property instanceof PropertyFetch && $property->var instanceof Variable && $property->var->name === "this"
				|| throw new LogicException("Unsupported protobuf method: {$class->name}::{$method->name}");
			$method->returnType = clone ($property_types[$property->name->toString()] ?? throw new LogicException("Missing protobuf property type: {$class->name}::{$method->name}"));
		}

		foreach($class->getProperties() as $property){
			$field = $property->props[0];
			$type = clone ($property_types[$field->name->toString()] ?? throw new LogicException("Missing protobuf property type: {$class->name}::\${$field->name}"));
			if($field->default instanceof ConstFetch && $field->default->name->toLowerString() === "null"){
				$type = $this->makeNullable($type);
			}
			$property->type = $type;
		}
	}

	private function findValidator(ClassMethod $method) : ?StaticCall{
		$call = $this->finder->findFirst($method->stmts, static function(Node $node) : bool{
			$resolved = $node instanceof StaticCall && $node->class instanceof Name ? $node->class->getAttribute("resolvedName") : null;
			return $resolved instanceof Name && $resolved->toString() === GPBUtil::class;
		});
		return $call instanceof StaticCall ? $call : null;
	}

	private function findThisCall(ClassMethod $method, string $name) : ?MethodCall{
		$call = $this->finder->findFirst($method->stmts, static function(Node $node) use($name) : bool{
			return $node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === "this"
				&& $node->name instanceof Identifier && $node->name->toString() === $name;
		});
		return $call instanceof MethodCall ? $call : null;
	}

	private function getValidatedType(StaticCall $call) : Identifier|Name|NullableType|UnionType{
		return match($call->name->toString()){
			"checkInt32", "checkUint32", "checkEnum" => new Identifier("int"),
			"checkInt64", "checkUint64" => new UnionType([new Identifier("int"), new Identifier("string")]),
			"checkFloat", "checkDouble" => new Identifier("float"),
			"checkBool" => new Identifier("bool"),
			"checkString" => new Identifier("string"),
			"checkMessage" => new NullableType(clone $call->args[1]->value->class->getAttribute("resolvedName")),
			"checkRepeatedField" => new FullyQualified(RepeatedField::class),
			"checkMapField" => new FullyQualified(MapField::class),
			default => throw new LogicException("Unsupported protobuf validator: {$call->name}")
		};
	}

	private function getParameterType(Identifier|Name|NullableType|UnionType $type) : Identifier|Name|NullableType|UnionType{
		if($type instanceof FullyQualified && ($type->toString() === RepeatedField::class || $type->toString() === MapField::class)){
			return new UnionType([new Identifier("array"), clone $type]);
		}
		return clone $type;
	}

	private function makeNullable(Identifier|Name|NullableType|UnionType $type) : NullableType|UnionType{
		if($type instanceof NullableType){
			return $type;
		}
		return $type instanceof UnionType
			? new UnionType([...$type->types, new Identifier("null")])
			: new NullableType($type);
	}
}
