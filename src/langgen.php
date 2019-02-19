<?php

namespace Validation;

class LangGen {

	/**
	 * update language files from GUMP source
	 * Sample: GET /update-lang = \Validation\LangGen->generate
	 */
	function generate() {
		$files = glob('vendor/wixel/gump/lang/*');
		$dest = realpath(__DIR__.'/lang/').'/';
		/** @var \Base $f3 */
		$f3 = \Base::instance();
		header("Content-Type: text;");
		foreach ($files as $lang) {
			$file = basename($lang,'.php');
			$content = '[error.validation]'."\n";
			$dic = require($lang);
			foreach ($dic as $key => $val) {
				$key = str_replace('validate_','',$key);
				$val = html_entity_decode($val);
				$val = str_replace('{field}','{0}',$val);
				$val = str_replace('{param}','{1}',$val);
				$content .= $key.' = '.$val."\n";
			}
			$f3->write($filename=$dest.$file.'.ini',$content);
			echo "file created: ".$filename."\n";
		}
	}
}