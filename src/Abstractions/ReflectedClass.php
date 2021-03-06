<?php
namespace Guardrail\Abstractions;

use Guardrail\Abstractions\ClassInterface;
use Guardrail\Abstractions\ReflectedClassMethod;

class ReflectedClass implements ClassInterface {
	/**
	 * @var \ReflectionClass
	 */
	private $refl;

	function __construct(\ReflectionClass $refl) {
		$this->refl = $refl;
	}

	function getParentClassName() {
		$parent = $this->refl->getParentClass();
		return $parent ? $parent->getName() : "";
	}

	function getInterfaceNames() {
		return $this->refl->getInterfaceNames();
	}

	function isInterface() {
		return $this->refl->isInterface();
	}

	function isDeclaredAbstract() {
		return $this->refl->isAbstract();
	}

	function getMethodNames() {
		$ret = [];
		foreach($this->refl->getMethods() as $method) {
			$ret[] = $method->name;
		}
		return $ret;
	}

	function hasConstant($name) {
		$constants = $this->refl->getConstants();
		return array_key_exists($name, $constants);
	}

	function getMethod($name) {
		try {
			$method = $this->refl->getMethod($name);
			if ($method) return new ReflectedClassMethod($method);
		}
		catch(\ReflectionException $e) {
			return null;
		}
		return null;
	}

	function getName() {
		return $this->refl->getName();
	}
}