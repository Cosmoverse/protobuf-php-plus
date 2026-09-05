<?php

declare(strict_types=1);

namespace cosmicpe\protobuf;

use LogicException;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

final class PropertyVisibility extends NodeVisitorAbstract{

	public function leaveNode(Node $node) : null{
		if($node instanceof Class_ && $node->getAttribute("protobuf_message", false)){
			foreach($node->getProperties() as $property){
				if($property->isProtected() || $property->isProtectedSet()){
					$property->type !== null || throw new LogicException("Property visibility requires the type annotation pass to run first");
					$property->flags = ($property->flags & ~(Modifiers::VISIBILITY_MASK | Modifiers::VISIBILITY_SET_MASK)) | Modifiers::PROTECTED_SET;
				}
			}
		}
		return null;
	}
}
