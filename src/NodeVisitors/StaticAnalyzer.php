<?php namespace Guardrail\NodeVisitors;

use PhpParser\Node;
use PhpParser\NodeTraverserInterface;
use PhpParser\NodeVisitor;
use Guardrail\Abstractions\FunctionLikeParameter;
use Guardrail\Checks;
use Guardrail\Output\OutputInterface;
use Guardrail\Scope;
use Guardrail\SymbolTable\SymbolTable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use Guardrail\TypeInferrer;

class StaticAnalyzer implements NodeVisitor {
	/** @var  SymbolTable */
	private $index;

	/** @var string */
	private $file;

	/** @var \Guardrail\Checks\BaseCheck[] */
	private $checks = [];

	/** @var Class_[] */
	private $classStack = [];
	/** @var Scope[] */
	private $scopeStack = [];

	/** @var TypeInferrer */
	private $typeInferrer;

	function __construct($basePath, $index, OutputInterface $output, $config) {
		$this->index = $index;
		$this->scopeStack = [new Scope(true, true)];
		$this->typeInferrer = new TypeInferrer($index);

		/** @var \Guardrail\Checks\BaseCheck[] $checkers */
		$checkers = [
			new \Guardrail\Checks\DocBlockTypesCheck($this->index, $output),
			new \Guardrail\Checks\UndefinedVariableCheck($this->index, $output),
			new \Guardrail\Checks\ImpossibleInjectionCheck($this->index, $output),
			new \Guardrail\Checks\DefinedConstantCheck($this->index, $output),
			new \Guardrail\Checks\BacktickOperatorCheck($this->index, $output),
			new \Guardrail\Checks\PropertyFetch($this->index, $output),
			new \Guardrail\Checks\InterfaceCheck($this->index, $output),
			new \Guardrail\Checks\ParamTypesCheck($this->index, $output),
			new \Guardrail\Checks\StaticCallCheck($this->index, $output),
			new \Guardrail\Checks\InstantiationCheck($this->index, $output),
			new \Guardrail\Checks\InstanceOfCheck($this->index, $output),
			new \Guardrail\Checks\CatchCheck($this->index, $output),
			new \Guardrail\Checks\ClassConstantCheck($this->index, $output),
			new \Guardrail\Checks\FunctionCallCheck($this->index, $output),
			new \Guardrail\Checks\MethodCall($this->index, $output),
			new \Guardrail\Checks\SwitchCheck($this->index, $output)
		];


		foreach ($checkers as $checker) {
			foreach ($checker->getCheckNodeTypes() as $nodeType) {
				if (!isset($this->checks[$nodeType])) {
					$this->checks[$nodeType] = [$checker];
				} else {
					$this->checks[$nodeType][] = $checker;
				}
			}
		}
	}

	function beforeTraverse(array $nodes) {
		return null;
	}

	function setFile($name) {
		$this->file = $name;
		$this->scopeStack = [new Scope(true, true)];
	}

	function enterNode(Node $node) {
		$class = get_class($node);
		if ($node instanceof Trait_) {
			return NodeTraverserInterface::DONT_TRAVERSE_CHILDREN;
		}
		if ($node instanceof Class_ || $node instanceof Trait_) {
			array_push($this->classStack, $node);
		}
		if($node instanceof Node\FunctionLike) { // Typecast
			$this->pushFunctionScope($node);
		}
		if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef) {
			$this->handleAssignment($node);
		}
		if ($node instanceof Node\Stmt\StaticVar) {
			$this->setScopeExpression($node->name, $node->default);
		}
		if ($node instanceof Node\Stmt\Catch_) {
			$this->setScopeType(strval($node->var), strval($node->type));
		}
		if($node instanceof Node\Stmt\Global_) {
			foreach($node->vars as $var) {
				if($var instanceof Node\Expr\Variable && gettype($var->name)=="string") {
					$this->setScopeType(strval($var->name), Scope::MIXED_TYPE);
				}
			}
		}
		if($node instanceof Node\Expr\MethodCall) {
			if(gettype($node->name)=="string") {

				$type = $this->typeInferrer->inferType( end($this->classStack)?:null, $node->var,end($this->scopeStack) );
				if($type && $type[0]!="!") {
					$method = $this->index->getAbstractedMethod($type, $node->name);
					if ($method) {
						/** @var FunctionLikeParameter[] $params */
						$params = $method->getParameters();
						$paramCount = count($params);
						foreach ($node->args as $index => $arg) {
							if (
								(isset($params[$index]) && $params[$index]->isReference()) ||
								($index>=$paramCount && $paramCount>0 && $params[$paramCount-1]->isReference())
							) {
								if ($arg->value instanceof Node\Expr\Variable && gettype($arg->value->name) == "string") {
									$this->setScopeType($arg->value->name, Scope::MIXED_TYPE);
								}
							}
						}
					}
				}
			}
		}
		if($node instanceof Node\Expr\StaticCall) {
			if($node->class instanceof Node\Name && gettype($node->name)=="string") {
				$method=$this->index->getAbstractedMethod( strval($node->class), strval($node->name));
				if($method) {
					/** @var FunctionLikeParameter[] $params */
					$params = $method->getParameters();
					$paramCount = count($params);
					foreach ($node->args as $index => $arg) {
						if (
							(isset($params[$index]) && $params[$index]->isReference()) ||
							($index>=$paramCount && $paramCount>0 && $params[$paramCount-1]->isReference())
						) {
							if ($arg->value instanceof Node\Expr\Variable && gettype($arg->value->name) == "string") {
								$this->setScopeType($arg->value->name, Scope::MIXED_TYPE);
							}
						}
					}
				}
			}
		}
		if($node instanceof Node\Expr\FuncCall) {
			if($node->name instanceof Node\Name) {
				$function = $this->index->getAbstractedFunction(strval($node->name));
				if($function) {
					$params = $function->getParameters();
					$paramCount = count($params);
					foreach ($node->args as $index => $arg) {
						if ($arg->value instanceof Node\Expr\Variable && gettype($arg->value->name) == "string" &&
							(
								(isset($params[$index]) && $params[$index]->isReference()) ||
								($index>=$paramCount && $paramCount>0 && $params[$paramCount-1]->isReference())
							)
						) {
							$this->setScopeType($arg->value->name, Scope::MIXED_TYPE);
						}
					}
				}
			}
		}

		if($node instanceof Node\Stmt\Foreach_) {
			if($node->keyVar instanceof Node\Expr\Variable && gettype($node->keyVar->name)=="string") {
				$this->setScopeType(strval($node->keyVar->name), Scope::MIXED_TYPE);
			}
			if($node->valueVar instanceof Node\Expr\Variable && gettype($node->valueVar->name)=="string") {
				$type = $this->typeInferrer->inferType(end($this->classStack)?:null, $node->expr, end($this->scopeStack));
				if(substr($type,-2)=="[]") {
					$type=substr($type,0,-2);
				} else {
					$type=Scope::MIXED_TYPE;
				}
				$this->setScopeType(strval($node->valueVar->name), $type);
			} else if($node->valueVar instanceof Node\Expr\List_) {
				foreach($node->valueVar->vars as $var) {
					if($var instanceof Node\Expr\Variable && gettype($var->name)=="string") {
						$this->setScopeType(strval($var->name), Scope::MIXED_TYPE);
					}
				}
			}
		}
		if($node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_) {
			if($node instanceof Node\Stmt\ElseIf_) {
				// Pop the previous if's scope
				array_pop($this->scopeStack);
			}
			$this->pushIfScope($node);
		}

		if ($node instanceof Node\Stmt\Else_) {
			// The previous scope was only valid for the if side.
			array_pop($this->scopeStack);
		}

		if (isset($this->checks[$class])) {
			foreach ($this->checks[$class] as $check) {
				$check->run($this->file, $node, end($this->classStack) ?: null, end($this->scopeStack) ?: null);
			}
		}
		return null;
	}

	/**
	 * @param Node\Stmt\If_|Node\Stmt\ElseIf_ $node
	 */
	function pushIfScope(Node $node) {
		/** @var Scope $scope */
		$scope = end($this->scopeStack);

		if (self::isCastableIf($node)) {
			$newScope = $scope->getScopeClone();
			$this->addCastedScope($node, $newScope);
		} else {
			// No need to actually instantiate a different scope, since it's identical to the old.
			$newScope = $scope;
		}
		array_push($this->scopeStack, $newScope);
	}

	/**
	 * When a node is of the form "if ($var instanceof ClassName)" (with no else clauses) then we can
	 * relax the scoping rules inside the if statement to allow a different set of methods that might not
	 * be normally visible.  This is primarily used for downcasting.
	 *
	 * "ClassName" inside the true clause.
	 * @param Node\Stmt\If_|Node\Stmt\ElseIf_ $node
	 */
	function addCastedScope(Node $node, Scope $newScope) {

		/** @var Node\Expr\Instanceof_ $cond */
		$cond = $node->cond;

		if ($cond->expr instanceof Node\Expr\Variable && gettype($cond->expr->name) == "string" && $cond->class instanceof Node\Name) {
			$newScope->setVarType($cond->expr->name, strval($cond->class));
		}

	}

	function pushFunctionScope(Node\FunctionLike $func) {
		$isStatic = true;
		if($func instanceof Node\Stmt\ClassMethod) {
			$isStatic = $func->isStatic();
		}
		$scope = new Scope( $isStatic );
		foreach ($func->getParams() as $param) {
			if($param->variadic) {
				// TODO: Track the type of a variadic array
				$scope->setVarType(strval($param->name), 'array');
			} else {
				$scope->setVarType(strval($param->name), strval($param->type));
			}
		}
		if($func instanceof Node\Expr\Closure) {
			$oldScope=end($this->scopeStack);
			foreach($func->uses as $variable) {
				$type = $oldScope->getVarType($variable->var);
				if($type==Scope::UNDEFINED) {
					$type=Scope::MIXED_TYPE;
				}
				$scope->setVarType($variable->var, $type);
			}
		}
		array_push($this->scopeStack, $scope);
	}

	/**
	 * An if is castable if there are no elseifs and the expr is a simple "InstanceOf" expression.
	 * @param Node $node
	 * @return bool
	 */
	static function isCastableIf(Node $node) {
		return ($node instanceof Node\Stmt\If_ || $node instanceof Node\Stmt\ElseIf_) && $node->cond instanceof Node\Expr\Instanceof_;
	}

	private function setScopeExpression($varName, $expr) {
		$scope = end($this->scopeStack);
		$class = end($this->classStack) ?: null;
		$newType = $this->typeInferrer->inferType($class, $expr, $scope);
		$this->setScopeType($varName, $newType);
	}

	private function setScopeType($varName, $newType) {
		$scope = end($this->scopeStack);
		$oldType = $scope->getVarType($varName);
		if ($oldType != $newType) {
			if ($oldType == Scope::UNDEFINED) {
				$scope->setVarType($varName, $newType);
			} else {
				// The variable has been used with 2 different types.  Update it in the scope as a mixed type.
				$scope->setVarType($varName, Scope::MIXED_TYPE);
			}
		}
	}


	/**
	 * Assignment can cause a new variable to come into scope.  We infer the type of the expression (if possible) and
	 * add an entry to the variable table for this scope.
	 * @param Node\Expr\Assign|Node\Expr\AssignRef $op
	 */
	private function handleAssignment( $op) {
		if ($op->var instanceof Node\Expr\Variable && gettype($op->var->name)=="string") {
			$varName = strval($op->var->name);
			$this->setScopeExpression($varName, $op->expr);
		} else if ($op->var instanceof Node\Expr\List_) {
			// We're not going to examine a potentially complex right side of the assignment, so just set all vars to mixed.
			foreach ($op->var->vars as $var) {
				if ($var && $var instanceof Node\Expr\Variable && gettype($var->name)=="string") {
					$this->setScopeType(strval($var->name), Scope::MIXED_TYPE);
				}
			}
		} else if($op->var instanceof Node\Expr\ArrayDimFetch) {
			$var=$op->var;
			while($var instanceof Node\Expr\ArrayDimFetch) {
				$var=$var->var;
			}
			if($var instanceof Node\Expr\Variable && gettype($var->name)=="string") {
				$varName = strval($var->name);
				$this->setScopeType($varName, "array");
			}
		}
	}

	function leaveNode(Node $node) {
		if ($node instanceof Class_) {
			array_pop($this->classStack);
		}
		if ($node instanceof Node\FunctionLike) {
			array_pop($this->scopeStack);
		}

		if ($node instanceof Node\Stmt\If_ && $node->else==null) {
			// We only need to pop the scope if there wasn't an else clause.  Otherwise, it has already been popped.
			array_pop($this->scopeStack);
		}
		return null;
	}

	function afterTraverse(array $nodes) {
		return null;
	}



}
