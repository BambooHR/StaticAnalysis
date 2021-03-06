<?php

namespace Guardrail\Output;

use Guardrail\Output\OutputInterface;
use N98\JUnitXml;
use PhpParser\Node;


class XUnitOutput implements OutputInterface {

	/** @var \Guardrail\Config  */
	private $config;

	/** @var JUnitXml\TestSuiteElement[] */
	protected $suites;

	/** @var JUnitXml\Document  */
	protected $doc;

	private $files;

	private $emitErrors;

	private $emitList = [];

	private $counts = [];

	function __construct(\Guardrail\Config $config) {
		$this->doc=new JUnitXml\Document();
		$this->doc->formatOutput=true;
		$this->config=$config;
		$this->emitErrors = $config->getOutputLevel()==1;
		$this->emitList = $config->getEmitList();
	}

	function getClass($className) {
		if(!isset($this->suites[$className])) {
			$suite = $this->doc->addTestSuite();
			$suite->setName($className);
			$this->suites[$className]=$suite;
		}
		return $this->suites[$className];

	}

	function incTests() {
		//$this->suite->addTestCase();
	}

	function emitError($className, $fileName, $lineNumber, $name, $message="") {
		if(!in_array($name, $this->emitList) && count($this->emitList)>0) {
			return;
		}
		$suite = $this->getClass($className);
		if(!isset($this->files[$className][$fileName])) {
			$case=$suite->addTestCase();
			$case->setName($fileName);
			$case->setClassname( $className );
			if(!isset($this->files[$className])) {
				$this->files[$className]=[];
			}
			$this->files[$className][$fileName]=$case;
		} else {
			$case=$this->files[$className][$fileName];
		}



		$message.=" on line ".$lineNumber;
		$case->addFailure($message , $name);
		if($this->emitErrors) {
			echo "E";
		}
		if(!isset($this->counts[$name])) {
			$this->counts[$name]=1;
		} else {
			++$this->counts[$name];
		}
		$this->outputExtraVerbose("ERROR: $fileName $lineNumber: $name: $message\n");
	}

	function output($verbose, $extraVerbose) {
		if($this->config->getOutputLevel()==1) {
			echo $verbose;flush();
		} else if($this->config->getOutputLevel()==2) {
			echo $extraVerbose."\n";flush();
		}
	}

	function getCounts() {
		return $this->counts;
	}

	function outputVerbose($string) {
		if($this->config->getOutputLevel()>=1) {
			echo $string;flush();
		}
	}

	function outputExtraVerbose($string) {
		if($this->config->getOutputLevel()>=2) {
			echo $string;flush();
		}
	}

	function getErrorCount() {
		$failures = $this->doc->getElementsByTagName("failure");
		return $failures->length;
	}

	function renderResults() {
		if($this->config->getOutputFile()) {
			$this->doc->save($this->config->getOutputFile());
		} else {
			echo $this->doc->saveXml();
		}
	}

	function getErrorsByFile() {
		$fileCount=[];
		$failures = $this->doc->getElementsByTagName("failure");
		for($i=0; $i<$failures->length; ++$i) {
			$item = $failures->item($i);
			$name = $item->parentNode->attributes->getNamedItem("name")->textContent;
			$fileCount[ $name ]++;
		}
		return $fileCount;
	}
}