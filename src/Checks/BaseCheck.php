<?php

namespace Guardrail\Checks;

use N98\JUnitXml;
use PhpParser\Node;
use Guardrail\Scope;
use Guardrail\SymbolTable\SymbolTable;
use Guardrail\Output\OutputInterface;

abstract class BaseCheck {
	const TYPE_SECURITY_BACKTICK="Standard.Security.Backtick";
	const TYPE_SECURITY_DANGEROUS="Standard.Security.Dangerous";

	const TYPE_UNKNOWN_CLASS="Standard.Unknown.Class";
	const TYPE_UNKNOWN_CLASS_CONSTANT="Standard.Unknown.Class.Constant";
	const TYPE_UNKNOWN_GLOBAL_CONSTANT="Standard.Unknown.Global.Constant";
	const TYPE_UNKNOWN_METHOD="Standard.Unknown.Class.Method";
	const TYPE_UNKNOWN_FUNCTION="Standard.Unknown.Function";
	const TYPE_UNKNOWN_VARIABLE="Standard.Unknown.Variable";

	const TYPE_INHERITANCE="Standard.Inheritance";
	const TYPE_PHP7_INHERITANCE="Standard.Inheritance.Php7";
	const TYPE_UNIMPLEMENTED_METHOD="Standard.Inheritance.Unimplemented";

	const TYPE_INCORRECT_STATIC_CALL="Standard.Incorrect.Static";
	const TYPE_INCORRECT_DYNAMIC_CALL="Standard.Incorrect.Dynamic";

	const TYPE_SCOPE_ERROR="Standard.Scope";
	const TYPE_SIGNATURE_COUNT="Standard.Param.Count";
	const TYPE_SIGNATURE_COUNT_EXCESS="Standard.Param.Count.Excess";
	const TYPE_SIGNATURE_TYPE="Standard.Param.Type";

	const TYPE_MISSING_BREAK="Standard.Switch.Break";
	const TYPE_PARSE_ERROR="Standard.Parse.Error";

	/** @var SymbolTable */
	protected $symbolTable;

	/** @var \Guardrail\Output\OutputInterface  */
	private $doc;

	function __construct(SymbolTable $symbolTable, OutputInterface $doc) {
		$this->symbolTable=$symbolTable;
		$this->doc=$doc;
	}

	function emitError($file, \PhpParser\Node $node, $class, $message="") {
		return $this->emitErrorOnLine($file, $node->getLine(), $class, $message);
	}

	function emitErrorOnLine($file, $lineNumber, $class, $message="") {
		return $this->doc->emitError(get_class($this), $file, $lineNumber, $class, $message);
	}

	function incTests() {
		$this->doc->incTests();
	}

	/**
	 * @return string[]
	 */
	abstract function getCheckNodeTypes();

	abstract function run($fileName, $node, Node\Stmt\ClassLike $inside=null, Scope $scope=null);
}